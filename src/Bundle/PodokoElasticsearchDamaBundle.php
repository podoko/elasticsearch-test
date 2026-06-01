<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Bundle;

use Podoko\ElasticsearchDama\Bundle\DependencyInjection\Compiler\FosClientDecoratorPass;
use Podoko\ElasticsearchDama\Bundle\DependencyInjection\PodokoElasticsearchDamaExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class PodokoElasticsearchDamaBundle extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new PodokoElasticsearchDamaExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Suffixage des index FOSElastica avec le token worker.
        // Ce pass doit s'exécuter après la compilation de FOSElasticaBundle.
        $container->addCompilerPass(new FosClientDecoratorPass());
    }
}
