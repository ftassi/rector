<?php

declare(strict_types=1);

namespace Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\Fixture;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symplify\PackageBuilder\Parameter\ParameterProvider;

final class ContainerGet
{
    /**
     * @var ParameterProvider
     */
    protected $parameterProvider;

    /**
     * @var ContainerInterface|Container
     */
    protected static $container;

    protected function setUp(): void
    {
        $this->parameterProvider = static::$container->get(ParameterProvider::class);
    }
}
