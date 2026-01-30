<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

final readonly class TransportConfig
{
    public function __construct(
        public string $transport,
        public int $processes,
        public int $failureLimit,
        public int $failureWindowSeconds,
        public int $backoffBaseSeconds,
        public int $backoffMaxSeconds,
        public int $pollIntervalMs,
        public ConsumeArgs $consumeArgs,
    ) {}

    public static function create(
        string $transport,
        int $processes = 1,
        int $failureLimit = 3,
        int $failureWindowSeconds = 60,
        int $backoffBaseSeconds = 1,
        int $backoffMaxSeconds = 30,
        int $pollIntervalMs = 200,
        ?ConsumeArgs $consumeArgs = null,
    ): self {
        return new self(
            $transport,
            $processes,
            $failureLimit,
            $failureWindowSeconds,
            $backoffBaseSeconds,
            $backoffMaxSeconds,
            $pollIntervalMs,
            $consumeArgs ?? ConsumeArgs::create(),
        );
    }
}
