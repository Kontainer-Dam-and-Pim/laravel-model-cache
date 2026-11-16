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

const CHART_CLIENT = 'phpredis';

const CHART_THEMES = [
    'light' => [
        'series' => ['#2a78d6', '#eb6834', '#1baf7a'],
        'surface' => '#ffffff',
        'primary' => '#1f2328',
        'secondary' => '#59636e',
        'grid' => '#d1d9e0',
    ],
    'dark' => [
        'series' => ['#3987e5', '#d95926', '#199e70'],
        'surface' => '#0d1117',
        'primary' => '#f0f6fc',
        'secondary' => '#9198a1',
        'grid' => '#3d444d',
    ],
];

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
        details(passing($compatibility).' PHP, Laravel and database combinations pass', table(['PHP', 'Laravel', ...array_values($columns)], $tableRows)),
    ];

    if ($suiteRows !== []) {
        $sections[] = details(passing($suites).' runs of the deeper suites pass, on the locked dependencies', table(['Suite', 'Database', 'Redis client', 'PHP', 'Result'], $suiteRows));
    }

    return implode("\n\n", $sections);
}

/**
 * @param  list<array<string, mixed>>  $results
 */
function passing(array $results): string
{
    $passed = count(array_filter($results, static fn (array $result): bool => $result['outcome'] === 'success'));

    return $passed === count($results) ? "All {$passed}" : "{$passed} of ".count($results);
}

function details(string $summary, string $content): string
{
    return "<details>\n<summary>{$summary}</summary>\n\n{$content}\n\n</details>";
}

function readsPerSecond(float $value): string
{
    return number_format($value, 0, '.', ',');
}

/**
 * @param  list<array<string, mixed>>  $results
 * @return list<array<string, mixed>>
 */
function successfulBenchmarks(array $results): array
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

    $shapes = shapeLabels($benchmarks[0]);

    foreach ($benchmarks as $result) {
        if (shapeLabels($result) !== $shapes) {
            fail("Benchmark on {$result['database']} with {$result['redis_client']} measured different shapes");
        }
    }

    return $benchmarks;
}

/**
 * @param  array<string, mixed>  $result
 * @return list<string>
 */
function shapeLabels(array $result): array
{
    return array_map(static fn (array $shape): string => (string) $shape['label'], $result['benchmark']['shapes']);
}

/**
 * @param  array<string, mixed>  $shape
 */
function speedup(array $shape): float
{
    return $shape['cached_reads_per_second'] / $shape['uncached_reads_per_second'];
}

/**
 * @param  list<array<string, mixed>>  $benchmarks
 */
function renderBenchmarks(array $benchmarks, string $runUrl): string
{
    $reference = $benchmarks[0]['benchmark'];
    $shapes = shapeLabels($benchmarks[0]);
    $rows = [];

    foreach ($benchmarks as $result) {
        $measured = $result['benchmark'];

        $rows[] = [
            DATABASES[$result['database']].' '.$result['database_version'],
            REDIS_CLIENTS[$result['redis_client']].' '.$result['redis_client_version'],
            ...array_map(static fn (array $shape): string => sprintf(
                '%s → %s (%.1f×)',
                readsPerSecond((float) $shape['uncached_reads_per_second']),
                readsPerSecond((float) $shape['cached_reads_per_second']),
                speedup($shape),
            ), $measured['shapes']),
        ];
    }

    return implode("\n\n", [
        sprintf(
            'Generated by [CI](%s) on PHP %s with Redis %s. Reads per second without the cache → with it, each the median of %s alternating rounds of %s reads cycling through %s distinct queries on %s rows, all cached reads being hits. '
            .'Both Redis clients of a database are measured one after the other on the same runner. Every cached read takes exactly one Redis command. The runners are shared, so compare the ratios rather than the absolute numbers.',
            $runUrl,
            distinct($benchmarks, 'php'),
            distinct($benchmarks, 'redis_version'),
            number_format((int) ($reference['rounds'] ?? fail('Benchmark results lack the number of rounds'))),
            number_format((int) $reference['reads']),
            number_format((int) $reference['distinct_queries']),
            number_format((int) $reference['cars']),
        ),
        '<picture>'
        .'<source media="(prefers-color-scheme: dark)" srcset="art/benchmarks-dark.svg">'
        .'<img src="art/benchmarks-light.svg" alt="How many times more reads per second each database serves with the cache, per query shape, with '.CHART_CLIENT.'. The table below has every number.">'
        .'</picture>',
        details('Reads per second on every database and Redis client', table(['Database', 'Redis client', ...$shapes], $rows)),
    ]);
}

