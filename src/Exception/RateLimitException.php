<?php

declare(strict_types=1);

namespace Perly\Pay\Exception;

/** Too many requests; already retried with backoff before it is raised. */
final class RateLimitException extends PerlyPayException
{
}
