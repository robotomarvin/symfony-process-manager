<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

final readonly class ConsumeArgs
{
    /**
     * @param list<string> $queues
     * @param list<string> $extra
     */
    public function __construct(
        public ?int $memoryLimit,
        public ?int $timeLimit,
        public ?int $limit,
        public ?int $sleep,
        public array $queues,
        public array $extra,
    ) {}

    /**
     * @param list<string> $queues
     * @param list<string> $extra
     */
    public static function create(
        ?int $memoryLimit = null,
        ?int $timeLimit = null,
        ?int $limit = null,
        ?int $sleep = null,
        array $queues = [],
        array $extra = [],
    ): self {
        return new self(
            $memoryLimit,
            $timeLimit,
            $limit,
            $sleep,
            $queues,
            $extra,
        );
    }

    /** @return list<string> */
    public function toCliArguments(): array
    {
        $args = [];

        if ($this->memoryLimit !== null) {
            $args[] = '--memory-limit';
            $args[] = (string) $this->memoryLimit;
        }

        if ($this->timeLimit !== null) {
            $args[] = '--time-limit';
            $args[] = (string) $this->timeLimit;
        }

        if ($this->limit !== null) {
            $args[] = '--limit';
            $args[] = (string) $this->limit;
        }

        if ($this->sleep !== null) {
            $args[] = '--sleep';
            $args[] = (string) $this->sleep;
        }

        foreach ($this->queues as $queue) {
            $args[] = $queue;
        }

        foreach ($this->extra as $flag) {
            $args[] = $flag;
        }

        return $args;
    }
}
