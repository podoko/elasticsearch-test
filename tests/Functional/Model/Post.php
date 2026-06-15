<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional\Model;

/**
 * DTO représentant un document "post" dans l'index Elasticsearch.
 * Pas d'entité Doctrine — ce DTO est sérialisé directement en document ES.
 */
final class Post
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $status,
        public readonly string $body = '',
    ) {}

    /**
     * Retourne le document sous la forme attendue par Elastica\Document.
     *
     * @return array<string, string>
     */
    public function toDocument(): array
    {
        return [
            'title'  => $this->title,
            'status' => $this->status,
            'body'   => $this->body,
        ];
    }
}
