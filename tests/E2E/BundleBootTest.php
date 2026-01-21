<?php

namespace SymfonyProcessManager\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use SymfonyProcessManager\SymfonyProcessManagerBundle;
use SymfonyProcessManager\Tests\Fixtures\App\Kernel;

#[CoversClass(SymfonyProcessManagerBundle::class)]
final class BundleBootTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        require_once __DIR__ . '/../Fixtures/app/Kernel.php';

        return Kernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $options['environment'] = $options['environment'] ?? 'test';
        $options['debug'] = $options['debug'] ?? false;

        $class = static::getKernelClass();

        /** @var class-string<KernelInterface> $class */

        return new $class($options['environment'], $options['debug']);
    }

    public function testKernelBootsWithBundle(): void
    {
        $peekHandler = static function (): callable|array|null {
            $handler = set_exception_handler(static function (
                \Throwable $throwable
            ): void {
            });
            restore_exception_handler();

            return $handler;
        };

        $peekErrorHandler = static function (): callable|array|null {
            $handler = set_error_handler(static function (
                int $errorNumber,
                string $errorMessage,
                string $errorFile,
                int $errorLine
            ): bool {
                return false;
            });
            restore_error_handler();

            return $handler;
        };

        $initialHandler = $peekHandler();
        $initialErrorHandler = $peekErrorHandler();

        self::bootKernel();

        try {
            $kernel = self::$kernel;
            self::assertNotNull($kernel);
            $bundles = $kernel->getBundles();
            self::assertArrayHasKey('SymfonyProcessManagerBundle', $bundles);
            self::assertInstanceOf(SymfonyProcessManagerBundle::class, $bundles['SymfonyProcessManagerBundle']);
        } finally {
            if (self::$kernel !== null) {
                self::$kernel->shutdown();
            }

            self::$kernel = null;
            self::$booted = false;

            $currentHandler = $peekHandler();
            if ($currentHandler !== $initialHandler) {
                if ($initialHandler !== null) {
                    set_exception_handler($initialHandler);
                } else {
                    restore_exception_handler();
                }
            }

            $currentErrorHandler = $peekErrorHandler();
            if ($currentErrorHandler !== $initialErrorHandler) {
                if ($initialErrorHandler !== null) {
                    set_error_handler($initialErrorHandler);
                } else {
                    restore_error_handler();
                }
            }
        }
    }
}
