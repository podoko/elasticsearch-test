<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Bundle\DependencyInjection\Compiler;

use Podoko\ElasticsearchDama\Bundle\Client\RefreshForcingClient;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * CompilerPass qui :
 *   1. Détecte les index FOSElastica configurés avec use_alias: true et lève une exception
 *      explicite (comportement non supporté — voir §4 de l'architecture).
 *   2. Change la classe de tous les clients FOSElastica en RefreshForcingClient.
 *   3. Active le suffixage des index (setSuffixIndexes(true)) sur ces clients.
 *   4. Auto-découvre les index gérés si la liste est vide dans la configuration.
 *
 * Le suffixage effectif n'a lieu qu'au runtime, dans RefreshForcingClient::getIndex(),
 * et uniquement quand ElasticsearchDamaExtension::isBootstrapped() est true —
 * c'est-à-dire uniquement sous PHPUnit. Les requêtes HTTP et commandes Symfony
 * en env de test ne sont pas affectées.
 */
final class FosClientDecoratorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('elasticsearch_dama.managed_indexes')) {
            return;
        }

        // -----------------------------------------------------------------------
        // 1. Fail-fast sur use_alias: true
        //    FOSElastica stocke la config de chaque index dans le premier argument
        //    de 'fos_elastica.config_source.container'. On lit ce tableau directement.
        // -----------------------------------------------------------------------
        $this->assertNoUseAlias($container);

        // -----------------------------------------------------------------------
        // 2. Décorer tous les clients FOSElastica
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
        // 3. Auto-découverte des index gérés si la liste est vide
        // -----------------------------------------------------------------------
        /** @var string[] $managedIndexes */
        $managedIndexes = $container->getParameter('elasticsearch_dama.managed_indexes');

        if (empty($managedIndexes)) {
            $managedIndexes = $this->discoverAllFosIndexes($container);
            $container->setParameter('elasticsearch_dama.managed_indexes', $managedIndexes);
        }
    }

    // -----------------------------------------------------------------------
    // Privé
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
                    'elasticsearch-dama ne supporte pas les index FOSElastica configurés avec '
                    . '"use_alias: true" (index concerné(s) : "%s"). '
                    . 'Le suffixage de getIndex() bypass la logique d\'alias de FOSElastica, '
                    . 'ce qui entraînerait des erreurs silencieuses. '
                    . 'Désactivez use_alias pour ces index dans votre configuration de test '
                    . '(config/packages/test/fos_elastica.yaml) ou excluez-les de managed_indexes.',
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
