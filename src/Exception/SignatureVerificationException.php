<?php

declare(strict_types=1);

namespace Perly\Pay\Exception;

/** A webhook whose Perly-Signature does not check out. */
final class SignatureVerificationException extends PerlyPayException
{
}
