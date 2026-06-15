<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\PHPUnit;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Podoko\ElasticsearchDama\PHPUnit\Subscriber\TestFinishedSubscriber;
use Podoko\ElasticsearchDama\PHPUnit\Subscriber\TestPreparedSubscriber;
use Podoko\ElasticsearchDama\PHPUnit\Subscriber\TestSuiteFinishedSubscriber;

/**
 * Extension PHPUnit 11 — point d'entrée de la librairie.
 *
 * À enregistrer dans phpunit.xml :
 *
 *   <extensions>
 *     <bootstrap class="Podoko\ElasticsearchDama\PHPUnit\ElasticsearchDamaExtension"/>
 *   </extensions>
 *
 * Cette extension ne lit aucun paramètre : toute la configuration (URL Elasticsearch,
 * index gérés, stratégie, fixtures) provient du bundle Symfony
 * (config/packages/test/elasticsearch_dama.yaml).
 *
 * Rôle de cette classe :
 *   1. Positionner $bootstrapped = true (sentinelle utilisée par RefreshForcingClient::getIndex()
 *      pour n'activer le suffixage qu'en contexte PHPUnit).
 *   2. Enregistrer les subscribers PHPUnit qui clonent/suppriment l'index de travail.
 *
 * StaticState est initialisé séparément par PodokoElasticsearchDamaBundle::boot()
 * (appel eager de StaticStateInitializer) lors du premier boot du kernel Symfony, dans
 * le setUp() du test. C'est donc garanti avant l'émission de Test\Prepared.
 */
final class ElasticsearchDamaExtension implements Extension
{
    private static bool $bootstrapped = false;

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
            new TestPreparedSubscriber(),
            new TestFinishedSubscriber(),
            new TestSuiteFinishedSubscriber(),
        );
    }
}
