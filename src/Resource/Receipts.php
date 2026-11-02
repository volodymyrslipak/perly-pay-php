<?php

declare(strict_types=1);

namespace Perly\Pay\Resource;

use Perly\Pay\Resource\Concerns\Lists;
use Perly\Pay\Resource\Concerns\Retrieves;

/** `/receipts`: one per paid invoice and per refund. */
final class Receipts extends AbstractResource
{
    use Lists, Retrieves;

    /**
     * The receipt as a PDF, its raw bytes.
     *
     * @param array<string, mixed> $options
     */
    public function pdf(string $id, array $options = []): string
    {
        return $this->requestor->raw($this->idPath($id, 'pdf'), $options);
    }
}
