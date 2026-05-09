<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Consumer;

use SymfonyProcessManager\Autoscaler\AutoscalerConfig;
use SymfonyProcessManager\Transport\ConsumeArgs;

final readonly class ConsumerConfig
{
    /**
     * @param list<string> $transports
     */
    public function __construct(
        public string $label,
        public array $transports,
        public int $failureLimit,
        public int $failureWindowSeconds,
        public int $backoffBaseSeconds,
        public int $backoffMaxSeconds,
        public int $pollIntervalMs,
        public ConsumeArgs $consumeArgs,
        public AutoscalerConfig $autoscaler,
    ) {
        assert($transports !== [], 'consumer must have at least one transport');
        assert(array_is_list($transports), 'transports must be a list');
    }

    /**
     * @param list<string>|string|null $transports
     */
    public static function create(
        string $label,
        array|string|null $transports = null,
        int $failureLimit = 3,
        int $failureWindowSeconds = 60,
        int $backoffBaseSeconds = 1,
        int $backoffMaxSeconds = 30,
        int $pollIntervalMs = 200,
        ?ConsumeArgs $consumeArgs = null,
        ?AutoscalerConfig $autoscaler = null,
    ): self {
        $transportsList = match (true) {
            is_string($transports) => [$transports],
            $transports === null => [$label],
            default => array_values($transports),
        };

        return new self(
            $label,
            $transportsList,
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
