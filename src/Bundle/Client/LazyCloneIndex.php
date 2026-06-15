<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Bundle\Client;

use Elastica\Script\AbstractScript;
use Elastica\Bulk\ResponseSet;
use Elastica\Document;
use Elastica\Response;
use FOS\ElasticaBundle\Elastica\Index as FosIndex;
use Podoko\ElasticsearchDama\StaticState;
use Podoko\ElasticsearchDama\TestToken;

/**
 * Proxy d'index à clonage paresseux.
 *
 * Par défaut, redirige toutes les opérations vers l'index seed (posts_seed).
 * Lors de la première opération d'écriture, déclenche la copie via StaticState::copy()
 * puis redirige toutes les opérations (lecture et écriture) vers le clone worker (posts_<token>).
 *
 * Délibérément stateless : l'état de copie est centralisé dans StaticState::$copiedIndexes
 * pour qu'il soit partagé entre toutes les instances représentant le même index logique
 * (plusieurs appels à $client->getIndex() créent des instances distinctes mais doivent
 * voir le même état de copie).
 *
 * Étend FOS\ElasticaBundle\Elastica\Index pour la compatibilité de type avec Resetter,
 * IndexManager et les services FOSElastica qui type-hintent sur cette classe.
 */
class LazyCloneIndex extends FosIndex
{
    public function __construct(
        private readonly string $logicalName,
        \Elastica\Client $client,
    ) {
        parent::__construct($client, $logicalName); // $_name est ignoré : getName() est surchargé
    }

    public function getName(): string
    {
        if (StaticState::isInitialized() && StaticState::hasCopy($this->logicalName)) {
            return $this->logicalName . '_' . TestToken::get();
        }

        return $this->logicalName . '_seed';
    }

    // -----------------------------------------------------------------------
    // Méthodes d'écriture — déclenchent le clone avant de déléguer au parent
    // -----------------------------------------------------------------------

    public function addDocument(Document $doc): Response
    {
        $this->ensureCopy();

        return parent::addDocument($doc);
    }

    /** @param Document[] $docs */
    public function addDocuments(array $docs, array $options = []): ResponseSet
    {
        $this->ensureCopy();

        return parent::addDocuments($docs, $options);
    }

    /** @param Document[] $docs */
    public function updateDocuments(array $docs, array $options = []): ResponseSet
    {
        $this->ensureCopy();

        return parent::updateDocuments($docs, $options);
    }

    public function updateDocument($data, array $options = []): Response
    {
        $this->ensureCopy();

        return parent::updateDocument($data, $options);
    }

    public function deleteById(string $id, array $options = []): Response
    {
        $this->ensureCopy();

        return parent::deleteById($id, $options);
    }

    public function deleteByQuery($query, array $options = []): Response
    {
        $this->ensureCopy();

        return parent::deleteByQuery($query, $options);
    }

    public function updateByQuery($query, AbstractScript $script, array $options = []): Response
    {
        $this->ensureCopy();

        return parent::updateByQuery($query, $script, $options);
    }

    // -----------------------------------------------------------------------
    // Privé
    // -----------------------------------------------------------------------

    private function ensureCopy(): void
    {
        if (StaticState::isInitialized() && !StaticState::hasCopy($this->logicalName)) {
            StaticState::copy($this->logicalName);
        }
    }
}
