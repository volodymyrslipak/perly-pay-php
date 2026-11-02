<?php

declare(strict_types=1);

namespace Perly\Pay\Exception;

/** Any other error the API answered (a 5xx after its retries, an unknown type). */
final class ApiException extends PerlyPayException
{
}
