# perly/perly-pay

Crypto payments straight to your wallet — bitcoin and dollar stablecoins,
2.5¢ a payment, nothing taken from it, and a month of up to 21 payments is free.
The PHP SDK for the [Perly Pay API](https://pay.perly.io/docs/api).

- PHP 8.1+, **no dependencies** beyond `ext-curl` and `ext-json`, PSR-4
- every POST carries an **Idempotency-Key**, reused across retries
- retries on 429 / 5xx / no answer with backoff, honouring `Retry-After`
- `Webhook::verify` checks `Perly-Signature` in constant time
- typed exceptions carrying the API's `type`, `code`, `param` and request id

## Five minutes

**1. Install and make a client** with your secret key (Dashboard → Developers).
A `sk_test_…` key works in test mode: test networks, nothing real moves.

```bash
composer require perly/perly-pay
```

```php
use Perly\Pay\PerlyPay;

$perly = new PerlyPay(getenv('PERLY_PAY_SECRET_KEY'));
```

**2. Sell something.** Amounts are integers in cents.

```php
$product = $perly->products->create(['name' => 'Pro plan']);
$price = $perly->prices->create([
    'product' => $product['id'],
    'model' => 'one_time',
    'unit_amount' => 4900, // $49.00
]);
```

**3. Take a payment.** Make a checkout session and send the buyer to its `url`.

```php
$session = $perly->checkoutSessions->create([
    'line_items' => [['price' => $price['id'], 'quantity' => 1]],
    'return_url' => 'https://shop.example/thanks',
    'metadata' => ['order_id' => '1042'],
], ['idempotency_key' => 'order-1042']);

header('Location: ' . $session['url'], true, 303);
```

The buyer picks bitcoin or USDC/USDT, pays from any wallet, and the money lands
in **your** wallet. Perly Pay never holds it.

**4. Hear about it.** Add an endpoint once (Dashboard → Developers → Webhooks, or
`$perly->webhookEndpoints->create([...])`) and keep its `whsec_…` secret. Then verify
every delivery on the **raw** body:

```php
use Perly\Pay\Exception\SignatureVerificationException;
use Perly\Pay\Webhook;

try {
    $event = Webhook::verify(
        file_get_contents('php://input'),          // the raw body, not json_decode'd
        $_SERVER['HTTP_PERLY_SIGNATURE'] ?? null,
        getenv('PERLY_PAY_WEBHOOK_SECRET'),
    );
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event['type'] === 'invoice.fulfilled') {
    ship($event['data']['object']['metadata']['order_id']);
}
http_response_code(204);
```

A signature older than five minutes is refused (replays), and any change to the body
breaks it. Ship on `invoice.fulfilled`: it comes once per invoice, after screening.
A delivery can arrive twice — use the event `id` to do things once.

**5. Try it without a wallet** (test keys only):

```php
$invoice = $perly->invoices->create(['amount' => 2400, 'description' => '1,000 API credits', 'customer_email' => 'ada@example.com']);
$perly->invoices->simulate($invoice['id']); // paid, fulfilled, receipt, webhooks (['outcome' => 'short' | 'over'] too)
```

## What's in it

Every call returns the API's JSON as an associative array. The last argument of
every call is request options: `idempotency_key`, `max_retries`, `timeout` (seconds).

| Property | Calls |
| --- | --- |
| `checkoutSessions` | `create`, `retrieve`, `list`, `listAll`, `expire` |
| `invoices` | `create`, `retrieve`, `list`, `listAll`, `cancel`, `simulate` |
| `paymentLinks` | `create`, `retrieve`, `update`, `list`, `listAll` |
| `products`, `prices`, `customers`, `coupons` | `create`, `retrieve`, `update`, `list`, `listAll` |
| `subscriptions` | `create`, `retrieve`, `update`, `list`, `listAll`, `cancel`, `pause`, `resume`, `prepay` |
| `refunds` | `quote`, `create`, `retrieve`, `list`, `listAll`, `markSent` |
| `payments`, `events` | `retrieve`, `list`, `listAll` |
| `receipts` | `retrieve`, `list`, `listAll`, `pdf` |
| `entitlements` | `check`, `retrieve`, `list`, `listAll`, `consume` |
| `credits` | `balance`, `consume`, `topUpUrl`, `usage` |
| `licenseKeys` | `validate`, `activate`, `deactivate`, `retrieve`, `list`, `listAll`, `revoke` |
| `portalSessions` | `create` |
| `webhookEndpoints` | `create`, `retrieve`, `update`, `delete`, `list`, `listAll`, `rollSecret`, `claim` |

```php
// Every page of a list
foreach ($perly->invoices->listAll(['status' => 'paid', 'limit' => 100]) as $invoice) {
    echo $invoice['id'], PHP_EOL;
}

// Refunds are non-custodial: the buyer gives an address, you send from your wallet.
$refund = $perly->refunds->create(['invoice' => $invoice['id']]);
// …when $refund['status'] is "ready_to_send", send amount_to_send to refund_address, then:
$perly->refunds->markSent($refund['id'], ['txid' => '0x…']);

// A receipt as PDF bytes
file_put_contents('receipt.pdf', $perly->receipts->pdf('rcpt_…'));
```

### Credits

The buyer tops up on their own page and pays — never an auto-debit — and your
server spends a call at a time. The customer is a `cus_…` id, an email, or your
own id for them (`external_id`).

```php
$left = $perly->credits->balance('user_42')['balance'];

try {
    // Never below zero; a retry with the same key never spends twice.
    $perly->credits->consume('user_42', 3, ['action' => 'chat', 'idempotency_key' => $requestId]);
} catch (InvalidRequestException $e) {
    if ($e->getErrorCode() === 'insufficient_credits') {
        redirect($perly->credits->topUpUrl('user_42'));
    }
}
```

### License keys from software you ship

Never ship a secret key in a plugin or an app. `LicenseClient` calls the public
license endpoints with the buyer's key and your `product`. `product` is required:
without it the call is refused (`InvalidRequestException`, code `parameter_missing`),
and a key of any other product or account answers `wrong_product`:

```php
use Perly\Pay\LicenseClient;

$result = (new LicenseClient())->activate(['key' => $userKey, 'product' => 'prod_…', 'instance_name' => gethostname()]);
if (!$result['valid']) {
    echo $result['reason']; // "limit_reached", "revoked", "expired", …
}
```

## Errors

Every failure throws a `Perly\Pay\Exception\PerlyPayException`: `InvalidRequestException`,
`AuthenticationException`, `PermissionException`, `RateLimitException`,
`IdempotencyException`, `ApiException`, `ConnectionException`, and
`SignatureVerificationException` for webhooks. A call from a place Perly Pay
can't be used (pay.perly.io/sanctions) is a `PermissionException` with code
`region_blocked` and status 451, never retried. A key whose maker left the
team, or may no longer manage keys, is revoked: `AuthenticationException`,
`api_key_invalid`.

```php
use Perly\Pay\Exception\InvalidRequestException;

try {
    $perly->checkoutSessions->create(['line_items' => []]);
} catch (InvalidRequestException $e) {
    echo $e->getErrorCode(), ' ', $e->getParam(); // "parameter_invalid line_items"
    echo $e->getRequestId();                       // quote it to support
}
```

## Amounts

Fiat amounts are integers in minor units (`4900` = $49.00). On-chain amounts are
integer **strings** in the asset's smallest unit (`"10000000"` USDC = 10.00 USDC;
satoshis for bitcoin) — keep them as strings or use `bcmath`, never floats.

## Options

```php
$perly = new PerlyPay($key, [
    'base_url' => 'https://pay.perly.io', // or http://localhost:3200 against a local stack
    'max_retries' => 2,
    'timeout' => 30,                      // seconds
    'transport' => $myTransport,          // any Perly\Pay\Http\Transport
]);
```

## Tests

```bash
composer install
composer test
```

## Links

- Docs: <https://pay.perly.io/docs> · API reference: <https://pay.perly.io/docs/api> · SDKs: <https://pay.perly.io/docs/sdks>
- OpenAPI: <https://pay.perly.io/api/v1/openapi.json>
- Node: [`perly-pay`](https://www.npmjs.com/package/perly-pay) on npm, the same calls and the same webhook check

## License

MIT. The SDK is open source; the Perly Pay service it talks to is not.
