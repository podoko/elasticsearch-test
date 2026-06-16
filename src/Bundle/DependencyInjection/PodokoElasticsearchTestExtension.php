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
     * Overrides the alias to match the YAML prefix "elasticsearch_test:".
     * Without this method, Symfony derives "podoko_elasticsearch_test" from the class name.
     */
    public function getAlias(): string
    {
        return 'elasticsearch_test';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        if (!$config['enabled']) {
            return;
        }

        $container->setParameter('elasticsearch_test.elasticsearch_url', $config['elasticsearch_url']);
        $container->setParameter('elasticsearch_test.managed_indexes', $config['managed_indexes']);
        $container->setParameter('elasticsearch_test.reset_strategy', $config['reset_strategy']);

        // -----------------------------------------------------------------------
        // Raw admin client — used by CloneResetStrategy for structural operations
        // (clone, delete, refresh…). Separate from the FOSElastica client
        // to never be redirected to the suffixed worker indexes.
        //
        // Elastica v7 Transport\Http builds the final URL by concatenation:
        //   baseUri . requestPath
        // → baseUri MUST end with '/' to avoid malformed URLs.
        // -----------------------------------------------------------------------
        $adminClientDef = new Definition(Client::class);
        $adminClientDef->setArguments([[
            'url' => $config['elasticsearch_url'].'/',
        ]]);
        $container->setDefinition('elasticsearch_test.admin_client', $adminClientDef);

        // -----------------------------------------------------------------------
        // ResetStrategy (clone by default)
        // -----------------------------------------------------------------------
        $this->registerResetStrategy($container, $config);

        // -----------------------------------------------------------------------
        // StaticStateInitializer — populates StaticState at kernel boot.
        //
        // Made public so PodokoElasticsearchTestBundle::boot() can
        // retrieve it via $this->container->get(...) and force its instantiation
        // (the constructor calls StaticState::initialize()).
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
    // Private
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $config */
    private function registerResetStrategy(ContainerBuilder $container, array $config): void
    {
        if ('clone' === $config['reset_strategy']) {
            $def = new Definition(CloneResetStrategy::class);
            $def->setArguments([
                new Reference('elasticsearch_test.admin_client'),
            ]);
            $container->setDefinition('elasticsearch_test.reset_strategy', $def);
        }

        // truncate and recreate will be added in a future version.
    }
}
