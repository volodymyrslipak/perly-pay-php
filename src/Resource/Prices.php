<?php

declare(strict_types=1);

namespace Perly\Pay\Resource;

use Perly\Pay\Resource\Concerns\Creates;
use Perly\Pay\Resource\Concerns\Lists;
use Perly\Pay\Resource\Concerns\Retrieves;
use Perly\Pay\Resource\Concerns\Updates;

/** `/prices`: how a product is sold (one-time, subscription, pay what you want, …). */
final class Prices extends AbstractResource
{
    use Creates, Lists, Retrieves, Updates;
}
