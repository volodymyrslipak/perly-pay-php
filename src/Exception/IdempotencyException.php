<?php

declare(strict_types=1);

namespace Perly\Pay\Exception;

/** The same Idempotency-Key was sent with a different request. */
final class IdempotencyException extends PerlyPayException
{
}
