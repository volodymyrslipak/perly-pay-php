<?php

declare(strict_types=1);

namespace Perly\Pay\Exception;

/** The request never got an answer: network down, DNS, timeout. */
final class ConnectionException extends PerlyPayException
{
}
