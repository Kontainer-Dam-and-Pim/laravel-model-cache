<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

/**
 * @param  list<int>  $lines
 */
function ranges(array $lines): string
{
    $ranges = [];
    $start = $previous = array_shift($lines);

    foreach ([...$lines, null] as $line) {
        if ($line === $previous + 1) {
            $previous = $line;

            continue;
        }

        $ranges[] = $start === $previous ? (string) $start : "{$start}-{$previous}";
        $start = $previous = $line;
    }

    return implode(', ', $ranges);
}

$clover = $argv[1] ?? fail('usage: check-coverage.php <clover.xml>');
$root = realpath(__DIR__.'/../..').'/';
$document = simplexml_load_file($clover) ?: fail("Could not read the Clover report {$clover}");

$uncovered = [];
$statements = 0;

foreach ($document->xpath('//file') ?: [] as $file) {
    $lines = [];

    foreach ($file->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }

        $statements++;

        if ((int) $line['count'] === 0) {
            $lines[] = (int) $line['num'];
        }
    }

    if ($lines !== []) {
        $uncovered[str_replace($root, '', (string) $file['name'])] = $lines;
    }
}

if ($statements === 0) {
    fail("The Clover report {$clover} covers no statements");
}

ksort($uncovered, SORT_STRING);
$missed = array_sum(array_map('count', $uncovered));

foreach ($uncovered as $path => $lines) {
    echo "{$path}: ".ranges($lines).PHP_EOL;
}

printf('Line coverage: %d of %d statements (%.2f%%)%s', $statements - $missed, $statements, 100 * ($statements - $missed) / $statements, PHP_EOL);

exit($missed === 0 ? 0 : 1);
