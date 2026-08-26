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
        self::addToAssertionCount(1);
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
                self::addToAssertionCount(1);
            }
        }
    }

    public function testProfileEndpointsOutsideTheAllowlistFollowTheHttpsOrOllamaLanRule(): void
    {
        $strict = new EndpointPolicy(['deployment.example.test'], false, false);
        // A profile-owned HTTPS endpoint with a public host is allowed at
        // request time even though its host is not deployment-configured.
        $strict->assertAllowed('https://8.8.8.8/v1/chat/completions');
        self::addToAssertionCount(1);

        foreach (
            [
                'http://8.8.8.8/api/chat',
                'https://192.168.1.10:11434/api/chat',
                'https://127.0.0.1/v1/chat/completions',
            ] as $endpoint
        ) {
            try {
                $strict->assertAllowed($endpoint);
                self::fail('A private or plain-HTTP profile endpoint was accepted without the LAN opt-in.');
            } catch (AiProviderException) {
                self::addToAssertionCount(1);
            }
        }

        $lan = new EndpointPolicy([], false, true);
        $lan->assertAllowed('http://192.168.1.10:11434/api/chat');
        $lan->assertAllowed('https://8.8.8.8/v1/chat/completions');
        self::addToAssertionCount(2);
    }

    public function testProfileLanOptInDoesNotWidenTheDeploymentAllowlistLane(): void
    {
        $policy = new EndpointPolicy(['127.0.0.1'], false, true);

        $this->expectException(AiProviderException::class);
        $policy->assertAllowed('http://127.0.0.1:11434/api/chat');
    }
}
