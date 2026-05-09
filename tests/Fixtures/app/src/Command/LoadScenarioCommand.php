<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use SymfonyProcessManager\Tests\Fixtures\App\Message\FixtureMessage;
use SymfonyProcessManager\Tests\Fixtures\App\Message\ScalableMessage;

#[AsCommand(
    name: 'fixture:load',
    description: 'Generate manual-test load: drives both async and scalable transports under named scenarios.',
)]
final class LoadScenarioCommand extends Command
{
    private const SCENARIOS = ['steady', 'burst', 'ramp', 'failures'];

    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'scenario',
            null,
            InputOption::VALUE_REQUIRED,
            'Preset: steady | burst | ramp | failures.',
            'steady',
        );
        $this->addOption(
            'duration',
            null,
            InputOption::VALUE_REQUIRED,
            'Override scenario duration in seconds.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawScenario = $input->getOption('scenario');
        $scenarioOption = is_string($rawScenario) ? $rawScenario : 'steady';
        if (!in_array($scenarioOption, self::SCENARIOS, true)) {
            $output->writeln(sprintf(
                '<error>Unknown scenario "%s". Pick one of: %s</error>',
                $scenarioOption,
                implode(', ', self::SCENARIOS),
            ));
            return Command::INVALID;
        }

        $durationOption = $input->getOption('duration');
        $durationOverride = is_numeric($durationOption) ? max(1, (int) $durationOption) : null;

        $output->writeln(sprintf('<info>Running scenario:</info> %s', $scenarioOption));

        // Suppress signals — Ctrl-C should still stop the dispatcher.
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => exit(0));
            pcntl_signal(SIGINT, fn() => exit(0));
        }

        match ($scenarioOption) {
            'steady'   => $this->runSteady($output, $durationOverride ?? 60),
            'burst'    => $this->runBurst($output, $durationOverride ?? 90),
            'ramp'     => $this->runRamp($output, $durationOverride ?? 60),
            'failures' => $this->runFailures($output, $durationOverride ?? 60),
        };

        $output->writeln('<info>Scenario complete.</info>');
        return Command::SUCCESS;
    }

    private function runSteady(OutputInterface $output, int $duration): void
    {
        // 4 msg/s split 50/50 across transports, scalable handler sleeps 1s, ~5% failures.
        $this->driveRate(
            output: $output,
            duration: $duration,
            ratePerSecond: 4.0,
            asyncShare: 0.5,
            scalableSleep: 1.0,
            failureRatio: 0.05,
        );
    }

    private function runBurst(OutputInterface $output, int $duration): void
    {
        // Repeat: dump 100 messages (50 each transport), then idle ~30s.
        $cycle = 30;
        $cycles = max(1, (int) ceil($duration / $cycle));
        for ($i = 0; $i < $cycles; $i++) {
            $output->writeln(sprintf('  burst %d/%d: dispatching 100 msgs', $i + 1, $cycles));
            $this->dispatchAsync(50, withFailures: true, failureRatio: 0.1);
            $this->dispatchScalable(50, sleepSeconds: 1.5, failureRatio: 0.1);
            $output->writeln(sprintf('  idle %ds…', $cycle));
            $this->sleepSeconds($cycle);
        }
    }

    private function runRamp(OutputInterface $output, int $duration): void
    {
        // Linear ramp from 1 msg/s to 10 msg/s over duration. Recompute rate every 5s tick.
        $start = microtime(true);
        $end = $start + $duration;
        $tickSeconds = 5;

        while (microtime(true) < $end) {
            $elapsed = microtime(true) - $start;
            $progress = min(1.0, $elapsed / $duration);
            $rate = 1.0 + 9.0 * $progress;
            $remaining = max(1, (int) ceil($end - microtime(true)));
            $tick = min($tickSeconds, $remaining);

            $output->writeln(sprintf('  t=%.0fs rate=%.2f msg/s', $elapsed, $rate));

            $this->driveRate(
                output: null,
                duration: $tick,
                ratePerSecond: $rate,
                asyncShare: 0.5,
                scalableSleep: 1.0,
                failureRatio: 0.05,
            );
        }
    }

    private function runFailures(OutputInterface $output, int $duration): void
    {
        // 5 msg/s, 30% failures across both transports — drives failed/retried counters.
        $this->driveRate(
            output: $output,
            duration: $duration,
            ratePerSecond: 5.0,
            asyncShare: 0.5,
            scalableSleep: 0.5,
            failureRatio: 0.3,
        );
    }

    private function driveRate(
        ?OutputInterface $output,
        int $duration,
        float $ratePerSecond,
        float $asyncShare,
        float $scalableSleep,
        float $failureRatio,
    ): void {
        if ($ratePerSecond <= 0.0) {
            return;
        }
        $intervalMicros = (int) round(1_000_000 / $ratePerSecond);
        $end = microtime(true) + $duration;
        $count = 0;

        while (microtime(true) < $end) {
            if ($this->randomFloat() < $asyncShare) {
                $this->dispatchAsync(1, withFailures: true, failureRatio: $failureRatio);
            } else {
                $this->dispatchScalable(1, sleepSeconds: $scalableSleep, failureRatio: $failureRatio);
            }

            $count++;
            if ($output !== null && $count % 20 === 0) {
                $output->writeln(sprintf('  …%d msgs dispatched', $count));
            }

            usleep($intervalMicros);
        }

        if ($output !== null) {
            $output->writeln(sprintf('  total: %d msgs', $count));
        }
    }

    private function dispatchAsync(int $count, bool $withFailures, float $failureRatio): void
    {
        for ($i = 0; $i < $count; $i++) {
            $payload = ($withFailures && $this->randomFloat() < $failureRatio)
                ? 'fail'
                : sprintf('msg-%d', $i);
            $this->bus->dispatch(new FixtureMessage($payload));
        }
    }

    private function dispatchScalable(int $count, float $sleepSeconds, float $failureRatio): void
    {
        for ($i = 0; $i < $count; $i++) {
            $shouldFail = $this->randomFloat() < $failureRatio;
            $this->bus->dispatch(new ScalableMessage($sleepSeconds, $shouldFail));
        }
    }

    private function sleepSeconds(int $seconds): void
    {
        $end = microtime(true) + $seconds;
        while (microtime(true) < $end) {
            usleep(200_000);
        }
    }

    private function randomFloat(): float
    {
        return random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
    }
}
