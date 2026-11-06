<?php

require_once __DIR__ . '/McpTestCase.php';

use Kanboard\Core\Security\Role;
use Kanboard\Model\ColumnModel;
use Kanboard\Plugin\ModelContextProtocol\Model\McpTokenModel;

class McpGatewayEnforcementTest extends McpTestCase
{
    public function testViewerListIsTheEightReadTools(): void
    {
        $projectId = $this->createProject('Viewer list');
        $viewerId = $this->createUser('list-viewer');
        $this->addMember($projectId, $viewerId, Role::PROJECT_VIEWER);

        $names = $this->toolNamesFrom($this->mcpRequest($viewerId, 'tools/list'));
        $this->assertSame($this->readToolNames(), $names);
        $this->assertCount(8, $names);

        $uris = array_column($this->mcpRequest($viewerId, 'resources/list')['result']['resources'], 'uri');
        $this->assertSame(['kanboard://projects', 'kanboard://users'], $uris);
    }

    public function testMemberListOmitsBoardAndCreateProject(): void
    {
        $projectId = $this->createProject('Member list');
        $memberId = $this->createUser('list-member');
        $this->addMember($projectId, $memberId, Role::PROJECT_MEMBER);
        $this->enableEveryCatalogEndpoint($memberId);

        $names = $this->toolNamesFrom($this->mcpRequest($memberId, 'tools/list'));
        $this->assertSame($this->filterTools(array_merge($this->readToolNames(), $this->taskToolNames())), $names);
        $this->assertNotContains('create_column', $names);
        $this->assertNotContains('reorder_columns', $names);
        $this->assertNotContains('create_project', $names);
        $this->assertContains('create_task', $names);
        $this->assertContains('delete_task', $names);
    }

    public function testManagerListOmitsCreateProject(): void
    {
        $projectId = $this->createProject('Manager list');
        $managerId = $this->createUser('list-manager');
        $this->addMember($projectId, $managerId, Role::PROJECT_MANAGER);
        $this->enableEveryCatalogEndpoint($managerId);

        $names = $this->toolNamesFrom($this->mcpRequest($managerId, 'tools/list'));
        $expected = $this->filterTools(array_merge(
            $this->readToolNames(),
            $this->taskToolNames(),
            $this->boardToolNames()
        ));
        $this->assertSame($expected, $names);
        $this->assertNotContains('create_project', $names);
        $this->assertContains('create_column', $names);
        $this->assertCount(25, $names);
    }

    public function testAppManagerWithoutMembershipSeesReadsPlusCreateProject(): void
    {
        $managerId = $this->createUser('app-manager-list', Role::APP_MANAGER);
        $this->enableEveryCatalogEndpoint($managerId);

        $names = $this->toolNamesFrom($this->mcpRequest($managerId, 'tools/list'));
        $this->assertSame($this->filterTools(array_merge($this->readToolNames(), ['create_project'])), $names);
        $this->assertNotContains('create_column', $names);
        $this->assertNotContains('create_task', $names);
        $this->assertContains('create_project', $names);
        $this->assertCount(9, $names);
    }

    public function testAppAdminIncludesAll26WhenGrantsAreEnabled(): void
    {
        $adminId = $this->adminId();
        $this->enableEveryCatalogEndpoint($adminId);

        $names = $this->toolNamesFrom($this->mcpRequest($adminId, 'tools/list'));
        $this->assertSame($this->allToolNames(), $names);
        $this->assertCount(26, $names);
    }

    public function testFreshAdminListShowsReadsUntilWritesAreEnabled(): void
    {
        $adminId = $this->adminId();
        $this->assertSame([], $this->grantRows($adminId));
        $this->assertSame($this->readToolNames(), $this->toolNamesFrom($this->mcpRequest($adminId, 'tools/list')));

        $allowed = $this->gateway()->allowedNames($this->accessFor($adminId));
        $this->gateway()->save($adminId, $allowed, $allowed);

        $this->assertSame($this->allToolNames(), $this->toolNamesFrom($this->mcpRequest($adminId, 'tools/list')));
    }

    public function testMigratedUserListStillShowsWrites(): void
    {
        $projectId = $this->createProject('Migrated board');
        $managerId = $this->createUser('migrated-manager');
        $this->addMember($projectId, $managerId, Role::PROJECT_MANAGER);
        $plain = $this->container['mcpTokenModel']->regenerateForUser($managerId);
        $hash = (string) $this->container['db']->table(McpTokenModel::TABLE)
            ->eq('user_id', $managerId)
            ->findOneColumn('token');

        \Kanboard\Plugin\ModelContextProtocol\Schema\version_3($this->container['db']->getConnection());

        $names = $this->toolNamesFrom($this->mcpRequest($managerId, 'tools/list'));
        $this->assertContains('create_task', $names);
        $this->assertContains('delete_task', $names);
        $this->assertContains('create_column', $names);
        $this->assertNotContains('create_project', $names);
        $this->assertSame($hash, (string) $this->container['db']->table(McpTokenModel::TABLE)
            ->eq('user_id', $managerId)
            ->findOneColumn('token'));
        $this->assertSame($managerId, $this->container['mcpTokenModel']->authenticate($plain));
    }

