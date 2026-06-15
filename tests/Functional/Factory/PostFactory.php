<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional\Factory;

use Podoko\ElasticsearchTest\Tests\Functional\Model\Post;
use Zenstruck\Foundry\ObjectFactory;

/**
 * Factory Foundry pour le DTO Post.
 *
 * Utilisée en mode ObjectFactory (sans persistence Doctrine) : les instances
 * créées sont de simples objets PHP, indexés ensuite dans Elasticsearch
 * via PostIndexer.
 *
 * @extends ObjectFactory<Post>
 */
final class PostFactory extends ObjectFactory
{
    public static function class(): string
    {
        return Post::class;
    }

    /**
     * Valeurs par défaut générées par Faker.
     * Les tests peuvent surcharger n'importe quel champ via createOne(['status' => 'draft']).
     */
    protected function defaults(): array
    {
        return [
            'id'     => self::faker()->uuid(),
            'title'  => self::faker()->sentence(4),
            'status' => self::faker()->randomElement(['published', 'draft']),
            'body'   => self::faker()->paragraph(),
        ];
    }
}
