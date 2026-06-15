<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration du bundle elasticsearch_dama.
 *
 * Exemple dans config/packages/test/elasticsearch_dama.yaml :
 *
 *   elasticsearch_dama:
 *     enabled: true
 *     elasticsearch_url: '%env(ELASTICSEARCH_URL)%'
 *     managed_indexes:
 *       - posts
 *       - comments
 *     reset_strategy: clone       # clone|truncate|recreate
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('elasticsearch_dama');
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                ->end()
                ->scalarNode('elasticsearch_url')
                    ->defaultValue('%env(ELASTICSEARCH_URL)%')
                    ->info('URL du cluster Elasticsearch (ex: http://localhost:9200).')
                ->end()
                ->arrayNode('managed_indexes')
                    ->info('Liste des noms logiques des index FOSElastica à isoler.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->enumNode('reset_strategy')
                    ->values(['clone', 'truncate', 'recreate'])
                    ->defaultValue('clone')
                    ->info('Stratégie de remise à zéro entre les tests.')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
