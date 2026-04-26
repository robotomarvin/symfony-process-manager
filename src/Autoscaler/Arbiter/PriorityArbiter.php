<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Autoscaler\Arbiter;

use SymfonyProcessManager\ProcessManager\WorkerPool;

final class PriorityArbiter
{
    public function __construct(private readonly int $totalCap)
    {
        assert($totalCap >= 0, 'total cap must be non-negative');
    }

    /**
     * Arbitrate the desired worker counts across pools so the sum does not exceed
     * the total cap. Pools are grouped by priority; higher priorities are preferred.
     *
     * @param array<string, int> $desired       transport => raw desired count
     * @param array<string, WorkerPool> $pools  transport => pool (must include all transports in $desired)
     * @return array<string, int>               transport => allocated count
     */
    public function allocate(array $desired, array $pools): array
    {
        $allocated = [];
        $minSum = 0;

        foreach ($pools as $transport => $pool) {
            $min = $pool->config->autoscaler->min;
            $allocated[$transport] = $min;
            $minSum += $min;
        }

        if ($minSum > $this->totalCap) {
            throw new \LogicException(sprintf(
                'PriorityArbiter: sum of pool minimums (%d) exceeds total_cap (%d).',
                $minSum,
                $this->totalCap,
            ));
        }

        $remaining = $this->totalCap - $minSum;

        $groups = $this->groupByPriorityDesc($pools);

        foreach ($groups as $priority => $transports) {
            unset($priority);

            $demand = [];
            $totalDemand = 0;

            foreach ($transports as $transport) {
                $pool = $pools[$transport];
                $minVal = $pool->config->autoscaler->min;
                $maxVal = $pool->config->autoscaler->max;
                $request = max($minVal, min($maxVal, $desired[$transport] ?? $minVal));
                $aboveMin = max(0, $request - $minVal);
                $demand[$transport] = $aboveMin;
                $totalDemand += $aboveMin;
            }

            if ($totalDemand === 0) {
                continue;
            }

            if ($totalDemand <= $remaining) {
                foreach ($demand as $transport => $aboveMin) {
                    $allocated[$transport] += $aboveMin;
                }
                $remaining -= $totalDemand;
                continue;
            }

            // Proportional split: allocate floor(remaining * (demand / totalDemand))
            $shares = [];
            $integerSum = 0;
            $remainders = [];

            foreach ($demand as $transport => $aboveMin) {
                $exact = ($remaining * $aboveMin) / $totalDemand;
                $intPart = (int) floor($exact);
                $shares[$transport] = $intPart;
                $remainders[$transport] = $exact - $intPart;
                $integerSum += $intPart;
            }

            $leftover = $remaining - $integerSum;

            $tieBreakOrder = $this->orderByLeftoverPreference($transports, $remainders, $pools);

            foreach ($tieBreakOrder as $transport) {
                if ($leftover <= 0) {
                    break;
                }
                $shares[$transport]++;
                $leftover--;
            }

            foreach ($shares as $transport => $share) {
                $allocated[$transport] += $share;
            }

            $remaining = 0;
        }

        return $allocated;
    }

    /**
     * @param array<string, WorkerPool> $pools
     * @return array<int, list<string>>  priority => list of transport names, ordered descending by priority
     */
    private function groupByPriorityDesc(array $pools): array
    {
        $groups = [];

        foreach ($pools as $transport => $pool) {
            $priority = $pool->config->autoscaler->priority;
            $groups[$priority][] = $transport;
        }

        krsort($groups);

        return $groups;
    }

    /**
     * Order transports for tie-break leftover allocation:
     * 1. Highest fractional remainder first.
     * 2. Then highest current worker count.
     * 3. Then alphabetical by transport name.
     *
     * @param list<string> $transports
     * @param array<string, float> $remainders
     * @param array<string, WorkerPool> $pools
     * @return list<string>
     */
    private function orderByLeftoverPreference(array $transports, array $remainders, array $pools): array
    {
        usort($transports, static function (string $a, string $b) use ($remainders, $pools): int {
            $remCmp = ($remainders[$b] ?? 0.0) <=> ($remainders[$a] ?? 0.0);
            if ($remCmp !== 0) {
                return $remCmp;
            }

            $countCmp = $pools[$b]->activeWorkerCount() <=> $pools[$a]->activeWorkerCount();
            if ($countCmp !== 0) {
                return $countCmp;
            }

            return strcmp($a, $b);
        });

        return array_values($transports);
    }
}
