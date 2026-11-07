<?php

require_once __DIR__ . '/McpTestCase.php';

use Kanboard\Core\Security\Role;
use Kanboard\Plugin\ModelContextProtocol\Core\McpCatalog;
use Kanboard\Plugin\ModelContextProtocol\Model\McpEndpointGatewayModel;
use Kanboard\Plugin\ModelContextProtocol\Model\McpTokenModel;

class McpEndpointGatewayModelTest extends McpTestCase
{
    public function testNewUserReadsAreOnAndWritesAreOff(): void
    {
        $userId = $this->createUser('fresh-gateway');

        $this->assertSame([], $this->grantRows($userId));
        $this->assertTrue($this->gateway()->isEnabled($userId, 'get_projects'));
        $this->assertTrue($this->gateway()->isEnabled($userId, 'get_users'));
        $this->assertTrue($this->gateway()->isEnabled($userId, 'kanboard://projects'));
        $this->assertTrue($this->gateway()->isEnabled($userId, 'kanboard://users'));
        $this->assertFalse($this->gateway()->isEnabled($userId, 'create_task'));
        $this->assertFalse($this->gateway()->isEnabled($userId, 'delete_task'));
        $this->assertFalse($this->gateway()->isEnabled($userId, 'create_column'));
        $this->assertFalse($this->gateway()->isEnabled($userId, 'create_project'));
        $this->assertFalse($this->gateway()->isEnabled($userId, 'not_a_real_tool'));
    }

    public function testBackfillEnablesWritesOnlyForExistingTokenUsers(): void
    {
        $withToken = $this->createUser('backfill-owner');
        $withoutToken = $this->createUser('backfill-other');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($withToken);
        $hash = $this->tokenHash($withToken);

        $this->migrateFromTokenSchema();
        $this->runVersion4();

        $this->assertSame($this->writeEndpointNames(), $this->storedEndpoints($withToken));
        $this->assertSame([], $this->storedEndpoints($withoutToken));
        foreach ($this->grantRows($withToken) as $row) {
            $this->assertSame(1, (int) $row['enabled']);
        }
        $this->assertNotContains('get_projects', $this->storedEndpoints($withToken));
        $this->assertNotContains('kanboard://users', $this->storedEndpoints($withToken));
        $this->assertTrue($this->gateway()->isEnabled($withToken, 'create_task'));
        $this->assertTrue($this->gateway()->isEnabled($withToken, 'delete_task'));
        $this->assertTrue($this->gateway()->isEnabled($withToken, 'create_column'));
        $this->assertTrue($this->gateway()->isEnabled($withToken, 'create_project'));
        $this->assertFalse($this->gateway()->isEnabled($withoutToken, 'create_task'));
        $this->assertTrue($this->gateway()->isEnabled($withoutToken, 'get_projects'));
        $this->assertSame($hash, $this->tokenHash($withToken));
        $this->assertSame($withToken, $this->container['mcpTokenModel']->authenticate($plain));
        $this->assertCount(count($this->writeEndpointNames()), $this->grantRows($withToken));
    }

    public function testVersion4LeavesAnExistingGrantsTableAlone(): void
    {
        $saved = $this->createUser('earlier-build-saved');
        $fresh = $this->createUser('earlier-build-fresh');
        $this->container['mcpTokenModel']->regenerateForUser($saved);
        $this->container['mcpTokenModel']->regenerateForUser($fresh);
        $this->insertGrant($saved, 'create_task', 0);

        $this->runVersion4();

        $this->assertSame([], $this->grantRows($fresh));
        $this->assertFalse($this->gateway()->isEnabled($fresh, 'create_task'));
        $this->assertCount(1, $this->grantRows($saved));
        $this->assertSame(0, $this->enabledValue($saved, 'create_task'));
    }

    public function testRegenerateKeepsGrantRows(): void
    {
        $userId = $this->createUser('regenerate-grants');
        $old = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->migrateFromTokenSchema();
        $before = $this->grantRows($userId);
        $this->assertNotEmpty($before);

        $fresh = $this->container['mcpTokenModel']->regenerateForUser($userId);

        $this->assertNotSame($old, $fresh);
        $this->assertNull($this->container['mcpTokenModel']->authenticate($old));
        $this->assertSame($userId, $this->container['mcpTokenModel']->authenticate($fresh));
        $this->assertSame($before, $this->grantRows($userId));
        $this->assertTrue($this->gateway()->isEnabled($userId, 'create_column'));
    }

    public function testUnknownEndpointIsRejectedAndNotStored(): void
    {
        $userId = $this->createUser('unknown-endpoint');

        try {
            $this->gateway()->save($userId, ['get_projects', 'not_a_tool'], ['get_projects']);
            $this->fail('Expected invalid allow-list to throw');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame([], $this->grantRows($userId));
        }

        try {
            $this->gateway()->save($userId, ['get_projects'], ['get_projects', 'not_a_tool']);
            $this->fail('Expected unknown posted name to throw');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame([], $this->grantRows($userId));
            $this->assertStringNotContainsString('not_a_tool', $exception->getMessage());
        }

        $this->assertFalse($this->gateway()->isEnabled($userId, 'not_a_tool'));
    }

    public function testViewerCannotSaveCreateProjectOrCreateColumn(): void
    {
        $projectId = $this->createProject('Viewer save');
        $viewerId = $this->createUser('viewer-save');
        $this->addMember($projectId, $viewerId, Role::PROJECT_VIEWER);
        $allowed = $this->gateway()->allowedNames($this->accessFor($viewerId));

        $this->assertNotContains('create_project', $allowed);
        $this->assertNotContains('create_column', $allowed);
        $this->assertContains('get_projects', $allowed);

        try {
            $this->gateway()->save($viewerId, $allowed, array_merge($allowed, ['create_project', 'create_column']));
            $this->fail('Expected a role-hidden name to abort the save');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame([], $this->grantRows($viewerId));
        }
    }

