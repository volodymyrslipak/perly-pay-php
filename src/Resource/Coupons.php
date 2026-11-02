<?php

declare(strict_types=1);

namespace Perly\Pay\Resource;

use Perly\Pay\Resource\Concerns\Creates;
use Perly\Pay\Resource\Concerns\Lists;
use Perly\Pay\Resource\Concerns\Retrieves;
use Perly\Pay\Resource\Concerns\Updates;

/** `/coupons`: codes that take an amount or a share off. */
final class Coupons extends AbstractResource
{
    use Creates, Lists, Retrieves, Updates;
}
