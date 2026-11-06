<?php

declare(strict_types=1);

namespace TacticMedia\Scim\Tests\Tooling;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

final class SymfonyPinTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function projects(): iterable
    {
        yield 'every component pinned' => ['pinned', 0, 'Every locked Symfony 8 component has the conflict ">=8.2".'];
        yield 'a component without a pin' => ['unpinned', 1, 'Add these packages to "conflict" with ">=8.2": symfony/uid'];
        yield 'no reference pin' => ['no-reference', 1, 'composer.json has no "conflict" entry for symfony/http-kernel.'];
    }

    #[Test]
    #[TestDox('bin/symfony-pin exits with $expectedExitCode for $_dataName')]
    #[DataProvider('projects')]
    public function exitCode(string $project, int $expectedExitCode, string $expectedOutput): void
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/bin/symfony-pin', __DIR__.'/Fixtures/symfony-pin/'.$project]);
        $process->run();

        $this->assertSame($expectedExitCode, $process->getExitCode());
        $this->assertStringContainsString($expectedOutput, $process->getOutput().$process->getErrorOutput());
    }
}
