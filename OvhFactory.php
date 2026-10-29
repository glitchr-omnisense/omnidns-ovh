<?php

namespace Omnidns\Ovh;

use GuzzleHttp\Client;
use Omnidns\Config;
use Omnidns\ProviderFactory;
use Omnidns\ProviderInterface;
use Ovh\Api;

/**
 * OVHcloud, from its options:
 *
 *   application_key, application_secret, consumer_key
 *                 the API's credentials (required): an application created at
 *                 https://eu.api.ovh.com/createToken/ with the rights it needs - GET/POST on /order/cart*,
 *                 GET/PUT/POST on /domain*, DELETE on /domain/zone/* (delete(), an update() that changes
 *                 a record's type), GET/POST on /service/*
 *   endpoint      ovh-eu (also ovh-ca, ovh-us, kimsufi-eu... or a URL)
 *   subsidiary    the OVHcloud company carts are opened with: FR, DE, GB, CA...
 *   auto_pay      true: an order is paid at once with the account's preferred means of payment; false (the
 *                 default): the order waits for its payment at Order::$paymentUrl
 *
 * ovh/ovh signs every call; it sends them with Guzzle - the client given to this factory, or its own.
 */
final class OvhFactory extends ProviderFactory
{
    public function __construct(private readonly ?Client $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnidns.factory_name' => 'ovh',
            'omnidns.factory_title' => 'OVHcloud',
            'omnidns.required_options' => ['application_key', 'application_secret', 'consumer_key'],
            'endpoint' => 'ovh-eu',
            'subsidiary' => 'FR',
            'auto_pay' => false,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new OvhProvider(
            new Api((string) $c->string('application_key'), (string) $c->string('application_secret'), $c->string('endpoint') ?? 'ovh-eu', $c->string('consumer_key'), $this->http),
            $c->string('subsidiary') ?? 'FR',
            $c->bool('auto_pay'),
        );
    }
}
