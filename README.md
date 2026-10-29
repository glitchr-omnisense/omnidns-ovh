# omnidns/ovh

OVHcloud for [omnisense/omnidns](https://github.com/glitchr-omnisense/omnidns), under any PHP host:
whether a domain is free and its price, registering it and renewing it, the account's domains and
their terms, their name servers, and the records of their zones.

```php
use Omnidns\Ovh\OvhFactory;
use Omnidns\Registry;

$registry = new Registry([new OvhFactory()], [
    'ovh' => ['factory' => 'ovh', 'options' => ['application_key' => $ak, 'application_secret' => $as, 'consumer_key' => $ck]],
]);
$registry->availability('ovh')->check('example.fr');
```

Options: `application_key`, `application_secret`, `consumer_key` (required: an application made at
https://eu.api.ovh.com/createToken/ with GET/POST on `/order/cart*`, GET/PUT/POST on `/domain*`, DELETE on `/domain/zone/*`,
GET/POST on `/service/*`), `endpoint` (`ovh-eu`, `ovh-ca`, `ovh-us`... or a URL), `subsidiary`
(`FR`), `auto_pay` (false).

**Reuse**: it wraps [ovh/ovh](https://github.com/ovh/php-ovh), OVHcloud's official PHP client
(maintained, framework-free): ovh/ovh signs each call and sends it through Guzzle - the
`GuzzleHttp\Client` given to `OvhFactory`, or its own. This package requires what ovh/ovh does
(Guzzle, league/oauth2-client).

- **Availability**: the domain's offers read in an order cart (`/order/cart/{id}/domain`, one cart
  per provider instance); free when a `create` offer is orderable, priced with its `TOTAL` and
  `RENEW`. OVH answers this to a signed call only.
- **Registration**: a new cart, assigned to the account, the domain added for the years asked,
  the owner as a contact the account holds (`Contact::handle('<id from /me/contact>')`; without
  one, the account's own), then checked out. With `auto_pay` false (the default) the order waits
  for its payment at `Order::$paymentUrl`; true pays it at once with the account's preferred means.
- **Renewal**: `/service/{serviceId}/renew` for the years asked (the service read from
  `/domain/{name}/serviceInfos`).
- **Domains**: `/domain`, each one's term and auto-renewal from its `serviceInfos`.
- **Name servers**: `/domain/{name}/nameServer`, changed with `/domain/{name}/nameServers/update`.
- **Records**: `/domain/zone/{zone}/record`, each change followed by `/domain/zone/{zone}/refresh`;
  OVH's own types (SPF, DKIM...) are left out; a change of type is a deletion and an addition.

## Terms

OVHcloud's [contracts](https://www.ovhcloud.com/en/terms-and-conditions/contracts/) (the domain
names' special conditions included) and its API's
[rules](https://help.ovhcloud.com/csm/en-api-getting-started-ovhcloud-api?id=kb_article_view&sysparm_article=KB0042784):
a registration or a renewal is a purchase from OVHcloud, made in the account's name.

## Verified, and not

- Verified on recorded answers, written from OVHcloud's API schemas (not captured), in the core's
  harness: availability (free, priced, taken with a transfer offered, not orderable, one cart
  kept), the signature (`$1$` + SHA-1 of secret, consumer key, method, URL, body and timestamp,
  after `/auth/time`), a registration as a cart checked out with its owner, a renewal through the
  services API, the domains and their terms, the name servers, the records (filters, OVH's types
  left out, an MX's priority), an addition, an update found by content, a deletion, a refresh
  after each, an error's message; `BareTest`; PHPStan at the highest level.
- **Not verified live**: no OVHcloud account at hand (an anonymous cart is answered "You must
  login first" for a domain's offers, 2026-10-24). The renewal call (`/service/{id}/renew`) is
  the least sure of the shapes: check it first on a real account, with a domain worth renewing.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by OVH SAS (OVHcloud). It reaches their service on behalf of whoever runs it; its MIT licence covers this
package's code only and grants no right to the service or its data. Whoever installs and runs it
is responsible for complying with OVHcloud's terms, for paying the orders it places, for obtaining what
they require (an account, a key, a contract), and for any use made of what it returns. The authors
publish it free of charge and accept no liability for how it is used (see [LICENSE](LICENSE)).

## Licence

MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
