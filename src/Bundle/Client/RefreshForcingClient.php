<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Bundle\Client;

use Elastica\Index as BaseIndex;
use FOS\ElasticaBundle\Elastica\Client as FosClient;
use Podoko\ElasticsearchTest\PHPUnit\ElasticsearchTestExtension;

/**
 * Sous-classe du client FOSElastica qui suffixe getIndex() avec le token worker PHPUnit.
 *
 * On étend FOS\ElasticaBundle\Elastica\Client (et non Elastica\Client) pour conserver :
 * - le cache d'index ($indexCache) évitant la recréation d'objets Index
 * - l'intégration Stopwatch (profiler Symfony)
 * - les événements Symfony (PreElasticaRequestEvent, PostElasticaRequestEvent…)
 * - le logging ElasticaLogger
 *
 * Le suffixage n'est actif que lorsque ElasticsearchTestExtension::isBootstrapped()
 * est true — uniquement sous PHPUnit. Une requête HTTP ou une commande Symfony en
 * APP_ENV=test ne déclenchent pas le bootstrap → comportement normal sans redirection.
 *
 * Non final pour permettre aux projets ayant un client personnalisé de sous-classer.
 */
class RefreshForcingClient extends FosClient
{
    /**
     * Active le suffixage des index pour ce client.
     * Positionné à true par FosClientDecoratorPass sur les clients FOSElastica.
     */
    private bool $suffixIndexes = false;

    public function setSuffixIndexes(bool $suffix): void
    {
        $this->suffixIndexes = $suffix;
    }

    /**
     * Retourne un LazyCloneIndex si le suffixage est actif, un index normal sinon.
     *
     * Le LazyCloneIndex pointe sur le seed (posts_seed) par défaut et ne crée le clone
     * worker (posts_<token>) qu'à la première opération d'écriture. Cela évite de cloner
     * tous les index au début de chaque test, y compris ceux que le test ne touche pas.
     *
     * Condition d'activation (identiques à l'ancienne logique de suffixage) :
     *   1. Ce client est configuré pour suffixer ($suffixIndexes = true).
     *   2. L'extension PHPUnit est bootstrappée (on est sous PHPUnit/ParaTest).
     */
    public function getIndex(string $name): BaseIndex
    {
        if ($this->suffixIndexes && ElasticsearchTestExtension::isBootstrapped()) {
            return new LazyCloneIndex($name, $this);
        }

        return parent::getIndex($name);
    }
}
