<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama;

use Elastica\Client;
use Podoko\ElasticsearchDama\Reset\ResetStrategyInterface;

/**
 * Registre statique central — équivalent du StaticDriver de dama/doctrine-test-bundle.
 *
 * Conçu pour être appelable depuis l'extension PHPUnit (hors conteneur Symfony)
 * via des méthodes statiques. Le conteneur Symfony alimente ce registre au boot
 * grâce à StaticStateInitializer.
 */
final class StaticState
{
    private static bool $initialized = false;

    /** @var string[] Noms logiques des index gérés (clés FOSElastica, sans token). */
    private static array $managedIndexes = [];

    private static ?ResetStrategyInterface $resetStrategy = null;

    private static ?Client $adminClient = null;

    private static ?string $elasticsearchUrl = null;

    // -----------------------------------------------------------------------
    // Bootstrap (appelé par StaticStateInitializer depuis le conteneur Symfony)
    // -----------------------------------------------------------------------

    public static function initialize(
        Client $adminClient,
        array $managedIndexes,
        ResetStrategyInterface $resetStrategy,
        string $elasticsearchUrl,
    ): void {
        self::$adminClient      = $adminClient;
        self::$managedIndexes   = $managedIndexes;
        self::$resetStrategy    = $resetStrategy;
        self::$elasticsearchUrl = $elasticsearchUrl;
        self::$initialized      = true;
    }

    // -----------------------------------------------------------------------
    // Cycle de vie par test (appelé par les subscribers PHPUnit)
    // -----------------------------------------------------------------------

    /**
     * Appelé par TestPreparedSubscriber : clone le seed vers l'index de travail.
     */
    public static function beginTest(): void
    {
        self::assertInitialized();

        $token    = TestToken::get();
        $strategy = self::$resetStrategy;

        foreach (self::$managedIndexes as $indexName) {
            $strategy->prepare($indexName, $token);
        }
    }

    /**
     * Appelé par TestFinishedSubscriber : supprime l'index de travail.
     */
    public static function rollbackTest(): void
    {
        self::assertInitialized();

        $token    = TestToken::get();
        $strategy = self::$resetStrategy;

        foreach (self::$managedIndexes as $indexName) {
            $strategy->cleanup($indexName, $token);
        }
    }

    /**
     * Construit le seed si inexistant (une fois par processus).
     * Peut être appelé manuellement dans un setUpBeforeClass() si besoin de garantir
     * l'existence du seed avant le premier test (dans le cadre normal, le seed est
     * créé paresseusement lors du premier appel à prepare()).
     */
    public static function ensureSeedExists(): void
    {
        self::assertInitialized();

        foreach (self::$managedIndexes as $indexName) {
            self::$resetStrategy->seed($indexName);
        }
    }

    // -----------------------------------------------------------------------
    // Accesseurs
    // -----------------------------------------------------------------------

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    public static function getAdminClient(): Client
    {
        self::assertInitialized();

        return self::$adminClient;
    }

    /** @return string[] */
    public static function getManagedIndexes(): array
    {
        return self::$managedIndexes;
    }

    public static function getElasticsearchUrl(): string
    {
        self::assertInitialized();

        return self::$elasticsearchUrl;
    }

    // -----------------------------------------------------------------------
    // Réinitialisation (tests unitaires de la lib)
    // -----------------------------------------------------------------------

    public static function reset(): void
    {
        self::$initialized      = false;
        self::$managedIndexes   = [];
        self::$resetStrategy    = null;
        self::$adminClient      = null;
        self::$elasticsearchUrl = null;
    }

    // -----------------------------------------------------------------------
    // Privé
    // -----------------------------------------------------------------------

    private static function assertInitialized(): void
    {
        if (!self::$initialized) {
            throw new \LogicException(
                \sprintf(
                    '%s n\'est pas initialisé. Assurez-vous que le bundle est configuré '
                    . 'et que StaticStateInitializer::initialize() a été appelé au démarrage.',
                    self::class,
                )
            );
        }
    }
}
