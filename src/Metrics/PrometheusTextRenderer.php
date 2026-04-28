<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Metrics;

final class PrometheusTextRenderer implements PrometheusRendererInterface
{
    /**
     * @param array<string, Counter> $counters
     * @param array<string, Gauge> $gauges
     * @param array<string, Histogram> $histograms
     */
    public function render(array $counters, array $gauges, array $histograms = []): string
    {
        $lines = [];

        foreach ($counters as $counter) {
            $name = $counter->getName() . '_total';
            $lines[] = '# HELP ' . $name . ' ' . $counter->getHelp();
            $lines[] = '# TYPE ' . $name . ' counter';

            foreach ($counter->getValues() as $entry) {
                $lines[] = $this->formatMetricLine($name, $entry['labels'], $entry['value']);
            }
        }

        foreach ($gauges as $gauge) {
            $name = $gauge->getName();
            $lines[] = '# HELP ' . $name . ' ' . $gauge->getHelp();
            $lines[] = '# TYPE ' . $name . ' gauge';

            foreach ($gauge->getValues() as $entry) {
                $lines[] = $this->formatMetricLine($name, $entry['labels'], $entry['value']);
            }
        }

        foreach ($histograms as $histogram) {
            $name = $histogram->getName();
            $lines[] = '# HELP ' . $name . ' ' . $histogram->getHelp();
            $lines[] = '# TYPE ' . $name . ' histogram';
            $buckets = $histogram->getBuckets();

            foreach ($histogram->getValues() as $entry) {
                foreach ($buckets as $i => $bound) {
                    $bucketLabels = $entry['labels'];
                    $bucketLabels['le'] = self::formatBucketBound($bound);
                    $lines[] = $this->formatMetricLine(
                        $name . '_bucket',
                        $bucketLabels,
                        $entry['bucketCounts'][$i],
                    );
                }

                $infLabels = $entry['labels'];
                $infLabels['le'] = '+Inf';
                $lines[] = $this->formatMetricLine($name . '_bucket', $infLabels, $entry['count']);
                $lines[] = $this->formatMetricLine($name . '_sum', $entry['labels'], $entry['sum']);
                $lines[] = $this->formatMetricLine($name . '_count', $entry['labels'], $entry['count']);
            }
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, string> $labels
     */
    private function formatMetricLine(string $name, array $labels, int|float $value): string
    {
        if ($labels === []) {
            return $name . ' ' . $this->formatValue($value);
        }

        $labelParts = [];

        foreach ($labels as $labelName => $labelValue) {
            $labelParts[] = $labelName . '="' . self::escapeLabelValue($labelValue) . '"';
        }

        return $name . '{' . implode(',', $labelParts) . '} ' . $this->formatValue($value);
    }

    private function formatValue(int|float $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        $formatted = (string) $value;

        if (!str_contains($formatted, '.')) {
            return $formatted . '.0';
        }

        return $formatted;
    }

    private static function formatBucketBound(float $bound): string
    {
        if ($bound === floor($bound) && abs($bound) < 1e15) {
            return number_format($bound, 0, '.', '');
        }

        return rtrim(rtrim(sprintf('%.10F', $bound), '0'), '.');
    }

    private static function escapeLabelValue(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
        ]);
    }
}
