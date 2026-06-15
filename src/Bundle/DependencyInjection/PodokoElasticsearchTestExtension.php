<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Bundle\DependencyInjection;

use Elastica\Client;
use Podoko\ElasticsearchTest\Reset\CloneResetStrategy;
use Podoko\ElasticsearchTest\StaticStateInitializer;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class PodokoElasticsearchTestExtension extends Extension
{
    /**
     * Override de l'alias pour correspondre au préfixe YAML "elasticsearch_test:".
     * Sans cette méthode, Symfony calcule "podoko_elasticsearch_test" depuis le nom de classe.
     */
    public function getAlias(): string
    {
        return 'elasticsearch_test';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        if (!$config['enabled']) {
            return;
        }

        $container->setParameter('elasticsearch_test.elasticsearch_url', $config['elasticsearch_url']);
        $container->setParameter('elasticsearch_test.managed_indexes', $config['managed_indexes']);
        $container->setParameter('elasticsearch_test.reset_strategy', $config['reset_strategy']);

        // -----------------------------------------------------------------------
        // Client admin brut — utilisé par CloneResetStrategy pour les opérations
        // structurelles (clone, delete, refresh…). Distinct du client FOSElastica
        // pour ne jamais être redirigé vers les index de travail suffixés.
        //
        // Elastica v7 Transport\Http construit l'URL finale par concaténation :
        //   baseUri . requestPath
        // → la baseUri DOIT se terminer par '/' pour éviter des URL incorrectes.
        // -----------------------------------------------------------------------
        $adminClientDef = new Definition(Client::class);
        $adminClientDef->setArguments([[
            'url' => $config['elasticsearch_url'] . '/',
        ]]);
        $container->setDefinition('elasticsearch_test.admin_client', $adminClientDef);

        // -----------------------------------------------------------------------
        // ResetStrategy (clone par défaut)
        // -----------------------------------------------------------------------
        $this->registerResetStrategy($container, $config);

        // -----------------------------------------------------------------------
        // StaticStateInitializer — alimente StaticState au boot du kernel.
        //
        // Rendu public pour que PodokoElasticsearchTestBundle::boot() puisse le
        // récupérer via $this->container->get(...) et forcer son instanciation
        // (le constructeur appelle StaticState::initialize()).
        // -----------------------------------------------------------------------
        $initializerDef = new Definition(StaticStateInitializer::class);
        $initializerDef->setPublic(true);
        $initializerDef->setArguments([
            new Reference('elasticsearch_test.admin_client'),
            '%elasticsearch_test.managed_indexes%',
            new Reference('elasticsearch_test.reset_strategy'),
            '%elasticsearch_test.elasticsearch_url%',
        ]);
        $container->setDefinition('elasticsearch_test.static_state_initializer', $initializerDef);
    }

    // -----------------------------------------------------------------------
    // Privé
    // -----------------------------------------------------------------------

    private function registerResetStrategy(ContainerBuilder $container, array $config): void
    {
        if ($config['reset_strategy'] === 'clone') {
            $def = new Definition(CloneResetStrategy::class);
            $def->setArguments([
                new Reference('elasticsearch_test.admin_client'),
            ]);
            $container->setDefinition('elasticsearch_test.reset_strategy', $def);
        }

        // truncate et recreate seront ajoutés dans une version ultérieure.
    }
}
