<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional\Model;

/**
 * DTO representing a 'post' document in the Elasticsearch index.
 * No Doctrine entity — this DTO is serialized directly into an ES document.
 */
final class Post
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $status,
        public readonly string $body = '',
    ) {
    }

    /**
     * Returns the document in the format expected by Elastica\Document.
     *
     * @return array<string, string>
     */
    public function toDocument(): array
    {
        return [
            'title' => $this->title,
            'status' => $this->status,
            'body' => $this->body,
        ];
    }
}
