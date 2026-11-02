<?php

declare(strict_types=1);

namespace Perly\Pay\Resource\Concerns;

/** @mixin \Perly\Pay\Resource\AbstractResource */
trait Deletes
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> `['id' => …, 'object' => …, 'deleted' => true]`
     */
    public function delete(string $id, array $options = []): array
    {
        return $this->requestor->request('DELETE', $this->idPath($id), null, [], $options);
    }
}
