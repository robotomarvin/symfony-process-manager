<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc;

final class WorkerContext implements WorkerContextInterface
{
    private ?WorkerMetadata $metadata = null;

    public function current(): ?WorkerMetadata
    {
        return $this->metadata;
    }

    public function setCurrent(WorkerMetadata $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function clear(): void
    {
        $this->metadata = null;
    }
}
