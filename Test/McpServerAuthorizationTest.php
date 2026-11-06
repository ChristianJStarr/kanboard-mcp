<?php

require_once __DIR__ . '/McpTestCase.php';

use Kanboard\Core\Security\Role;
use Kanboard\Model\CommentModel;

class McpServerAuthorizationTest extends McpTestCase
{
    /**
     * Today's behavior: the gateway does not filter tools/list yet.
     *
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

    public function testToolsListReturnsAll26NamesForAViewer(): void
    {
        $projectId = $this->createProject('Viewer list');
        $viewerId = $this->createUser('list-viewer');
        $this->addMember($projectId, $viewerId, Role::PROJECT_VIEWER);

        $response = $this->mcpRequest($viewerId, 'tools/list');
        $this->assertSame($this->allToolNames(), $this->toolNamesFrom($response));
        $this->assertCount(26, $this->toolNamesFrom($response));
    }

    public function testResourcesListReturnsBothUris(): void
    {
        $viewerId = $this->createUser('resource-viewer');
        $response = $this->mcpRequest($viewerId, 'resources/list');

        $uris = array_column($response['result']['resources'], 'uri');
        $this->assertSame(['kanboard://projects', 'kanboard://users'], $uris);
    }

    public function testViewerCreateTaskOnVisibleProjectIsForbidden(): void
    {
        $projectId = $this->createProject('Viewer write');
        $viewerId = $this->createUser('viewer-writer');
        $this->addMember($projectId, $viewerId, Role::PROJECT_VIEWER);

        $response = $this->callTool($viewerId, 'create_task', [
            'project_id' => $projectId,
            'title' => 'Should fail',
        ]);
        $this->assertForbiddenTool($response);
    }

    public function testViewerGetTasksOnHiddenProjectIsForbidden(): void
    {
        $hidden = $this->createProject('Hidden tasks');
        $viewerId = $this->createUser('hidden-viewer');

        $response = $this->callTool($viewerId, 'get_tasks', ['project_id' => $hidden]);
        $this->assertForbiddenTool($response);
    }

    public function testMemberCanCreateTaskAndCannotDeleteAnothers(): void
    {
        $projectId = $this->createProject('Member tasks');
        $memberId = $this->createUser('creating-member');
        $otherId = $this->createUser('other-creator');
        $this->addMember($projectId, $memberId, Role::PROJECT_MEMBER);
        $this->addMember($projectId, $otherId, Role::PROJECT_MEMBER);

        $created = $this->decodeToolResult($this->callTool($memberId, 'create_task', [
            'project_id' => $projectId,
            'title' => 'Mine',
        ]));
        $this->assertArrayHasKey('task_id', $created);

        $own = $this->container['taskFinderModel']->getById((int) $created['task_id']);
        $this->assertSame($memberId, (int) $own['creator_id']);

        $otherTaskId = (int) $this->container['taskCreationModel']->create([
            'title' => 'Not mine',
            'project_id' => $projectId,
            'creator_id' => $otherId,
        ]);

        $this->assertForbiddenTool($this->callTool($memberId, 'delete_task', [
            'task_id' => $otherTaskId,
        ]));

        $deleted = $this->decodeToolResult($this->callTool($memberId, 'delete_task', [
            'task_id' => (int) $created['task_id'],
        ]));
        $this->assertTrue((bool) $deleted['success']);
    }

    public function testMemberCannotCreateColumn(): void
    {
        $projectId = $this->createProject('Member columns');
        $memberId = $this->createUser('column-member');
        $this->addMember($projectId, $memberId, Role::PROJECT_MEMBER);

        $this->assertForbiddenTool($this->callTool($memberId, 'create_column', [
            'project_id' => $projectId,
            'title' => 'Extra',
        ]));
    }

    public function testManagerCanCreateColumn(): void
    {
        $projectId = $this->createProject('Manager columns');
        $managerId = $this->createUser('column-manager');
        $this->addMember($projectId, $managerId, Role::PROJECT_MANAGER);

        $created = $this->decodeToolResult($this->callTool($managerId, 'create_column', [
            'project_id' => $projectId,
            'title' => 'Review',
        ]));
        $this->assertGreaterThan(0, (int) $created['column_id']);
    }

    public function testAppUserCannotCreateProject(): void
    {
        $userId = $this->createUser('plain-app-user', Role::APP_USER);
        $this->assertForbiddenTool($this->callTool($userId, 'create_project', [
            'name' => 'Not allowed',
        ]));
    }

    public function testAppAdminAndAppManagerCanCreateProject(): void
    {
        $admin = $this->decodeToolResult($this->callTool($this->adminId(), 'create_project', [
            'name' => 'Admin project',
        ]));
        $this->assertGreaterThan(0, (int) $admin['project_id']);

        $managerId = $this->createUser('app-manager-create', Role::APP_MANAGER);
        $manager = $this->decodeToolResult($this->callTool($managerId, 'create_project', [
            'name' => 'Manager project',
        ]));
        $this->assertGreaterThan(0, (int) $manager['project_id']);
    }

    public function testGetUsersRedactsToIdUsernameName(): void
    {
        $projectId = $this->createProject('Redaction');
        $viewerId = $this->createUser('redact-viewer', Role::APP_USER, 'Viewer Name');
        $memberId = $this->createUser('redact-member', Role::APP_USER, 'Member Name');
        $this->addMember($projectId, $viewerId, Role::PROJECT_VIEWER);
        $this->addMember($projectId, $memberId, Role::PROJECT_MEMBER);

        $users = $this->decodeToolResult($this->callTool($viewerId, 'get_users'));
        $this->assertNotEmpty($users);

        $ids = [];
        foreach ($users as $user) {
            $this->assertSame(['id', 'username', 'name'], array_keys($user));
            $ids[] = (int) $user['id'];
        }

        $this->assertContains($viewerId, $ids);
        $this->assertContains($memberId, $ids);
        $this->assertNotContains($this->adminId(), $ids);
    }

    public function testAdminGetUsersListsActiveUsers(): void
    {
        $activeId = $this->createUser('active-listed', Role::APP_USER, 'Active Person');
        $inactiveId = $this->createUser('inactive-hidden', Role::APP_USER, 'Inactive Person');
        $this->assertTrue($this->container['userModel']->disable($inactiveId));

        $users = $this->decodeToolResult($this->callTool($this->adminId(), 'get_users'));
        $byId = [];
        foreach ($users as $user) {
            $this->assertSame(['id', 'username', 'name'], array_keys($user));
            $byId[(int) $user['id']] = $user;
        }

        $this->assertSame('admin', $byId[$this->adminId()]['username']);
        $this->assertSame('active-listed', $byId[$activeId]['username']);
        $this->assertSame('Active Person', $byId[$activeId]['name']);
        $this->assertArrayNotHasKey($inactiveId, $byId);
    }

    public function testUnknownToolIsJsonRpcError(): void
    {
        $userId = $this->createUser('unknown-tool-user');
        $this->assertUnknownTool($this->callTool($userId, 'not_a_real_tool'), 'not_a_real_tool');
    }

    public function testForbiddenToolIsAToolResultNotAJsonRpcError(): void
    {
        $projectId = $this->createProject('Forbidden shape');
        $viewerId = $this->createUser('forbidden-shape');
        $this->addMember($projectId, $viewerId, Role::PROJECT_VIEWER);

        $response = $this->callTool($viewerId, 'create_task', [
            'project_id' => $projectId,
            'title' => 'Nope',
        ]);
        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertArrayNotHasKey('error', $response);
        $this->assertForbiddenTool($response);
    }

    public function testCommentUserIdIsTheTokenUser(): void
    {
        $projectId = $this->createProject('Comments');
        $memberId = $this->createUser('comment-member');
        $this->addMember($projectId, $memberId, Role::PROJECT_MEMBER);
        $this->login($this->adminId());

        $taskId = (int) $this->container['taskCreationModel']->create([
            'title' => 'Commented',
            'project_id' => $projectId,
            'creator_id' => $memberId,
        ]);

        $created = $this->decodeToolResult($this->callTool($memberId, 'add_task_comment', [
            'task_id' => $taskId,
            'comment' => 'From the token',
        ]));

        $comment = $this->container['db']->table(CommentModel::TABLE)->eq('id', (int) $created['comment_id'])->findOne();
        $this->assertSame($memberId, (int) $comment['user_id']);
        $this->assertNotSame($this->adminId(), (int) $comment['user_id']);
    }

    public function testSessionAdminDoesNotAuthorizeAViewerToken(): void
    {
        $visible = $this->createProject('Viewer visible');
        $hidden = $this->createProject('Viewer hidden');
        $viewerId = $this->createUser('token-viewer');
        $this->addMember($visible, $viewerId, Role::PROJECT_VIEWER);
        $this->login($this->adminId());

        $this->assertForbiddenTool($this->callTool($viewerId, 'create_project', [
            'name' => 'Should not exist',
        ]));
        $this->assertForbiddenTool($this->callTool($viewerId, 'get_tasks', [
            'project_id' => $hidden,
        ]));
        $this->assertNull($this->container['projectModel']->getByName('Should not exist'));
    }
}
