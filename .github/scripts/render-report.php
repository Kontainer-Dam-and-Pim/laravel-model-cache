<?php

declare(strict_types=1);

const DATABASES = [
    'sqlite' => 'SQLite',
    'mysql' => 'MySQL',
    'mariadb' => 'MariaDB',
    'pgsql' => 'PostgreSQL',
];

const REDIS_CLIENTS = [
    'phpredis' => 'phpredis',
    'predis' => 'Predis',
];

const DEPENDENCY_ORDER = ['lowest' => 0, 'highest' => 1, 'locked' => 2];

const OUTCOMES = [
    'success' => '✅',
    'failure' => '❌',
    'cancelled' => '⏹️',
    'skipped' => '⏭️',
];

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

/**
 * @return list<array<string, mixed>>
 */
function loadResults(string $directory): array
{
    $files = glob(rtrim($directory, '/').'/*.json') ?: [];
    sort($files, SORT_STRING);

    if ($files === []) {
        fail("No result files found in {$directory}");
    }

    return array_map(static function (string $file): array {
        $contents = file_get_contents($file);

        if ($contents === false) {
            fail("Could not read {$file}");
        }

        try {
            $result = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            fail("Invalid JSON in {$file}: {$exception->getMessage()}");
        }

        if (! is_array($result) || ! isset($result['kind'], $result['outcome'], $result['database'], $result['redis_client'])) {
            fail("Result {$file} lacks kind, outcome, database or redis_client");
        }

        if (! isset(DATABASES[$result['database']])) {
            fail("Result {$file} names unknown database {$result['database']}");
        }

        if (! isset(REDIS_CLIENTS[$result['redis_client']])) {
            fail("Result {$file} names unknown Redis client {$result['redis_client']}");
        }

        return $result;
    }, $files);
}

/**
 * @param  list<array<string, mixed>>  $results
 * @return list<array<string, mixed>>
 */
function ofKind(array $results, string $kind): array
{
    return array_values(array_filter($results, static fn (array $result): bool => $result['kind'] === $kind));
}

/**
 * @param  list<array<string, mixed>>  $results
 */
function distinct(array $results, string $field): string
{
    $values = array_values(array_unique(array_map(static fn (array $result): string => (string) $result[$field], $results)));
    usort($values, 'version_compare');

    return implode(', ', $values);
}

function phpMinor(string $version): string
{
    return implode('.', array_slice(explode('.', $version), 0, 2));
}

function outcome(string $outcome): string
{
    return OUTCOMES[$outcome] ?? fail("Unknown job outcome {$outcome}");
}

/**
 * @param  list<string>  $header
 * @param  list<list<string>>  $rows
 */
function table(array $header, array $rows): string
{
    $lines = [
        '| '.implode(' | ', $header).' |',
        '|'.implode('|', array_fill(0, count($header), '---')).'|',
    ];

    foreach ($rows as $row) {
        $lines[] = '| '.implode(' | ', $row).' |';
    }

    return implode("\n", $lines);
}

/**
 * @param  list<array<string, mixed>>  $results
 * @return array<string, string>
 */
function databaseColumns(array $results): array
{
    $columns = [];

    foreach (DATABASES as $database => $name) {
        $ofDatabase = array_values(array_filter($results, static fn (array $result): bool => $result['database'] === $database));

        if ($ofDatabase !== []) {
            $columns[$database] = $name.' '.distinct($ofDatabase, 'database_version');
        }
    }

    return $columns;
}

/**
 * @param  list<array<string, mixed>>  $results
 */
function renderCompatibility(array $results, string $runUrl): string
{
    $compatibility = ofKind($results, 'compatibility');

    if ($compatibility === []) {
        fail('No compatibility results to render');
    }

    $columns = databaseColumns($compatibility);
    $rows = [];

    foreach ($compatibility as $result) {
        $rows[phpMinor((string) $result['php']).' '.$result['dependencies']][] = $result;
    }

    uksort($rows, static function (string $left, string $right) use ($rows): int {
        $a = $rows[$left][0];
        $b = $rows[$right][0];

        return version_compare(phpMinor((string) $a['php']), phpMinor((string) $b['php']))
            ?: (DEPENDENCY_ORDER[$a['dependencies']] ?? fail("Unknown dependency set {$a['dependencies']}"))
            <=> (DEPENDENCY_ORDER[$b['dependencies']] ?? fail("Unknown dependency set {$b['dependencies']}"));
    });

    $tableRows = [];

    foreach ($rows as $row) {
        $byDatabase = [];

        foreach ($row as $result) {
            if (isset($byDatabase[$result['database']])) {
                fail("Two compatibility results for PHP {$result['php']}, {$result['dependencies']} dependencies, {$result['database']}");
            }

            $byDatabase[$result['database']] = outcome((string) $result['outcome']);
        }

        $tableRows[] = [
            distinct($row, 'php'),
            distinct($row, 'laravel').' ('.$row[0]['dependencies'].')',
            ...array_map(static fn (string $database): string => $byDatabase[$database] ?? '', array_keys($columns)),
        ];
    }

    $redis = 'Redis '.distinct($compatibility, 'redis_version').', '
        .REDIS_CLIENTS[$compatibility[0]['redis_client']].' '.distinct($compatibility, 'redis_client_version');

    $suites = ofKind($results, 'suite');
    usort($suites, static fn (array $a, array $b): int => [$a['suite'], array_search($a['database'], array_keys(DATABASES), true), $a['redis_client']]
        <=> [$b['suite'], array_search($b['database'], array_keys(DATABASES), true), $b['redis_client']]);

    $suiteRows = array_map(static fn (array $result): array => [
        (string) $result['suite'],
        DATABASES[$result['database']].' '.$result['database_version'],
        REDIS_CLIENTS[$result['redis_client']].' '.$result['redis_client_version'],
        'PHP '.$result['php'],
        outcome((string) $result['outcome']),
    ], $suites);

    $sections = [
        "Generated by [CI]({$runUrl}). Each cell runs the Unit and Integration suites against {$redis}. "
        .'"lowest" resolves every dependency to the oldest version `composer.json` allows, "highest" to the newest.',
        table(['PHP', 'Laravel', ...array_values($columns)], $tableRows),
    ];

    if ($suiteRows !== []) {
        $sections[] = 'Deeper suites, on the locked dependencies:';
        $sections[] = table(['Suite', 'Database', 'Redis client', 'PHP', 'Result'], $suiteRows);
    }

    return implode("\n\n", $sections);
}

