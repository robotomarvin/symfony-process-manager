<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Output;

use SymfonyProcessManager\Ipc\IpcCodec;

final class WorkerOutputFormatter
{
    public function format(int $workerId, string $line): string
    {
        if (IpcCodec::isIpcLine($line)) {
            return '';
        }

        $decoded = json_decode($line, true);

        if (is_array($decoded) && $this->isAssociativeArray($decoded)) {
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
