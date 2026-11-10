<?php

require_once __DIR__ . '/McpTestCase.php';

use Kanboard\Core\Security\Role;
use Kanboard\Model\CommentModel;
use Kanboard\Model\ProjectRoleRestrictionModel;
use Kanboard\Model\TaskModel;

class McpServerAuthorizationTest extends McpTestCase
{
    public function testResourcesListReturnsBothUris(): void
    {
        $viewerId = $this->createUser('resource-viewer');
        $response = $this->mcpRequest($viewerId, 'resources/list');

        $uris = array_column($response['result']['resources'], 'uri');
        $this->assertSame(['kanboard://projects', 'kanboard://users'], $uris);
    }

    public function testViewerCreateTaskOnVisibleProjectIsUnknown(): void
    {
        $projectId = $this->createProject('Viewer write');
        $viewerId = $this->createUser('viewer-writer');
        $this->addMember($projectId, $viewerId, Role::PROJECT_VIEWER);

        $response = $this->callTool($viewerId, 'create_task', [
            'project_id' => $projectId,
            'title' => 'Should fail',
        ]);
        $this->assertUnknownTool($response, 'create_task');
        $this->assertSame(0, (int) $this->container['db']->table(TaskModel::TABLE)->eq('project_id', $projectId)->count());
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
        $this->enableEndpoints($memberId, ['create_task', 'delete_task']);

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

        $this->assertUnknownTool($this->callTool($memberId, 'create_column', [
            'project_id' => $projectId,
            'title' => 'Extra',
        ]), 'create_column');
    }

    public function testManagerCanCreateColumn(): void
    {
        $projectId = $this->createProject('Manager columns');
        $managerId = $this->createUser('column-manager');
        $this->addMember($projectId, $managerId, Role::PROJECT_MANAGER);
        $this->enableEndpoints($managerId, ['create_column']);

        $created = $this->decodeToolResult($this->callTool($managerId, 'create_column', [
            'project_id' => $projectId,
            'title' => 'Review',
        ]));
        $this->assertGreaterThan(0, (int) $created['column_id']);
    }

    public function testAppUserCannotCreateProject(): void
    {
        $userId = $this->createUser('plain-app-user', Role::APP_USER);
        $this->assertUnknownTool($this->callTool($userId, 'create_project', [
            'name' => 'Not allowed',
        ]), 'create_project');
        $this->assertNull($this->container['projectModel']->getByName('Not allowed'));
    }

