<?php

declare(strict_types=1);

namespace Perly\Pay\Resource\Concerns;

/** @mixin \Perly\Pay\Resource\AbstractResource */
trait Retrieves
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function retrieve(string $id, array $options = []): array
    {
        return $this->requestor->request('GET', $this->idPath($id), null, [], $options);
    }
}
