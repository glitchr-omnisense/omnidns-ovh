<?php

namespace Omnidns\Ovh;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Omnidns\AvailabilityInterface;
use Omnidns\Capabilities;
use Omnidns\DnsInterface;
use Omnidns\DomainsInterface;
use Omnidns\Exception\InvalidArgumentException;
use Omnidns\Exception\ProviderException;
use Omnidns\Exception\UnreachableException;
use Omnidns\Http\Json;
use Omnidns\Model\Availability;
use Omnidns\Model\Contact;
use Omnidns\Model\DomainInfo;
use Omnidns\Model\DomainName;
use Omnidns\Model\Money;
use Omnidns\Model\Order;
use Omnidns\Model\Record;
use Omnidns\NameserversInterface;
use Omnidns\RegistrarInterface;
use Ovh\Api;

/**
 * OVHcloud's API through ovh/ovh: a domain's offers read in an order cart,
 * a registration as a cart checked out, a renewal through the services API,
 * the account's domains (/domain), their name servers and their zones'
 * records (/domain/zone), each change of a zone followed by its refresh.
 */
final class OvhProvider implements AvailabilityInterface, RegistrarInterface, DomainsInterface, NameserversInterface, DnsInterface
{
    private ?string $cart = null;

    public function __construct(private readonly Api $api, private readonly string $subsidiary = 'FR', private readonly bool $autoPay = false)
    {
    }

    public function getName(): string
    {
        return 'ovh';
    }

    public function getTitle(): string
    {
        return 'OVHcloud';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(true, true, true, true, true, Record::TYPES, [
            'https://www.ovhcloud.com/en/terms-and-conditions/contracts/',
            'https://help.ovhcloud.com/csm/en-api-getting-started-ovhcloud-api?id=kb_article_view&sysparm_article=KB0042784',
        ]);
    }

    public function check(string $domain): Availability
    {
        $domain = DomainName::normalize($domain);
        $this->cart ??= $this->newCart();
        $offers = Json::list($this->call('GET', "/order/cart/{$this->cart}/domain", ['domain' => $domain], "the offers for $domain"));
        foreach ($offers as $offer) {
            if ('create' === Json::string($offer, 'action') && Json::bool($offer, 'orderable')) {
                return new Availability($domain, true, self::price($offer, 'TOTAL') ?? self::price($offer, 'PRICE'), self::price($offer, 'RENEW'), str_contains((string) Json::string($offer, 'pricingMode'), 'premium'));
            }
        }
        $transfer = array_filter($offers, static fn ($offer) => 'transfer' === Json::string($offer, 'action'));

        return new Availability($domain, false, reason: [] !== $transfer ? 'registered (a transfer is offered)' : 'not orderable');
    }

    public function register(string $domain, int $years = 1, ?Contact $owner = null): Order
    {
        $domain = DomainName::normalize($domain);
        self::years($years);
        if (null !== $owner && null === $owner->handle) {
            throw new InvalidArgumentException('OVHcloud takes an owner as a contact the account holds: create it (POST /me/contact) and give its id as Contact::handle().');
        }
        $cart = $this->newCart();
        $this->call('POST', "/order/cart/$cart/assign", null, "the cart for $domain");
        $item = Json::string($this->call('POST', "/order/cart/$cart/domain", ['domain' => $domain, 'duration' => "P{$years}Y"], "the cart for $domain"), 'itemId');
        if (null !== $owner && null !== $item) {
            $this->call('POST', "/order/cart/$cart/item/$item/configuration", ['label' => 'OWNER_CONTACT', 'value' => '/me/contact/'.$owner->handle], "the owner of $domain");
        }
        $order = $this->call('POST', "/order/cart/$cart/checkout", ['autoPayWithPreferredPaymentMethod' => $this->autoPay, 'waiveRetractationPeriod' => false], "the order of $domain");

        return $this->order($domain, Order::REGISTER, $order, $years);
    }

    public function renew(string $domain, int $years = 1): Order
    {
        $domain = DomainName::normalize($domain);
        self::years($years);
        $service = Json::string($this->call('GET', "/domain/$domain/serviceInfos", null, "the service of $domain"), 'serviceId') ?? throw ProviderException::answered('OVHcloud', "the service of $domain", 'no serviceId');
        $answer = $this->call('POST', "/service/$service/renew", ['dryRun' => false, 'duration' => "P{$years}Y", 'services' => [(int) $service]], "the renewal of $domain");

        return $this->order($domain, Order::RENEW, Json::get($answer, 'order') ?? $answer, $years);
    }

    public function domains(): array
    {
        return array_values(array_filter(array_map(static fn ($d) => \is_string($d) ? $d : null, Json::list($this->call('GET', '/domain', null, 'the domains')))));
    }

    public function domain(string $domain): DomainInfo
    {
        $domain = DomainName::normalize($domain);
        $info = $this->call('GET', "/domain/$domain/serviceInfos", null, "the domain $domain");
        $status = Json::string($info, 'status');
        $automatic = Json::get($info, 'renew', 'automatic');

        return new DomainInfo($domain, Json::date($info, 'expiration'), \is_bool($automatic) ? $automatic : null, null === $status ? [] : [$status], null, Json::date($info, 'creation'));
    }

    public function nameservers(string $domain): array
    {
        $domain = DomainName::normalize($domain);
        $hosts = [];
        foreach (Json::list($this->call('GET', "/domain/$domain/nameServer", null, "the name servers of $domain")) as $id) {
            if (is_numeric($id) && null !== $host = Json::string($this->call('GET', "/domain/$domain/nameServer/$id", null, "the name servers of $domain"), 'host')) {
                $hosts[] = rtrim(strtolower($host), '.');
            }
        }

        return $hosts;
    }

