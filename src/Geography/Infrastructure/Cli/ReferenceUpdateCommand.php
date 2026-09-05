<?php

declare(strict_types=1);

namespace Providentia\Geography\Infrastructure\Cli;

use Doctrine\DBAL\Connection;
use Providentia\Access\Domain\FeatureCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'reference:update', description: ('Process queued country, region and city updates from the upstream GitHub'
    . ' release.'))]
final class ReferenceUpdateCommand extends Command
{
    private const REPOSITORY = 'dr5hn/countries-states-cities-database';
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'recover',
            null,
            InputOption::VALUE_NONE,
            'Requeue updates left running for more than two hours.',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        if ($input->getOption('recover')) {
            $this->connection->executeStatement(
                ('UPDATE reference_update_jobs SET status = \'queued\' WHERE status = '
                    . '\'running\' AND started_at < ?'),
                [gmdate('Y-m-d H:i:s', time() - 7200)],
            );
        }
        $job = $this->connection->fetchAssociative(
            ('SELECT id FROM reference_update_jobs WHERE status = \'queued\' ORDER BY '
                . 'created_at, id LIMIT 1'),
        );
        if ($job === false) {
            return Command::SUCCESS;
        }
        $id = (string) $job['id'];
        if (
            $this->connection->update(
                'reference_update_jobs',
                [
                'status' => 'running',
                'started_at' => gmdate('Y-m-d H:i:s'),
                'safe_message' => 'Downloading a verified upstream release',
                ],
                ['id' => $id, 'status' => 'queued'],
            ) !== 1
        ) {
            return Command::SUCCESS;
        }
        $files = [];
        try {
            $metadata = $this->download(
                'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
                2097152,
            );
            $files[] = $metadata;
            /** @var array{tag_name: string, assets: list<array{name: string, digest: string}>} $release */
            $release = json_decode(
                (string) file_get_contents($metadata),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
            $tag = $release['tag_name'];
            if (preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $tag) !== 1) {
                throw new \RuntimeException('Invalid upstream release tag.');
            }
            $digest = null;
            foreach ($release['assets'] as $asset) {
                if ($asset['name'] === 'csv-cities.csv.gz') {
                    $digest = $asset['digest'];
                }
            }
            if (!is_string($digest) || preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new \RuntimeException(
                    'The city release has no supported integrity digest.',
                );
            }
            $countries = $this->download(
                'https://raw.githubusercontent.com/' . self::REPOSITORY . '/' . $tag
                    . '/json/countries.json',
                2097152,
            );
            $files[] = $countries;
            $states = $this->download(
                'https://raw.githubusercontent.com/' . self::REPOSITORY . '/' . $tag . '/csv/states.csv',
                8388608,
            );
            $files[] = $states;
            $cities = $this->download(
                'https://github.com/' . self::REPOSITORY . '/releases/download/' . $tag
                    . '/csv-cities.csv.gz',
                16777216,
            );
            $files[] = $cities;
            if (
                !hash_equals(
                    substr($digest, 7),
                    (string) hash_file('sha256', $cities),
                )
            ) {
                throw new \RuntimeException('The city release digest did not match.');
            }
            $this->connection->update(
                'reference_update_jobs',
                [
                    'source_version' => $tag,
                    'safe_message' => 'Applying countries, regions and cities atomically',
                ],
                ['id' => $id],
            );
            $count = $this->connection->transactional(
                function () use ($tag, $countries, $states, $cities): int {
                    // Serialize simultaneous imports; a failure rolls back the entire reference revision.
                    $this->connection->executeStatement(
                        "UPDATE reference_countries SET code = code WHERE code = 'NA'",
                    );
                    $count = 0;
                    /** @var list<array<string, mixed>> $items */
                    $items = json_decode(
                        (string) file_get_contents($countries),
                        true,
                        64,
                        JSON_THROW_ON_ERROR,
                    );
                    if (count($items) < 200) {
                        throw new \RuntimeException('Incomplete upstream countries.');
                    }
                    foreach ($items as $country) {
                        $code = (string) $country['iso2'];
                        /** @var list<array{zoneName: string}> $zones */
                        $zones = $country['timezones'];
                        $timezones = array_column($zones, 'zoneName');
                        $this->upsert(
                            'reference_countries',
                            ['code' => $code],
                            [
                                'source_id' => (int) $country['id'],
                                'name' => $country['name'],
                                'currency' => $country['currency'],
                                'timezones_json' => json_encode($timezones, JSON_THROW_ON_ERROR),
                                'source_version' => $tag,
                                'active' => 1,
                            ],
                        );
                        if (
                            !$this->connection->fetchOne(
                                'SELECT country_code FROM country_settings WHERE country_code = ?',
                                [$code],
                            )
                        ) {
                            $this->connection->insert(
                                'country_settings',
                                [
                                    'country_code' => $code,
                                    'published' => 0,
                                    'account_group_id' => FeatureCatalog::STARTER_ACCOUNT,
                                    'invited_group_id' => FeatureCatalog::INVITED_ACCOUNT,
                                    'home_group_id' => FeatureCatalog::STARTER_HOME,
                                    'default_currency' => $country['currency'],
                                    'default_timezone' => $timezones[0] ?? 'UTC',
                                    'policy_id' => 'a1000000-0000-4000-8000-000000000005',
                                    'revision' => 1,
                                    'updated_at' => gmdate('Y-m-d H:i:s'),
                                ],
                            );
                        }
                        ++$count;
                    }
                    $count += $this->importPlaces($states, false, $tag);
                    $count += $this->importPlaces('compress.zlib://' . $cities, true, $tag);
                    foreach (
                        [
                        'reference_countries',
                        'reference_states',
                        'reference_cities',
                        ] as $table
                    ) {
                        // Retire removed source records without deleting local references or country settings.
                        $this->connection->executeStatement(
                            'UPDATE ' . $table . ' SET active = 0 WHERE source_version <> ?',
                            [$tag],
                        );
                    }
                    return $count;
                },
            );
            $this->connection->update(
                'reference_update_jobs',
                [
                    'status' => 'completed',
                    'processed_count' => $count,
                    'safe_message'
                        => 'Reference data updated; local policies, publication and groups preserved',
                    'completed_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['id' => $id],
            );
            $output->writeln(
                'Updated ' . $count . ' reference records from ' . $tag . '.',
            );
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $this->connection->update(
                'reference_update_jobs',
                [
                    'status' => 'failed',
                    'safe_message'
                        => ('The reference update failed. Existing data was retained; request a new '
                        . 'update to retry.'),
                    'completed_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['id' => $id],
            );
            $output->writeln(
                '<error>Reference update failed (' . $error::class
                    . '). Existing reference data was retained.</error>',
            );
            return Command::FAILURE;
        } finally {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }

    private function importPlaces(
        string $path,
        bool $cities,
        string $tag,
    ): int {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Reference stream unavailable.');
        }
        try {
            $header = fgetcsv($stream, 16384, ',', '"', '');
            if (
                $header === false || !in_array('country_code', $header, true)
                || !in_array('id', $header, true)
            ) {
                throw new \RuntimeException('Invalid reference header.');
            }
            $count = 0;
            while (($fields = fgetcsv($stream, 16384, ',', '"', '')) !== false) {
                if (count($fields) !== count($header) || ++$count > 2000000) {
                    throw new \RuntimeException('Invalid or excessive reference data.');
                }
                $row = array_combine(
                    array_map(
                        static fn(?string $column): string => (string) $column,
                        $header,
                    ),
                    $fields,
                );
                if (!ctype_digit((string) $row['id']) || strlen((string) $row['country_code']) !== 2) {
                    throw new \RuntimeException('Invalid reference identity.');
                }
                $values = [
                    'country_code' => $row['country_code'],
                    'name' => $row['name'],
                    'source_version' => $tag,
                    'active' => 1,
                ];
                if ($cities) {
                    $values += [
                        'state_id' => empty($row['state_id'])
                            ? null
                            : (int) $row['state_id'],
                        'latitude' => $row['latitude'] ?? null,
                        'longitude' => $row['longitude'] ?? null,
                        'timezone' => $row['timezone'] ?? null,
                    ];
                }
                $this->upsert(
                    $cities
                        ? 'reference_cities'
                        : 'reference_states',
                    ['source_id' => (int) $row['id']],
                    $values,
                );
            }
            if (
                $count < ($cities
                ? 1000
                : 100)
            ) {
                throw new \RuntimeException('Incomplete reference data.');
            }
            return $count;
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, int|string> $key
     * @param array<string, mixed> $values
     */
    private function upsert(
        string $table,
        array $key,
        array $values,
    ): void {
        $column = array_key_first($key);
        if (
            $this->connection->fetchOne(
                'SELECT ' . $column . ' FROM ' . $table . ' WHERE ' . $column . ' = ?',
                [reset($key)],
            ) !== false
        ) {
            $this->connection->update($table, $values, $key);
        } else {
            $this->connection->insert($table, [...$key, ...$values]);
        }
    }

    private function download(string $url, int $maximumBytes): string
    {
        $context = stream_context_create(
            [
                'http' => [
                    'timeout' => 60,
                    'header' => ('User-Agent: Providentia-Reference-Updater
Accept: '
                        . 'application/vnd.github+json
'),
                    'max_redirects' => 5,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ],
        );
        $source = fopen($url, 'rb', false, $context);
        if ($source === false) {
            throw new \RuntimeException('Upstream download unavailable.');
        }
        $path = tempnam(sys_get_temp_dir(), 'providentia-reference-');
        if ($path === false) {
            fclose($source);
            throw new \RuntimeException('Reference staging unavailable.');
        }
        $target = fopen($path, 'wb');
        if ($target === false) {
            fclose($source);
            unlink($path);
            throw new \RuntimeException('Reference staging is not writable.');
        }
        try {
            $bytes = stream_copy_to_stream($source, $target, $maximumBytes + 1);
            if ($bytes === false || $bytes === 0 || $bytes > $maximumBytes || !feof($source)) {
                throw new \RuntimeException(
                    'Reference download exceeded its limit or was incomplete.',
                );
            }
        } catch (\Throwable $error) {
            unlink($path);
            throw $error;
        } finally {
            fclose($source);
            fclose($target);
        }
        return $path;
    }
}
