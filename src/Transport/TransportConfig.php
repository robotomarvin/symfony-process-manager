<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Transport;

use SymfonyProcessManager\Autoscaler\AutoscalerConfig;

final readonly class TransportConfig
{
    public function __construct(
        public string $transport,
        public int $failureLimit,
        public int $failureWindowSeconds,
        public int $backoffBaseSeconds,
        public int $backoffMaxSeconds,
        public int $pollIntervalMs,
        public ConsumeArgs $consumeArgs,
        public AutoscalerConfig $autoscaler,
    ) {}

    public static function create(
        string $transport,
        int $failureLimit = 3,
        int $failureWindowSeconds = 60,
        int $backoffBaseSeconds = 1,
        int $backoffMaxSeconds = 30,
        int $pollIntervalMs = 200,
        ?ConsumeArgs $consumeArgs = null,
        ?AutoscalerConfig $autoscaler = null,
    ): self {
        return new self(
            $transport,
            $failureLimit,
            $failureWindowSeconds,
            $backoffBaseSeconds,
            $backoffMaxSeconds,
            $pollIntervalMs,
            $consumeArgs ?? ConsumeArgs::create(),
            $autoscaler ?? AutoscalerConfig::legacyFixed(1),
        );
    }
}
