<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tool/check-coverage.php <clover.xml> <minimum-line-percent> [path-prefix ...]\n");
    exit(2);
}

$path = $argv[1];
$minimum = filter_var($argv[2], FILTER_VALIDATE_FLOAT);
$prefixes = array_values(array_filter(array_slice($argv, 3), 'is_string'));
if (! is_string($path) || ! is_file($path) || $minimum === false || $minimum < 0 || $minimum > 100) {
    fwrite(STDERR, "Coverage path or minimum percentage is invalid.\n");
    exit(2);
}

$xml = file_get_contents($path);
if (! is_string($xml) || $xml === '') {
    fwrite(STDERR, "Coverage report is empty.\n");
    exit(2);
}

$statements = 0;
$covered = 0;
if ($prefixes === []) {
    $metrics = [];
    $count = preg_match_all(
        '/<metrics\b[^>]*\bstatements="(?<statements>\d+)"[^>]*\bcoveredstatements="(?<covered>\d+)"[^>]*>/i',
        $xml,
        $metrics,
    );
    if ($count === false || $count === 0) {
        fwrite(STDERR, "Coverage report has no Clover statement metrics.\n");
        exit(2);
    }
    $position = $count - 1;
    $statements = (int) $metrics['statements'][$position];
    $covered = (int) $metrics['covered'][$position];
} else {
    $files = [];
    $count = preg_match_all(
        '/<file\b[^>]*\bname="(?<name>[^"]+)"[^>]*>(?<body>.*?)<\/file>/is',
        $xml,
        $files,
        PREG_SET_ORDER,
    );
    if ($count === false || $count === 0) {
        fwrite(STDERR, "Coverage report has no Clover file metrics.\n");
        exit(2);
    }
    foreach ($files as $file) {
        $name = str_replace('\\', '/', html_entity_decode($file['name'], ENT_QUOTES | ENT_XML1));
        $included = false;
        foreach ($prefixes as $prefix) {
            $normalized = trim(str_replace('\\', '/', $prefix), '/');
            if ($normalized !== '' && str_contains('/' . ltrim($name, '/'), '/' . $normalized . '/')) {
                $included = true;
                break;
            }
        }
        if (! $included) {
            continue;
        }
        $metrics = [];
        if (preg_match_all(
            '/<metrics\b[^>]*\bstatements="(?<statements>\d+)"[^>]*\bcoveredstatements="(?<covered>\d+)"[^>]*>/i',
            $file['body'],
            $metrics,
        ) < 1) {
            continue;
        }
        $position = count($metrics['statements']) - 1;
        $statements += (int) $metrics['statements'][$position];
        $covered += (int) $metrics['covered'][$position];
    }
}

if ($statements <= 0 || $covered < 0 || $covered > $statements) {
    fwrite(STDERR, "Coverage report contains no consistent statement totals for the selected risk boundary.\n");
    exit(2);
}

$percentage = ($covered / $statements) * 100;
fwrite(STDOUT, sprintf(
    "Risk-boundary line coverage: %.2f%% (%d/%d); required: %.2f%%.\n",
    $percentage,
    $covered,
    $statements,
    $minimum,
));

if ($percentage + 0.00001 < $minimum) {
    fwrite(STDERR, "Coverage is below the required release ratchet.\n");
    exit(1);
}
