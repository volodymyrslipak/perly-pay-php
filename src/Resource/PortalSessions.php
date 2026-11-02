<?php

declare(strict_types=1);

namespace Perly\Pay\Resource;

use Perly\Pay\Resource\Concerns\Creates;

/** `/portal_sessions`: a link that lets a customer manage their subscriptions and receipts. */
final class PortalSessions extends AbstractResource
{
    use Creates;
}
