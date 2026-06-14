<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Functional;

use Elastica\Query;
use Podoko\ElasticsearchDama\Tests\Functional\Factory\PostFactory;
use Podoko\ElasticsearchDama\Tests\Functional\Support\FunctionalTestCase;

/**
 * Vérifie que les mutations dans un test (suppression, modification) ne
 * franchissent pas les frontières du test : chaque clone repart du seed intact.
 */
final class FixturePreservationTest extends FunctionalTestCase
{
    private const BASELINE_COUNT = 3;

    /**
     * Supprime le document baseline id='1' et asserte sa disparition.
     * → Le test suivant devra retrouver id='1' (clone frais du seed).
     */
    public function test_deleted_fixture_is_gone_within_this_test(): void
    {
        $this->deletePost('1');

        self::assertSame(
            self::BASELINE_COUNT - 1,
            $this->countAll(),
            'Après suppression d\'un doc baseline, le total doit être baseline - 1.'
        );
    }

    /**
     * Asserte que id='1' est bien présent → prouve que la suppression du test
     * précédent n'a pas affecté ce clone-ci.
     */
    public function test_fixture_is_intact_after_deletion_in_previous_test(): void
    {
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'Le clone de ce test doit être intact (3 fixtures), '
            . 'indépendamment des suppressions du test précédent.'
        );

        // Vérification directe que id='1' existe dans ce clone
        $index    = $this->indexer()->getIndex();
        $document = $index->getDocument('1');
        self::assertSame('1', $document->getId());
    }

    /**
     * Indexe un nouveau document dans ce test.
     */
    public function test_new_document_is_visible_only_in_this_test(): void
    {
        $post = PostFactory::createOne(['title' => 'Unique post for this test']);
        $this->index($post);

        self::assertSame(self::BASELINE_COUNT + 1, $this->countAll());
    }

    /**
     * Sans indexation ni suppression, le compte reste à 3.
     * → Prouve que le document créé dans le test précédent n'est pas visible ici.
     */
    public function test_no_new_document_from_previous_test(): void
    {
        self::assertSame(
            self::BASELINE_COUNT,
            $this->countAll(),
            'Le document créé dans le test précédent ne doit pas être visible ici.'
        );
    }

    /**
     * Vérifie que les fixtures ont les statuts attendus.
     */
    public function test_fixture_status_is_preserved(): void
    {
        $index = $this->indexer()->getIndex();

        // Fixtures id='1' et id='3' sont "published", id='2' est "draft"
        $doc1 = $index->getDocument('1');
        self::assertSame('published', $doc1->get('status'));

        $doc2 = $index->getDocument('2');
        self::assertSame('draft', $doc2->get('status'));
    }
}