    public function testAppAdminAndAppManagerCanCreateProject(): void
    {
        $this->enableEndpoints($this->adminId(), ['create_project']);
        $admin = $this->decodeToolResult($this->callTool($this->adminId(), 'create_project', [
            'name' => 'Admin project',
        ]));
        $this->assertGreaterThan(0, (int) $admin['project_id']);

        $managerId = $this->createUser('app-manager-create', Role::APP_MANAGER);
        $this->enableEndpoints($managerId, ['create_project']);
        $manager = $this->decodeToolResult($this->callTool($managerId, 'create_project', [
            'name' => 'Manager project',
        ]));
        $this->assertGreaterThan(0, (int) $manager['project_id']);

        $project = $this->container['projectModel']->getById((int) $manager['project_id']);
        $this->assertSame($managerId, (int) $project['owner_id']);
        $access = new \Kanboard\Plugin\ModelContextProtocol\Core\McpAccess($this->container, $managerId);
        $this->assertContains((int) $manager['project_id'], $access->getVisibleProjectIds());
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

        $allowed = $this->gateway()->allowedNames($this->accessFor($viewerId));
        $posted = array_values(array_diff($allowed, ['get_tasks']));
        $this->gateway()->save($viewerId, $allowed, $posted);

        $response = $this->callTool($viewerId, 'get_tasks', [
            'project_id' => $projectId,
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
        $this->enableEndpoints($memberId, ['add_task_comment']);
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

        $this->assertUnknownTool($this->callTool($viewerId, 'create_project', [
            'name' => 'Should not exist',
        ]), 'create_project');
        $this->assertForbiddenTool($this->callTool($viewerId, 'get_tasks', [
            'project_id' => $hidden,
        ]));
        $this->assertNull($this->container['projectModel']->getByName('Should not exist'));
    }

    public function testGetProjectsOmitsThePublicAccessToken(): void
    {
        $projectId = $this->createProject('Shared secret');
        $memberId = $this->createUser('project-reader');
        $this->addMember($projectId, $memberId, Role::PROJECT_MEMBER);
        $this->assertTrue($this->container['projectModel']->update([
            'id' => $projectId,
            'token' => 'public-secret-value',
        ]));

        $projects = $this->decodeToolResult($this->callTool($memberId, 'get_projects'));
        $this->assertNotEmpty($projects);
        foreach ($projects as $project) {
            $this->assertArrayNotHasKey('token', $project);
            $this->assertStringNotContainsString('public-secret-value', json_encode($project));
        }
    }

    public function testAppUserDoesNotReceiveManagerComments(): void
    {
        $projectId = $this->createProject('Comment board');
        $userId = $this->createUser('comment-reader', Role::APP_USER);
        $managerId = $this->createUser('comment-manager', Role::APP_MANAGER);
        $this->addMember($projectId, $userId, Role::PROJECT_VIEWER);
        $this->addMember($projectId, $managerId, Role::PROJECT_MANAGER);
        $taskId = (int) $this->container['taskCreationModel']->create([
            'title' => 'Commented',
            'project_id' => $projectId,
            'creator_id' => $managerId,
        ]);
        $hiddenId = (int) $this->container['commentModel']->create([
            'task_id' => $taskId,
            'user_id' => $managerId,
            'comment' => 'manager only',
            'visibility' => Role::APP_MANAGER,
        ]);
        $publicId = (int) $this->container['commentModel']->create([
            'task_id' => $taskId,
            'user_id' => $managerId,
            'comment' => 'everyone',
            'visibility' => Role::APP_USER,
        ]);

        $seen = $this->decodeToolResult($this->callTool($userId, 'get_task_comments', [
            'task_id' => $taskId,
        ]));
        $ids = array_map(static function ($row) {
            return (int) $row['id'];
        }, $seen);
        $this->assertContains($publicId, $ids);
        $this->assertNotContains($hiddenId, $ids);

        $managerSeen = $this->decodeToolResult($this->callTool($managerId, 'get_task_comments', [
            'task_id' => $taskId,
        ]));
        $managerIds = array_map(static function ($row) {
            return (int) $row['id'];
        }, $managerSeen);
        $this->assertContains($hiddenId, $managerIds);
    }

    public function testGetTaskCommentsOmitsProfilePii(): void
    {
        $projectId = $this->createProject('Comment PII board');
        $authorId = $this->createUser('pii-author', Role::APP_USER, 'PII Author');
        $readerId = $this->createUser('pii-reader');
        $this->addMember($projectId, $authorId, Role::PROJECT_MEMBER);
        $this->addMember($projectId, $readerId, Role::PROJECT_VIEWER);

        $this->assertTrue($this->container['db']->table('users')->eq('id', $authorId)->update([
            'email' => 'pii-author@example.com',
            'avatar_path' => 'uploads/avatars/secret-face.png',
        ]));

        $taskId = (int) $this->container['taskCreationModel']->create([
            'title' => 'Commented PII',
            'project_id' => $projectId,
            'creator_id' => $authorId,
        ]);
        $commentId = (int) $this->container['commentModel']->create([
            'task_id' => $taskId,
            'user_id' => $authorId,
            'comment' => 'visible body',
            'visibility' => Role::APP_USER,
        ]);

        $raw = $this->container['commentModel']->getAll($taskId);
        $this->assertSame('pii-author@example.com', $raw[0]['email']);
        $this->assertSame('uploads/avatars/secret-face.png', $raw[0]['avatar_path']);

        $seen = $this->decodeToolResult($this->callTool($readerId, 'get_task_comments', [
            'task_id' => $taskId,
        ]));
        $this->assertCount(1, $seen);
        $this->assertSame($commentId, (int) $seen[0]['id']);
        $this->assertSame($taskId, (int) $seen[0]['task_id']);
        $this->assertSame($authorId, (int) $seen[0]['user_id']);
        $this->assertSame('pii-author', $seen[0]['username']);
        $this->assertSame('PII Author', $seen[0]['name']);
        $this->assertSame('visible body', $seen[0]['comment']);
        $this->assertArrayNotHasKey('email', $seen[0]);
        $this->assertArrayNotHasKey('avatar_path', $seen[0]);
        $this->assertArrayNotHasKey('password', $seen[0]);

        $encoded = json_encode($seen);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('pii-author@example.com', $encoded);
        $this->assertStringNotContainsString('secret-face.png', $encoded);

        $allowed = [
            'id',
            'task_id',
            'user_id',
            'username',
            'name',
            'comment',
            'date_creation',
            'date_modification',
            'visibility',
        ];
        sort($allowed);
        $keys = array_keys($seen[0]);
        sort($keys);
        $this->assertSame($allowed, $keys);
    }

    public function testCustomRoleCannotCreateWhenRestricted(): void
    {
        $projectId = $this->createProject('Custom role board');
        $userId = $this->createUser('custom-dev');
        $roleId = $this->container['projectRoleModel']->create($projectId, 'Developer');
        $this->assertNotFalse($roleId);
        $this->assertNotFalse($this->container['projectRoleRestrictionModel']->create(
            $projectId,
            (int) $roleId,
            ProjectRoleRestrictionModel::RULE_TASK_CREATION
        ));
        $this->addMember($projectId, $userId, 'Developer');
        $this->enableEndpoints($userId, ['create_task']);

        $this->assertForbiddenTool($this->callTool($userId, 'create_task', [
            'project_id' => $projectId,
            'title' => 'Blocked',
        ]));
    }

    public function testCustomRoleCanCreateWithoutRestriction(): void
    {
        $projectId = $this->createProject('Open custom role');
        $userId = $this->createUser('custom-writer');
        $this->assertNotFalse($this->container['projectRoleModel']->create($projectId, 'Developer'));
        $this->addMember($projectId, $userId, 'Developer');
        $this->enableEndpoints($userId, ['create_task']);

        $created = $this->decodeToolResult($this->callTool($userId, 'create_task', [
            'project_id' => $projectId,
            'title' => 'Allowed',
        ]));
        $this->assertGreaterThan(0, (int) $created['task_id']);
    }
}
