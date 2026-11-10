<?php

require_once __DIR__ . '/McpTestCase.php';

use Kanboard\Plugin\ModelContextProtocol\Model\McpTokenModel;

class McpTokenModelTest extends McpTestCase
{
    public function testAuthenticateReturnsUserIdForPlaintextAndStoresSha256(): void
    {
        $userId = $this->createUser('token-owner');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);

        $this->assertTrue($this->container['mcpTokenModel']->hasToken($userId));
        $this->assertSame($userId, $this->container['mcpTokenModel']->authenticate($plain));

        $row = $this->container['db']->table(McpTokenModel::TABLE)->eq('user_id', $userId)->findOne();
        $this->assertSame(hash('sha256', $plain), $row['token']);
        $this->assertNotSame($plain, $row['token']);
        $this->assertArrayNotHasKey('token_plain', $row);
    }

    public function testAuthenticateRejectsTheStoredHashItself(): void
    {
        $userId = $this->createUser('hash-user');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $row = $this->container['db']->table(McpTokenModel::TABLE)->eq('user_id', $userId)->findOne();

        $this->assertNull($this->container['mcpTokenModel']->authenticate($row['token']));
        $this->assertSame($userId, $this->container['mcpTokenModel']->authenticate($plain));
    }

    public function testAuthenticateRejectsWrongAndEmptyTokens(): void
    {
        $userId = $this->createUser('wrong-token-user');
        $this->container['mcpTokenModel']->regenerateForUser($userId);

        $this->assertNull($this->container['mcpTokenModel']->authenticate('not-the-token'));
        $this->assertNull($this->container['mcpTokenModel']->authenticate(''));
    }

    public function testAuthenticateRejectsInactiveUser(): void
    {
        $userId = $this->createUser('inactive-token-user');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->assertTrue($this->container['userModel']->disable($userId));

        $this->assertNull($this->container['mcpTokenModel']->authenticate($plain));
    }

    public function testTokensAreIsolatedPerUser(): void
    {
        $alice = $this->createUser('alice-token');
        $bob = $this->createUser('bob-token');
        $aliceToken = $this->container['mcpTokenModel']->regenerateForUser($alice);
        $bobToken = $this->container['mcpTokenModel']->regenerateForUser($bob);

        $this->assertSame($alice, $this->container['mcpTokenModel']->authenticate($aliceToken));
        $this->assertSame($bob, $this->container['mcpTokenModel']->authenticate($bobToken));
        $this->assertNotSame($alice, $this->container['mcpTokenModel']->authenticate($bobToken));
    }

    public function testRegenerateReplacesOnlyThatUsersToken(): void
    {
        $alice = $this->createUser('alice-rotate');
        $bob = $this->createUser('bob-rotate');
        $aliceOld = $this->container['mcpTokenModel']->regenerateForUser($alice);
        $bobToken = $this->container['mcpTokenModel']->regenerateForUser($bob);

        $aliceNew = $this->container['mcpTokenModel']->regenerateForUser($alice);

        $this->assertNotSame($aliceOld, $aliceNew);
        $this->assertNull($this->container['mcpTokenModel']->authenticate($aliceOld));
        $this->assertSame($alice, $this->container['mcpTokenModel']->authenticate($aliceNew));
        $this->assertTrue($this->container['mcpTokenModel']->hasToken($bob));
        $this->assertSame($bob, $this->container['mcpTokenModel']->authenticate($bobToken));
    }

    public function testRevealIsShownOnceAndIsNotStored(): void
    {
        $userId = $this->createUser('plain-display');
        $this->assertFalse($this->container['mcpTokenModel']->hasToken($userId));

        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->container['mcpTokenModel']->rememberReveal($userId, $plain);

        $this->assertSame($plain, $this->container['mcpTokenModel']->takeReveal($userId));
        $this->assertNull($this->container['mcpTokenModel']->takeReveal($userId));
        $this->assertSame(64, strlen($plain));
    }
}
