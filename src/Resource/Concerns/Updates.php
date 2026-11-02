<?php

declare(strict_types=1);

namespace Perly\Pay\Resource\Concerns;

/** @mixin \Perly\Pay\Resource\AbstractResource */
trait Updates
{
    /**
     * @param array<string, mixed> $params only the fields to change
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params, array $options = []): array
    {
        return $this->requestor->request('POST', $this->idPath($id), $params, [], $options);
    }
}