    public function testDisabledToolIsOmittedAndCallReturnsForbidden(): void
    {
        $projectId = $this->createProject('Disabled read');
        $viewerId = $this->createUser('disabled-read');
        $this->addMember($projectId, $viewerId, Role::PROJECT_VIEWER);

        $allowed = $this->gateway()->allowedNames($this->accessFor($viewerId));
        $this->gateway()->save($viewerId, $allowed, array_values(array_diff($allowed, ['get_tasks'])));

        $names = $this->toolNamesFrom($this->mcpRequest($viewerId, 'tools/list'));
        $this->assertNotContains('get_tasks', $names);
        $this->assertContains('get_projects', $names);
        $this->assertForbiddenTool($this->callTool($viewerId, 'get_tasks', [
            'project_id' => $projectId,
        ]));
    }

    public function testDisabledToolWithBadArgumentsIsStillForbidden(): void
    {
        $projectId = $this->createProject('Disabled bad args');
        $viewerId = $this->createUser('disabled-bad-args');
        $this->addMember($projectId, $viewerId, Role::PROJECT_VIEWER);
        $this->insertGrant($viewerId, 'get_tasks', 0);

        $this->assertForbiddenTool($this->callTool($viewerId, 'get_tasks', [
            'project_id' => 'nope',
        ]));
        $this->assertForbiddenTool($this->callTool($viewerId, 'get_tasks', []));
    }

    public function testNonObjectArgumentsDoNotRevealCatalogMembership(): void
    {
        $userId = $this->createUser('bad-argument-shape');
        $this->insertGrant($userId, 'get_tasks', 0);

        $response = $this->mcpRequest($userId, 'tools/call', [
            'name' => ['get_tasks'],
            'arguments' => [],
        ]);
        $this->assertSame(-32602, $response['error']['code']);
        $this->assertSame('Invalid params: name is required and must be a non-empty string', $response['error']['message']);

        foreach (['get_tasks', 'create_project', 'not_a_real_tool'] as $name) {
            $response = $this->mcpRequest($userId, 'tools/call', [
                'name' => $name,
                'arguments' => 'bad',
            ]);
            $this->assertSame(-32602, $response['error']['code']);
            $this->assertSame('Invalid params: arguments must be an object', $response['error']['message']);
            $this->assertArrayNotHasKey('result', $response);
        }
    }

    public function testRoleHiddenEnabledToolMatchesUnknownTool(): void
    {
        $viewerId = $this->createUser('hidden-enabled');
        $this->insertGrant($viewerId, 'create_project', 1);
        $this->assertTrue($this->gateway()->isEnabled($viewerId, 'create_project'));

        $hidden = $this->callTool($viewerId, 'create_project', ['name' => 'Hidden project']);
        $fake = $this->callTool($viewerId, 'not_a_real_tool', ['name' => 'Hidden project']);

        $this->assertSame(['code', 'message'], array_keys($hidden['error']));
        $this->assertSame(array_keys($fake['error']), array_keys($hidden['error']));
        $this->assertSame(-32602, $hidden['error']['code']);
        $this->assertSame(-32602, $fake['error']['code']);
        $this->assertSame('Unknown tool: create_project', $hidden['error']['message']);
        $this->assertSame('Unknown tool: not_a_real_tool', $fake['error']['message']);
        $this->assertArrayNotHasKey('result', $hidden);
        $this->assertArrayNotHasKey('result', $fake);
        $this->assertNull($this->container['projectModel']->getByName('Hidden project'));
    }

    public function testUnknownToolMessageIsUnchanged(): void
    {
        $userId = $this->createUser('unknown-message');
        $this->assertUnknownTool($this->callTool($userId, 'not_a_real_tool'), 'not_a_real_tool');
    }

