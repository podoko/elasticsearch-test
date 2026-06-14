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

    /**
     * Instancie StaticStateInitializer de façon eager à chaque boot du kernel.
     *
     * StaticStateInitializer::__construct() appelle StaticState::initialize(),
     * ce qui rend StaticState prêt avant l'émission de Test\Prepared (PHPUnit).
     * Sans cette instanciation forcée, Symfony ne crée le service que si quelque
     * chose le demande explicitement — ce qui n'est pas garanti.
     *
     * Le service est rendu public dans PodokoElasticsearchDamaExtension pour
     * permettre cet accès depuis boot().
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->container->has('elasticsearch_dama.static_state_initializer')) {
            $this->container->get('elasticsearch_dama.static_state_initializer');
        }
    }
}
