<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Metrics\MessageClassResolver;

#[CoversClass(MessageClassResolver::class)]
final class MessageClassResolverTest extends TestCase
{
    public function testEmptyWhitelistReturnsFqcn(): void
    {
        $resolver = new MessageClassResolver();

        self::assertSame('App\\Message\\Foo', $resolver->resolve('App\\Message\\Foo'));
    }

    public function testExactMatchReturnsFqcn(): void
    {
        $resolver = new MessageClassResolver(['App\\Message\\Foo']);

        self::assertSame('App\\Message\\Foo', $resolver->resolve('App\\Message\\Foo'));
    }

    public function testExactMissReturnsOther(): void
    {
        $resolver = new MessageClassResolver(['App\\Message\\Foo']);

        self::assertSame('other', $resolver->resolve('App\\Message\\Bar'));
    }

    public function testGlobMatchReturnsFqcn(): void
    {
        $resolver = new MessageClassResolver(['App\\Message\\Email\\*']);

        self::assertSame('App\\Message\\Email\\WelcomeMail', $resolver->resolve('App\\Message\\Email\\WelcomeMail'));
    }

    public function testGlobMissReturnsOther(): void
    {
        $resolver = new MessageClassResolver(['App\\Message\\Email\\*']);

        self::assertSame('other', $resolver->resolve('App\\Message\\Sms\\Verify'));
    }

    public function testQuestionMarkGlobIsSupported(): void
    {
        $resolver = new MessageClassResolver(['App\\Message\\Foo?']);

        self::assertSame('App\\Message\\Foo1', $resolver->resolve('App\\Message\\Foo1'));
        self::assertSame('other', $resolver->resolve('App\\Message\\Foo12'));
    }

    public function testMixedExactAndGlobEntries(): void
    {
        $resolver = new MessageClassResolver([
            'App\\Message\\Foo',
            'App\\Message\\Email\\*',
        ]);

        self::assertSame('App\\Message\\Foo', $resolver->resolve('App\\Message\\Foo'));
        self::assertSame('App\\Message\\Email\\X', $resolver->resolve('App\\Message\\Email\\X'));
        self::assertSame('other', $resolver->resolve('App\\Message\\Bar'));
    }

    public function testFallbackConstantIsOther(): void
    {
        self::assertSame('other', MessageClassResolver::FALLBACK);
    }
}
