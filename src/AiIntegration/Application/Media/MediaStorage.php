<?php

declare(strict_types=1);

namespace Providentia\AiIntegration\Application\Media;

interface MediaStorage
{
    public function store(string $homeId, string $assetId, string $bytes): EncryptedMediaObject;

    public function read(string $homeId, string $assetId, EncryptedMediaObject $object): string;

    public function delete(EncryptedMediaObject $object): void;
}
