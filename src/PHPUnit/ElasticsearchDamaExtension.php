<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\PHPUnit;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Podoko\ElasticsearchDama\PHPUnit\Subscriber\TestFinishedSubscriber;
use Podoko\ElasticsearchDama\PHPUnit\Subscriber\TestPreparedSubscriber;
use Podoko\ElasticsearchDama\PHPUnit\Subscriber\TestRunnerStartedSubscriber;

/**
 * Extension PHPUnit 11 — point d'entrée de la librairie.
 *
 * À enregistrer dans phpunit.xml :
 *
 *   <extensions>
 *     <bootstrap class="Podoko\ElasticsearchDama\PHPUnit\ElasticsearchDamaExtension"/>
 *   </extensions>
 *
 * Le flag statique $bootstrapped est la sentinelle utilisée par RefreshForcingClient
 * pour n'activer le suffixage des index que lors d'une exécution PHPUnit.
 * Toute autre utilisation du conteneur Symfony en env de test (requête HTTP,
 * commande console) ne déclenche pas ce bootstrap → comportement normal.
 */
final class ElasticsearchDamaExtension implements Extension
{
    private static bool $bootstrapped = false;

    /**
     * Retourne true uniquement si PHPUnit a chargé cette extension.
     * Appelé par RefreshForcingClient::getIndex() pour gater le suffixage.
     */
    public static function isBootstrapped(): bool
    {
        return self::$bootstrapped;
    }

    /** Réinitialise le flag (tests unitaires de la lib elle-même). */
    public static function reset(): void
    {
        self::$bootstrapped = false;
    }

    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        self::$bootstrapped = true;

        $facade->registerSubscribers(
            new TestRunnerStartedSubscriber(),
            new TestPreparedSubscriber(),
            new TestFinishedSubscriber(),
        );
    }
}
