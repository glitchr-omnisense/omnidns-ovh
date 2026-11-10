<?php

namespace Omnidns\Ovh\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Omnidns\Exception\OmnidnsException;
use Omnidns\Exception\UsageLimitReachedException;
use Omnidns\Ovh\OvhFactory;
use Omnidns\Ovh\OvhProvider;
use Omnidns\Registry;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * OvhProvider's usage policy (policy.json) through omnidns's registry: as
 * declared (no published limit: unknown), a site's limit counted with the
 * call over it never made, a 429 and its Retry-After read from Guzzle's
 * exception and holding every next call, a looser setting refused. No
 * network: a Guzzle handler answering.
 */
final class PolicyTest extends TestCase
{
    private const OPTIONS = ['application_key' => 'ak', 'application_secret' => 'as', 'consumer_key' => 'ck'];

    private int $made = 0;

    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = $this->registry()->policy('ovh');

        self::assertSame('ovh', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertFileExists(OvhProvider::POLICY);
        self::assertNull($policy->rateLimits, 'OVHcloud publishes none for this API: unknown');
        self::assertTrue($policy->requirements->key);
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $registry = $this->registry(200, ['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['domains']]]]);

        self::assertSame([], $registry->domains('ovh')->domains());
        $made = $this->made;
        try {
            $registry->domains('ovh')->domains();
            self::fail('The call over the site\'s limit was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnidnsException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/minute/application/domains', $e->limit?->id());
        }
        self::assertSame($made, $this->made, 'the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $registry = $this->registry(429);

        try {
            $registry->domains('ovh')->domains();
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(120, $e->retryAfter);
            self::assertSame('ovh answered 429 Too Many Requests: try again in 120 s.', $e->getMessage());
        }
        try {
            $registry->dns('ovh')->records('example.fr');
            self::fail('A call went out while the provider asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(119, $e->retryAfter);
        }
        self::assertSame(1, $this->made, 'the wait held the next request back');
    }

    public function testASiteCannotLoosenThePolicy(): void
    {
        $registry = $this->registry(200, ['rights' => ['storage' => 'allowed']]);

        $this->expectException(InvalidPolicyException::class);
        $registry->dns('ovh');
    }

    /** @param array<string, mixed> $policy */
    private function registry(int $status = 200, array $policy = []): Registry
    {
        $handler = function (RequestInterface $request) use ($status) {
            if ('/1.0/auth/time' === $request->getUri()->getPath()) {
                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], (string) time()));
            }
            ++$this->made;

            return Create::promiseFor(429 === $status
                ? new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '120'], '{"message":"Too many requests"}')
                : new Response(200, ['Content-Type' => 'application/json'], '[]'));
        };

        return new Registry([new OvhFactory(new Client(['handler' => HandlerStack::create($handler)]))], ['ovh' => ['factory' => 'ovh', 'options' => self::OPTIONS] + ([] === $policy ? [] : ['policy' => $policy])]);
    }
}
