<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Application;

interface DataArtifactStorage
{
    public function store(string $requestId, string $json): DataArtifact;

    public function read(string $requestId, DataArtifact $artifact): string;

    public function delete(DataArtifact $artifact): void;
}
