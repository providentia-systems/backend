<?php

declare(strict_types=1);

namespace ProvidentiaTest\Unit\AiIntegration;

use PHPUnit\Framework\TestCase;
use Providentia\AiIntegration\Application\AiProviderException;
use Providentia\AiIntegration\Infrastructure\Http\EndpointPolicy;

final class EndpointPolicyTest extends TestCase
{
    public function testPrivateNetworkEndpointIsBlockedByDefault(): void
    {
        $policy = new EndpointPolicy(['127.0.0.1'], false);

        $this->expectException(AiProviderException::class);
        $policy->assertAllowed('https://127.0.0.1/v1/responses');
    }

    public function testHttpRequiresExplicitPrivateNetworkMode(): void
    {
        $policy = new EndpointPolicy(['127.0.0.1'], true);
        $policy->assertAllowed('http://127.0.0.1:11434/api/chat');

        self::assertTrue(true);
    }

    public function testCredentialsAndQueryParametersCannotBeSmuggledIntoEndpoint(): void
    {
        $policy = new EndpointPolicy(['127.0.0.1'], true);

        foreach (
            [
                'http://user:secret@127.0.0.1/api/chat',
                'http://127.0.0.1/api/chat?redirect=https://example.test',
            ] as $endpoint
        ) {
            try {
                $policy->assertAllowed($endpoint);
                self::fail('An unsafe endpoint was accepted.');
            } catch (AiProviderException) {
                self::assertTrue(true);
            }
        }
    }
}
