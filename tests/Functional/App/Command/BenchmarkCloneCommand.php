<?php

declare(strict_types=1);

namespace Podoko\ElasticsearchTest\Tests\Functional\App\Command;

use Elastica\Client;
use Elastica\Exception\ResponseException;
use Elastica\Request;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:benchmark:clone',
    description: 'Measures clone and deletion time for an Elasticsearch index.',
)]
final class BenchmarkCloneCommand extends Command
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
            ->addOption('index', 'i', InputOption::VALUE_REQUIRED, 'Source index to clone', 'articles')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Suffix for clone names', 'bench')
            ->addOption('iterations', 'N', InputOption::VALUE_REQUIRED, 'Number of iterations', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $indexName  = (string) $input->getOption('index');
        $token      = (string) $input->getOption('token');
        $iterations = max(1, (int) $input->getOption('iterations'));

        $io->title(sprintf('Benchmark clone — %s (%d iteration(s))', $indexName, $iterations));

        try {
            $countResponse = $this->client->request("$indexName/_count", Request::GET);
            $docCount      = $countResponse->getData()['count'] ?? '?';
        } catch (ResponseException $e) {
            $io->error(sprintf('Index "%s" not found or unreachable: %s', $indexName, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->text(sprintf('Source: <info>%s</info> (%s documents)', $indexName, $docCount));

        /** @var float[] $cloneTimes */
        $cloneTimes = [];
        /** @var float[] $deleteTimes */
        $deleteTimes = [];

        for ($i = 1; $i <= $iterations; ++$i) {
            $cloneName = sprintf('%s_%s_%d', $indexName, $token, $i);

            $io->section(sprintf('Iteration %d/%d → %s', $i, $iterations, $cloneName));

            if ($this->indexExists($cloneName)) {
                $io->text(sprintf('Leftover clone <comment>%s</comment> deleted.', $cloneName));
                $this->client->request($cloneName, Request::DELETE);
            }

            // Clone
            $t0 = microtime(true);

            $this->client->request(
                "$indexName/_settings",
                Request::PUT,
                ['index' => ['blocks' => ['write' => true]]],
            );
            $tBlock = microtime(true);

            $this->client->request(
                "$indexName/_clone/$cloneName",
                Request::POST,
                ['settings' => ['index.blocks.write' => false, 'index.number_of_replicas' => 0]],
            );
            $tClone = microtime(true);

            $this->client->request(
                "_cluster/health/$cloneName",
                Request::GET,
                [],
                ['wait_for_status' => 'green', 'timeout' => '30s'],
            );
            $tHealthy = microtime(true);

            $cloneTotal = $tHealthy - $t0;
            $cloneTimes[] = $cloneTotal;

            $io->table(
                ['Step', 'Duration'],
                [
                    ['Source write-block', sprintf('%.3f s', $tBlock - $t0)],
                    ['_clone API',         sprintf('%.3f s', $tClone - $tBlock)],
                    ['Wait for green',     sprintf('%.3f s', $tHealthy - $tClone)],
                    ['<info>Total clone</info>', sprintf('<info>%.3f s</info>', $cloneTotal)],
                ],
            );

            // Deletion
            $tDelStart = microtime(true);
            $this->client->request($cloneName, Request::DELETE);
            $tDelEnd = microtime(true);

            $deleteTotal = $tDelEnd - $tDelStart;
            $deleteTimes[] = $deleteTotal;

            $io->text(sprintf('Deletion: %.3f s', $deleteTotal));

            // Unlock the source
            $this->client->request(
                "$indexName/_settings",
                Request::PUT,
                ['index' => ['blocks' => ['write' => false]]],
            );
        }

        // Statistics
        $io->section('Summary statistics');
        $io->text(sprintf('<info>%d iteration(s)</info> — %s documents', $iterations, $docCount));
        $io->newLine();

        $io->table(
            ['Metric', 'Clone (s)', 'Deletion (s)'],
            [
                ['Min',      sprintf('%.3f', $this->min($cloneTimes)),                   sprintf('%.3f', $this->min($deleteTimes))],
                ['Q1',       sprintf('%.3f', $this->percentile($cloneTimes, 25)),         sprintf('%.3f', $this->percentile($deleteTimes, 25))],
                ['Median',   sprintf('%.3f', $this->percentile($cloneTimes, 50)),         sprintf('%.3f', $this->percentile($deleteTimes, 50))],
                ['Mean',     sprintf('%.3f', $this->mean($cloneTimes)),                   sprintf('%.3f', $this->mean($deleteTimes))],
                ['Q3',       sprintf('%.3f', $this->percentile($cloneTimes, 75)),         sprintf('%.3f', $this->percentile($deleteTimes, 75))],
                ['Max',      sprintf('%.3f', $this->max($cloneTimes)),                    sprintf('%.3f', $this->max($deleteTimes))],
                ['Std. dev.',sprintf('%.3f', $this->stdDev($cloneTimes)),                 sprintf('%.3f', $this->stdDev($deleteTimes))],
            ],
        );

        return Command::SUCCESS;
    }

    private function indexExists(string $name): bool
    {
        try {
            $response = $this->client->request($name, Request::HEAD);

            return $response->getStatus() === 200;
        } catch (ResponseException $e) {
            if ($e->getResponse()->getStatus() === 404) {
                return false;
            }

            throw $e;
        }
    }

    /** @param float[] $values */
    private function min(array $values): float
    {
        return min($values);
    }

    /** @param float[] $values */
    private function max(array $values): float
    {
        return max($values);
    }

    /** @param float[] $values */
    private function mean(array $values): float
    {
        return array_sum($values) / count($values);
    }

    /**
     * Percentile by linear interpolation (inclusive method).
     *
     * @param float[] $values
     */
    private function percentile(array $values, int $p): float
    {
        $sorted = $values;
        sort($sorted);
        $n     = count($sorted);
        $index = ($p / 100) * ($n - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $frac  = $index - $lower;

        return $sorted[$lower] + $frac * ($sorted[$upper] - $sorted[$lower]);
    }

    /** @param float[] $values */
    private function stdDev(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $mean     = $this->mean($values);
        $variance = array_sum(array_map(fn (float $v) => ($v - $mean) ** 2, $values)) / (count($values) - 1);

        return sqrt($variance);
    }
}
