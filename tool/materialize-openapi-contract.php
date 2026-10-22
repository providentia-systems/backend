<?php

declare(strict_types=1);

// Production image builds have PHP and zlib, but do not need Node.js or Composer
// development packages to bake the same lock-verified canonical contract.
$arguments = array_slice($_SERVER['argv'] ?? [], 1);
if ($arguments !== [] && $arguments !== ['--check']) {
    fwrite(STDERR, "Usage: php tool/materialize-openapi-contract.php [--check]\n");
    exit(2);
}
$checkOnly = $arguments === ['--check'];
$root = dirname(__DIR__);
$temporary = null;
$exitCode = 0;
try {
    $read = static function (string $path): string {
        if (!is_readable($path)) {
            throw new RuntimeException('A required OpenAPI source or lock is unavailable.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('The OpenAPI source or lock could not be read.');
        }
        return $contents;
    };
    $lock = json_decode($read($root . '/contracts/openapi/contract.lock.json'), true, 512, JSON_THROW_ON_ERROR);
    $expected = is_array($lock) ? ($lock['artifacts']['providentia-v1.json']['sha256'] ?? null) : null;
    if (!is_string($expected) || preg_match('/^[0-9a-f]{64}$/D', $expected) !== 1) {
        throw new RuntimeException('The canonical OpenAPI lock digest is invalid.');
    }
    $contract = @gzdecode($read($root . '/contracts/source/providentia-v1.json.gz'));
    if (!is_string($contract) || !hash_equals($expected, hash('sha256', $contract))) {
        throw new RuntimeException('The canonical OpenAPI source does not match its lock digest.');
    }
    $document = json_decode($contract, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($document) || !is_string($document['openapi'] ?? null) || !is_array($document['paths'] ?? null)) {
        throw new RuntimeException('The canonical OpenAPI document is invalid.');
    }
    $output = $root . '/contracts/openapi/providentia-v1.json';
    $matches = is_readable($output) && hash_equals($expected, (string) hash_file('sha256', $output));
    if ($checkOnly && !$matches) {
        throw new RuntimeException('The deployed canonical OpenAPI file is missing or does not match its lock.');
    }
    if (!$checkOnly && !$matches) {
        $temporary = tempnam(dirname($output), 'providentia-openapi-');
        if (
            $temporary === false || file_put_contents($temporary, $contract) !== strlen($contract)
            || !chmod($temporary, 0644) || !rename($temporary, $output)
        ) {
            throw new RuntimeException('The canonical OpenAPI document could not be installed atomically.');
        }
    }
    fwrite(STDOUT, 'Verified canonical OpenAPI SHA-256: ' . $expected . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if (is_string($temporary) && is_file($temporary)) {
        unlink($temporary);
    }
}
exit($exitCode);
