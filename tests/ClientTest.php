<?php

declare(strict_types=1);

namespace Perly\Pay\Tests;

use Perly\Pay\Exception\AuthenticationException;
use Perly\Pay\Exception\ConnectionException;
use Perly\Pay\Exception\IdempotencyException;
use Perly\Pay\Exception\InvalidRequestException;
use Perly\Pay\Exception\PerlyPayException;
use Perly\Pay\Exception\RateLimitException;
use Perly\Pay\Http\Requestor;
use Perly\Pay\Http\Response;
use Perly\Pay\Http\TransportException;
use Perly\Pay\LicenseClient;
use Perly\Pay\PerlyPay;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    /**
     * @param list<Response|TransportException> $responses
     */
    private function client(array $responses, ?FakeTransport &$transport = null, int $maxRetries = 2): PerlyPay
    {
        $transport = new FakeTransport($responses);
        $this->sleeps = [];

        return new PerlyPay('sk_test_abc', [
            'base_url' => 'http://pay.test/',
            'transport' => $transport,
            'max_retries' => $maxRetries,
            'sleep' => function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        ]);
    }

    public function testTakesOnlyASecretKeyAndKnowsItsMode(): void
    {
        self::assertSame('live', (new PerlyPay('sk_live_x'))->mode);
        self::assertSame('test', (new PerlyPay('sk_test_x'))->mode);
        self::assertSame('http://localhost:3200', (new PerlyPay('sk_test_x', ['base_url' => 'http://localhost:3200/']))->baseUrl);
        $this->expectException(\InvalidArgumentException::class);
        new PerlyPay('pk_test_nope');
    }

    public function testCreatesACheckoutSessionWithAnIdempotencyKey(): void
    {
        $perly = $this->client([FakeTransport::json(201, ['id' => 'cs_1', 'object' => 'checkout_session', 'url' => 'https://pay.perly.io/c/cs_1'])], $t);
        $session = $perly->checkoutSessions->create(['line_items' => [['price' => 'price_1', 'quantity' => 2]], 'return_url' => 'https://shop.example/thanks']);

        self::assertSame('https://pay.perly.io/c/cs_1', $session['url']);
        $call = $t->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('http://pay.test/api/v1/checkout_sessions', $call['url']);
        self::assertSame('Bearer sk_test_abc', $call['headers']['Authorization']);
        self::assertSame('application/json', $call['headers']['Content-Type']);
        self::assertSame('php/' . PerlyPay::VERSION, $call['headers']['Perly-Client']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $call['headers']['Idempotency-Key']);
        self::assertSame('{"line_items":[{"price":"price_1","quantity":2}],"return_url":"https://shop.example/thanks"}', $call['body']);
    }

    public function testUsesYourIdempotencyKeyAndKeepsItAcrossRetries(): void
    {
        $perly = $this->client([
            FakeTransport::json(500, ['error' => ['type' => 'api_error', 'code' => 'internal', 'message' => 'Boom.']]),
            FakeTransport::json(409, ['error' => ['type' => 'idempotency_error', 'code' => 'idempotency_in_progress', 'message' => 'Still running.']]),
            FakeTransport::json(201, ['id' => 'inv_1']),
        ], $t);
        $invoice = $perly->invoices->create(['amount' => 2400, 'description' => 'Credits'], ['idempotency_key' => 'order-1042']);

        self::assertSame('inv_1', $invoice['id']);
        self::assertCount(3, $t->calls);
        foreach ($t->calls as $call) {
            self::assertSame('order-1042', $call['headers']['Idempotency-Key']);
        }
        self::assertCount(2, $this->sleeps);
    }

    public function testHonoursRetryAfterOnA429(): void
    {
        $perly = $this->client([
            FakeTransport::json(429, ['error' => ['type' => 'rate_limit_error', 'code' => 'rate_limited', 'message' => 'Slow down.']], ['Retry-After' => '3']),
            FakeTransport::json(200, ['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null]),
        ]);
        $perly->products->list();
        self::assertSame([3000], $this->sleeps);
    }

    public function testGivesUpAfterItsRetriesWithATypedError(): void
    {
        $limited = FakeTransport::json(429, ['error' => ['type' => 'rate_limit_error', 'code' => 'rate_limited', 'message' => 'Slow down.']]);
        $perly = $this->client([$limited, $limited], $t, maxRetries: 1);
        $this->expectException(RateLimitException::class);
        $perly->products->list();
    }

    public function testDoesNotRetryAConflictThatIsNotInProgress(): void
    {
        $perly = $this->client([
            FakeTransport::json(409, ['error' => ['type' => 'idempotency_error', 'code' => 'idempotency_mismatch', 'message' => 'Another body.']]),
        ], $t);
        try {
            $perly->invoices->create(['amount' => 1, 'description' => 'x']);
            self::fail('should have thrown');
        } catch (IdempotencyException $error) {
            self::assertSame('idempotency_mismatch', $error->getErrorCode());
            self::assertCount(1, $t->calls);
        }
    }

    public function testRaisesTheApisErrorWithItsFields(): void
    {
        $perly = $this->client([
            FakeTransport::json(400, ['error' => ['type' => 'invalid_request_error', 'code' => 'parameter_invalid', 'message' => 'line_items is empty.', 'param' => 'line_items']], ['Perly-Request-Id' => 'req_9']),
        ]);
        try {
            $perly->checkoutSessions->create(['line_items' => []]);
            self::fail('should have thrown');
        } catch (InvalidRequestException $error) {
            self::assertSame('invalid_request_error', $error->getType());
            self::assertSame('parameter_invalid', $error->getErrorCode());
            self::assertSame('line_items', $error->getParam());
            self::assertSame(400, $error->getHttpStatus());
            self::assertSame('req_9', $error->getRequestId());
            self::assertSame('line_items is empty.', $error->getMessage());
            self::assertInstanceOf(PerlyPayException::class, $error);
        }
    }

    public function testMapsAnAnswerWithoutAnErrorBody(): void
    {
        $perly = $this->client([new Response(401, [], 'nope')]);
        try {
            $perly->invoices->retrieve('inv_1');
            self::fail('should have thrown');
        } catch (PerlyPayException $error) {
            self::assertSame('http_401', $error->getErrorCode());
            self::assertSame(401, $error->getHttpStatus());
        }

        $perly = $this->client([FakeTransport::json(401, ['error' => ['type' => 'authentication_error', 'code' => 'key_invalid', 'message' => 'No such key.']])]);
        $this->expectException(AuthenticationException::class);
        $perly->invoices->retrieve('inv_1');
    }

    public function testRetriesWhenThereIsNoAnswerThenSaysSo(): void
    {
        $perly = $this->client([new TransportException('refused'), new TransportException('timed out', true)], $t, maxRetries: 1);
        try {
            $perly->events->list();
            self::fail('should have thrown');
        } catch (ConnectionException $error) {
            self::assertSame('timeout', $error->getErrorCode());
            self::assertCount(2, $t->calls);
        }
    }

    public function testBuildsPathsAndQueries(): void
    {
        $ok = FakeTransport::json(200, ['id' => 'x']);
        $perly = $this->client([$ok, $ok, $ok, $ok, $ok, $ok], $t);
        $perly->invoices->list(['status' => 'paid', 'limit' => 10, 'customer' => null]);
        $perly->entitlements->check(['customer_email' => 'ada+1@example.com', 'product' => 'prod_1']);
        $perly->refunds->markSent('rf_1', ['txid' => '0xabc']);
        $perly->subscriptions->cancel('sub/1', ['at_period_end' => true]);
        $perly->webhookEndpoints->delete('we_1');
        $perly->prices->list(['active' => false]);

        self::assertSame('GET', $t->calls[0]['method']);
        self::assertSame('http://pay.test/api/v1/invoices?status=paid&limit=10', $t->calls[0]['url']);
        self::assertArrayNotHasKey('Idempotency-Key', $t->calls[0]['headers']);
        self::assertNull($t->calls[0]['body']);
        self::assertSame('http://pay.test/api/v1/entitlements/check?customer_email=ada%2B1%40example.com&product=prod_1', $t->calls[1]['url']);
        self::assertSame('http://pay.test/api/v1/refunds/rf_1/mark_sent', $t->calls[2]['url']);
        self::assertSame('{"txid":"0xabc"}', $t->calls[2]['body']);
        self::assertSame('http://pay.test/api/v1/subscriptions/sub%2F1/cancel', $t->calls[3]['url']);
        self::assertSame('DELETE', $t->calls[4]['method']);
        self::assertSame('http://pay.test/api/v1/prices?active=false', $t->calls[5]['url']);
    }

    public function testSendsAnEmptyBodyAsAnObject(): void
    {
        $perly = $this->client([FakeTransport::json(200, ['id' => 'inv_1', 'status' => 'canceled'])], $t);
        $perly->invoices->cancel('inv_1');
        self::assertSame('{}', $t->calls[0]['body']);
        self::assertSame('http://pay.test/api/v1/invoices/inv_1/cancel', $t->calls[0]['url']);
    }

    public function testWalksEveryPage(): void
    {
        $perly = $this->client([
            FakeTransport::json(200, ['object' => 'list', 'data' => [['id' => 'inv_3'], ['id' => 'inv_2']], 'has_more' => true, 'next_cursor' => 'c1']),
            FakeTransport::json(200, ['object' => 'list', 'data' => [['id' => 'inv_1']], 'has_more' => false, 'next_cursor' => null]),
        ], $t);
        $ids = [];
        foreach ($perly->invoices->listAll(['limit' => 2]) as $invoice) {
            $ids[] = $invoice['id'];
        }
        self::assertSame(['inv_3', 'inv_2', 'inv_1'], $ids);
        self::assertSame('http://pay.test/api/v1/invoices?limit=2&cursor=c1', $t->calls[1]['url']);
    }

    public function testReadsAReceiptPdfAsBytes(): void
    {
        $perly = $this->client([new Response(200, ['content-type' => 'application/pdf'], "%PDF-1.7\n")], $t);
        self::assertSame("%PDF-1.7\n", $perly->receipts->pdf('rcpt_1'));
        self::assertSame('http://pay.test/api/v1/receipts/rcpt_1/pdf', $t->calls[0]['url']);
    }

    public function testChecksLicensesWithoutASecretKey(): void
    {
        $t = new FakeTransport([FakeTransport::json(200, ['valid' => true])]);
        $result = (new LicenseClient(['base_url' => 'http://pay.test', 'transport' => $t]))->activate(['key' => 'K', 'product' => 'prod_1', 'instance_name' => 'laptop']);
        self::assertTrue($result['valid']);
        self::assertSame('http://pay.test/api/v1/license_keys/activate', $t->calls[0]['url']);
        self::assertArrayNotHasKey('Authorization', $t->calls[0]['headers']);
    }

    public function testBacksOffExponentiallyWithJitter(): void
    {
        self::assertSame(250, Requestor::backoffMs(0, null, static fn (): float => 0.0));
        self::assertSame(1000, Requestor::backoffMs(1, null, static fn (): float => 0.999999));
        self::assertSame(8000, Requestor::backoffMs(9, null, static fn (): float => 0.999999));
        self::assertSame(2000, Requestor::backoffMs(0, '2'));
        self::assertSame(60000, Requestor::backoffMs(0, '3600'));
    }

    public function testSpendsCreditsInOneLineWithYourKeyOnEveryRetry(): void
    {
        $usage = ['id' => 'cu_1', 'object' => 'credit_usage', 'credits' => 3, 'balance' => 97, 'action' => 'chat'];
        $perly = $this->client([
            FakeTransport::json(503, ['error' => ['type' => 'api_error', 'code' => 'internal', 'message' => 'Boom.']]),
            FakeTransport::json(200, $usage),
        ], $t);
        $spent = $perly->credits->consume('user_42', 3, ['action' => 'chat', 'idempotency_key' => 'req-7']);

        self::assertSame(97, $spent['balance']);
        self::assertCount(2, $t->calls);
        foreach ($t->calls as $call) {
            self::assertSame('http://pay.test/api/v1/credits/consume', $call['url']);
            self::assertSame('req-7', $call['headers']['Idempotency-Key']);
            self::assertSame('{"customer_external_id":"user_42","credits":3,"action":"chat"}', $call['body']);
        }
    }

    public function testNamesTheCustomerByIdEmailOrYourOwnId(): void
    {
        $balance = ['object' => 'credit_balance', 'balance' => 5, 'top_up_url' => 'https://pay.perly.io/topup/abc'];
        $perly = $this->client(array_fill(0, 5, FakeTransport::json(200, $balance)), $t);
        $perly->credits->balance('cus_0123456789abcdef0123456789abcdef');
        $perly->credits->balance(['email' => 'ada@example.com'], ['product' => 'prod_1']);
        $perly->credits->balance(['external_id' => 'ada@example.com']);
        $url = $perly->credits->topUpUrl('user_42');
        // A bare string with an @ is your own id, never somebody's email.
        $perly->credits->balance('ada@example.com');

        self::assertSame('https://pay.perly.io/topup/abc', $url);
        self::assertSame('http://pay.test/api/v1/credits/balance?customer=cus_0123456789abcdef0123456789abcdef', $t->calls[0]['url']);
        self::assertSame('http://pay.test/api/v1/credits/balance?product=prod_1&customer_email=ada%40example.com', $t->calls[1]['url']);
        self::assertSame('http://pay.test/api/v1/credits/balance?customer_external_id=ada%40example.com', $t->calls[2]['url']);
        self::assertSame('http://pay.test/api/v1/credits/balance?customer_external_id=user_42', $t->calls[3]['url']);
        self::assertSame('http://pay.test/api/v1/credits/balance?customer_external_id=ada%40example.com', $t->calls[4]['url']);
    }

    public function testSaysInsufficientCreditsAsAnInvalidRequest(): void
    {
        $perly = $this->client([FakeTransport::json(409, ['error' => ['type' => 'invalid_request_error', 'code' => 'insufficient_credits', 'message' => 'Not enough credits left (2).', 'param' => 'credits']])], $t);
        try {
            $perly->credits->consume(['customer' => 'cus_1', 'credits' => 5]);
            self::fail('expected a refusal');
        } catch (InvalidRequestException $e) {
            self::assertSame('insufficient_credits', $e->getErrorCode());
        }
        self::assertCount(1, $t->calls);
    }
}
