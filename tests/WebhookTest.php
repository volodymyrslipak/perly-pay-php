<?php

declare(strict_types=1);

namespace Perly\Pay\Tests;

use Perly\Pay\Exception\SignatureVerificationException;
use Perly\Pay\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    // A delivery signed by Perly Pay's own signer (src/services/billing/signature.ts):
    // packages/sdk-php/webhook.test.ts recomputes VECTOR_SIGNATURE in Node on every gate.
    public const VECTOR_SECRET = 'whsec_test';
    public const VECTOR_TIMESTAMP = 1791446400;
    public const VECTOR_BODY = '{"id":"evt_1","object":"event","type":"invoice.fulfilled"}';
    public const VECTOR_SIGNATURE = 'e03ae1418940658736455dd3fa93bf8230b09d3ea9336a3947012e2061a071f0';

    private function header(): string
    {
        return 't=' . self::VECTOR_TIMESTAMP . ',v1=' . self::VECTOR_SIGNATURE;
    }

    public function testComputesThePlatformsSignature(): void
    {
        self::assertSame(self::VECTOR_SIGNATURE, Webhook::computeSignature(self::VECTOR_SECRET, self::VECTOR_TIMESTAMP, self::VECTOR_BODY));
    }

    public function testAcceptsAGoodDeliveryAndReturnsTheEvent(): void
    {
        $event = Webhook::verify(self::VECTOR_BODY, $this->header(), self::VECTOR_SECRET, now: self::VECTOR_TIMESTAMP + 60);
        self::assertSame('evt_1', $event['id']);
        self::assertSame('invoice.fulfilled', $event['type']);
    }

    public function testAcceptsAnyOfSeveralSignaturesWhileASecretRolls(): void
    {
        $header = 't=' . self::VECTOR_TIMESTAMP . ',v1=' . str_repeat('0', 64) . ',v1=' . strtoupper(self::VECTOR_SIGNATURE);
        $event = Webhook::verify(self::VECTOR_BODY, $header, self::VECTOR_SECRET, now: self::VECTOR_TIMESTAMP);
        self::assertSame('evt_1', $event['id']);
    }

    public function testRoundTripsItsOwnHeader(): void
    {
        $body = '{"id":"evt_2","type":"refund.confirmed"}';
        $header = Webhook::signatureHeader($body, 'whsec_x', 1_800_000_000);
        self::assertSame('evt_2', Webhook::verify($body, $header, 'whsec_x', now: 1_800_000_000)['id']);
    }

    /**
     * @return iterable<string, array{string, ?string, string, int, string}>
     */
    public static function refusals(): iterable
    {
        $good = 't=' . self::VECTOR_TIMESTAMP . ',v1=' . self::VECTOR_SIGNATURE;
        $now = self::VECTOR_TIMESTAMP;
        yield 'changed body' => [self::VECTOR_BODY . ' ', $good, self::VECTOR_SECRET, $now, 'signature_mismatch'];
        yield 'other secret' => [self::VECTOR_BODY, $good, 'whsec_other', $now, 'signature_mismatch'];
        yield 'too old' => [self::VECTOR_BODY, $good, self::VECTOR_SECRET, $now + 301, 'timestamp_out_of_tolerance'];
        yield 'from the future' => [self::VECTOR_BODY, $good, self::VECTOR_SECRET, $now - 301, 'timestamp_out_of_tolerance'];
        yield 'no header' => [self::VECTOR_BODY, null, self::VECTOR_SECRET, $now, 'no_signature'];
        yield 'garbage header' => [self::VECTOR_BODY, 'garbage', self::VECTOR_SECRET, $now, 'malformed_signature'];
        yield 'no v1' => [self::VECTOR_BODY, 't=' . $now, self::VECTOR_SECRET, $now, 'malformed_signature'];
        yield 'no secret' => [self::VECTOR_BODY, $good, '', $now, 'no_secret'];
    }

    #[DataProvider('refusals')]
    public function testRefuses(string $body, ?string $header, string $secret, int $now, string $code): void
    {
        try {
            Webhook::verify($body, $header, $secret, now: $now);
            self::fail('verify should have thrown');
        } catch (SignatureVerificationException $error) {
            self::assertSame($code, $error->getErrorCode());
            self::assertSame('signature_verification_error', $error->getType());
        }
    }

    public function testRefusesABodyThatIsNotJson(): void
    {
        $header = Webhook::signatureHeader('not json', 'whsec_x', 1_800_000_000);
        $this->expectException(SignatureVerificationException::class);
        Webhook::verify('not json', $header, 'whsec_x', now: 1_800_000_000);
    }
}
