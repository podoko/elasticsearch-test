<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Functional;

use Elastica\Index;
use Podoko\ElasticsearchDama\PHPUnit\ElasticsearchDamaExtension;
use Podoko\ElasticsearchDama\TestToken;
use Podoko\ElasticsearchDama\Tests\Functional\Support\FunctionalTestCase;

/**
 * Vérifie que le suffixage runtime de getIndex() est bien actif sous PHPUnit.
 *
 * Ce test garantit que :
 *   1. L'extension PHPUnit est bootstrappée (sentinelle active).
 *   2. Le service fos_elastica.index.posts pointe sur posts_<token> et non posts.
 *   3. Le nom du clone correspond au token worker courant (ParaTest-safe).
 */
final class SuffixingTest extends FunctionalTestCase
{
    public function test_extension_is_bootstrapped(): void
    {
        self::assertTrue(
            ElasticsearchDamaExtension::isBootstrapped(),
            'L\'extension PHPUnit doit être bootstrappée pour activer le suffixage.'
        );
    }

    public function test_fos_index_is_suffixed_with_worker_token(): void
    {
        /** @var Index $index */
        $index = static::getContainer()->get('fos_elastica.index.posts');

        $expectedName = 'posts_' . TestToken::get();

        self::assertSame(
            $expectedName,
            $index->getName(),
            \sprintf(
                'L\'index FOSElastica doit pointer sur "%s" (clone suffixé), mais pointe sur "%s".',
                $expectedName,
                $index->getName(),
            )
        );
    }

    public function test_index_name_changes_with_token(): void
    {
        /** @var Index $index */
        $index = static::getContainer()->get('fos_elastica.index.posts');

        // Le nom doit contenir le token et ne pas être l'index brut "posts"
        self::assertStringStartsWith('posts_', $index->getName());
        self::assertNotEquals('posts', $index->getName());
    }
}