function readsPerSecond(float $value): string
{
    return number_format($value, 0, '.', ',');
}

/**
 * @param  list<array<string, mixed>>  $results
 */
function renderBenchmarks(array $results, string $runUrl): string
{
    $benchmarks = array_values(array_filter(
        ofKind($results, 'benchmark'),
        static fn (array $result): bool => $result['outcome'] === 'success' && is_array($result['benchmark']),
    ));

    if ($benchmarks === []) {
        fail('No successful benchmark results to render');
    }

    usort($benchmarks, static fn (array $a, array $b): int => [array_search($a['database'], array_keys(DATABASES), true), $a['redis_client']]
        <=> [array_search($b['database'], array_keys(DATABASES), true), $b['redis_client']]);

    $reference = $benchmarks[0]['benchmark'];
    $shapes = array_map(static fn (array $shape): string => (string) $shape['label'], $reference['shapes']);
    $rows = [];

    foreach ($benchmarks as $result) {
        $measured = $result['benchmark'];

        if (array_map(static fn (array $shape): string => (string) $shape['label'], $measured['shapes']) !== $shapes) {
            fail("Benchmark on {$result['database']} with {$result['redis_client']} measured different shapes");
        }

        $rows[] = [
            DATABASES[$result['database']].' '.$result['database_version'],
            REDIS_CLIENTS[$result['redis_client']].' '.$result['redis_client_version'],
            ...array_map(static fn (array $shape): string => sprintf(
                '%s → %s (%.1f×)',
                readsPerSecond((float) $shape['uncached_reads_per_second']),
                readsPerSecond((float) $shape['cached_reads_per_second']),
                $shape['cached_reads_per_second'] / $shape['uncached_reads_per_second'],
            ), $measured['shapes']),
        ];
    }

    return implode("\n\n", [
        sprintf(
            'Generated by [CI](%s) on PHP %s with Redis %s. Reads per second without the cache → with it, over %s reads cycling through %s distinct queries on %s rows, all cached reads being hits. '
            .'Every cached read takes exactly one Redis command. The runners are shared, so compare the ratios rather than the absolute numbers.',
            $runUrl,
            distinct($benchmarks, 'php'),
            distinct($benchmarks, 'redis_version'),
            number_format((int) $reference['reads']),
            number_format((int) $reference['distinct_queries']),
            number_format((int) $reference['cars']),
        ),
        table(['Database', 'Redis client', ...$shapes], $rows),
    ]);
}

/**
 * @param  array<string, string>  $sections
 */
function replaceSections(string $document, array $sections): string
{
    foreach ($sections as $name => $content) {
        $start = "<!-- {$name}:start -->";
        $end = "<!-- {$name}:end -->";
        $from = strpos($document, $start);
        $to = strpos($document, $end);

        if ($from === false || $to === false || $to < $from || substr_count($document, $start) !== 1 || substr_count($document, $end) !== 1) {
            fail("The document needs exactly one {$start} followed by one {$end}");
        }

        $document = substr($document, 0, $from + strlen($start))."\n".$content."\n".substr($document, $to);
    }

    return $document;
}

function writeAtomically(string $path, string $contents): void
{
    $temporary = $path.'.tmp';

    if (file_put_contents($temporary, $contents) === false || ! rename($temporary, $path)) {
        fail("Could not write {$path}");
    }
}

$options = getopt('', ['results:', 'run-url:', 'readme:']);
$resultsDirectory = $options['results'] ?? fail('usage: render-report.php --results=<dir> --run-url=<url> [--readme=<README.md>]');
$runUrl = $options['run-url'] ?? fail('--run-url is required');

$results = loadResults((string) $resultsDirectory);
$sections = [
    'compatibility' => renderCompatibility($results, (string) $runUrl),
    'benchmarks' => renderBenchmarks($results, (string) $runUrl),
];

echo "## Compatibility\n\n{$sections['compatibility']}\n\n## Benchmarks\n\n{$sections['benchmarks']}\n";

if (isset($options['readme'])) {
    $readme = (string) $options['readme'];
    $document = file_get_contents($readme);

    if ($document === false) {
        fail("Could not read {$readme}");
    }

    writeAtomically($readme, replaceSections($document, $sections));
}
