# elasticsearch-test

Test isolation for Elasticsearch in Symfony + FOSElastica projects — inspired by [`dama/doctrine-test-bundle`](https://github.com/dmaicher/doctrine-test-bundle).

Each PHPUnit test runs against its own clean copy of the index. No `sleep()`, no manual teardown, no pollution between tests. Works with [ParaTest](https://github.com/paratestphp/paratest) out of the box.

## Requirements

- PHP 8.2+
- Symfony 6.4 or 7.x
- `friendsofsymfony/elastica-bundle` ^6.3
- `ruflin/elastica` ^7.3
- PHPUnit 11

## Installation

```bash
composer require --dev podoko/elasticsearch-test
```

## Setup

### 1. Register the bundle

```php
// config/bundles.php
return [
    // ...
    Podoko\ElasticsearchTest\Bundle\PodokoElasticsearchTestBundle::class => ['test' => true],
];
```

### 2. Configure the bundle

```yaml
# config/packages/test/elasticsearch_test.yaml
elasticsearch_test:
  elasticsearch_url: '%env(ELASTICSEARCH_URL)%'

  # List the FOSElastica index names to isolate.
  # Leave empty to auto-discover all FOSElastica indexes.
  managed_indexes:
    - posts
    - comments
```

### 3. Register the PHPUnit extension

```xml
<!-- phpunit.xml -->
<extensions>
  <bootstrap class="Podoko\ElasticsearchTest\PHPUnit\ElasticsearchTestExtension"/>
</extensions>
```

The extension requires no parameters — all configuration comes from the bundle YAML above.

### 4. Populate your indexes before running tests

The bundle clones your indexes as-is. It does not create or populate them. Before running your test suite, create and populate your indexes the same way you would in production — via `fos:elastica:populate`, custom fixtures, or any other mechanism:

```bash
# Example using FOSElastica's populate command
php bin/console fos:elastica:populate --env=test
```

Run this once before `phpunit` or `paratest`. Re-run it whenever you change your mappings or baseline fixtures.

## How it works

On the **first write** inside a test, the bundle clones the original index (`posts`) into a temporary worker index (`posts_<token>`). Subsequent reads and writes in that test go to the clone. After the test completes — regardless of outcome — the clone is deleted. Your code never touches the original index.

```
posts   (your pre-populated source — write-blocked during clone)
  │
  ├── _clone ──▶  posts_1   (worker 1, test A)
  ├── _clone ──▶  posts_1   (worker 1, test B)
  └── _clone ──▶  posts_2   (worker 2, test C)
```

Cloning is **lazy**: tests that only read never trigger a clone and query `posts` directly. This makes read-only tests nearly free.

The cloning uses Elasticsearch's native `_clone` API, which hardlinks Lucene segment files on disk. It is nearly instantaneous even for large indexes.

## ParaTest support

Each ParaTest worker gets its own working index (e.g., `posts_1`, `posts_2`, …). The worker number comes from the `TEST_TOKEN` environment variable injected by ParaTest. No extra configuration is needed.

```bash
vendor/bin/paratest --processes 4
```

The `_clone` API requires the source index to have `index.blocks.write: true`. The bundle sets this automatically and removes it at the end of the suite. Under ParaTest the write-block persists until the next run's setup command (which deletes and recreates the index), avoiding race conditions between workers.

## Limitations

- **`use_alias: true` is not supported.** If a FOSElastica index is configured with `use_alias: true`, the bundle throws a `RuntimeException` at container compile time. Disable `use_alias` in your test configuration or exclude those indexes from `managed_indexes`.

- **Only the `clone` reset strategy is implemented.** The configuration accepts `truncate` and `recreate` as values but they are not yet functional.

- **No automatic `_refresh`.** The bundle does not force an Elasticsearch refresh after writes. If your test indexes a document and immediately searches for it, call `_refresh` explicitly (or use `refresh=true` on the index request).

- **Source index must exist before tests run.** The bundle does not create or populate indexes. Run your populate command (or equivalent) before starting the test suite.

## License

MIT
