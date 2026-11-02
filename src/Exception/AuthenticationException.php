<?php

declare(strict_types=1);

namespace Perly\Pay\Exception;

/** The secret key is missing, malformed, revoked, or for the other mode. */
final class AuthenticationException extends PerlyPayException
{
}
