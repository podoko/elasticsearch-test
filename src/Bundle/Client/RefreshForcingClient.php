<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Bundle\Client;

use Elastica\Index as BaseIndex;
use FOS\ElasticaBundle\Elastica\Client as FosClient;
use Podoko\ElasticsearchDama\PHPUnit\ElasticsearchDamaExtension;
use Podoko\ElasticsearchDama\TestToken;

/**
 * Sous-classe du client FOSElastica qui suffixe getIndex() avec le token worker PHPUnit.
 *
 * On étend FOS\ElasticaBundle\Elastica\Client (et non Elastica\Client) pour conserver :
 * - le cache d'index ($indexCache) évitant la recréation d'objets Index
 * - l'intégration Stopwatch (profiler Symfony)
 * - les événements Symfony (PreElasticaRequestEvent, PostElasticaRequestEvent…)
 * - le logging ElasticaLogger
 *
 * Le suffixage n'est actif que lorsque ElasticsearchDamaExtension::isBootstrapped()
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
     * Retourne un Index suffixé avec le token worker, mais seulement si :
     *   1. Ce client est configuré pour suffixer ($suffixIndexes = true).
     *   2. L'extension PHPUnit est bootstrappée (on est sous PHPUnit/ParaTest).
     *
     * Le suffixage est résolu au runtime (ici) et non au compile-time (CompilerPass)
     * pour éviter que la valeur du token soit figée dans le cache du conteneur Symfony
     * partagé entre les workers ParaTest.
     */
    public function getIndex(string $name): BaseIndex
    {
        if ($this->suffixIndexes && ElasticsearchDamaExtension::isBootstrapped()) {
            return parent::getIndex($name . '_' . TestToken::get());
        }

        return parent::getIndex($name);
    }
}
