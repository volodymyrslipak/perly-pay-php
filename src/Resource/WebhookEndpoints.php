<?php

declare(strict_types=1);

namespace Perly\Pay\Resource;

use Perly\Pay\Resource\Concerns\Creates;
use Perly\Pay\Resource\Concerns\Deletes;
use Perly\Pay\Resource\Concerns\Lists;
use Perly\Pay\Resource\Concerns\Retrieves;
use Perly\Pay\Resource\Concerns\Updates;

/** `/webhook_endpoints`: where events are POSTed, signed. */
final class WebhookEndpoints extends AbstractResource
{
    use Creates, Deletes, Lists, Retrieves, Updates;

    /**
     * A new signing secret; the old one stops at once.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> the endpoint, with its new `secret`
     */
    public function rollSecret(string $id, array $options = []): array
    {
        return $this->action($id, 'roll_secret', [], $options);
    }
}
