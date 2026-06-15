<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional\App\Command;

use Elastica\Client;
use Elastica\Document;
use Podoko\ElasticsearchTest\Tests\Functional\Factory\ArticleFactory;
use Podoko\ElasticsearchTest\Tests\Functional\Model\Article;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:benchmark:seed',
    description: 'Populates the articles index with N documents for clone benchmarks.',
)]
final class BenchmarkSeedCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'elasticsearch_test.admin_client')]
        private readonly Client $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of documents to index', '10000')
            ->addOption('batch', 'b', InputOption::VALUE_REQUIRED, 'Batch size for indexing', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int) $input->getOption('count');
        $batch = (int) $input->getOption('batch');

        $io->title(sprintf('Seed benchmark — %d documents (batches of %d)', $count, $batch));

        // Delete and recreate the index.
        try {
            $this->client->request('articles', 'DELETE');
            $io->text('Index <info>articles</info> deleted.');
        } catch (\Elastica\Exception\ResponseException $e) {
            if (404 !== $e->getResponse()->getStatus()) {
                throw $e;
            }
        }

        $this->client->request('articles', 'PUT', [
            'settings' => [
                'number_of_replicas' => 0,
            ],
            'mappings' => [
                'properties' => [
                    'title' => ['type' => 'text'],
                    'content' => ['type' => 'text'],
                    'category' => ['type' => 'keyword'],
                    'tags' => ['type' => 'keyword'],
                    'author' => ['type' => 'keyword'],
                    'publishedAt' => ['type' => 'date'],
                    'views' => ['type' => 'integer'],
                ],
            ],
        ]);
        $io->text('Index <info>articles</info> recreated.');

        $index = $this->client->getIndex('articles');

        $progressBar = new ProgressBar($output, $count);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        $startTime = microtime(true);
        $indexed = 0;
        $remaining = $count;

        while ($remaining > 0) {
            $batchSize = min($batch, $remaining);

            $documents = array_map(
                static fn (Article $a) => new Document($a->id, $a->toDocument()),
                ArticleFactory::createMany($batchSize),
            );

            $index->addDocuments($documents);
            $indexed += $batchSize;
            $remaining -= $batchSize;
            $progressBar->advance($batchSize);
        }

        $index->refresh();
        $progressBar->finish();

        $elapsed = microtime(true) - $startTime;

        $output->writeln('');
        $io->success(sprintf(
            '%d documents indexed in %.2fs (%.0f docs/s)',
            $indexed,
            $elapsed,
            $indexed / $elapsed,
        ));

        return Command::SUCCESS;
    }
}
