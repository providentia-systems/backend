<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\SharedKernel;

use PHPUnit\Framework\TestCase;

final class ProductionContractMaterializationTest extends TestCase
{
    private const CONTRACT = '{"openapi":"3.1.0","info":{"version":"image-fixture"},"paths":{}}';
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/providentia-image-contract-' . bin2hex(random_bytes(12));
        foreach (['tool', 'contracts/source', 'contracts/openapi'] as $directory) {
            self::assertTrue(mkdir($this->root . '/' . $directory, 0700, true));
        }
        self::assertTrue(copy(
            dirname(__DIR__, 3) . '/tool/materialize-openapi-contract.php',
            $this->root . '/tool/materialize-openapi-contract.php',
        ));
        self::assertNotFalse(file_put_contents(
            $this->root . '/contracts/source/providentia-v1.json.gz',
            gzencode(self::CONTRACT),
        ));
        self::assertNotFalse(file_put_contents(
            $this->root . '/contracts/openapi/contract.lock.json',
            json_encode(['artifacts' => ['providentia-v1.json' => [
                'sha256' => hash('sha256', self::CONTRACT),
            ]]], JSON_THROW_ON_ERROR),
        ));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/contracts/openapi/*') ?: [] as $file) {
            unlink($file);
        }
        unlink($this->root . '/contracts/source/providentia-v1.json.gz');
        unlink($this->root . '/tool/materialize-openapi-contract.php');
        foreach (['contracts/openapi', 'contracts/source', 'contracts', 'tool', ''] as $directory) {
            rmdir($this->root . '/' . $directory);
        }
    }

    public function testCleanImageMaterializesReadableLockVerifiedContractAndCheckDoesNotRewriteIt(): void
    {
        self::assertFileDoesNotExist($this->canonical());
        [$status, $output, $errors] = $this->runMaterializer();
        self::assertSame(0, $status, $errors);
        self::assertSame('', $errors);
        self::assertStringContainsString(hash('sha256', self::CONTRACT), $output);
        self::assertSame(self::CONTRACT, file_get_contents($this->canonical()));
        self::assertSame(0644, fileperms($this->canonical()) & 0777);
        self::assertTrue(touch($this->canonical(), 1234567890));
        [$status, , $errors] = $this->runMaterializer('--check');
        self::assertSame(0, $status, $errors);
        clearstatcache(true, $this->canonical());
        self::assertSame(1234567890, filemtime($this->canonical()));
    }

    public function testImageProofFailsForMissingOrTamperedContractWithoutRepairingIt(): void
    {
        [$status] = $this->runMaterializer('--check');
        self::assertSame(1, $status);
        self::assertFileDoesNotExist($this->canonical());
        self::assertNotFalse(file_put_contents($this->canonical(), 'tampered-image-contract'));
        [$status, , $errors] = $this->runMaterializer('--check');
        self::assertSame(1, $status);
        self::assertStringContainsString('missing or does not match', $errors);
        self::assertSame('tampered-image-contract', file_get_contents($this->canonical()));
    }

    public function testCorruptArchiveFailsBeforeReplacingAnyExistingCanonicalFile(): void
    {
        self::assertNotFalse(file_put_contents($this->canonical(), 'retained-original'));
        self::assertNotFalse(file_put_contents(
            $this->root . '/contracts/source/providentia-v1.json.gz',
            gzencode(self::CONTRACT . "\n"),
        ));
        [$status, , $errors] = $this->runMaterializer();
        self::assertSame(1, $status);
        self::assertStringContainsString('does not match its lock digest', $errors);
        self::assertSame('retained-original', file_get_contents($this->canonical()));
    }

    /** @return array{int, string, string} */
    private function runMaterializer(string ...$arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->root . '/tool/materialize-openapi-contract.php', ...array_values($arguments)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($output);
        self::assertIsString($errors);

        return [proc_close($process), $output, $errors];
    }

    private function canonical(): string
    {
        return $this->root . '/contracts/openapi/providentia-v1.json';
    }
}
