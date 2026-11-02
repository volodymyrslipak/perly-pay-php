<?php

declare(strict_types=1);

namespace Perly\Pay\Resource;

use Perly\Pay\Resource\Concerns\Lists;
use Perly\Pay\Resource\Concerns\Retrieves;

/** `/events`: everything that happened, the same objects webhooks deliver. */
final class Events extends AbstractResource
{
    use Lists, Retrieves;
}
