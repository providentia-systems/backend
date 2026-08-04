<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tool/check-coverage.php <clover.xml> <minimum-line-percent>\n");
    exit(2);
}

$path = $argv[1];
$minimum = filter_var($argv[2], FILTER_VALIDATE_FLOAT);
if (! is_string($path) || ! is_file($path) || ! is_float($minimum) || $minimum < 0 || $minimum > 100) {
    fwrite(STDERR, "Coverage path or minimum percentage is invalid.\n");
    exit(2);
}

$xml = file_get_contents($path);
if (! is_string($xml) || $xml === '') {
    fwrite(STDERR, "Coverage report is empty.\n");
    exit(2);
}

$matches = [];
$count = preg_match_all(
    '/<metrics\b[^>]*\bstatements="(?<statements>\d+)"[^>]*\bcoveredstatements="(?<covered>\d+)"[^>]*>/i',
    $xml,
    $matches,
);
if ($count === false || $count === 0) {
    fwrite(STDERR, "Coverage report has no Clover statement metrics.\n");
    exit(2);
}

$position = $count - 1;
$statements = (int) $matches['statements'][$position];
$covered = (int) $matches['covered'][$position];
if ($statements <= 0 || $covered < 0 || $covered > $statements) {
    fwrite(STDERR, "Coverage report contains inconsistent statement totals.\n");
    exit(2);
}

$percentage = ($covered / $statements) * 100;
fwrite(STDOUT, sprintf(
    "Line coverage: %.2f%% (%d/%d); required: %.2f%%.\n",
    $percentage,
    $covered,
    $statements,
    $minimum,
));

if ($percentage + 0.00001 < $minimum) {
    fwrite(STDERR, "Coverage is below the required release ratchet.\n");
    exit(1);
}
