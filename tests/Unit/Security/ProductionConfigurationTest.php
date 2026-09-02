<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
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
                'HOMEOWNER_APP_LINK_BASE',
                'ADMIN_APP_LINK_BASE',
                'AUTH_APP_LINK_ALLOWED_HOSTS',
                'CORS_ALLOWED_ORIGINS',
                'METRICS_ENABLED',
                'METRICS_BEARER_TOKEN',
                'AI_SERVER_PROXY_ENABLED',
                'AI_CREDENTIAL_KEK',
                'AI_MEDIA_KEK',
                'AI_MAX_IMAGES',
                'CATALOG_IMAGE_KEK',
                'CATALOG_IMAGE_KEY_VERSION',
                'CATALOG_IMAGE_PREVIOUS_KEYS_JSON',
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
         *   mail: array{dsn: string, public_base_url: string, public_origin: string},
         *   identity: array{expose_development_tokens: bool},
         *   http: array{allowed_origins: list<string>}
         * } $config
         */
        $config = require dirname(__DIR__, 3) . '/config/autoload/global.php';

        self::assertSame('production', $config['app']['environment']);
        self::assertSame('smtps://smtp.example.net:465', $config['mail']['dsn']);
        self::assertSame('https://api.example.net', $config['mail']['public_base_url']);
        self::assertSame('https://api.example.net', $config['mail']['public_origin']);
        self::assertFalse($config['identity']['expose_development_tokens']);
        self::assertArrayNotHasKey('password_login_enabled', $config['identity']);
        self::assertSame(
            ['https://client.example.net', 'https://api.example.net'],
            $config['http']['allowed_origins'],
        );
    }

    public function testProductionBrowserApprovalOriginRequiresHttps(): void
    {
        $this->productionEnvironment();
        putenv('PUBLIC_BASE_URL=http://api.example.net');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('production requires HTTPS');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
    }

    #[DataProvider('invalidPublicBaseUrls')]
    public function testBrowserApprovalBaseRejectsAnythingOtherThanAnOrigin(string $url): void
    {
        $this->productionEnvironment();
        putenv('PUBLIC_BASE_URL=' . $url);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PUBLIC_BASE_URL');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
    }

    /** @return list<array{string}> */
    public static function invalidPublicBaseUrls(): array
    {
        return [
            ['https://user@example.net'],
            ['https://api.example.net/login'],
            ['https://api.example.net?approval=credential'],
            ['https://api.example.net#approval=credential'],
            ["https://api.example.net\r\nInjected: value"],
        ];
    }

    public function testApplicationLinkRejectsAnEmbeddedCapabilityFragment(): void
    {
        $this->productionEnvironment();
        putenv('HOMEOWNER_APP_LINK_BASE=providentia://login-link/homeowner#credential');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HOMEOWNER_APP_LINK_BASE');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
    }

    public function testApplicationLinkRejectsAHostOutsideTheExactAllowlist(): void
    {
        $this->productionEnvironment();
        putenv('ADMIN_APP_LINK_BASE=providentia-admin://lookalike-login-link/admin');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_APP_LINK_BASE');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
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

    public function testDirectAiImageCountCannotExceedTheHttpUploadBoundary(): void
    {
        $this->productionEnvironment();
        putenv('AI_MAX_IMAGES=16');

        /** @var array{ai: array{max_images: int}} $config */
        $config = require dirname(__DIR__, 3) . '/config/autoload/global.php';

        self::assertSame(8, $config['ai']['max_images']);
    }

    public function testProductionCatalogImagesRequireAnIndependentEnvelopeEncryptionKey(): void
    {
        $this->productionEnvironment();
        putenv('CATALOG_IMAGE_KEK=invalid');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CATALOG_IMAGE_KEK');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
    }

    public function testCatalogImageReadKeyRingRejectsTheCurrentOrMalformedVersions(): void
    {
        $this->productionEnvironment();
        putenv('CATALOG_IMAGE_KEY_VERSION=2');
        putenv('CATALOG_IMAGE_PREVIOUS_KEYS_JSON=' . json_encode([
            ['version' => 2, 'kek' => base64_encode(str_repeat('o', 32))],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Previous catalog image keys need unique positive versions');
        require dirname(__DIR__, 3) . '/config/autoload/global.php';
    }

    public function testCatalogImageWriteKeyVersionMustBePositive(): void
    {
        $this->productionEnvironment();
        putenv('CATALOG_IMAGE_KEY_VERSION=0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CATALOG_IMAGE_KEY_VERSION must be a positive');
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
        putenv('SYNC_CURSOR_SECRET=' . str_repeat('b', 32));
        putenv('EXPOSE_DEVELOPMENT_TOKENS=0');
        putenv('MAIL_DSN=smtps://smtp.example.net:465');
        putenv('PUBLIC_BASE_URL=https://api.example.net');
        putenv('HOMEOWNER_APP_LINK_BASE=providentia://login-link/homeowner');
        putenv('ADMIN_APP_LINK_BASE=providentia-admin://login-link/admin');
        putenv('AUTH_APP_LINK_ALLOWED_HOSTS=login-link');
        putenv('CORS_ALLOWED_ORIGINS=https://client.example.net');
        putenv('METRICS_ENABLED=0');
        putenv('METRICS_BEARER_TOKEN=');
        putenv('AI_SERVER_PROXY_ENABLED=0');
        putenv('AI_CREDENTIAL_KEK=');
        putenv('AI_MEDIA_KEK=' . base64_encode(str_repeat('m', 32)));
        putenv('AI_MAX_IMAGES=8');
        putenv('CATALOG_IMAGE_KEK=' . base64_encode(str_repeat('c', 32)));
        putenv('CATALOG_IMAGE_KEY_VERSION=1');
        putenv('CATALOG_IMAGE_PREVIOUS_KEYS_JSON=[]');
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
