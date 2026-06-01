<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Bundle\DependencyInjection\Compiler;

use Podoko\ElasticsearchDama\Bundle\Client\RefreshForcingClient;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * CompilerPass qui :
 *   1. Change la classe de tous les clients FOSElastica en RefreshForcingClient.
 *   2. Active le suffixage des index (setSuffixIndexes(true)) sur ces clients.
 *   3. Auto-découvre les index gérés si la liste est vide dans la configuration.
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
        // 1. Décorer tous les clients FOSElastica
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
        // 2. Auto-découverte des index gérés si la liste est vide
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