function svgText(string $text): string
{
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function bar(float $x, float $y, float $width, float $height, string $fill): string
{
    $radius = min(4.0, $width / 2, $height / 2);
    $straight = $width - $radius;

    return sprintf(
        '<path fill="%s" d="M%.1f %.1fh%.1fq%.1f 0 %.1f %.1fv%.1fq0 %.1f %.1f %.1fh%.1fz"/>',
        $fill, $x, $y, $straight, $radius, $radius, $radius, $height - 2 * $radius, $radius, -$radius, $radius, -$straight,
    );
}

/**
 * @param  list<array<string, mixed>>  $benchmarks
 * @param  array{series: list<string>, surface: string, primary: string, secondary: string, grid: string}  $theme
 */
function benchmarkChart(array $benchmarks, array $theme): string
{
    $charted = array_values(array_filter($benchmarks, static fn (array $result): bool => $result['redis_client'] === CHART_CLIENT));

    if ($charted === []) {
        fail('No successful '.CHART_CLIENT.' benchmark results to chart');
    }

    $shapes = shapeLabels($charted[0]);

    if (count($shapes) > count($theme['series'])) {
        fail('The benchmark chart has colors for '.count($theme['series']).' query shapes, not '.count($shapes));
    }

    $width = 880;
    $plotLeft = 150.0;
    $plotRight = $width - 64.0;
    $plotTop = 76.0;
    $barHeight = 16.0;
    $barGap = 2.0;
    $groupHeight = count($shapes) * $barHeight + (count($shapes) - 1) * $barGap;
    $groupStep = $groupHeight + 26.0;
    $plotBottom = $plotTop + count($charted) * $groupStep - 26.0;
    $height = (int) ceil($plotBottom + 40.0);
    $maximum = max(2, (int) ceil(max(array_merge(...array_map(
        static fn (array $result): array => array_map('speedup', $result['benchmark']['shapes']),
        $charted,
    )))));
    $x = static fn (float $ratio): float => $plotLeft + ($plotRight - $plotLeft) * $ratio / $maximum;
    $font = "font-family=\"-apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans', Helvetica, Arial, sans-serif\"";
    $parts = [];

    $legendX = $plotLeft;
    foreach ($shapes as $index => $shape) {
        $parts[] = sprintf('<rect x="%.1f" y="18" width="12" height="12" rx="2" fill="%s"/>', $legendX, $theme['series'][$index]);
        $parts[] = sprintf('<text x="%.1f" y="28.5" font-size="13" fill="%s">%s</text>', $legendX + 18, $theme['primary'], svgText($shape));
        $legendX += 18 + 6.6 * mb_strlen($shape) + 24;
    }

    $parts[] = sprintf('<text x="%.1f" y="56" font-size="12" fill="%s">Reads per second with the cache ÷ without it, using %s. Higher is better; below 1× the cache costs time.</text>', $plotLeft, $theme['secondary'], CHART_CLIENT);

    for ($tick = 0; $tick <= $maximum; $tick++) {
        $tickX = $x((float) $tick);
        $parts[] = $tick === 1
            ? sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="1.5" stroke-dasharray="4 4"/>', $tickX, $plotTop - 8, $tickX, $plotBottom + 6, $theme['secondary'])
            : sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="1"/>', $tickX, $plotTop - 8, $tickX, $plotBottom + 6, $theme['grid']);
        $parts[] = sprintf('<text x="%.1f" y="%.1f" font-size="12" text-anchor="middle" fill="%s">%d×</text>', $tickX, $plotBottom + 24, $theme['secondary'], $tick);
    }

    foreach ($charted as $row => $result) {
        $top = $plotTop + $row * $groupStep;
        $middle = $top + $groupHeight / 2;
        $parts[] = sprintf('<text x="%.1f" y="%.1f" font-size="14" font-weight="600" text-anchor="end" fill="%s">%s</text>', $plotLeft - 16, $middle - 2, $theme['primary'], svgText(DATABASES[$result['database']]));
        $parts[] = sprintf('<text x="%.1f" y="%.1f" font-size="12" text-anchor="end" fill="%s">%s</text>', $plotLeft - 16, $middle + 14, $theme['secondary'], svgText((string) $result['database_version']));

        foreach ($result['benchmark']['shapes'] as $index => $shape) {
            $ratio = speedup($shape);
            $barTop = $top + $index * ($barHeight + $barGap);
            $barEnd = $x($ratio);
            $parts[] = bar($plotLeft, $barTop, $barEnd - $plotLeft, $barHeight, $theme['series'][$index]);
            $parts[] = sprintf('<text x="%.1f" y="%.1f" font-size="12" fill="%s" stroke="%s" stroke-width="4" stroke-linejoin="round" paint-order="stroke">%.1f×</text>', $barEnd + 6, $barTop + 12, $theme['secondary'], $theme['surface'], $ratio);
        }
    }

    return sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" %s>', $width, $height, $width, $height, $font)
        .'<title>Read throughput with the cache compared to without it</title>'
        .implode('', $parts)
        ."</svg>\n";
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

$options = getopt('', ['results:', 'run-url:', 'readme:', 'art:']);
$resultsDirectory = $options['results'] ?? fail('usage: render-report.php --results=<dir> --run-url=<url> [--readme=<README.md> --art=<art directory>]');
$runUrl = $options['run-url'] ?? fail('--run-url is required');

if (isset($options['readme']) !== isset($options['art'])) {
    fail('--readme and --art go together, since the README shows the charts written to the art directory');
}

$results = loadResults((string) $resultsDirectory);
$benchmarks = successfulBenchmarks($results);
$sections = [
    'compatibility' => renderCompatibility($results, (string) $runUrl),
    'benchmarks' => renderBenchmarks($benchmarks, (string) $runUrl),
];

echo "## Compatibility\n\n{$sections['compatibility']}\n\n## Benchmarks\n\n{$sections['benchmarks']}\n";

if (isset($options['readme'])) {
    $readme = (string) $options['readme'];
    $document = file_get_contents($readme);

    if ($document === false) {
        fail("Could not read {$readme}");
    }

    foreach (CHART_THEMES as $name => $theme) {
        writeAtomically(rtrim((string) $options['art'], '/')."/benchmarks-{$name}.svg", benchmarkChart($benchmarks, $theme));
    }

    writeAtomically($readme, replaceSections($document, $sections));
}
