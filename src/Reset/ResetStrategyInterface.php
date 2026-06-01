<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Reset;

/**
 * Contrat pour les stratégies de remise à zéro d'un index entre deux tests.
 */
interface ResetStrategyInterface
{
    /**
     * Prépare l'index de travail avant le test.
     *
     * @param string $indexName Nom logique de l'index (sans suffixe token, sans "_seed").
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
     * Crée/peuple l'index seed s'il n'existe pas encore.
     * Appelé une fois par processus worker au démarrage.
     *
     * @param string $indexName Nom logique de l'index.
     */
    public function seed(string $indexName): void;
}
