<?php

require_once __DIR__ . '/McpTestCase.php';

use Kanboard\Core\Security\Role;
use Kanboard\Plugin\ModelContextProtocol\Core\McpAccess;

class McpAccessTest extends McpTestCase
{
    public function testAppAdminSeesEveryActiveProject(): void
    {
        $activeA = $this->createProject('Active A');
        $activeB = $this->createProject('Active B');
        $inactive = $this->createProject('Inactive');
        $this->assertTrue($this->container['projectModel']->disable($inactive));

        $access = new McpAccess($this->container, $this->adminId());
        $visible = $access->getVisibleProjectIds();
        sort($visible);

        $this->assertSame([$activeA, $activeB], $visible);
        $this->assertNotContains($inactive, $access->getVisibleProjectIds());
    }

    public function testRegularUserSeesOnlyMembership(): void
    {
        $mine = $this->createProject('Mine');
        $other = $this->createProject('Other');
        $userId = $this->createUser('member-only');
        $this->addMember($mine, $userId, Role::PROJECT_MEMBER);

        $access = new McpAccess($this->container, $userId);
        $visible = $access->getVisibleProjectIds();
        sort($visible);

        $this->assertSame([$mine], $visible);
        $this->assertNotContains($other, $visible);
    }

    public function testAppAdminRoleIsProjectManagerWithoutMembership(): void
    {
        $projectId = $this->createProject('Admin board');
        $adminId = $this->adminId();
        $membership = $this->container['db']
            ->table('project_has_users')
            ->eq('project_id', $projectId)
            ->eq('user_id', $adminId)
            ->exists();
        $this->assertFalse($membership);

        $access = new McpAccess($this->container, $adminId);
        $this->assertSame(Role::PROJECT_MANAGER, $access->getProjectRole($projectId));
        $this->assertTrue($access->isProjectManager($projectId));
    }

    public function testAppManagerIsNotElevatedToProjectManager(): void
    {
        $projectId = $this->createProject('Managed elsewhere');
        $managerId = $this->createUser('app-manager-role', Role::APP_MANAGER);
        $access = new McpAccess($this->container, $managerId);

        $this->assertNotSame(Role::PROJECT_MANAGER, $access->getProjectRole($projectId));
        $this->assertFalse($access->isProjectManager($projectId));
        $this->assertFalse($access->canManageBoard($projectId));
        $this->assertTrue($access->canCreateProject());
    }

    public function testViewerCannotWriteManageOrDelete(): void
    {
        $projectId = $this->createProject('Viewed');
        $viewerId = $this->createUser('viewer-role');
        $ownerId = $this->createUser('task-owner');
        $this->addMember($projectId, $viewerId, Role::PROJECT_VIEWER);
        $this->addMember($projectId, $ownerId, Role::PROJECT_MEMBER);

        $taskId = $this->container['taskCreationModel']->create([
            'title' => 'Visible task',
            'project_id' => $projectId,
            'creator_id' => $ownerId,
        ]);
        $task = $this->container['taskFinderModel']->getById($taskId);

        $access = new McpAccess($this->container, $viewerId);
        $this->assertFalse($access->canWriteToProject($projectId));
        $this->assertFalse($access->canManageBoard($projectId));
        $this->assertFalse($access->canDeleteTask($task));
        $this->assertFalse($access->canCreateProject());
    }

    public function testMemberCanWriteAndDeleteOnlyOwnTask(): void
    {
        $projectId = $this->createProject('Member board');
        $memberId = $this->createUser('board-member');
        $otherId = $this->createUser('other-member');
        $this->addMember($projectId, $memberId, Role::PROJECT_MEMBER);
        $this->addMember($projectId, $otherId, Role::PROJECT_MEMBER);

        $ownId = $this->container['taskCreationModel']->create([
            'title' => 'Own',
            'project_id' => $projectId,
            'creator_id' => $memberId,
        ]);
        $otherTaskId = $this->container['taskCreationModel']->create([
            'title' => 'Theirs',
            'project_id' => $projectId,
            'creator_id' => $otherId,
        ]);

        $access = new McpAccess($this->container, $memberId);
        $this->assertTrue($access->canWriteToProject($projectId));
        $this->assertFalse($access->canManageBoard($projectId));
        $this->assertTrue($access->canDeleteTask($this->container['taskFinderModel']->getById($ownId)));
        $this->assertFalse($access->canDeleteTask($this->container['taskFinderModel']->getById($otherTaskId)));
    }

    public function testManagerCanManageBoardAndDeleteAnyTask(): void
    {
        $projectId = $this->createProject('Manager board');
        $managerId = $this->createUser('project-manager-user');
        $memberId = $this->createUser('project-member-user');
        $this->addMember($projectId, $managerId, Role::PROJECT_MANAGER);
        $this->addMember($projectId, $memberId, Role::PROJECT_MEMBER);

        $taskId = $this->container['taskCreationModel']->create([
            'title' => 'Someone else',
            'project_id' => $projectId,
            'creator_id' => $memberId,
        ]);

        $access = new McpAccess($this->container, $managerId);
        $this->assertTrue($access->canManageBoard($projectId));
        $this->assertTrue($access->isProjectManager($projectId));
        $this->assertTrue($access->canDeleteTask($this->container['taskFinderModel']->getById($taskId)));
        $this->assertFalse($access->canCreateProject());
    }

    public function testAuthorizeTaskIsNullForMissingAndHiddenProject(): void
    {
        $visible = $this->createProject('Visible project');
        $hidden = $this->createProject('Hidden project');
        $viewerId = $this->createUser('limited-viewer');
        $authorId = $this->createUser('hidden-author');
        $this->addMember($visible, $viewerId, Role::PROJECT_VIEWER);
        $this->addMember($hidden, $authorId, Role::PROJECT_MEMBER);

        $hiddenTaskId = (int) $this->container['taskCreationModel']->create([
            'title' => 'Secret',
            'project_id' => $hidden,
            'creator_id' => $authorId,
        ]);
        $this->assertGreaterThan(0, $hiddenTaskId);

        $access = new McpAccess($this->container, $viewerId);
        $this->assertNull($access->authorizeTask(999999));
        $this->assertNull($access->authorizeTask($hiddenTaskId));
    }

    public function testSessionAdminDoesNotWidenTokenUser(): void
    {
        $visible = $this->createProject('Viewer project');
        $hidden = $this->createProject('Admin only project');
        $viewerId = $this->createUser('session-viewer');
        $this->addMember($visible, $viewerId, Role::PROJECT_VIEWER);

        $this->login($this->adminId());
        $this->assertTrue($this->container['userSession']->isAdmin());

        $access = new McpAccess($this->container, $viewerId);
        $visibleIds = $access->getVisibleProjectIds();
        sort($visibleIds);

        $this->assertFalse($access->canCreateProject());
        $this->assertSame([$visible], $visibleIds);
        $this->assertNotContains($hidden, $visibleIds);
    }
}
