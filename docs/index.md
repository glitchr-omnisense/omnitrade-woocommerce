# omnitrade/woocommerce

WooCommerce for [omniscient/omnitrade](https://github.com/glitchr-studio/omnitrade): a sale paid on
the shop's own checkout (an order created unpaid, with its payment URL), the shop's orders and
their payment state, refunds, the payment gateways switched on, the catalogue (products,
variations, stock) and webhooks - WooCommerce's REST API v3, at

```
<url>/wp-json/wc/v3/
```

with the consumer key and secret as HTTP basic authentication (over HTTPS). There is no sandbox
address: a staging shop is the test shop.

| Operation | Path |
|---|---|
| purchase | `POST /orders` |
| fetch, fetch order | `GET /orders/{id}` |
| refund | `GET /orders/{id}`, `POST /orders/{id}/refunds` |
| payment methods | `GET /payment_gateways` |
| products | `GET /products`, `GET /products/{id}/variations` |
| product | `GET /products/{id}` or `GET /products?slug=`, its variations |
| inventory | `GET /products?include=`, `GET /products/{id}`, or every product and variation |
| notify | none, unless a product webhook needs its variations or its parent |

## Installation

```sh
composer require omnitrade/woocommerce
```

Requires PHP 8.2, `omniscient/omnitrade` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a
Symfony application with `Omnitrade\Bridge\Symfony\OmnitradeBundle`, the factory is registered as
soon as the package is installed.

```php
$gateway = (new WooCommerceGatewayFactory($http))->create(['url' => 'https://shop.example/', 'consumer_key' => '...', 'consumer_secret' => '...']);   // $http: the application's HTTP client; none given, the factory makes its own
```

## Credentials

- A **REST API key** with Read/Write permissions (WooCommerce → Settings → Advanced → REST API):
  its consumer key (`ck_...`) and consumer secret (`cs_...`). Read is enough for the catalogue;
  purchases and refunds need Write. The shop must be served over HTTPS for basic authentication.
- **Webhooks** (same screen → Webhooks) for `Order updated` (and `Order created`), and for the
  catalogue `Product created`, `Product updated`, `Product deleted`, `Product restored`, all with
  one secret of your own: `webhook_secret`.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `url` | string | - | yes | the shop's address (`https://shop.example`; a trailing slash is removed) |
| `consumer_key` | string | - | yes | `ck_...` |
| `consumer_secret` | string | - | yes | `cs_...` |
| `webhook_secret` | ?string | `null` | for `notify()` | the webhooks' secret |
| `timeout` | int | `15` | no | seconds |
| `currency` | string | `EUR` | no | the store's currency, for the catalogue's prices (bare decimals in the API); orders carry their own |
| `weight_unit` | string | `kg` | no | the store's weight unit (Settings → Products): `kg`, `g`, `lbs`, `oz`; weights are given in grams |
| `product_status` | string | `any` | no | what `fetchProducts()` lists: `any`, or `publish` |

```yaml
omnitrade:
    gateways:
        woo:
            factory: woocommerce
            options:
                url: '%env(WOOCOMMERCE_URL)%'                       # https://shop.example
                consumer_key: '%env(WOOCOMMERCE_KEY)%'               # ck_...
                consumer_secret: '%env(WOOCOMMERCE_SECRET)%'         # cs_...
                webhook_secret: '%env(WOOCOMMERCE_WEBHOOK_SECRET)%'  # for notify()
                currency: EUR                                        # the store's, for the catalogue
```

## Capabilities

| Request | Supported |
|---|---|
| `Purchase` | yes (an unpaid order, paid on the shop's checkout) |
| `Authorize`, `Capture`, `VoidAuthorization` | no |
| `Refund` | yes (through the shop's payment gateway) |
| `FetchTransaction` | yes (the order's payment state) |
| `GetPaymentMethods` | yes (the shop's gateways enabled) |
| `Notify` | yes |
| `FetchOrder` | yes |
| `FetchProducts`, `FetchProduct`, `FetchInventory` | yes |
| `AffiliateLink`, connected accounts, subscriptions | no |

## Behaviour

**Purchase.** An order created `pending`, `set_paid: false`, answered PENDING with its
`payment_url` as `redirectUrl`, where the shop's own payment gateways take the money; `fetch()`
or the `order.updated` webhook say when they did. WooCommerce brings nobody back: `returnUrl` and
`cancelUrl` are not used. The order:

| Payment | Order |
|---|---|
| `amount->currency` | `currency` |
| `lines`, when they add up | `line_items`: `name`, `quantity`, `total` (the line's, as a decimal); a numeric `reference` as `product_id` |
| no lines adding up | one item of the amount, named by the description (else the reference) |
| `discount` (lines adding up) | a fee line `Discount` of minus the discount |
| `shipping` (lines adding up) | a shipping line `flat_rate`, `Shipping` |
| `notice` | `customer_note` |
| `reference` | the meta `omnitrade_reference` |
| `metadata` | more `meta_data` |
| `customer` | `billing`: email, first name (the name's first word) and last name (the rest), phone, the two first street lines, postcode, city, country |

Not sent: `idempotencyKey`, `method`, `locale`, `destination`. An answer without an `id` or a
`payment_url` is a `ProviderException` (`Order <id> was created, but the shop gave no payment
URL.`).

**Fetch.** The order's status, read with its refunds:

| `status` | `Status` |
|---|---|
| `processing`, `completed` | PAID; REFUNDED or PARTIALLY_REFUNDED when its refunds add up to the total or less |
| `refunded` | REFUNDED |
| `cancelled` | CANCELLED |
| `failed` | REFUSED |
| `pending`, `on-hold`, `checkout-draft`, any other | PENDING, with its `payment_url` |

The amount is its `total`, `refunded` its refunds' sum, `method` its `payment_method`, `metadata`
its `number`, `order_key` and `transaction_id`, `createdAt` its `date_created_gmt`.

**Fetch order.** The same order as a `PlatformOrder`: `number` `#<number>`, the status as above,
`total`, the customer from `billing` (email, name, phone, `customer_id` as `reference`) and the
shipping address (else the billing one), its `line_items` (name, price, quantity, SKU,
`product_id` as `reference`), `financialStatus` the order's `status`, `fulfillmentStatus`
`completed` for a completed order (else `null`), `createdAt`.

**Refund.** The order is read for its currency and total; `POST /orders/{id}/refunds` with the
amount (the order's total when none is given), `reason`, and `api_refund: true`: the shop's
payment gateway gives the money back when it can. The refund is recorded as REFUNDED, whatever
its amount; `transactionReference` is the order's id, `message` the reason. The idempotency key
is not sent.

**Payment methods.** The gateways `GET /payment_gateways` lists with `enabled` true: `id` as code,
`title` as label, the kind guessed from the id (`stripe`, `card`: card; `paypal`, `apple`,
`google`: wallet; `bacs`, `sepa`, `bank`: bank; `klarna`, `afterpay`: buy now pay later; else
other), the gateway in `raw`.

**Notify.** `X-WC-Webhook-Signature` is checked first (base64 of the HMAC-SHA256 of the raw body
with `webhook_secret`); the topic is `X-WC-Webhook-Topic`, the event's `id`
`X-WC-Webhook-Delivery-ID`.

| Topic | `status` | `transaction` | `reference` |
|---|---|---|---|
| `order.created`, `order.updated` | the order's status, as `fetch()` reads it | the order | the order's id |
| `order.deleted` | none | none | the order's id |
| `product.created`, `product.updated`, `product.restored` | none | none | the product's id; `product` carries it |
| `product.deleted` | none | none | the product's id, no product |
| any other | none | none | none |

`raw` holds the topic, the `X-WC-Webhook-Resource` and `X-WC-Webhook-Event` headers and the
payload. How the product webhooks are read: [WooCommerce's catalogue](catalogue.md#woocommerces-catalogue).

**Errors from the shop.** Every call reads the JSON answer; an HTTP status of 400 or more is a
`ProviderException` with WooCommerce's `message` and its `code` as `providerCode`
(`woocommerce_rest_shop_order_invalid_id`).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\WooCommerce\WooCommerceGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$woo = (new WooCommerceGatewayFactory(HttpClient::create()))->create([
    'url' => getenv('WOOCOMMERCE_URL'),
    'consumer_key' => getenv('WOOCOMMERCE_CONSUMER_KEY'),
    'consumer_secret' => getenv('WOOCOMMERCE_CONSUMER_SECRET'),
    'webhook_secret' => getenv('WOOCOMMERCE_WEBHOOK_SECRET'),
]);

$transaction = $woo->purchase(new Payment(
    amount: Money::of(2750, 'EUR'),
    reference: 'ORDER-1042',
    customer: new Customer(email: 'camille@example.org', name: 'Camille Durand', street: ['12 rue des Juifs'], postalCode: '67000', city: 'Strasbourg', country: 'FR'),
    lines: [new Line('Margaux 2019', Money::of(1200, 'EUR'), 2, reference: '501')],
    shipping: Money::of(350, 'EUR'),
));
header('Location: '.$transaction->redirectUrl);   // the shop's checkout for that order

// later: the order.updated webhook, or
$woo->fetch($transaction->reference)->isPaid();
$woo->refund($transaction->reference, Money::of(500, 'EUR'), reason: 'One bottle broken');
```

A WooCommerce catalogue copied page by page, offline, with its output, is in the core's manual
([catalogue](https://github.com/glitchr-studio/omnitrade/blob/1.x/docs/catalogue.md)). In the core's
Docker harness: `docker compose run --rm omnitrade purchase woocommerce --amount 1990`, `catalogue
woocommerce --inventory`.

## Errors

Every failure is an `Omnitrade\Exception\ProviderException` whose `provider` is `woocommerce`:

- an HTTP status of 400 or more: WooCommerce's `message`, its `code` as `providerCode`;
- a body that is not JSON (an HTML page from WordPress or a proxy): `WooCommerce answered HTTP
  <status> with a body that is not JSON.`; a network failure: `WooCommerce request failed: ...`,
  the exception as `previous`;
- `WooCommerce created no order.`, `Order <id> was created, but the shop gave no payment URL.`,
  `WooCommerce recorded no refund.`;
- a webhook: `InvalidNotificationException` without `webhook_secret` or for a signature that does
  not match; a verified body that is not JSON is a `ProviderException`.

A missing `url`, `consumer_key` or `consumer_secret` is an `InvalidConfigException` when the
gateway is built; `Authorize`, `Capture`, `VoidAuthorization`, `AffiliateLink`, connected accounts
and subscriptions raise `RequestNotSupportedException` before any call. `fetchProduct()` of an id
the shop does not have (`..._invalid_id`) is `null`.

## Verified, and not

Tested offline, 23 tests on answers shaped as the REST API v3 documents them:
`Tests/WooCommerceGatewayTest.php` (a purchase as an order paid on the shop's checkout, an order
fetched as its status means, a refund and the payment methods, a webhook checked then read),
`Tests/WooCommerceCatalogueTest.php` (the catalogue page by page, the published ones only when asked, a simple
product as its own only variant, a variable product's variations as its variants, a product found
by id, slug or variation or not at all, the inventory of products and variations, the product
webhooks)
and `Tests/WooCommerceContractTest.php`, the family's conformance suite (nothing to fix).

Not verified: no call was made to a WooCommerce shop.

## Responsibility

An independent, unofficial client, not affiliated with, endorsed by or sponsored by Automattic
Inc. (WooCommerce). Whoever runs it complies with the terms of the shop it reaches and
WooCommerce's ([woocommerce.com/terms-conditions](https://woocommerce.com/terms-conditions/)) and
obtains what they require: see the README's Responsibility section.
