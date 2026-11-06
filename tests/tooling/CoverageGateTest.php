<?php

declare(strict_types=1);

namespace TacticMedia\Scim\Tests\Tooling;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class CoverageGateTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{list<string>, int, string}>
     */
    public static function reports(): iterable
    {
        yield 'at the minimum' => [[__DIR__.'/Fixtures/clover-95.xml', '95'], 0, 'Line coverage: 95.00% (95 of 100 statements). Minimum: 95%.'];
        yield 'below the minimum' => [[__DIR__.'/Fixtures/clover-94.xml', '95'], 1, 'Line coverage: 94.00% (94 of 100 statements). Minimum: 95%.'];
        yield 'missing report' => [[__DIR__.'/Fixtures/missing.xml', '95'], 2, 'is not a Clover report.'];
        yield 'missing minimum' => [[__DIR__.'/Fixtures/clover-95.xml'], 2, 'Usage: bin/coverage'];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[TestDox('bin/coverage exits with $expectedExitCode for $_dataName')]
    #[DataProvider('reports')]
    public function exitCode(array $arguments, int $expectedExitCode, string $expectedOutput): void
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/bin/coverage', ...$arguments]);
        $process->run();

        $this->assertSame($expectedExitCode, $process->getExitCode());
        $this->assertStringContainsString($expectedOutput, $process->getOutput().$process->getErrorOutput());
    }
}
