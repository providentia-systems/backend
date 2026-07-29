#!/usr/bin/env php
<?php

declare(strict_types=1);

$report = $argv[1] ?? '';
if ($report === '' || ! is_file($report)) {
    fwrite(STDERR, "Usage: check-composer-licenses.php <composer-licenses.json>\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
$allowed = [
    '0BSD',
    'Apache-2.0',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'GPL-2.0',
    'GPL-2.0-only',
    'GPL-2.0-or-later',
    'GPL-3.0',
    'GPL-3.0-only',
    'GPL-3.0-or-later',
    'ISC',
    'LGPL-2.1',
    'LGPL-2.1-only',
    'LGPL-2.1-or-later',
    'LGPL-3.0',
    'LGPL-3.0-only',
    'LGPL-3.0-or-later',
    'MIT',
    'MPL-2.0',
    'Unlicense',
];
$errors = [];

foreach ($data['dependencies'] ?? [] as $name => $dependency) {
    $licenses = $dependency['license'] ?? [];
    if (! is_array($licenses) || $licenses === []) {
        $errors[] = $name . ': no declared licence';
        continue;
    }
    foreach ($licenses as $license) {
        if (! in_array($license, $allowed, true)) {
            $errors[] = $name . ': unapproved licence ' . $license;
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "All Composer dependency licences are on the Phase 1 OSI allowlist.\n");

