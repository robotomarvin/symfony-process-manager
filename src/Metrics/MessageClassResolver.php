<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Metrics;

final class MessageClassResolver
{
    public const FALLBACK = 'other';

    /** @var list<string> */
    private array $exact = [];

    /** @var list<string> */
    private array $globs = [];

    private readonly bool $autoMode;

    /**
     * @param list<string> $whitelist
     */
    public function __construct(array $whitelist = [])
    {
        foreach ($whitelist as $entry) {
            if (self::isGlob($entry)) {
                $this->globs[] = $entry;
            } else {
                $this->exact[] = $entry;
            }
        }

        $this->autoMode = $whitelist === [];
    }

    public function resolve(string $messageClass): string
    {
        if ($this->autoMode) {
            return $messageClass;
        }

        if (in_array($messageClass, $this->exact, true)) {
            return $messageClass;
        }

        foreach ($this->globs as $glob) {
            if (fnmatch($glob, $messageClass, \FNM_NOESCAPE)) {
                return $messageClass;
            }
        }

        return self::FALLBACK;
    }

    private static function isGlob(string $entry): bool
    {
        return str_contains($entry, '*') || str_contains($entry, '?');
    }
}
