<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Metrics;

final class Counter
{
    /** @var array<string, int|float> */
    private array $values = [];

    public function __construct(
        private readonly string $name,
        private readonly string $help,
    ) {}

    /**
     * @param array<string, string> $labels
     */
    public function increment(array $labels = []): void
    {
        $key = self::serializeLabels($labels);
        $this->values[$key] = ($this->values[$key] ?? 0) + 1;
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
     * @return list<array{labels: array<string, string>, value: int|float}>
     */
    public function getValues(): array
    {
        $result = [];

        foreach ($this->values as $key => $value) {
            $result[] = [
                'labels' => self::deserializeLabels($key),
                'value' => $value,
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
