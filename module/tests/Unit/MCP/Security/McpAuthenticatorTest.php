<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Security;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use XC\MCP\MCP\Security\McpAuthenticationException;
use XC\MCP\MCP\Security\McpAuthenticator;
use XC\MCP\MCP\Security\SecurityContext;
use XLite\Model\Profile;
use XLite\Model\Profile\APIKey;
use XLite\Model\Repo\Profile\APIKey as ApiKeyRepository;

class McpAuthenticatorTest extends TestCase
{
    private ApiKeyRepository&MockObject $apiKeyRepo;
    private McpAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->apiKeyRepo = $this->createMock(ApiKeyRepository::class);
        $this->authenticator = new McpAuthenticator($this->apiKeyRepo);
    }

    public function testAuthenticateValidKey(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer valid-api-key-123');

        $profile = $this->createStub(Profile::class);
        $profile->method('isAdmin')->willReturn(true);
        $profile->method('getId')->willReturn(1);

        $apiKey = $this->createStub(APIKey::class);
        $apiKey->method('getProfile')->willReturn($profile);
        $apiKey->method('getId')->willReturn(10);

        $this->apiKeyRepo
            ->expects($this->once())
            ->method('findActiveApiKey')
            ->with('valid-api-key-123')
            ->willReturn($apiKey);

        $context = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(SecurityContext::class, $context);
        $this->assertSame($profile, $context->getProfile());
        $this->assertSame(10, $context->getApiKeyId());
        $this->assertFalse($context->isFullAccess());
    }

    public function testAuthenticateMissingHeader(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('');

        $this->apiKeyRepo->expects($this->never())->method('findActiveApiKey');

        $this->expectException(McpAuthenticationException::class);
        $this->expectExceptionMessage('Missing Authorization header');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateInvalidKey(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer nonexistent-key');

        $this->apiKeyRepo
            ->expects($this->once())
            ->method('findActiveApiKey')
            ->with('nonexistent-key')
            ->willReturn(null);

        $this->expectException(McpAuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired API key');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateNonAdmin(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->with('Authorization')
            ->willReturn('Bearer customer-key');

        $profile = $this->createStub(Profile::class);
        $profile->method('isAdmin')->willReturn(false);

        $apiKey = $this->createStub(APIKey::class);
        $apiKey->method('getProfile')->willReturn($profile);

        $this->apiKeyRepo
            ->expects($this->once())
            ->method('findActiveApiKey')
            ->with('customer-key')
            ->willReturn($apiKey);

        $this->expectException(McpAuthenticationException::class);
        $this->expectExceptionMessage('MCP access requires admin profile');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateStdioWithEnvKey(): void
    {
        $profile = $this->createStub(Profile::class);
        $profile->method('isAdmin')->willReturn(true);

        $apiKey = $this->createStub(APIKey::class);
        $apiKey->method('getProfile')->willReturn($profile);
        $apiKey->method('getId')->willReturn(5);

        $this->apiKeyRepo
            ->expects($this->once())
            ->method('findActiveApiKey')
            ->with('env-stdio-key')
            ->willReturn($apiKey);

        // Set env variable for the test
        $previousValue = getenv('MCP_API_KEY');
        putenv('MCP_API_KEY=env-stdio-key');

        try {
            $context = $this->authenticator->authenticateStdio();

            $this->assertInstanceOf(SecurityContext::class, $context);
            $this->assertSame($profile, $context->getProfile());
            $this->assertSame(5, $context->getApiKeyId());
            $this->assertFalse($context->isFullAccess());
        } finally {
            // Restore env
            if ($previousValue === false) {
                putenv('MCP_API_KEY');
            } else {
                putenv('MCP_API_KEY=' . $previousValue);
            }
        }
    }

    public function testAuthenticateStdioWithoutKey(): void
    {
        $previousValue = getenv('MCP_API_KEY');
        putenv('MCP_API_KEY');

        try {
            $this->apiKeyRepo->expects($this->never())->method('findActiveApiKey');

            $context = $this->authenticator->authenticateStdio();

            $this->assertInstanceOf(SecurityContext::class, $context);
            $this->assertTrue($context->isFullAccess());
            $this->assertNull($context->getProfile());
            $this->assertNull($context->getApiKeyId());
        } finally {
            if ($previousValue !== false) {
                putenv('MCP_API_KEY=' . $previousValue);
            }
        }
    }
}
