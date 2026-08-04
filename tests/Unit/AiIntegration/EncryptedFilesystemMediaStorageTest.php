<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Infrastructure\Media\EncryptedFilesystemMediaStorage;

final class EncryptedFilesystemMediaStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/providentia-media-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testRoundTripStoresOnlyCiphertextAndSupportsDeletion(): void
    {
        $storage = $this->storage('m');
        $plaintext = "receipt image bytes\0private";
        $object = $storage->store('home-1', 'asset-1', $plaintext);
        $stored = file_get_contents($this->directory . '/' . $object->objectKey);

        self::assertIsString($stored);
        self::assertStringNotContainsString($plaintext, $stored);
        self::assertSame(hash('sha256', $plaintext), $object->sha256);
        self::assertSame($plaintext, $storage->read('home-1', 'asset-1', $object));

        $storage->delete($object);
        self::assertFileDoesNotExist($this->directory . '/' . $object->objectKey);
    }

    public function testWrongHomeAssociatedDataCannotDecrypt(): void
    {
        $storage = $this->storage('m');
        $object = $storage->store('home-1', 'asset-1', 'private media');

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('failed authentication');
        $storage->read('home-2', 'asset-1', $object);
    }

    public function testWrongKeyCannotUnwrapAssetKey(): void
    {
        $object = $this->storage('m')->store('home-1', 'asset-1', 'private media');

        $this->expectException(AiProviderException::class);
        $this->storage('x')->read('home-1', 'asset-1', $object);
    }

    private function storage(string $byte): EncryptedFilesystemMediaStorage
    {
        return new EncryptedFilesystemMediaStorage(
            $this->directory,
            base64_encode(str_repeat($byte, 32)),
            1,
        );
    }
}
