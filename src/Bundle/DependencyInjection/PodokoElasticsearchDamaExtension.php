<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Bundle\DependencyInjection;

use Elastica\Client;
use Podoko\ElasticsearchDama\Reset\CloneResetStrategy;
use Podoko\ElasticsearchDama\Seed\SeedBuilder;
use Podoko\ElasticsearchDama\StaticStateInitializer;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class PodokoElasticsearchDamaExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        if (!$config['enabled']) {
            return;
        }

        $container->setParameter('elasticsearch_dama.elasticsearch_url', $config['elasticsearch_url']);
        $container->setParameter('elasticsearch_dama.managed_indexes', $config['managed_indexes']);
        $container->setParameter('elasticsearch_dama.reset_strategy', $config['reset_strategy']);
        $container->setParameter('elasticsearch_dama.seed_fixtures', $config['seed']['fixtures'] ?? []);

        // -----------------------------------------------------------------------
        // Client admin brut — utilisé par CloneResetStrategy pour les opérations
        // structurelles (clone, delete, refresh seed...). Distinct du client FOSElastica
        // pour ne jamais être redirigé vers les index de travail suffixés.
        // -----------------------------------------------------------------------
        $adminClientDef = new Definition(Client::class);
        $adminClientDef->setArguments([[
            'host' => $config['elasticsearch_url'],
        ]]);
        $container->setDefinition('elasticsearch_dama.admin_client', $adminClientDef);

        // -----------------------------------------------------------------------
        // SeedBuilder
        // -----------------------------------------------------------------------
        $seedBuilderDef = new Definition(SeedBuilder::class);
        $seedBuilderDef->setArguments([
            new Reference('elasticsearch_dama.admin_client'),
            '%elasticsearch_dama.seed_fixtures%',
        ]);
        $container->setDefinition('elasticsearch_dama.seed_builder', $seedBuilderDef);

        // -----------------------------------------------------------------------
        // ResetStrategy (clone par défaut)
        // -----------------------------------------------------------------------
        $this->registerResetStrategy($container, $config);

        // -----------------------------------------------------------------------
        // StaticStateInitializer — alimente StaticState au boot du kernel.
        // -----------------------------------------------------------------------
        $initializerDef = new Definition(StaticStateInitializer::class);
        $initializerDef->setArguments([
            new Reference('elasticsearch_dama.admin_client'),
            '%elasticsearch_dama.managed_indexes%',
            new Reference('elasticsearch_dama.reset_strategy'),
            '%elasticsearch_dama.elasticsearch_url%',
        ]);
        $container->setDefinition('elasticsearch_dama.static_state_initializer', $initializerDef);
    }

    // -----------------------------------------------------------------------
    // Privé
    // -----------------------------------------------------------------------

    private function registerResetStrategy(ContainerBuilder $container, array $config): void
    {
        if ($config['reset_strategy'] === 'clone') {
            $def = new Definition(CloneResetStrategy::class);
            $def->setArguments([
                new Reference('elasticsearch_dama.admin_client'),
                null,  // seedCallback — null = seed vide (les fixtures passent par SeedBuilder)
                [],    // indexMappings
                [],    // indexSettings
            ]);
            $container->setDefinition('elasticsearch_dama.reset_strategy', $def);
        }

        // truncate et recreate seront ajoutés dans une version ultérieure.
    }
}
