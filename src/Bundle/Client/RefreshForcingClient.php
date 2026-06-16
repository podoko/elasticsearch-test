<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Bundle\Client;

use Elastica\Index as BaseIndex;
use FOS\ElasticaBundle\Elastica\Client as FosClient;
use Podoko\ElasticsearchTest\PHPUnit\ElasticsearchTestExtension;

/**
 * FOSElastica client subclass that suffixes getIndex() with the PHPUnit worker token.
 *
 * Extends FOS\ElasticaBundle\Elastica\Client (not Elastica\Client) to preserve:
 * - the index cache ($indexCache) avoiding Index object recreation
 * - the Stopwatch integration (Symfony profiler)
 * - Symfony events (PreElasticaRequestEvent, PostElasticaRequestEvent…)
 * - ElasticaLogger logging
 *
 * Suffixing is only active when ElasticsearchTestExtension::isBootstrapped()
 * is true — only under PHPUnit. An HTTP request or a Symfony command in
 * APP_ENV=test does not trigger bootstrap → normal behavior without redirection.
 *
 * Not final to allow projects with a custom client to subclass.
 */
class RefreshForcingClient extends FosClient
{
    /**
     * Enables index suffixing for this client.
     * Set to true by FosClientDecoratorPass on FOSElastica clients.
     */
    private bool $suffixIndexes = false;

    public function setSuffixIndexes(bool $suffix): void
    {
        $this->suffixIndexes = $suffix;
    }

    /**
     * Returns a LazyCloneIndex when suffixing is active, a regular index otherwise.
     *
     * LazyCloneIndex points to the source (posts) by default and only creates the worker
     * clone (posts_<token>) on the first write. This avoids cloning all indexes at the
     * start of each test, including those the test never writes to.
     *
     * Activation conditions:
     *   1. This client is configured to suffix ($suffixIndexes = true).
     *   2. The PHPUnit extension is bootstrapped (running under PHPUnit/ParaTest).
     */
    public function getIndex(string $name): BaseIndex
    {
        if ($this->suffixIndexes && ElasticsearchTestExtension::isBootstrapped()) {
            return new LazyCloneIndex($name, $this);
        }

        return parent::getIndex($name);
    }
}
