<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

use SymfonyProcessManager\Metrics\MetricsRegistry;

final class WorkerOutputFormatter
{
    public function __construct(
        private readonly ?MetricsRegistry $metrics = null,
    ) {}

    public function format(int $workerId, string $line, string $transport = ''): string
    {
        $decoded = json_decode($line, true);

        if (is_array($decoded) && $this->isAssociativeArray($decoded)) {
            if ($this->metrics !== null && isset($decoded['message']) && is_string($decoded['message'])
                && str_contains($decoded['message'], 'was handled successfully (acknowledging to transport).')) {
                $this->metrics->incrementCounter('messages_processed', 'Total messages processed', ['transport' => $transport]);
            }

            $extra = $decoded['extra'] ?? null;
            $decoded['extra'] = is_array($extra) ? $extra : [];
            $decoded['extra']['worker_id'] = $workerId;
            $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($encoded !== false) {
                return $encoded;
            }
        }

        return sprintf('[worker %d] %s', $workerId, $line);
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssociativeArray(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (!is_int($key)) {
                return true;
            }
        }

        return false;
    }
}
