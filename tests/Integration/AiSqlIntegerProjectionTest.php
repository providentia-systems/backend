<?php

declare(strict_types=1);

namespace ProvidentiaTest\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\Response\JsonResponse;
use PDO;
use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Infrastructure\Doctrine\DbalAiStore;
use UnexpectedValueException;

/** Real PDO/DBAL reads and production JSON encoding, not a mocked store. */
final class AiSqlIntegerProjectionTest extends TestCase
{
    private Connection $connection;
    private DbalAiStore $store;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $native = $this->connection->getNativeConnection();
        self::assertInstanceOf(PDO::class, $native);
        $this->pdo = $native;
        $this->store = new DbalAiStore($this->connection);
        $this->connection->executeStatement(
            'CREATE TABLE ai_settings (home_id TEXT PRIMARY KEY, mode TEXT, provider TEXT,
             model TEXT, revision INTEGER, updated_by_user_id TEXT, created_at TEXT, updated_at TEXT)',
        );
        $this->connection->executeStatement(
            'CREATE TABLE ai_orchestration_policies (home_id TEXT PRIMARY KEY,
             extraction_profile_ids_json TEXT, validation_profile_id TEXT, max_attempts INTEGER,
             max_total_tokens INTEGER, max_estimated_cost_micros INTEGER, revision INTEGER, updated_at TEXT)',
        );
        $this->connection->executeStatement(
            'CREATE TABLE ai_provider_profiles (id TEXT PRIMARY KEY, home_id TEXT, label TEXT,
             provider TEXT, model TEXT, owner_user_id TEXT, endpoint TEXT, ciphertext TEXT, nonce TEXT,
             key_version INTEGER, last_four TEXT, estimated_cost_micros INTEGER, status TEXT,
             revision INTEGER, created_at TEXT, updated_at TEXT)',
        );
        $this->connection->insert('ai_settings', [
            'home_id' => 'home-a', 'mode' => 'manual_only', 'provider' => null, 'model' => null,
            'revision' => 3, 'updated_by_user_id' => 'owner',
            'created_at' => '2026-09-15 12:00:00', 'updated_at' => '2026-09-15 12:00:00',
        ]);
        $this->connection->insert('ai_orchestration_policies', [
            'home_id' => 'home-a', 'extraction_profile_ids_json' => '["shared"]',
            'validation_profile_id' => null, 'max_attempts' => 2, 'max_total_tokens' => 12000,
            'max_estimated_cost_micros' => 50000, 'revision' => 4, 'updated_at' => '2026-09-15 12:00:00',
        ]);
        foreach (['shared' => null, 'private' => 'other-user'] as $id => $owner) {
            $this->connection->insert('ai_provider_profiles', [
                'id' => $id, 'home_id' => 'home-a', 'label' => $id, 'provider' => 'ollama',
                'model' => 'synthetic-model', 'owner_user_id' => $owner, 'endpoint' => null,
                'ciphertext' => null, 'nonce' => null, 'key_version' => null, 'last_four' => null,
                'estimated_cost_micros' => 0, 'status' => 'active', 'revision' => 2,
                'created_at' => '2026-09-15 12:00:00', 'updated_at' => '2026-09-15 12:00:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testStringifyingDriverPreservesJsonIntegers(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        self::assertSame('3', $this->connection->fetchOne('SELECT revision FROM ai_settings'));
        $settings = $this->store->settings('home-a');
        self::assertNotNull($settings);
        self::assertSame(3, $settings['revision']);
        $policy = $this->store->orchestrationPolicy('home-a');
        self::assertNotNull($policy);
        self::assertSame(4, $policy['revision']);
        self::assertSame(2, $policy['maxAttempts']);
        self::assertSame(12000, $policy['maxTotalTokens']);
        self::assertSame(50000, $policy['maxEstimatedCostMicros']);
        $profiles = $this->store->providerProfiles('home-a', 'owner');
        self::assertCount(1, $profiles);
        self::assertSame('shared', $profiles[0]['id']);
        self::assertSame(2, $profiles[0]['revision']);
        self::assertSame(0, $profiles[0]['estimatedCostMicros']);
        self::assertNull($profiles[0]['keyVersion']);
        self::assertSame($profiles[0], $this->store->providerProfile('home-a', 'shared'));
        $response = new JsonResponse(['settings' => $settings, 'policy' => $policy, 'profiles' => $profiles]);
        $decoded = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(3, $decoded['settings']['revision']);
        self::assertSame(2, $decoded['policy']['maxAttempts']);
        self::assertSame(0, $decoded['profiles'][0]['estimatedCostMicros']);
        self::assertSame('manual_only', $decoded['settings']['mode']);
        self::assertNull($decoded['settings']['provider']);
    }

    public function testNativeAndStringifyingDriverResultsAreIdentical(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $native = [
            $this->store->settings('home-a'),
            $this->store->orchestrationPolicy('home-a'),
            $this->store->providerProfiles('home-a', 'other-user'),
        ];
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        self::assertSame($native, [
            $this->store->settings('home-a'),
            $this->store->orchestrationPolicy('home-a'),
            $this->store->providerProfiles('home-a', 'other-user'),
        ]);
    }

    public function testMalformedPersistedRevisionFailsClosed(): void
    {
        $this->connection->update('ai_settings', ['revision' => 'not-an-integer'], ['home_id' => 'home-a']);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The persisted AI metadata contains an invalid integer.');
        $this->store->settings('home-a');
    }

    public function testMissingConfigurationStillReturnsNull(): void
    {
        self::assertNull($this->store->settings('absent-home'));
        self::assertNull($this->store->orchestrationPolicy('absent-home'));
        self::assertSame([], $this->store->providerProfiles('absent-home'));
        self::assertNull($this->store->providerProfile('absent-home', 'shared'));
    }
}
