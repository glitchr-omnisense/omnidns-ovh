<?php

namespace Omnidns\Ovh\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Omnidns\Exception\InvalidArgumentException;
use Omnidns\Exception\ProviderException;
use Omnidns\Model\Contact;
use Omnidns\Model\Record;
use Omnidns\Ovh\OvhFactory;
use Omnidns\Ovh\OvhProvider;
use Omnidns\Registry;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * OVHcloud's answers recorded, written from its API's schemas (no account
 * at hand: not captured), each request checked as ovh/ovh signs and sends
 * it through Guzzle.
 */
final class OvhProviderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    public function testAFreeDomainAndItsPrice(): void
    {
        $provider = $this->provider([
            'POST /order/cart' => ['cartId' => 'cart-1', 'expire' => '2026-10-25T04:41:54+00:00', 'items' => [], 'readOnly' => false],
            'GET /order/cart/cart-1/domain?domain=omnidns-test.fr' => 'offers-free',
        ]);
        $availability = $provider->check('OMNIDNS-test.fr');

        self::assertTrue($availability->available);
        self::assertSame(['6.99', 'EUR'], [$availability->price?->decimal(), $availability->price?->currency]);
        self::assertSame('8.39', $availability->renewal?->decimal());
        self::assertFalse($availability->premium);
        self::assertSame(['GET /auth/time', 'POST /order/cart', 'GET /order/cart/cart-1/domain?domain=omnidns-test.fr'], $this->calls());
        self::assertSame('{"ovhSubsidiary":"FR"}', (string) $this->requests[1]->getBody());
        self::assertSame('ak', $this->requests[1]->getHeaderLine('X-Ovh-Application'));
        self::assertSame('ck', $this->requests[1]->getHeaderLine('X-Ovh-Consumer'));
        $timestamp = $this->requests[1]->getHeaderLine('X-Ovh-Timestamp');
        self::assertSame('$1$'.sha1('as+ck+POST+https://eu.api.ovh.com/1.0/order/cart+{"ovhSubsidiary":"FR"}+'.$timestamp), $this->requests[1]->getHeaderLine('X-Ovh-Signature'), 'signed as OVH documents it');
    }

    public function testATakenDomainAndTheCartKept(): void
    {
        $provider = $this->provider([
            'POST /order/cart' => ['cartId' => 'cart-1'],
            'GET /order/cart/cart-1/domain?domain=ovh.com' => 'offers-taken',
            'GET /order/cart/cart-1/domain?domain=example.fr' => [],
        ]);

        self::assertSame([false, 'registered (a transfer is offered)'], [($a = $provider->check('ovh.com'))->available, $a->reason]);
        self::assertSame([false, 'not orderable'], [($b = $provider->check('example.fr'))->available, $b->reason]);
        self::assertCount(1, array_filter($this->calls(), static fn (string $c) => 'POST /order/cart' === $c), 'one cart for every check');
    }

    public function testARegistrationIsACartCheckedOut(): void
    {
        $provider = $this->provider([
            'POST /order/cart' => ['cartId' => 'cart-2'],
            'POST /order/cart/cart-2/assign' => null,
            'POST /order/cart/cart-2/domain' => ['itemId' => 123456, 'cartId' => 'cart-2', 'productId' => 'domain'],
            'POST /order/cart/cart-2/item/123456/configuration' => ['id' => 1, 'label' => 'OWNER_CONTACT', 'value' => '/me/contact/9876'],
            'POST /order/cart/cart-2/checkout' => ['orderId' => 222333444, 'url' => 'https://www.ovh.com/cgi-bin/order/displayOrder.cgi?orderId=222333444&orderPassword=x', 'prices' => ['withTax' => ['currencyCode' => 'EUR', 'text' => '16.78 €', 'value' => 16.78], 'withoutTax' => ['currencyCode' => 'EUR', 'value' => 13.98]]],
        ]);
        $order = $provider->register('omnidns-test.fr', 2, Contact::handle('9876'));

        self::assertSame(['register', 'ordered, awaiting payment', '222333444', '16.78', 2], [$order->action, $order->status, $order->id, $order->price?->decimal(), $order->years]);
        self::assertStringStartsWith('https://www.ovh.com/cgi-bin/order/displayOrder.cgi', (string) $order->paymentUrl);
        self::assertSame('{"domain":"omnidns-test.fr","duration":"P2Y"}', $this->body('POST /order/cart/cart-2/domain'));
        self::assertSame('{"label":"OWNER_CONTACT","value":"/me/contact/9876"}', $this->body('POST /order/cart/cart-2/item/123456/configuration'));
        self::assertSame('{"autoPayWithPreferredPaymentMethod":false,"waiveRetractationPeriod":false}', $this->body('POST /order/cart/cart-2/checkout'));
    }

    public function testAnOwnerOvhDoesNotHold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->provider([])->register('omnidns-test.fr', 1, new Contact('Ada', 'Lovelace', 'ada@example.org'));
    }

    public function testARenewalThroughTheServicesApi(): void
    {
        $provider = $this->provider([
            'GET /domain/example.fr/serviceInfos' => ['serviceId' => 111222, 'domain' => 'example.fr', 'expiration' => '2027-03-01', 'creation' => '2020-03-01', 'status' => 'ok', 'renew' => ['automatic' => true, 'forced' => false, 'period' => 12, 'deleteAtExpiration' => false]],
            'POST /service/111222/renew' => ['order' => ['orderId' => 555, 'url' => 'https://www.ovh.com/pay/555', 'prices' => ['withTax' => ['currencyCode' => 'EUR', 'value' => 10.07]]]],
        ], autoPay: true);
        $order = $provider->renew('example.fr');

        self::assertSame(['renew', 'ordered, paid with the preferred means of payment', '555', '10.07'], [$order->action, $order->status, $order->id, $order->price?->decimal()]);
        self::assertSame('{"dryRun":false,"duration":"P1Y","services":[111222]}', $this->body('POST /service/111222/renew'));
    }

    public function testTheDomainsAndTheirTerms(): void
    {
        $provider = $this->provider([
            'GET /domain' => ['example.fr', 'example.com'],
            'GET /domain/example.fr/serviceInfos' => ['serviceId' => 1, 'expiration' => '2027-03-01', 'creation' => '2020-03-01', 'status' => 'ok', 'renew' => ['automatic' => false]],
        ]);

        self::assertSame(['example.fr', 'example.com'], $provider->domains());
        $info = $provider->domain('example.fr');
        self::assertSame(['2027-03-01', '2020-03-01', false, ['ok'], null], [$info->expiresAt?->format('Y-m-d'), $info->createdAt?->format('Y-m-d'), $info->autoRenew, $info->status, $info->nameservers]);
    }

    public function testTheNameServers(): void
    {
        $provider = $this->provider([
            'GET /domain/example.fr/nameServer' => [11, 12],
            'GET /domain/example.fr/nameServer/11' => ['id' => 11, 'host' => 'dns200.anycast.me', 'ip' => null, 'isUsed' => true, 'toDelete' => false],
            'GET /domain/example.fr/nameServer/12' => ['id' => 12, 'host' => 'ns200.anycast.me', 'ip' => null, 'isUsed' => true, 'toDelete' => false],
            'POST /domain/example.fr/nameServers/update' => ['id' => 1, 'function' => 'DomainDnsUpdate', 'status' => 'todo'],
        ]);

        self::assertSame(['dns200.anycast.me', 'ns200.anycast.me'], $provider->nameservers('example.fr'));
        $provider->setNameservers('example.fr', ['NS1.Example.NET.', 'ns2.example.net']);
        self::assertSame('{"nameServers":[{"host":"ns1.example.net"},{"host":"ns2.example.net"}]}', $this->body('POST /domain/example.fr/nameServers/update'));
    }

    public function testTheZonesRecords(): void
    {
        $provider = $this->provider([
            'GET /domain/zone/example.fr/record' => [1001, 1002, 1003],
            'GET /domain/zone/example.fr/record/1001' => ['id' => 1001, 'zone' => 'example.fr', 'fieldType' => 'A', 'subDomain' => '', 'target' => '192.0.2.1', 'ttl' => 0],
            'GET /domain/zone/example.fr/record/1002' => ['id' => 1002, 'zone' => 'example.fr', 'fieldType' => 'MX', 'subDomain' => '', 'target' => '1 mx1.mail.ovh.net.', 'ttl' => 3600],
            'GET /domain/zone/example.fr/record/1003' => ['id' => 1003, 'zone' => 'example.fr', 'fieldType' => 'SPF', 'subDomain' => '', 'target' => '"v=spf1 include:mx.ovh.com ~all"', 'ttl' => 600],
            'GET /domain/zone/example.fr/record?fieldType=MX' => [1002],
        ]);
        $records = $provider->records('example.fr');

        self::assertCount(2, $records, 'OVH\'s own SPF type left out');
        self::assertSame(['A', '', '192.0.2.1', null, '1001'], [$records[0]->type, $records[0]->name, $records[0]->content, $records[0]->ttl, $records[0]->id]);
        self::assertSame(['mx1.mail.ovh.net.', 1, 3600], [$records[1]->content, $records[1]->priority, $records[1]->ttl]);
        self::assertSame('1002', $provider->records('example.fr', 'mx')[0]->id);
    }

    public function testARecordAddedUpdatedDeletedAndTheZoneRefreshed(): void
    {
        $provider = $this->provider([
            'POST /domain/zone/example.fr/record' => ['id' => 2001, 'zone' => 'example.fr', 'fieldType' => 'MX', 'subDomain' => '', 'target' => '10 mx.example.net.', 'ttl' => 0],
            'POST /domain/zone/example.fr/refresh' => null,
            'GET /domain/zone/example.fr/record?fieldType=TXT&subDomain=_dmarc' => [2002],
            'GET /domain/zone/example.fr/record/2002' => ['id' => 2002, 'zone' => 'example.fr', 'fieldType' => 'TXT', 'subDomain' => '_dmarc', 'target' => '"v=DMARC1; p=none"', 'ttl' => 0],
            'PUT /domain/zone/example.fr/record/2002' => null,
            'DELETE /domain/zone/example.fr/record/2001' => null,
        ]);

        $mx = $provider->add('example.fr', new Record('MX', '', 'mx.example.net.', priority: 10));
        self::assertSame('2001', $mx->id);
        self::assertSame('{"fieldType":"MX","subDomain":"","target":"10 mx.example.net."}', $this->body('POST /domain/zone/example.fr/record'));

        $txt = $provider->update('example.fr', new Record('TXT', '_dmarc', 'v=DMARC1; p=none'), new Record('TXT', '_dmarc', 'v=DMARC1; p=reject', 300));
        self::assertSame('2002', $txt->id, 'found by its name, type and content');
        self::assertSame('{"subDomain":"_dmarc","target":"v=DMARC1; p=reject","ttl":300}', $this->body('PUT /domain/zone/example.fr/record/2002'));

        $provider->delete('example.fr', $mx);
        self::assertSame(3, \count(array_filter($this->calls(), static fn (string $c) => 'POST /domain/zone/example.fr/refresh' === $c)), 'a refresh after each change');
    }

    public function testAnErrorOvhAnswers(): void
    {
        $provider = $this->provider(['GET /domain/nowhere.fr/serviceInfos' => [404, ['class' => 'Client::NotFound', 'message' => 'The requested object (domain = nowhere.fr) does not exist']]]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OVHcloud answered 404 (The requested object (domain = nowhere.fr) does not exist) for the domain nowhere.fr.');
        $provider->domain('nowhere.fr');
    }

    public function testTheFactory(): void
    {
        $registry = new Registry([new OvhFactory()], ['ovh' => ['factory' => 'ovh', 'options' => ['application_key' => 'ak', 'application_secret' => 'as', 'consumer_key' => 'ck']]]);

        self::assertInstanceOf(OvhProvider::class, $registry->get('ovh'));
        self::assertSame(['availability', 'registrar', 'domains', 'nameservers', 'dns'], $registry->get('ovh')->capabilities()->contracts());
    }

    /**
     * Nothing of the keys anywhere in what a log of the whole chain writes when OVHcloud is not
     * reached: the messages, the previous exception (a copy of Guzzle's, its class kept, without
     * the request it carried, whose headers held the application and consumer keys), the traces'
     * arguments, which PHP keeps unless zend.exception_ignore_args is on.
     */
    public function testNoPartOfAnUnreachableChainHoldsTheKeys(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $handler = static function (RequestInterface $request) {
            if (str_ends_with($request->getUri()->getPath(), '/auth/time')) {
                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], (string) time()));
            }

            throw new \GuzzleHttp\Exception\ConnectException('cURL error 6: Could not resolve host: eu.api.ovh.com for '.$request->getUri(), $request);
        };
        $provider = (new OvhFactory(new Client(['handler' => HandlerStack::create($handler)])))->create(['application_key' => 'application-key-123', 'application_secret' => 'application-secret-456', 'consumer_key' => 'consumer-key-789']);
        self::assertInstanceOf(OvhProvider::class, $provider);

        try {
            $provider->records('example.org');
            self::fail('no exception');
        } catch (\Omnidns\Exception\UnreachableException $e) {
            self::assertInstanceOf(\GuzzleHttp\Exception\ConnectException::class, $e->getPrevious());
            for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                foreach (['application-key-123', 'application-secret-456', 'consumer-key-789'] as $secret) {
                    self::assertStringNotContainsString($secret, $one->getMessage());
                    self::assertStringNotContainsString($secret, print_r($one->getTrace(), true));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', false !== $ignoreArgs ? $ignoreArgs : '1');
        }
    }

    /** @param array<string, mixed> $routes "METHOD /path?query" => an answer, a fixture's name, or [status, answer] */
    private function provider(array $routes, bool $autoPay = false): OvhProvider
    {
        $routes += ['GET /auth/time' => time()];
        $handler = function (RequestInterface $request) use ($routes) {
            $this->requests[] = $request;
            $key = self::call($request);
            if (!\array_key_exists($key, $routes)) {
                return Create::promiseFor(new Response(404, [], '{"message":"not recorded: '.$key.'"}'));
            }
            $answer = $routes[$key];
            $status = 200;
            if (\is_array($answer) && [0, 1] === array_keys($answer) && \is_int($answer[0]) && $answer[0] >= 400) {
                [$status, $answer] = $answer;
            }
            $body = \is_string($answer) && !is_numeric($answer) ? (string) file_get_contents(__DIR__."/Fixtures/$answer.json") : (null === $answer ? '' : (string) json_encode($answer));

            return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], $body));
        };
        $client = new Client(['handler' => HandlerStack::create($handler)]);

        $provider = (new OvhFactory($client))->create(['application_key' => 'ak', 'application_secret' => 'as', 'consumer_key' => 'ck', 'auto_pay' => $autoPay]);
        self::assertInstanceOf(OvhProvider::class, $provider);

        return $provider;
    }

    private static function call(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = preg_replace('~^/1\.0~', '', $uri->getPath());

        return $request->getMethod().' '.$path.('' === $uri->getQuery() ? '' : '?'.$uri->getQuery());
    }

    /** @return list<string> */
    private function calls(): array
    {
        return array_map(self::call(...), $this->requests);
    }

    private function body(string $call): string
    {
        foreach ($this->requests as $request) {
            if (self::call($request) === $call) {
                return (string) $request->getBody();
            }
        }

        return '';
    }
}
