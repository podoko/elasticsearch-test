<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Functional;

use Podoko\ElasticsearchDama\Tests\Functional\Support\FunctionalTestCase;

/**
 * Vérifie que chaque test démarre avec exactement les documents du seed (3 fixtures).
 *
 * Ce test est le gardien de deux invariants critiques :
 *   - Les fixtures sont bien injectées dans le seed (A2 : SeedBuilder câblé).
 *   - Chaque test repart de la baseline identique, quel que soit l'ordre d'exécution.
 *
 * En mode ParaTest, plusieurs workers exécutent ces méthodes en parallèle.
 * Si un worker voit un compte différent de 3, c'est une collision inter-workers.
 */
final class SeedBaselineTest extends FunctionalTestCase
{
    private const BASELINE_COUNT = 3;

    public function test_baseline_at_start_of_first_test(): void
    {
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'Le clone doit contenir exactement les 3 fixtures du seed au début du test.'
        );
    }

    public function test_baseline_at_start_of_second_test(): void
    {
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'Le clone doit être réinitialisé entre les tests : 3 fixtures attendues.'
        );
    }

    public function test_baseline_at_start_of_third_test(): void
    {
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'Même résultat pour un troisième test consécutif.'
        );
    }

    /**
     * Vérifie que les IDs fixes des fixtures baseline sont bien présents.
     */
    public function test_fixture_ids_are_present(): void
    {
        $index = $this->indexer()->getIndex();

        // Rechercher chaque document baseline par son id
        foreach (['1', '2', '3'] as $fixtureId) {
            $document = $index->getDocument($fixtureId);
            self::assertSame($fixtureId, $document->getId());
        }
    }
}
