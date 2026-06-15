<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional\App;

use FOS\ElasticaBundle\FOSElasticaBundle;
use Podoko\ElasticsearchTest\Bundle\PodokoElasticsearchTestBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

/**
 * Minimal kernel for the library's functional tests.
 *
 * Stack: FrameworkBundle + FOSElasticaBundle + PodokoElasticsearchTestBundle + ZenstruckFoundryBundle.
 * No Doctrine — data is pushed directly into Elasticsearch
 * via the FOSElastica client (index.addDocuments()), with Foundry in ObjectFactory mode.
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
            new PodokoElasticsearchTestBundle(),
            new ZenstruckFoundryBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__ . '/config/framework.yaml');
        $loader->load(__DIR__ . '/config/fos_elastica.yaml');
        $loader->load(__DIR__ . '/config/elasticsearch_test.yaml');
        $loader->load(__DIR__ . '/config/services.yaml');
    }

    protected function build(ContainerBuilder $container): void
    {
        // Make all services public in test mode for easy access
        // from test cases via $this->getContainer()->get(...)
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