    public function testSavingUserADoesNotChangeUserB(): void
    {
        $userA = $this->createUser('gateway-a');
        $userB = $this->createUser('gateway-b');
        $allowedA = $this->gateway()->allowedNames($this->accessFor($userA));
        $allowedB = $this->gateway()->allowedNames($this->accessFor($userB));

        $this->gateway()->save($userB, $allowedB, ['get_projects']);
        $before = $this->grantRows($userB);

        $this->gateway()->save($userA, $allowedA, $allowedA);

        $this->assertSame($before, $this->grantRows($userB));
        $this->assertNotSame([], $this->grantRows($userA));
        $bEnabled = [];
        foreach ($this->grantRows($userB) as $row) {
            $bEnabled[$row['endpoint']] = (int) $row['enabled'];
        }
        $this->assertSame(1, $bEnabled['get_projects']);
        $this->assertSame(0, $bEnabled['get_tasks']);
    }

    public function testSaveLeavesRowsTheUserMayNoLongerToggle(): void
    {
        $viewerId = $this->createUser('former-manager');
        $this->insertGrant($viewerId, 'create_column', 1);
        $this->insertGrant($viewerId, 'create_project', 0);
        $allowed = $this->gateway()->allowedNames($this->accessFor($viewerId));

        $this->gateway()->save($viewerId, $allowed, ['get_projects']);

        $this->assertNotContains('create_column', $allowed);
        $this->assertSame(1, $this->enabledValue($viewerId, 'create_column'));
        $this->assertSame(0, $this->enabledValue($viewerId, 'create_project'));
        $this->assertSame(1, $this->enabledValue($viewerId, 'get_projects'));
        $this->assertSame(0, $this->enabledValue($viewerId, 'get_tasks'));
        $this->assertFalse($this->accessFor($viewerId)->allowsCapability(McpCatalog::CAP_BOARD));
    }

    public function testDirectEnableAboveRoleDoesNotGrantCapability(): void
    {
        $viewerId = $this->createUser('elevated-row');
        $this->insertGrant($viewerId, 'create_project', 1);
        $this->insertGrant($viewerId, 'create_column', 1);

        $access = $this->accessFor($viewerId);
        $this->assertTrue($this->gateway()->isEnabled($viewerId, 'create_project'));
        $this->assertFalse($access->allowsCapability(McpCatalog::CAP_CREATE_PROJECT));
        $this->assertFalse($access->allowsCapability(McpCatalog::CAP_BOARD));
        $this->assertTrue($access->allowsCapability(McpCatalog::CAP_READ));
        $this->assertFalse($access->allowsCapability('not-a-capability'));
        $this->assertFalse($access->allowsCapability('not-a-capability'));

        $this->assertUnknownTool($this->callTool($viewerId, 'create_project', [
            'name' => 'Should not exist',
        ]), 'create_project');
        $this->assertNull($this->container['projectModel']->getByName('Should not exist'));
    }

    public function testFailedUpsertRollsBackTheWholeSave(): void
    {
        $userId = $this->createUser('rollback-save');
        $this->insertGrant($userId, 'get_projects', 1);
        $before = $this->grantRows($userId);
        $allowed = $this->gateway()->allowedNames($this->accessFor($userId));
        $this->assertSame('kanboard://users', end($allowed));
        $this->failGrantInsert('kanboard://users');

        try {
            $this->gateway()->save($userId, $allowed, []);
            $this->fail('Expected the failed upsert to throw');
        } catch (RuntimeException $exception) {
            $this->assertSame($before, $this->grantRows($userId));
            $this->assertTrue($this->gateway()->isEnabled($userId, 'get_projects'));
        }
    }

    public function testOffIsStoredAndAMissingRowUsesTheCatalogDefault(): void
    {
        $userId = $this->createUser('explicit-off');
        $allowed = $this->gateway()->allowedNames($this->accessFor($userId));
        $this->gateway()->save($userId, $allowed, []);

        $this->assertFalse($this->gateway()->isEnabled($userId, 'get_projects'));
        $row = $this->container['db']->table(McpEndpointGatewayModel::TABLE)
            ->eq('user_id', $userId)
            ->eq('endpoint', 'get_projects')
            ->findOne();
        $this->assertSame(0, (int) $row['enabled']);
        $this->assertCount(count($allowed), $this->grantRows($userId));
    }

    /**
     * @return string[]
     */
    private function writeEndpointNames(): array
    {
        $names = [];
        foreach (McpCatalog::ENDPOINTS as $endpoint) {
            if ($endpoint['defaultEnabled'] === false) {
                $names[] = $endpoint['name'];
            }
        }
        sort($names);

        return $names;
    }

    /**
     * @return string[]
     */
    private function storedEndpoints(int $userId): array
    {
        $names = [];
        foreach ($this->grantRows($userId) as $row) {
            $names[] = $row['endpoint'];
        }
        sort($names);

        return $names;
    }

    private function enabledValue(int $userId, string $endpoint): int
    {
        $row = $this->container['db']->table(McpEndpointGatewayModel::TABLE)
            ->eq('user_id', $userId)
            ->eq('endpoint', $endpoint)
            ->findOne();
        $this->assertNotEmpty($row);

        return (int) $row['enabled'];
    }

    private function tokenHash(int $userId): string
    {
        return (string) $this->container['db']->table(McpTokenModel::TABLE)
            ->eq('user_id', $userId)
            ->findOneColumn('token');
    }

    private function runVersion4(): void
    {
        \Kanboard\Plugin\ModelContextProtocol\Schema\version_4($this->container['db']->getConnection());
    }
}
