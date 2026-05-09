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
     * @param array<string, int> $desired       consumer-label => raw desired count
     * @param array<string, WorkerPool> $pools  consumer-label => pool (must include all consumers in $desired)
     * @return array<string, int>               consumer-label => allocated count
     */
    public function allocate(array $desired, array $pools): array
    {
        $allocated = [];
        $minSum = 0;

        foreach ($pools as $consumer => $pool) {
            $min = $pool->config->autoscaler->min;
            $allocated[$consumer] = $min;
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

        foreach ($groups as $priority => $consumers) {
            unset($priority);

            $demand = [];
            $totalDemand = 0;

            foreach ($consumers as $consumer) {
                $pool = $pools[$consumer];
                $minVal = $pool->config->autoscaler->min;
                $maxVal = $pool->config->autoscaler->max;
                $request = max($minVal, min($maxVal, $desired[$consumer] ?? $minVal));
                $aboveMin = max(0, $request - $minVal);
                $demand[$consumer] = $aboveMin;
                $totalDemand += $aboveMin;
            }

            if ($totalDemand === 0) {
                continue;
            }

            if ($totalDemand <= $remaining) {
                foreach ($demand as $consumer => $aboveMin) {
                    $allocated[$consumer] += $aboveMin;
                }
                $remaining -= $totalDemand;
                continue;
            }

            // Proportional split: allocate floor(remaining * (demand / totalDemand))
            $shares = [];
            $integerSum = 0;
            $remainders = [];

            foreach ($demand as $consumer => $aboveMin) {
                $exact = ($remaining * $aboveMin) / $totalDemand;
                $intPart = (int) floor($exact);
                $shares[$consumer] = $intPart;
                $remainders[$consumer] = $exact - $intPart;
                $integerSum += $intPart;
            }

            $leftover = $remaining - $integerSum;

            $tieBreakOrder = $this->orderByLeftoverPreference($consumers, $remainders, $pools);

            foreach ($tieBreakOrder as $consumer) {
                if ($leftover <= 0) {
                    break;
                }
                $shares[$consumer]++;
                $leftover--;
            }

            foreach ($shares as $consumer => $share) {
                $allocated[$consumer] += $share;
            }

            $remaining = 0;
        }

        return $allocated;
    }

    /**
     * @param array<string, WorkerPool> $pools
     * @return array<int, list<string>>  priority => list of consumer labels, ordered descending by priority
     */
    private function groupByPriorityDesc(array $pools): array
    {
        $groups = [];

        foreach ($pools as $consumer => $pool) {
            $priority = $pool->config->autoscaler->priority;
            $groups[$priority][] = $consumer;
        }

        krsort($groups);

        return $groups;
    }

    /**
     * Order consumers for tie-break leftover allocation:
     * 1. Highest fractional remainder first.
     * 2. Then highest current worker count.
     * 3. Then alphabetical by consumer label.
     *
     * @param list<string> $consumers
     * @param array<string, float> $remainders
     * @param array<string, WorkerPool> $pools
     * @return list<string>
     */
    private function orderByLeftoverPreference(array $consumers, array $remainders, array $pools): array
    {
        usort($consumers, static function (string $a, string $b) use ($remainders, $pools): int {
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

        return array_values($consumers);
    }
}