    public function setNameservers(string $domain, array $nameservers): void
    {
        $domain = DomainName::normalize($domain);
        $this->call('POST', "/domain/$domain/nameServers/update", ['nameServers' => array_map(static fn (string $host) => ['host' => rtrim(strtolower($host), '.')], $nameservers)], "the name servers of $domain");
    }

    public function records(string $zone, ?string $type = null, ?string $name = null): array
    {
        $zone = DomainName::normalize($zone);
        $query = array_filter(['fieldType' => null === $type ? null : strtoupper($type), 'subDomain' => null === $name ? null : Record::normalizeName($name)], static fn ($v) => null !== $v);
        // OVH reads an empty subDomain as no filter: the apex is filtered here.
        $records = [];
        foreach (Json::list($this->call('GET', "/domain/zone/$zone/record", $query ?: null, "the records of $zone")) as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            $data = $this->call('GET', "/domain/zone/$zone/record/$id", null, "the records of $zone");
            $fieldType = (string) Json::string($data, 'fieldType');
            if (\in_array($fieldType, Record::TYPES, true) && (null === $name || '' !== Record::normalizeName($name) || '' === (string) Json::string($data, 'subDomain'))) {
                $records[] = Record::fromValue($fieldType, (string) Json::string($data, 'subDomain'), (string) Json::string($data, 'target'), Json::int($data, 'ttl') ?: null, (string) $id);
            }
        }

        return $records;
    }

    public function add(string $zone, Record $record): Record
    {
        $zone = DomainName::normalize($zone);
        $data = $this->call('POST', "/domain/zone/$zone/record", array_filter(['fieldType' => $record->type, 'subDomain' => $record->name, 'target' => $record->value(), 'ttl' => $record->ttl], static fn ($v) => null !== $v), "a record of $zone");
        $this->refresh($zone);

        return $record->withId(Json::string($data, 'id'));
    }

    public function update(string $zone, Record $current, Record $new): Record
    {
        $zone = DomainName::normalize($zone);
        $found = $this->find($zone, $current);
        if ($found->type !== $new->type) {
            // A record's type does not change in place at OVH: the old one goes, the new one comes.
            $this->delete($zone, $found);

            return $this->add($zone, $new);
        }
        $this->call('PUT', "/domain/zone/$zone/record/{$found->id}", ['subDomain' => $new->name, 'target' => $new->value(), 'ttl' => $new->ttl ?? 0], "a record of $zone");
        $this->refresh($zone);

        return $new->withId($found->id);
    }

    public function delete(string $zone, Record $record): void
    {
        $zone = DomainName::normalize($zone);
        $found = $this->find($zone, $record);
        $this->call('DELETE', "/domain/zone/$zone/record/{$found->id}", null, "a record of $zone");
        $this->refresh($zone);
    }

    private function find(string $zone, Record $record): Record
    {
        if (null !== $record->id) {
            return $record;
        }

        return $record->findIn($this->records($zone, $record->type, $record->name)) ?? throw ProviderException::answered('OVHcloud', "a record of $zone", 'no such record');
    }

    private function refresh(string $zone): void
    {
        $this->call('POST', "/domain/zone/$zone/refresh", null, "the refresh of $zone");
    }

    private function newCart(): string
    {
        return Json::string($this->call('POST', '/order/cart', ['ovhSubsidiary' => $this->subsidiary], 'a cart'), 'cartId') ?? throw ProviderException::answered('OVHcloud', 'a cart', 'no cartId');
    }

    private function order(string $domain, string $action, mixed $order, int $years): Order
    {
        $price = Json::get($order, 'prices', 'withTax');

        return new Order(
            $domain,
            $action,
            $this->autoPay ? 'ordered, paid with the preferred means of payment' : 'ordered, awaiting payment',
            Json::string($order, 'orderId'),
            null === Json::string($price, 'value') ? null : Money::fromDecimal((string) Json::string($price, 'value'), Json::string($price, 'currencyCode') ?? 'EUR'),
            Json::string($order, 'url'),
            $years,
        );
    }

    private static function price(mixed $offer, string $label): ?Money
    {
        foreach (Json::list($offer, 'prices') as $price) {
            if ($label === Json::string($price, 'label') && null !== $value = Json::string($price, 'price', 'value')) {
                return Money::fromDecimal($value, Json::string($price, 'price', 'currencyCode') ?? 'EUR');
            }
        }

        return null;
    }

    private static function years(int $years): void
    {
        if ($years < 1 || $years > 10) {
            throw new InvalidArgumentException(\sprintf('A domain is registered or renewed for 1 to 10 years, not %d.', $years));
        }
    }

    /** @param array<string, mixed>|null $content */
    private function call(string $method, string $path, ?array $content, string $what): mixed
    {
        try {
            return match ($method) {
                'GET' => $this->api->get($path, $content),
                'POST' => $this->api->post($path, $content),
                'PUT' => $this->api->put($path, $content ?? []),
                default => $this->api->delete($path, $content),
            };
        } catch (RequestException $e) {
            if (null === $response = $e->getResponse()) {
                throw new UnreachableException(\sprintf('OVHcloud could not be reached for %s: %s', $what, $e->getMessage()), 0, $e);
            }
            $message = Json::string(json_decode((string) $response->getBody(), true), 'message');

            throw ProviderException::answered('OVHcloud', $what, $response->getStatusCode().(null === $message ? '' : " ($message)"));
        } catch (GuzzleException $e) {
            throw new UnreachableException(\sprintf('OVHcloud could not be reached for %s: %s', $what, $e->getMessage()), 0, $e);
        } catch (\JsonException $e) {
            throw ProviderException::answered('OVHcloud', $what, 'something that is not JSON');
        }
    }
}