    public function testDisabledResourceReadMatchesUnknownUri(): void
    {
        $viewerId = $this->createUser('disabled-resource');
        $allowed = $this->gateway()->allowedNames($this->accessFor($viewerId));
        $this->gateway()->save($viewerId, $allowed, array_values(array_diff($allowed, ['kanboard://projects'])));

        $uris = array_column($this->mcpRequest($viewerId, 'resources/list')['result']['resources'], 'uri');
        $this->assertSame(['kanboard://users'], $uris);

        $disabled = $this->mcpRequest($viewerId, 'resources/read', ['uri' => 'kanboard://projects']);
        $unknown = $this->mcpRequest($viewerId, 'resources/read', ['uri' => 'kanboard://does-not-exist']);

        $this->assertSame([
            'code' => -32002,
            'message' => 'Resource not found',
            'data' => ['uri' => 'kanboard://projects'],
        ], $disabled['error']);
        $this->assertSame([
            'code' => -32002,
            'message' => 'Resource not found',
            'data' => ['uri' => 'kanboard://does-not-exist'],
        ], $unknown['error']);
        $this->assertArrayNotHasKey('result', $disabled);
        $this->assertArrayNotHasKey('result', $unknown);
    }

    public function testManagerOfOneProjectCannotCreateColumnOnAViewedProject(): void
    {
        $managedId = $this->createProject('Managed board');
        $viewedId = $this->createProject('Viewed board');
        $userId = $this->createUser('split-manager');
        $this->addMember($managedId, $userId, Role::PROJECT_MANAGER);
        $this->addMember($viewedId, $userId, Role::PROJECT_VIEWER);

        $allowed = $this->gateway()->allowedNames($this->accessFor($userId));
        $this->assertContains('create_column', $allowed);
        $this->assertNotContains('create_project', $allowed);
        $this->gateway()->save($userId, $allowed, $allowed);

        $names = $this->toolNamesFrom($this->mcpRequest($userId, 'tools/list'));
        $this->assertContains('create_column', $names);
        $this->assertNotContains('create_project', $names);

        $this->assertForbiddenTool($this->callTool($userId, 'create_column', [
            'project_id' => $viewedId,
            'title' => 'Not here',
        ]));
        $viewedColumns = $this->container['columnModel']->getAll($viewedId);
        foreach ($viewedColumns as $column) {
            $this->assertNotSame('Not here', $column['title']);
        }

        $created = $this->decodeToolResult($this->callTool($userId, 'create_column', [
            'project_id' => $managedId,
            'title' => 'Review',
        ]));
        $this->assertGreaterThan(0, (int) $created['column_id']);
        $this->assertNotEmpty($this->container['db']->table(ColumnModel::TABLE)
            ->eq('id', (int) $created['column_id'])
            ->eq('project_id', $managedId)
            ->findOne());
    }

    public function testInitializeKeepsListChangedFalse(): void
    {
        $userId = $this->createUser('list-changed');
        $response = $this->mcpRequest($userId, 'initialize', [
            'protocolVersion' => '2025-11-25',
        ]);

        $this->assertFalse($response['result']['capabilities']['tools']['listChanged']);
        $this->assertFalse($response['result']['capabilities']['resources']['listChanged']);
        $this->assertSame('1.2.0', $response['result']['serverInfo']['version']);
    }

    /**
     * @return string[]
     */
    private function allToolNames(): array
    {
        return [
            'get_projects',
            'create_project',
            'get_tasks',
            'create_task',
            'update_task',
            'get_columns',
            'move_task',
            'get_task_details',
            'delete_task',
            'assign_task',
            'set_task_due_date',
            'add_task_comment',
            'get_users',
            'get_task_comments',
            'create_column',
            'update_column',
            'delete_column',
            'reorder_columns',
            'create_category',
            'update_category',
            'delete_category',
            'get_categories',
            'create_swimlane',
            'update_swimlane',
            'delete_swimlane',
            'get_swimlanes',
        ];
    }

    /**
     * @return string[]
     */
    private function readToolNames(): array
    {
        return [
            'get_projects',
            'get_tasks',
            'get_columns',
            'get_task_details',
            'get_users',
            'get_task_comments',
            'get_categories',
            'get_swimlanes',
        ];
    }

    /**
     * @return string[]
     */
    private function taskToolNames(): array
    {
        return [
            'create_task',
            'update_task',
            'move_task',
            'delete_task',
            'assign_task',
            'set_task_due_date',
            'add_task_comment',
        ];
    }

    /**
     * @return string[]
     */
    private function boardToolNames(): array
    {
        return [
            'create_column',
            'update_column',
            'delete_column',
            'reorder_columns',
            'create_category',
            'update_category',
            'delete_category',
            'create_swimlane',
            'update_swimlane',
            'delete_swimlane',
        ];
    }

    /**
     * @param string[] $include
     * @return string[]
     */
    private function filterTools(array $include): array
    {
        return array_values(array_filter(
            $this->allToolNames(),
            static function (string $name) use ($include): bool {
                return in_array($name, $include, true);
            }
        ));
    }
}
