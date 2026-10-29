# omnidns/ovh

OVHcloud for [omnisense/omnidns](https://github.com/glitchr-omnisense/omnidns): whether a domain
is free and its price, registering and renewing it, the account's domains and their terms, their
name servers, and the records of their zones - through OVHcloud's API, over
[ovh/ovh](https://github.com/ovh/php-ovh), OVHcloud's official PHP client, which signs every call
(`$1$` + SHA-1 of the secret, the consumer key, the method, the URL, the body and a timestamp
read from `/auth/time`) and sends it with Guzzle. The endpoint is ovh/ovh's: `ovh-eu` by default.

| Operation | OVHcloud API |
|---|---|
| `check()` | `POST /order/cart` (once per provider object), `GET /order/cart/{id}/domain?domain=` |
| `register()` | `POST /order/cart`, `POST .../assign`, `POST .../domain`, `POST .../item/{item}/configuration` (the owner), `POST .../checkout` |
| `renew()` | `GET /domain/{name}/serviceInfos`, `POST /service/{serviceId}/renew` |
| `domains()`, `domain()` | `GET /domain`, `GET /domain/{name}/serviceInfos` |
| `nameservers()`, `setNameservers()` | `GET /domain/{name}/nameServer` and each id, `POST /domain/{name}/nameServers/update` |
| `records()` | `GET /domain/zone/{zone}/record` and each id |
| `add()`, `update()`, `delete()` | `POST`, `PUT`, `DELETE /domain/zone/{zone}/record[/{id}]`, each followed by `POST /domain/zone/{zone}/refresh` |

## Installation

```sh
composer require omnidns/ovh
```

Requires PHP 8.2, `omnisense/omnidns` (1.x), `ovh/ovh` (^3.4) and `guzzlehttp/guzzle` (^7.0);
ovh/ovh brings `league/oauth2-client`. The wrapped client is maintained and framework-free.

## Credentials

An API application created at https://eu.api.ovh.com/createToken/ for the account: its
**application key**, **application secret** and **consumer key**, with the rights the factory
lists - GET/POST on `/order/cart*`, GET/PUT/POST on `/domain*`, DELETE on `/domain/zone/*` (for
`delete()` and an `update()` that changes a record's type: `DELETE /domain/zone/{zone}/record/{id}`),
GET/POST on `/service/*`.

Owners are contacts the account holds: created with `POST /me/contact` (a call this package does
not make), then named by their id.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `application_key` | string | - | yes | |
| `application_secret` | string | - | yes | |
| `consumer_key` | string | - | yes | |
| `endpoint` | string | `ovh-eu` | no | `ovh-eu`, `ovh-ca`, `ovh-us`, `kimsufi-eu`... or a URL |
| `subsidiary` | string | `FR` | no | the OVHcloud company carts are opened with: `FR`, `DE`, `GB`, `CA`... |
| `auto_pay` | bool | `false` | no | `true`: an order is paid at once with the account's preferred means of payment; `false`: it waits at `Order::$paymentUrl` |

`OvhFactory(?GuzzleHttp\Client $http = null)`: the Guzzle client ovh/ovh sends with, or its own.

## Capabilities

| Contract | Supported |
|---|---|
| `AvailabilityInterface` | yes, priced (first year and renewal) |
| `RegistrarInterface` | yes |
| `DomainsInterface` | yes |
| `NameserversInterface` | yes |
| `DnsInterface` | yes; OVH's own types (SPF, DKIM...) left out |

Terms: OVHcloud's contracts, the API's getting-started rules.

## Behaviour

**Availability.** One cart per provider object, opened with `ovhSubsidiary` and kept for every
`check()`. The domain's offers: a `create` offer that is `orderable` makes it free, its price the
offer's `TOTAL` (else `PRICE`), its renewal `RENEW`, premium when the `pricingMode` says; a
`transfer` offer means `registered (a transfer is offered)`; nothing orderable `not orderable`.
OVH answers this to a signed call only.

**Registration.** A new cart, assigned to the account, the domain added with `duration`
`P<years>Y`; the owner, when given, as the item's `OWNER_CONTACT` configuration
(`/me/contact/<handle>`) - an owner without a handle is an `InvalidArgumentException`, none means
the account's own contact; then checked out with `autoPayWithPreferredPaymentMethod` (`auto_pay`)
and `waiveRetractationPeriod: false`. The `Order`: the `orderId`, the price with tax
(`prices.withTax`), the payment `url`, the status `ordered, awaiting payment` or `ordered, paid with
the preferred means of payment`.

**Renewal.** The service id read from `serviceInfos` (none: `ProviderException` `no
serviceId`), then `/service/{id}/renew` with `dryRun: false`, `duration` `P<years>Y`; the order read
from the answer's `order`. This is the least sure of the shapes (README).

**Domains.** `GET /domain`; each one's `serviceInfos`: `expiration`, `renew.automatic`, the
service's `status`, `creation`; no name servers in the same answer.

**Name servers.** Their ids, then each id's `host`; written as `[{host}]`, lower case, no trailing
dot.

**Records.** Filtered by `fieldType` and `subDomain`; OVH reads an empty `subDomain` as no filter,
so the apex is filtered here. Each record's id, `subDomain`, `target` (an MX's priority split from
it), `ttl` (0 read as `null`). An addition sends `fieldType`, `subDomain`, `target` (`value()`) and
the TTL when given; an update `subDomain`, `target` and `ttl` (0 when `null`: the zone's
default) - a change of type deletes and adds; every change is followed by the zone's refresh.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnidns\Model\Contact;
use Omnidns\Model\Record;
use Omnidns\Ovh\OvhFactory;
use Omnidns\Registry;

$registry = new Registry([new OvhFactory()], [
    'ovh' => ['factory' => 'ovh', 'options' => [
        'application_key' => getenv('OVH_APPLICATION_KEY'),
        'application_secret' => getenv('OVH_APPLICATION_SECRET'),
        'consumer_key' => getenv('OVH_CONSUMER_KEY'),
    ]],
]);

$check = $registry->availability('ovh')->check('example.fr');
if ($check->available) {
    $order = $registry->registrar('ovh')->register('example.fr', 1, Contact::handle('1234567'));
    echo 'Pay at ', $order->paymentUrl, "\n";               // auto_pay false: the order waits
}

$registry->dns('ovh')->add('example.fr', new Record('TXT', '_dmarc', 'v=DMARC1; p=none'));
```

Not run live (no account).

## Errors

- OVH's error answers (Guzzle's `RequestException` with a response): `ProviderException`
  `OVHcloud answered <status> (<OVH's message>) for <what>.`, code 0.
- No response, or another Guzzle error: `UnreachableException` `OVHcloud could not be reached
  for ...`.
- A body ovh/ovh cannot decode: `ProviderException` `... answered something that is not JSON ...`.
- A duration outside 1 to 10 years, an owner without a handle: `InvalidArgumentException`.
- No cart id, no service id, a record not found: `ProviderException`.

## Verified, and not

`Tests/OvhProviderTest.php`, on recorded answers written from OVHcloud's API schemas (not
captured): availability (free, priced, taken with a transfer offered, not orderable, one cart
kept), the signature, a registration as a cart checked out with its owner, a renewal through the
services API, the domains and their terms, the name servers, the records (filters, OVH's types
left out, an MX's priority), an addition, an update found by content, a deletion, a refresh after
each, an error's message. Green in the family's harness on 2026-10-26. **Not run live**: no
OVHcloud account at hand. Check the renewal first on a real account, with a domain worth
renewing.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by OVH SAS (OVHcloud). Whoever runs it complies with OVHcloud's
[contracts](https://www.ovhcloud.com/en/terms-and-conditions/contracts/) and pays the orders it
places; see the README's Responsibility section.
