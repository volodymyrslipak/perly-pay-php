<?php

declare(strict_types=1);

namespace Perly\Pay\Resource;

use Perly\Pay\Resource\Concerns\Lists;
use Perly\Pay\Resource\Concerns\Retrieves;

/** `/payments`: transfers that arrived on-chain for your invoices. */
final class Payments extends AbstractResource
{
    use Lists, Retrieves;
}
