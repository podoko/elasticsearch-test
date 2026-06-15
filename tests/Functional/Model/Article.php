<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional\Model;

final class Article
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $content,
        public readonly string $category,
        public readonly array $tags,
        public readonly string $author,
        public readonly string $publishedAt,
        public readonly int $views,
    ) {}

    /** @return array<string, mixed> */
    public function toDocument(): array
    {
        return [
            'title'       => $this->title,
            'content'     => $this->content,
            'category'    => $this->category,
            'tags'        => $this->tags,
            'author'      => $this->author,
            'publishedAt' => $this->publishedAt,
            'views'       => $this->views,
        ];
    }
}
