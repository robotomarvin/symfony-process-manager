<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use React\EventLoop\Loop;
use SymfonyProcessManager\Command\Serve\ConsumeArgs;
use SymfonyProcessManager\Command\Serve\HttpServer;
use SymfonyProcessManager\Command\Serve\ProcessManagerLoop;
use SymfonyProcessManager\Command\Serve\TransportConfig;
use SymfonyProcessManager\Command\Serve\WorkerOutputHandler;
use SymfonyProcessManager\Command\Serve\WorkerProcessFactoryInterface;

#[AsCommand(
    name: 'pm:serve',
    description: 'Run the process manager server.',
)]
final class ServeCommand extends Command
{
    /** @var list<TransportConfig> */
    private readonly array $resolvedTransportConfigs;

    /**
     * @param array<string, array{
     *     processes: int,
     *     failure_limit: int,
     *     failure_window: int,
     *     backoff_base: int,
     *     backoff_max: int,
     *     poll_interval_ms: int,
     *     consume_args: array{
     *         memory_limit: int|null,
     *         time_limit: int|null,
     *         limit: int|null,
     *         sleep: int|null,
     *         queues: list<string>,
     *         extra: list<string>,
     *     },
     * }> $transportConfigs
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly WorkerProcessFactoryInterface $processFactory,
        private readonly WorkerOutputHandler $outputHandler,
        private readonly HttpServer $httpServer,
        array $transportConfigs,
        private readonly string $httpHost = '127.0.0.1',
        private readonly int $httpPort = 9100,
    ) {
        $this->resolvedTransportConfigs = self::buildTransportConfigs($transportConfigs);
        parent::__construct();
    }

    /**
     * @param array<string, array{
     *     processes: int,
     *     failure_limit: int,
     *     failure_window: int,
     *     backoff_base: int,
     *     backoff_max: int,
     *     poll_interval_ms: int,
     *     consume_args: array{
     *         memory_limit: int|null,
     *         time_limit: int|null,
     *         limit: int|null,
     *         sleep: int|null,
     *         queues: list<string>,
     *         extra: list<string>,
     *     },
     * }> $transports
     * @return list<TransportConfig>
     */
    private static function buildTransportConfigs(array $transports): array
    {
        $configs = [];

        foreach ($transports as $name => $transport) {
            $consumeArgs = $transport['consume_args'];

            $configs[] = TransportConfig::create(
                transport: $name,
                processes: $transport['processes'],
                failureLimit: $transport['failure_limit'],
                failureWindowSeconds: $transport['failure_window'],
                backoffBaseSeconds: $transport['backoff_base'],
                backoffMaxSeconds: $transport['backoff_max'],
                pollIntervalMs: $transport['poll_interval_ms'],
                consumeArgs: ConsumeArgs::create(
                    memoryLimit: $consumeArgs['memory_limit'],
                    timeLimit: $consumeArgs['time_limit'],
                    limit: $consumeArgs['limit'],
                    sleep: $consumeArgs['sleep'],
                    queues: $consumeArgs['queues'],
                    extra: $consumeArgs['extra'],
                ),
            );
        }

        return $configs;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        unset($input, $output);

        $loop = new ProcessManagerLoop(
            $this->clock,
            $this->logger,
            $this->processFactory,
            $this->outputHandler,
            $this->resolvedTransportConfigs,
        );

        $eventLoop = Loop::get();

        $this->httpServer->start($eventLoop, $this->httpHost, $this->httpPort);

        return $loop->run($eventLoop);
    }
}
