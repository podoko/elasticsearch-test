<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchDama\Tests\Functional\Factory;

use Podoko\ElasticsearchDama\Tests\Functional\Model\Article;
use Zenstruck\Foundry\ObjectFactory;

/**
 * @extends ObjectFactory<Article>
 */
final class ArticleFactory extends ObjectFactory
{
    private const CATEGORIES = ['tech', 'science', 'sport', 'culture', 'politique', 'économie'];
    private const TAGS       = ['php', 'python', 'docker', 'kubernetes', 'ia', 'cloud', 'linux', 'web', 'data', 'devops'];

    public static function class(): string
    {
        return Article::class;
    }

    protected function defaults(): array
    {
        return [
            'id'          => self::faker()->uuid(),
            'title'       => self::faker()->sentence(mt_rand(4, 10)),
            'content'     => self::faker()->paragraphs(mt_rand(50, 100), true),
            'category'    => self::faker()->randomElement(self::CATEGORIES),
            'tags'        => self::faker()->randomElements(self::TAGS, mt_rand(1, 4)),
            'author'      => self::faker()->name(),
            'publishedAt' => self::faker()->dateTimeBetween('-2 years', 'now')->format('Y-m-d\TH:i:s'),
            'views'       => self::faker()->numberBetween(0, 100000),
        ];
    }
}
