<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Functional\App;

use FOS\ElasticaBundle\FOSElasticaBundle;
use Podoko\ElasticsearchDama\Bundle\PodokoElasticsearchDamaBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

/**
 * Kernel minimal pour les tests fonctionnels de la lib.
 *
 * Stack : FrameworkBundle + FOSElasticaBundle + PodokoElasticsearchDamaBundle + ZenstruckFoundryBundle.
 * Pas de Doctrine — les données sont poussées directement dans Elasticsearch
 * via le client FOSElastica (index.addDocuments()), avec Foundry en mode ObjectFactory.
 */
final class Kernel extends BaseKernel
{
    public function __construct()
    {
        parent::__construct('test', true);
    }

    /** @return BundleInterface[] */
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new FOSElasticaBundle(),
            new PodokoElasticsearchDamaBundle(),
            new ZenstruckFoundryBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__ . '/config/framework.yaml');
        $loader->load(__DIR__ . '/config/fos_elastica.yaml');
        $loader->load(__DIR__ . '/config/elasticsearch_dama.yaml');
    }

    protected function build(ContainerBuilder $container): void
    {
        // Rend tous les services publics en mode test pour un accès simplifié
        // depuis les cas de test via $this->getContainer()->get(...)
        $container->setParameter('kernel.secret', 'test-secret-for-functional-tests');
    }

    public function getCacheDir(): string
    {
        return __DIR__ . '/var/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return __DIR__ . '/var/log';
    }
}
