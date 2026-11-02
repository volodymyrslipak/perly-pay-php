<?php

declare(strict_types=1);

namespace Perly\Pay\Tests;

use Perly\Pay\Http\Response;
use Perly\Pay\Http\Transport;
use Perly\Pay\Http\TransportException;

/** Answers from a queue and remembers every request. */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    public array $calls = [];

    /**
     * @param list<Response|TransportException> $queue
     */
    public function __construct(private array $queue)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): Response
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeoutSeconds];
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('No more responses queued.');
        }
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response(
            $status,
            array_change_key_case($headers + ['Content-Type' => 'application/json'], CASE_LOWER),
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
