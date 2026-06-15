<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Reset;

/**
 * Contrat pour les stratégies de remise à zéro d'un index entre deux tests.
 */
interface ResetStrategyInterface
{
    /**
     * Prépare l'index de travail avant le test.
     *
     * @param string $indexName Nom logique de l'index (sans suffixe token).
     * @param string $token     Token worker courant (depuis TestToken::get()).
     */
    public function prepare(string $indexName, string $token): void;

    /**
     * Nettoie l'index de travail après le test.
     *
     * @param string $indexName Nom logique de l'index.
     * @param string $token     Token worker courant.
     */
    public function cleanup(string $indexName, string $token): void;

    /**
     * Retire le write-block posé sur l'index source en vue des clones.
     * Sans effet si l'index n'existe pas ou n'est pas bloqué.
     *
     * @param string $indexName Nom logique de l'index source.
     */
    public function unlockSource(string $indexName): void;
}
