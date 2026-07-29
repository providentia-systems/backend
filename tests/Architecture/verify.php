<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
$errors = [];

foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $source = (string) file_get_contents($path);
    $relative = substr($path, strlen($root) + 1);

    if (str_contains($relative, '/Domain/')) {
        foreach (['Doctrine\\', 'Laminas\\', 'Mezzio\\', 'Enqueue\\', 'Interop\\Queue', 'Psr\\Http'] as $forbidden) {
            if (str_contains($source, $forbidden)) {
                $errors[] = $relative . ' Domain dependency: ' . $forbidden;
            }
        }
    }
    if (str_contains($relative, '/Application/')) {
        foreach (['Doctrine\\', 'Laminas\\', 'Mezzio\\', 'Enqueue\\', 'Interop\\Queue', 'Psr\\Http'] as $forbidden) {
            if (str_contains($source, $forbidden)) {
                $errors[] = $relative . ' Application dependency: ' . $forbidden;
            }
        }
    }
    if (str_contains($relative, '/Http/')) {
        foreach (['Doctrine\\', 'Enqueue\\', 'Interop\\Queue', 'Redis\\', 'use Redis;'] as $forbidden) {
            if (str_contains($source, $forbidden)) {
                $errors[] = $relative . ' Http dependency: ' . $forbidden;
            }
        }
    }
    if (
        (str_contains($relative, '/Domain/')
            || str_contains($relative, '/Application/')
            || str_contains($relative, '/Http/'))
        && preg_match('/use Providentia\\\\[^;]+\\\\Infrastructure\\\\/', $source) === 1
    ) {
        $errors[] = $relative . ' reaches into Infrastructure';
    }
    if (preg_match('/ContainerInterface\\s+\\$[a-zA-Z]/', $source) === 1
        && ! str_contains($relative, '/Infrastructure/Factory/')) {
        $errors[] = $relative . ' injects the service container outside a factory';
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Architecture dependency rules passed.\n");
