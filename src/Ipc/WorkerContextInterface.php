<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc;

interface WorkerContextInterface
{
    public function current(): ?WorkerMetadata;

    public function setCurrent(WorkerMetadata $metadata): void;

    public function clear(): void;
}
