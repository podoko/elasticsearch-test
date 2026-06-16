<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Bundle\Client;

use Elastica\Bulk\ResponseSet;
use Elastica\Document;
use Elastica\Response;
use Elastica\Script\AbstractScript;
use FOS\ElasticaBundle\Elastica\Index as FosIndex;
use Podoko\ElasticsearchTest\StaticState;
use Podoko\ElasticsearchTest\TestToken;

/**
 * Lazy-clone index proxy.
 *
 * By default, routes all operations to the pre-existing source index (posts).
 * On the first write operation, triggers cloning via StaticState::copy()
 * then routes all operations (reads and writes) to the worker clone (posts_<token>).
 *
 * Intentionally stateless: clone state is centralized in StaticState::$copiedIndexes
 * so that it is shared across all instances representing the same logical index
 * (multiple calls to $client->getIndex() create distinct instances but must
 * observe the same clone state).
 *
 * Extends FOS\ElasticaBundle\Elastica\Index for type compatibility with Resetter,
 * IndexManager and FOSElastica services that type-hint on this class.
 */
class LazyCloneIndex extends FosIndex
{
    public function __construct(
        private readonly string $logicalName,
        \Elastica\Client $client,
    ) {
        parent::__construct($client, $logicalName); // $_name is ignored: getName() is overridden
    }

    public function getName(): string
    {
        if (StaticState::isInitialized() && StaticState::hasCopy($this->logicalName)) {
            return $this->logicalName.'_'.TestToken::get();
        }

        return $this->logicalName;
    }

    // -----------------------------------------------------------------------
    // Write methods — trigger cloning before delegating to parent
    // -----------------------------------------------------------------------

    public function addDocument(Document $doc): Response
    {
        $this->ensureCopy();

        return parent::addDocument($doc);
    }

    /**
     * @param Document[]           $docs
     * @param array<string, mixed> $options
     */
    public function addDocuments(array $docs, array $options = []): ResponseSet
    {
        $this->ensureCopy();

        return parent::addDocuments($docs, $options);
    }

    /**
     * @param Document[]           $docs
     * @param array<string, mixed> $options
     */
    public function updateDocuments(array $docs, array $options = []): ResponseSet
    {
        $this->ensureCopy();

        return parent::updateDocuments($docs, $options);
    }

    /** @param array<string, mixed> $options */
    public function updateDocument($data, array $options = []): Response
    {
        $this->ensureCopy();

        return parent::updateDocument($data, $options);
    }

    /** @param array<string, mixed> $options */
    public function deleteById(string $id, array $options = []): Response
    {
        $this->ensureCopy();

        return parent::deleteById($id, $options);
    }

    /** @param array<string, mixed> $options */
    public function deleteByQuery($query, array $options = []): Response
    {
        $this->ensureCopy();

        return parent::deleteByQuery($query, $options);
    }

    /** @param array<string, mixed> $options */
    public function updateByQuery($query, AbstractScript $script, array $options = []): Response
    {
        $this->ensureCopy();

        return parent::updateByQuery($query, $script, $options);
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    private function ensureCopy(): void
    {
        if (StaticState::isInitialized() && !StaticState::hasCopy($this->logicalName)) {
            StaticState::copy($this->logicalName);
        }
    }
}
