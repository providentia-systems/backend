<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Security;

use PHPUnit\Framework\TestCase;

final class ProductionConfigurationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (
            [
                'APP_ENV',
                'AUTH_TOKEN_PEPPER',
                'SYNC_CURSOR_SECRET',
                'EXPOSE_DEVELOPMENT_TOKENS',
                'MAIL_DSN',
                'PUBLIC_BASE_URL',
                'AI_SERVER_PROXY_ENABLED',
                'AI_CREDENTIAL_KEK',
                'NOTIFICATION_PAYLOAD_KEK',
            ] as $name
        ) {
            $this->previous[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testProductionRejectsPlaceholderSecrets(): void
    {
        $this->productionEnvironment();
        putenv('AUTH_TOKEN_PEPPER=development-only-change-me');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Production requires two independent');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
    }

    public function testProductionRejectsPlainSmtp(): void
    {
        $this->productionEnvironment();
        putenv('MAIL_DSN=smtp://smtp-user:secret@smtp.example.net:587');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Production MAIL_DSN must use smtps://');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
    }

    public function testProductionAcceptsIndependentSecretsAndImplicitTlsMail(): void
    {
        $this->productionEnvironment();

        /** @var array{app: array{environment: string}, mail: array{dsn: string}, identity: array{expose_development_tokens: bool}} $config */
        $config = require dirname(__DIR__, 3) . '/config/autoload/global.php';

        self::assertSame('production', $config['app']['environment']);
        self::assertSame('smtps://smtp.example.net:465', $config['mail']['dsn']);
        self::assertFalse($config['identity']['expose_development_tokens']);
    }

    public function testProductionAiProxyRequiresAnIndependentEnvelopeEncryptionKey(): void
    {
        $this->productionEnvironment();
        putenv('AI_SERVER_PROXY_ENABLED=1');
        putenv('AI_CREDENTIAL_KEK=');
        putenv('NOTIFICATION_PAYLOAD_KEK=' . base64_encode(str_repeat('n', 32)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI_CREDENTIAL_KEK');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
    }

    private function productionEnvironment(): void
    {
        putenv('APP_ENV=production');
        putenv('AUTH_TOKEN_PEPPER=' . str_repeat('a', 32));
        putenv('SYNC_CURSOR_SECRET=' . str_repeat('b', 32));
        putenv('EXPOSE_DEVELOPMENT_TOKENS=0');
        putenv('MAIL_DSN=smtps://smtp.example.net:465');
        putenv('PUBLIC_BASE_URL=https://app.example.net');
        putenv('AI_SERVER_PROXY_ENABLED=0');
        putenv('AI_CREDENTIAL_KEK=');
        putenv('NOTIFICATION_PAYLOAD_KEK=' . base64_encode(str_repeat('n', 32)));
    }
}
