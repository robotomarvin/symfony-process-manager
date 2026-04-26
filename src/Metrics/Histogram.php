<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Metrics;

final class Histogram
{
    /** @var list<float> */
    private array $buckets;

    /** @var array<string, list<int>> */
    private array $bucketCounts = [];

    /** @var array<string, float> */
    private array $sums = [];

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * @param list<float|int> $buckets
     */
    public function __construct(
        private readonly string $name,
        private readonly string $help,
        array $buckets,
    ) {
        $normalized = [];

        foreach ($buckets as $bucket) {
            $normalized[] = (float) $bucket;
        }

        $normalized = array_values(array_unique($normalized, \SORT_NUMERIC));
        sort($normalized, \SORT_NUMERIC);

        $this->buckets = $normalized;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getHelp(): string
    {
        return $this->help;
    }

    /**
     * @return list<float>
     */
    public function getBuckets(): array
    {
        return $this->buckets;
    }

    /**
     * @param array<string, string> $labels
     */
    public function observe(float $value, array $labels = []): void
    {
        $key = self::serializeLabels($labels);

        if (!isset($this->counts[$key])) {
            $this->bucketCounts[$key] = array_fill(0, count($this->buckets), 0);
            $this->sums[$key] = 0.0;
            $this->counts[$key] = 0;
        }

        foreach ($this->buckets as $i => $bound) {
            if ($value <= $bound) {
                $this->bucketCounts[$key][$i]++;
            }
        }

        $this->sums[$key] += $value;
        $this->counts[$key]++;
    }

    /**
     * @return list<array{labels: array<string, string>, bucketCounts: list<int>, sum: float, count: int}>
     */
    public function getValues(): array
    {
        $result = [];

        foreach ($this->counts as $key => $count) {
            $result[] = [
                'labels' => self::deserializeLabels($key),
                'bucketCounts' => $this->bucketCounts[$key],
                'sum' => $this->sums[$key],
                'count' => $count,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, string> $labels
     */
    private static function serializeLabels(array $labels): string
    {
        ksort($labels);

        return json_encode($labels, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private static function deserializeLabels(string $key): array
    {
        /** @var array<string, string> */
        return json_decode($key, true, 512, \JSON_THROW_ON_ERROR);
    }
}
