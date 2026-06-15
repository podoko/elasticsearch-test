<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Bundle;

use Podoko\ElasticsearchTest\Bundle\DependencyInjection\Compiler\FosClientDecoratorPass;
use Podoko\ElasticsearchTest\Bundle\DependencyInjection\PodokoElasticsearchTestExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class PodokoElasticsearchTestBundle extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new PodokoElasticsearchTestExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Suffix FOSElastica indexes with the worker token.
        // This pass must run after FOSElasticaBundle compiles.
        $container->addCompilerPass(new FosClientDecoratorPass());
    }

    /**
     * Eagerly instantiates StaticStateInitializer on every kernel boot.
     *
     * StaticStateInitializer::__construct() calls StaticState::initialize(),
     * making StaticState ready before Test\Prepared is dispatched (PHPUnit).
     * Without this forced instantiation, Symfony only creates the service if
     * something explicitly requests it — which is not guaranteed.
     *
     * The service is made public in PodokoElasticsearchTestExtension to
     * allow access from boot().
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->container->has('elasticsearch_test.static_state_initializer')) {
            $this->container->get('elasticsearch_test.static_state_initializer');
        }
    }
}
