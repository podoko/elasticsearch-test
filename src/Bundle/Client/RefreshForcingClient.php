<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Bundle\Client;

use Elastica\Client;
use Elastica\Index;
use Podoko\ElasticsearchDama\PHPUnit\ElasticsearchDamaExtension;
use Podoko\ElasticsearchDama\TestToken;

/**
 * Sous-classe de Client qui suffixe getIndex() avec le token worker PHPUnit.
 *
 * Le suffixage n'est actif que lorsque l'extension PHPUnit a appelé son
 * bootstrap() — c'est-à-dire uniquement quand le processus tourne sous
 * PHPUnit/ParaTest. Une requête HTTP ou une commande Symfony en env de test
 * ne déclenchent pas l'extension PHPUnit → getIndex() se comporte normalement.
 *
 * Le refresh après écriture est laissé à la charge du code applicatif.
 * Notre CloneResetStrategy appelle _refresh explicitement quand nécessaire.
 */
final class RefreshForcingClient extends Client
{
    /**
     * Indique que ce client doit suffixer les noms d'index.
     * Positionné à true par FosClientDecoratorPass sur le client FOSElastica.
     * Reste à false sur le client admin (elasticsearch_dama.admin_client).
     */
    private bool $suffixIndexes = false;

    public function setSuffixIndexes(bool $suffix): void
    {
        $this->suffixIndexes = $suffix;
    }

    /**
     * Retourne un Index suffixé avec le token worker, mais seulement si :
     *   1. Ce client est configuré pour suffixer ($suffixIndexes = true).
     *   2. L'extension PHPUnit est active (bootstrap() a été appelé).
     *
     * Condition 2 garantit que les commandes Symfony et les requêtes HTTP
     * en APP_ENV=test ne sont pas redirigées vers les index de test isolés.
     */
    public function getIndex(string $name): Index
    {
        if ($this->suffixIndexes && ElasticsearchDamaExtension::isBootstrapped()) {
            return parent::getIndex($name . '_' . TestToken::get());
        }

        return parent::getIndex($name);
    }
}
