<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\VarExporter\DeepCloner;
use TacticMedia\ScimServerBundle\DependencyInjection\Compiler\RepositoryPass;

/**
 * Keeps the definitions, aliases, repository tags and parameters as they are after RepositoryPass.
 */
final class RecordingPass implements CompilerPassInterface
{
    /** @var array<string, Definition> */
    public array $definitions = [];

    /** @var array<string, string> */
    public array $aliases = [];

    /** @var array<string, array<array<string, mixed>>> */
    public array $tagged = [];

    /** @var array<string, mixed> */
    public array $parameters = [];

    public function process(ContainerBuilder $container): void
    {
        $this->definitions = array_map(static fn (Definition $definition): Definition => new DeepCloner($definition)->cloneAs($definition::class), $container->getDefinitions());
        $this->aliases = array_map(strval(...), $container->getAliases());
        $this->tagged = $container->findTaggedServiceIds(RepositoryPass::REPOSITORY_TAG);
        $this->parameters = $container->getParameterBag()->all();
    }

    public function definition(string $id): Definition
    {
        return $this->definitions[$id] ?? throw new \LogicException(\sprintf('No definition %s.', $id));
    }
}
