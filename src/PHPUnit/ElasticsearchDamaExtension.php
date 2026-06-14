<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\PHPUnit;

use Elastica\Client;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Podoko\ElasticsearchDama\PHPUnit\Subscriber\TestFinishedSubscriber;
use Podoko\ElasticsearchDama\PHPUnit\Subscriber\TestPreparedSubscriber;
use Podoko\ElasticsearchDama\PHPUnit\Subscriber\TestRunnerStartedSubscriber;
use Podoko\ElasticsearchDama\Reset\CloneResetStrategy;
use Podoko\ElasticsearchDama\StaticState;

/**
 * Extension PHPUnit 11 — point d'entrée de la librairie.
 *
 * À enregistrer dans phpunit.xml :
 *
 *   <extensions>
 *     <bootstrap class="Podoko\ElasticsearchDama\PHPUnit\ElasticsearchDamaExtension">
 *       <parameter name="elasticsearch-url" value="http://localhost:9200"/>
 *       <parameter name="managed-indexes" value="posts,comments"/>
 *       <parameter name="reset-strategy" value="clone"/>
 *     </bootstrap>
 *   </extensions>
 *
 * Pourquoi StaticState est initialisé ici et non dans StaticStateInitializer (service Symfony) :
 * un service Symfony n'est instancié que si le conteneur le demande explicitement. Rien
 * ne garantit cette instanciation avant le premier test. L'extension PHPUnit s'exécute
 * en tout premier, avant tout test et avant tout boot de kernel — c'est le seul endroit
 * fiable pour initialiser StaticState.
 *
 * Le flag $bootstrapped est la sentinelle utilisée par RefreshForcingClient::getIndex()
 * pour ne suffixer les index qu'en contexte PHPUnit. Une requête HTTP ou une commande
 * Symfony en APP_ENV=test ne déclenche jamais ce bootstrap.
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

        $esUrl          = $this->resolveParam($parameters, 'elasticsearch-url', \getenv('ELASTICSEARCH_URL') ?: 'http://localhost:9200');
        $managedIndexes = $this->resolveManagedIndexes($parameters);
        $resetStrategy  = $this->resolveParam($parameters, 'reset-strategy', 'clone');

        $adminClient = new Client(['host' => $esUrl]);

        $strategy = match ($resetStrategy) {
            'clone' => new CloneResetStrategy($adminClient),
            default => throw new \InvalidArgumentException(
                \sprintf('Stratégie de reset inconnue : "%s". Valeurs acceptées : clone.', $resetStrategy)
            ),
        };

        StaticState::initialize(
            adminClient: $adminClient,
            managedIndexes: $managedIndexes,
            resetStrategy: $strategy,
            elasticsearchUrl: $esUrl,
        );

        $facade->registerSubscribers(
            new TestRunnerStartedSubscriber(),
            new TestPreparedSubscriber(),
            new TestFinishedSubscriber(),
        );
    }

    // -----------------------------------------------------------------------
    // Privé
    // -----------------------------------------------------------------------

    private function resolveParam(ParameterCollection $parameters, string $name, string $default = ''): string
    {
        return $parameters->has($name) ? $parameters->get($name) : $default;
    }

    private function resolveManagedIndexes(ParameterCollection $parameters): array
    {
        if (!$parameters->has('managed-indexes')) {
            throw new \LogicException(
                'Le paramètre "managed-indexes" est requis dans la configuration de '
                . self::class . ' (phpunit.xml). '
                . 'Exemple : <parameter name="managed-indexes" value="posts,comments"/>'
            );
        }

        return \array_values(\array_filter(\array_map('trim', \explode(',', $parameters->get('managed-indexes')))));
    }
}
