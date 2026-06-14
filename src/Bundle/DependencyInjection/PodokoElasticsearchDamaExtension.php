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
    /**
     * Override de l'alias pour correspondre au préfixe YAML "elasticsearch_dama:".
     * Sans cette méthode, Symfony calcule "podoko_elasticsearch_dama" depuis le nom de classe.
     */
    public function getAlias(): string
    {
        return 'elasticsearch_dama';
    }

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
        //
        // Elastica v7 Transport\Http construit l'URL finale par concaténation :
        //   baseUri . requestPath
        // → la baseUri DOIT se terminer par '/' pour éviter des URL incorrectes.
        // -----------------------------------------------------------------------
        $adminClientDef = new Definition(Client::class);
        $adminClientDef->setArguments([[
            // Le trailing '/' est obligatoire : Elastica concatène directement requestPath
            // à cette baseUri (ex: 'http://localhost:9200' + 'posts_seed' sans slash).
            // Symfony résout %env(ELASTICSEARCH_URL)% à runtime → 'http://localhost:9200/'
            'url' => $config['elasticsearch_url'] . '/',
        ]]);
        $container->setDefinition('elasticsearch_dama.admin_client', $adminClientDef);

        // -----------------------------------------------------------------------
        // SeedBuilder — construit le callback de peuplement des seeds depuis les
        // fixtures déclarées dans elasticsearch_dama.yaml.
        // -----------------------------------------------------------------------
        $seedBuilderDef = new Definition(SeedBuilder::class);
        $seedBuilderDef->setArguments([
            new Reference('elasticsearch_dama.admin_client'),
            '%elasticsearch_dama.seed_fixtures%',
        ]);
        $container->setDefinition('elasticsearch_dama.seed_builder', $seedBuilderDef);

        // -----------------------------------------------------------------------
        // seed_callback — Closure retournée par SeedBuilder::buildGlobalCallback().
        // Définie comme service "factory" : Symfony appelle la méthode à l'instanciation
        // et stocke le Closure résultant. Injectée dans CloneResetStrategy.
        // -----------------------------------------------------------------------
        $seedCallbackDef = new Definition(\Closure::class);
        $seedCallbackDef->setFactory([new Reference('elasticsearch_dama.seed_builder'), 'buildGlobalCallback']);
        $container->setDefinition('elasticsearch_dama.seed_callback', $seedCallbackDef);

        // -----------------------------------------------------------------------
        // ResetStrategy (clone par défaut)
        // -----------------------------------------------------------------------
        $this->registerResetStrategy($container, $config);

        // -----------------------------------------------------------------------
        // StaticStateInitializer — alimente StaticState au boot du kernel.
        //
        // Rendu public pour que PodokoElasticsearchDamaBundle::boot() puisse le
        // récupérer via $this->container->get(...) et forcer son instanciation
        // (le constructeur appelle StaticState::initialize()).
        // -----------------------------------------------------------------------
        $initializerDef = new Definition(StaticStateInitializer::class);
        $initializerDef->setPublic(true);
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
                new Reference('elasticsearch_dama.seed_callback'),  // A2 : callback branché
                [],    // indexMappings — injecté par FosClientDecoratorPass (A3)
                [],    // indexSettings — injecté par FosClientDecoratorPass (A3)
            ]);
            $container->setDefinition('elasticsearch_dama.reset_strategy', $def);
        }

        // truncate et recreate seront ajoutés dans une version ultérieure.
    }
}
