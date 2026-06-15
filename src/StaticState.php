<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest;

use Elastica\Client;
use Podoko\ElasticsearchTest\Reset\ResetStrategyInterface;

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

    /** @var array<string, true> Index logiques copiés pendant le test courant. */
    private static array $copiedIndexes = [];

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
        self::$copiedIndexes    = [];
    }

    // -----------------------------------------------------------------------
    // Cycle de vie par test (appelé par les subscribers PHPUnit et LazyCloneIndex)
    // -----------------------------------------------------------------------

    /**
     * Vérifie si un clone a déjà été créé pour cet index dans le test courant.
     * Appelé par LazyCloneIndex::getName() pour résoudre le nom physique de l'index.
     */
    public static function hasCopy(string $indexName): bool
    {
        return isset(self::$copiedIndexes[$indexName]);
    }

    /**
     * Clone paresseux : crée le clone seed → worker pour cet index si ce n'est pas déjà fait.
     * Appelé par LazyCloneIndex::ensureCopy() lors de la première opération d'écriture.
     * Idempotent : un deuxième appel pour le même index est sans effet.
     */
    public static function copy(string $indexName): void
    {
        self::assertInitialized();

        if (isset(self::$copiedIndexes[$indexName])) {
            return;
        }

        self::$resetStrategy->prepare($indexName, TestToken::get());
        self::$copiedIndexes[$indexName] = true;
    }

    /**
     * Appelé par TestFinishedSubscriber : supprime uniquement les clones créés pendant ce test.
     */
    public static function rollbackTest(): void
    {
        self::assertInitialized();

        $token    = TestToken::get();
        $strategy = self::$resetStrategy;

        foreach (\array_keys(self::$copiedIndexes) as $indexName) {
            $strategy->cleanup($indexName, $token);
        }

        self::$copiedIndexes = [];
    }

    /**
     * Retire le write-block posé sur tous les index sources.
     * Appelé en fin de suite (propre), au boot suivant (SIGKILL recovery)
     * et via register_shutdown_function (dd()/exit()).
     *
     * Sans effet si StaticState n'est pas initialisé (guard crash avant init).
     * N'est pas exécuté sous ParaTest — chaque worker conserve le write-block
     * jusqu'au prochain boot kernel, évitant toute race condition entre workers.
     */
    public static function unlockSourceIndexes(): void
    {
        if (!self::$initialized) {
            return;
        }

        $strategy = self::$resetStrategy;

        foreach (self::$managedIndexes as $indexName) {
            $strategy->unlockSource($indexName);
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
        self::$copiedIndexes    = [];
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
