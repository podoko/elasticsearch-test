<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Bundle\DependencyInjection\Compiler;

use Podoko\ElasticsearchTest\Bundle\Client\RefreshForcingClient;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * CompilerPass that:
 *   1. Detects FOSElastica indexes configured with use_alias: true and throws an explicit
 *      exception (unsupported — see §4 of the architecture docs).
 *   2. Changes the class of all FOSElastica clients to RefreshForcingClient.
 *   3. Enables index suffixing (setSuffixIndexes(true)) on those clients.
 *   4. Auto-discovers managed indexes when the config list is empty.
 *
 * Actual suffixing happens at runtime, inside RefreshForcingClient::getIndex(),
 * and only when ElasticsearchTestExtension::isBootstrapped() is true —
 * i.e. only under PHPUnit. HTTP requests and Symfony commands
 * in the test environment are not affected.
 */
final class FosClientDecoratorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('elasticsearch_test.managed_indexes')) {
            return;
        }

        // -----------------------------------------------------------------------
        // 1. Fail-fast on use_alias: true
        //    FOSElastica stores each index config in the first argument
        //    of 'fos_elastica.config_source.container'. We read that array directly.
        // -----------------------------------------------------------------------
        $this->assertNoUseAlias($container);

        // -----------------------------------------------------------------------
        // 2. Decorate all FOSElastica clients
        // -----------------------------------------------------------------------
        foreach ($container->findTaggedServiceIds('fos_elastica.client') as $serviceId => $_tags) {
            if (!$container->hasDefinition($serviceId)) {
                continue;
            }

            $def = $container->getDefinition($serviceId);
            $def->setClass(RefreshForcingClient::class);
            $def->addMethodCall('setSuffixIndexes', [true]);
        }

        // -----------------------------------------------------------------------
        // 3. Auto-discover managed indexes when the list is empty
        // -----------------------------------------------------------------------
        /** @var string[] $managedIndexes */
        $managedIndexes = $container->getParameter('elasticsearch_test.managed_indexes');

        if (empty($managedIndexes)) {
            $managedIndexes = $this->discoverAllFosIndexes($container);
            $container->setParameter('elasticsearch_test.managed_indexes', $managedIndexes);
        }

    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    private function assertNoUseAlias(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('fos_elastica.config_source.container')) {
            return;
        }

        $indexConfigs = $container->getDefinition('fos_elastica.config_source.container')->getArgument(0);

        if (!\is_array($indexConfigs)) {
            return;
        }

        $aliasedIndexes = [];

        foreach ($indexConfigs as $indexName => $config) {
            if (!empty($config['use_alias'])) {
                $aliasedIndexes[] = $indexName;
            }
        }

        if (!empty($aliasedIndexes)) {
            throw new \RuntimeException(
                \sprintf(
                    'elasticsearch-test does not support FOSElastica indexes configured with '
                    . '"use_alias: true" (affected index(es): "%s"). '
                    . 'Suffixing getIndex() bypasses FOSElastica\'s alias logic, '
                    . 'which would cause silent errors. '
                    . 'Disable use_alias for these indexes in your test configuration '
                    . '(config/packages/test/fos_elastica.yaml) or exclude them from managed_indexes.',
                    \implode('", "', $aliasedIndexes)
                )
            );
        }
    }

    private function discoverAllFosIndexes(ContainerBuilder $container): array
    {
        $indexes = [];

        foreach ($container->findTaggedServiceIds('fos_elastica.index') as $_serviceId => $tags) {
            foreach ($tags as $tag) {
                if (isset($tag['name'])) {
                    $indexes[] = $tag['name'];
                }
            }
        }

        return $indexes;
    }
}
