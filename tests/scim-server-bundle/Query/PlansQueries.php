<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Query;

use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\NativeConverter;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\QueryPlanner;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Device;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\TagConverter;
use TacticMedia\ScimServerBundle\Tests\Query\Fixtures\Contact;

/**
 * Plans filters as the server passes them: parsed and resolved against the published resource type.
 */
trait PlansQueries
{
    private ?MappingRegistry $registry = null;

    private function mapping(string $name): ResourceMapping
    {
        $this->registry ??= new MappingRegistry(new AttributeLoader(), ['User' => User::class, 'Group' => Group::class, 'Device' => Device::class, 'Contact' => Contact::class]);

        return $this->registry->get($name);
    }

    private function plan(ListQuery $query, string $name): ObjectQuery|false
    {
        $mapping = $this->mapping($name);

        $converters = new ServiceLocator([
            NativeConverter::class => static fn (): NativeConverter => new NativeConverter(),
            IdConverter::class => static fn (): IdConverter => new IdConverter(),
            TagConverter::class => static fn (): TagConverter => new TagConverter(),
        ]);

        return new QueryPlanner($this->registry ?? throw new \LogicException('No registry.'), $converters)->plan($query, $mapping);
    }

    private function resolved(string $filter, string $name): Filter
    {
        return new FilterResolver()->resolve(new FilterParser()->parse($filter), $this->mapping($name)->resourceType);
    }

    private function path(string $path, string $name): AttributePath
    {
        $parsed = AttributePath::parse($path) ?? throw new \LogicException(\sprintf('"%s" is not a path.', $path));

        return $this->mapping($name)->resourceType->resolve($parsed)?->path() ?? throw new \LogicException(\sprintf('"%s" does not resolve.', $path));
    }
}
