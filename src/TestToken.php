<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama;

/**
 * Résout le token worker courant.
 *
 * En mode mono-processus PHPUnit, retourne '1'.
 * En mode parallèle ParaTest, lit TEST_TOKEN ou UNIQUE_TEST_TOKEN
 * pour isoler chaque worker sur son propre index.
 */
final class TestToken
{
    private static ?string $resolved = null;

    public static function get(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        // ParaTest injecte TEST_TOKEN (entier, ex: "1", "2", "3") ou
        // UNIQUE_TEST_TOKEN (UUID, disponible depuis paratest 6.x).
        // On préfère TEST_TOKEN pour des noms d'index courts.
        $token = \getenv('TEST_TOKEN');

        if ($token === false || $token === '') {
            $token = \getenv('UNIQUE_TEST_TOKEN');
        }

        if ($token === false || $token === '') {
            $token = '1';
        }

        // Sanitize : on ne garde que les caractères valides dans un nom d'index ES
        // (alphanumériques et tirets).
        $token = \preg_replace('/[^a-zA-Z0-9\-]/', '-', $token);

        return self::$resolved = $token;
    }

    /** Réinitialise le cache (utile pour les tests unitaires de la lib elle-même). */
    public static function reset(): void
    {
        self::$resolved = null;
    }
}
