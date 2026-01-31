<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Metrics;

final class PrometheusTextRenderer
{
    /**
     * @param array<string, Counter> $counters
     * @param array<string, Gauge> $gauges
     */
    public function render(array $counters, array $gauges): string
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

    private static function escapeLabelValue(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
        ]);
    }
}
