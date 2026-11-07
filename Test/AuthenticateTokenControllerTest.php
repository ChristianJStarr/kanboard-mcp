<?php

require_once __DIR__ . '/McpTestCase.php';

use Kanboard\Plugin\ModelContextProtocol\Controller\ModelContextProtocolController;
use Kanboard\Plugin\ModelContextProtocol\Model\McpTokenModel;

class AuthenticateTokenControllerTest extends McpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_GET = [];
        parent::tearDown();
    }

    public function testQueryTokenWorksWhenUrlTokensAreAllowed(): void
    {
        $userId = $this->createUser('query-allowed');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->assertFalse($this->container['mcpTokenModel']->urlTokensDisabled());

        $_GET['token'] = $plain;

        $this->assertSame($userId, $this->authenticate());
    }

    public function testQueryTokenIsRejectedWhenUrlTokensAreDisabled(): void
    {
        $userId = $this->createUser('query-disabled');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->assertTrue($this->container['mcpTokenModel']->setUrlTokensDisabled(true));

        $_GET['token'] = $plain;

        $this->assertNull($this->authenticate());
    }

    public function testHeaderStillWorksWhenUrlTokensAreDisabled(): void
    {
        $userId = $this->createUser('header-disabled');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->assertTrue($this->container['mcpTokenModel']->setUrlTokensDisabled(true));

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $plain;
        $_GET['token'] = $plain;

        $this->assertSame($userId, $this->authenticate());
    }

    public function testHeaderAndQueryConflictIsRejectedWhenUrlTokensAreAllowed(): void
    {
        $userId = $this->createUser('conflict-user');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $plain;
        $_GET['token'] = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

        $this->assertNull($this->authenticate());
    }

    public function testConflictingQueryIsIgnoredWhenUrlTokensAreDisabled(): void
    {
        $userId = $this->createUser('conflict-ignored');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->assertTrue($this->container['mcpTokenModel']->setUrlTokensDisabled(true));

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $plain;
        $_GET['token'] = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

        $this->assertSame($userId, $this->authenticate());
    }

    public function testRedirectHttpAuthorizationIsAccepted(): void
    {
        $userId = $this->createUser('cgi-bearer');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);

        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $plain;

        $this->assertSame($userId, $this->authenticate());
    }

    public function testHttpAuthorizationIsPreferredOverRedirect(): void
    {
        $owner = $this->createUser('header-owner');
        $other = $this->createUser('redirect-owner');
        $ownerToken = $this->container['mcpTokenModel']->regenerateForUser($owner);
        $otherToken = $this->container['mcpTokenModel']->regenerateForUser($other);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $ownerToken;
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $otherToken;

        $this->assertSame($owner, $this->authenticate());
    }

    public function testUrlTokenSettingSurvivesTokenRotation(): void
    {
        $userId = $this->createUser('keep-setting');
        $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->assertTrue($this->container['mcpTokenModel']->setUrlTokensDisabled(true));

        $this->container['mcpTokenModel']->regenerateForUser($userId);

        $this->assertTrue($this->container['mcpTokenModel']->urlTokensDisabled());
        $row = $this->container['db']->table('settings')
            ->eq('option', McpTokenModel::CONFIG_DISABLE_URL_TOKEN)
            ->findOne();
        $this->assertSame('1', $row['value']);
    }

    private function authenticate(): ?int
    {
        $controller = new ModelContextProtocolController($this->container);
        $method = new ReflectionMethod(ModelContextProtocolController::class, 'authenticateToken');
        $method->setAccessible(true);

        $userId = $method->invoke($controller);
        $this->assertTrue($userId === null || is_int($userId));

        return $userId;
    }
}
