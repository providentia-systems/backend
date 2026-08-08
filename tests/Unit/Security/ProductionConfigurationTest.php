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
                'AUTH_PASSWORD_LOGIN_ENABLED',
                'SYNC_CURSOR_SECRET',
                'EXPOSE_DEVELOPMENT_TOKENS',
                'MAIL_DSN',
                'PUBLIC_BASE_URL',
                'AI_SERVER_PROXY_ENABLED',
                'AI_CREDENTIAL_KEK',
                'AI_MEDIA_KEK',
                'NOTIFICATION_PAYLOAD_KEK',
                'DATA_EXPORT_KEK',
                'BILLING_ENABLED',
                'BILLING_ALLOW_PRIVATE_ENDPOINTS',
                'PAYPAL_ENABLED',
                'PAYPAL_ENVIRONMENT',
                'PAYPAL_CLIENT_ID',
                'PAYPAL_CLIENT_SECRET',
                'PAYPAL_WEBHOOK_ID',
                'HOSTED_CARD_ENABLED',
                'HOSTED_CARD_API_BASE',
                'HOSTED_CARD_REDIRECT_HOSTS',
                'HOSTED_CARD_API_KEY',
                'HOSTED_CARD_WEBHOOK_SECRET',
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

        /**
         * @var array{
         *   app: array{environment: string},
         *   mail: array{dsn: string},
         *   identity: array{expose_development_tokens: bool, password_login_enabled: bool}
         * } $config
         */
        $config = require dirname(__DIR__, 3) . '/config/autoload/global.php';

        self::assertSame('production', $config['app']['environment']);
        self::assertSame('smtps://smtp.example.net:465', $config['mail']['dsn']);
        self::assertFalse($config['identity']['expose_development_tokens']);
        self::assertFalse($config['identity']['password_login_enabled']);
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

    public function testProductionPrivateMediaRequiresAnEnvelopeEncryptionKey(): void
    {
        $this->productionEnvironment();
        putenv('AI_MEDIA_KEK=invalid');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI_MEDIA_KEK');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
    }

    public function testProductionPayPalRequiresServerCredentialsAndWebhookIdentity(): void
    {
        $this->productionEnvironment();
        putenv('BILLING_ENABLED=1');
        putenv('PAYPAL_ENABLED=1');
        putenv('PAYPAL_CLIENT_ID=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Enabled PayPal billing requires');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
    }

    public function testProductionHostedCardRequiresHttpsAndHmacSecret(): void
    {
        $this->productionEnvironment();
        putenv('BILLING_ENABLED=1');
        putenv('HOSTED_CARD_ENABLED=1');
        putenv('HOSTED_CARD_API_BASE=http://payments.internal');
        putenv('HOSTED_CARD_REDIRECT_HOSTS=secure-payments.example.net');
        putenv('HOSTED_CARD_API_KEY=fixture-key');
        putenv('HOSTED_CARD_WEBHOOK_SECRET=short');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Enabled hosted-card billing requires HTTPS');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
    }

    private function productionEnvironment(): void
    {
        putenv('APP_ENV=production');
        putenv('AUTH_TOKEN_PEPPER=' . str_repeat('a', 32));
        putenv('AUTH_PASSWORD_LOGIN_ENABLED');
        putenv('SYNC_CURSOR_SECRET=' . str_repeat('b', 32));
        putenv('EXPOSE_DEVELOPMENT_TOKENS=0');
        putenv('MAIL_DSN=smtps://smtp.example.net:465');
        putenv('PUBLIC_BASE_URL=https://app.example.net');
        putenv('AI_SERVER_PROXY_ENABLED=0');
        putenv('AI_CREDENTIAL_KEK=');
        putenv('AI_MEDIA_KEK=' . base64_encode(str_repeat('m', 32)));
        putenv('NOTIFICATION_PAYLOAD_KEK=' . base64_encode(str_repeat('n', 32)));
        putenv('DATA_EXPORT_KEK=' . base64_encode(str_repeat('e', 32)));
        putenv('BILLING_ENABLED=0');
        putenv('BILLING_ALLOW_PRIVATE_ENDPOINTS=0');
        putenv('PAYPAL_ENABLED=0');
        putenv('PAYPAL_ENVIRONMENT=live');
        putenv('PAYPAL_CLIENT_ID=');
        putenv('PAYPAL_CLIENT_SECRET=');
        putenv('PAYPAL_WEBHOOK_ID=');
        putenv('HOSTED_CARD_ENABLED=0');
        putenv('HOSTED_CARD_API_BASE=');
        putenv('HOSTED_CARD_REDIRECT_HOSTS=');
        putenv('HOSTED_CARD_API_KEY=');
        putenv('HOSTED_CARD_WEBHOOK_SECRET=');
    }
}
