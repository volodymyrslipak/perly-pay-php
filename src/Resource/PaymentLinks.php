<?php

declare(strict_types=1);

namespace Perly\Pay\Resource;

use Perly\Pay\Resource\Concerns\Creates;
use Perly\Pay\Resource\Concerns\Lists;
use Perly\Pay\Resource\Concerns\Retrieves;
use Perly\Pay\Resource\Concerns\Updates;

/** `/payment_links`: a lasting `/l/…` address for one price. */
final class PaymentLinks extends AbstractResource
{
    use Creates, Lists, Retrieves, Updates;
}
