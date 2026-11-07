<?php
declare(strict_types=1);

namespace Kanboard\Plugin\ModelContextProtocol\Core;

use Kanboard\Core\Security\Role;
use Kanboard\Model\ColumnRestrictionModel;
use Kanboard\Model\ProjectRoleRestrictionModel;

/**
 * Per-user permission gate for MCP tool calls.
 *
 * This class deliberately does NOT rely on the browser session or on
 * ProjectPermissionModel::isUserAllowed(), which reads the session and
 * returns true for any admin regardless of the supplied user_id.
 *
 * Terminology used in comments:
 *   - "board manager" = project role PROJECT_MANAGER
 *   - "board member"  = project role PROJECT_MEMBER
 *   - "board viewer"  = project role PROJECT_VIEWER
 *   - "app admin"     = application role APP_ADMIN
 *   - "app manager"   = application role APP_MANAGER
 */
class McpAccess
{
    private $container;
    private int $userId;

    /** Cached application role string for the token user */
    private ?string $appRole = null;

    /** Cached list of visible project ids  [project_id => project_name] */
    private ?array $visibleProjects = null;

    /** @var array<string,bool> */
    private array $capabilityCache = [];

    /** @var array<int,string> Project roles read by capability scans  [project_id => role] */
    private array $projectRoleCache = [];

    public function __construct($container, int $userId)
    {
        $this->container = $container;
        $this->userId    = $userId;
    }

    // -----------------------------------------------------------------------
    // Application-level checks
    // -----------------------------------------------------------------------

    /**
     * Return true when the token user may call create_project.
     * Only app-admin and app-manager can create projects.
     */
    public function canCreateProject(): bool
    {
        $role = $this->getAppRole();
        return $role === Role::APP_ADMIN || $role === Role::APP_MANAGER;
    }

    /**
     * Whether this token user may be offered the given capability at all.
     *
     * Unknown capabilities are false. enabled=1 does not skip this check.
     * Results are cached for the request.
     */
    public function allowsCapability(string $capability): bool
    {
        if (!array_key_exists($capability, $this->capabilityCache)) {
            $this->capabilityCache[$capability] = $this->resolveCapability($capability);
        }

        return $this->capabilityCache[$capability];
    }

    private function resolveCapability(string $capability): bool
    {
        if ($capability === McpCatalog::CAP_READ) {
            return true;
        }

        if ($capability === McpCatalog::CAP_TASK_DELETE) {
            return $this->allowsCapability(McpCatalog::CAP_TASK_WRITE);
        }

        if ($capability === McpCatalog::CAP_TASK_WRITE) {
            return $this->isAppAdmin()
                || $this->hasExactRoleOnAnyVisibleProject([
                    Role::PROJECT_MEMBER,
                    Role::PROJECT_MANAGER,
                ])
                || $this->hasCustomRoleOnAnyVisibleProject();
        }

        if ($capability === McpCatalog::CAP_BOARD) {
            return $this->isAppAdmin() || $this->hasExactRoleOnAnyVisibleProject([
                Role::PROJECT_MANAGER,
            ]);
        }

        if ($capability === McpCatalog::CAP_CREATE_PROJECT) {
            return $this->canCreateProject();
        }

        return false;
    }

    /**
     * Exact role match only. Custom project roles are handled separately.
     *
     * App-admins are handled by the caller via isAppAdmin(). They are project
     * managers through getProjectRole() even when they have no membership.
     *
     * @param string[] $roles
     */
    private function hasExactRoleOnAnyVisibleProject(array $roles): bool
    {
        foreach ($this->getVisibleProjectIds() as $projectId) {
            if (in_array($this->getCachedProjectRole($projectId), $roles, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasCustomRoleOnAnyVisibleProject(): bool
    {
        foreach ($this->getVisibleProjectIds() as $projectId) {
            if ($this->isCustomProjectRole($this->getCachedProjectRole($projectId))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Capability scans only. Per-project checks keep reading the live role.
     */
    private function getCachedProjectRole(int $projectId): string
    {
        if (!array_key_exists($projectId, $this->projectRoleCache)) {
            $this->projectRoleCache[$projectId] = $this->getProjectRole($projectId);
        }

        return $this->projectRoleCache[$projectId];
    }

    /**
     * Return true when the token user is an application admin.
     * Used to decide whether get_users returns all users or only visible members.
     */
    public function isAppAdmin(): bool
    {
        return $this->getAppRole() === Role::APP_ADMIN;
    }

    // -----------------------------------------------------------------------
    // Project-level visibility
    // -----------------------------------------------------------------------

    /**
     * Return all active project ids the token user may see.
     *
     * - App-admin: every active project (matches what Kanboard's UI shows).
     * - Anyone else: only projects where the user is a direct member or
     *   belongs to a member group.
     *
     * @return int[]
     */
    public function getVisibleProjectIds(): array
    {
        if ($this->visibleProjects !== null) {
            return array_keys($this->visibleProjects);
        }

        if ($this->isAppAdmin()) {
            $rows = $this->container['projectModel']->getAllByStatus(1 /* active */);
            $this->visibleProjects = [];
            foreach ($rows as $row) {
                $this->visibleProjects[(int) $row['id']] = $row['name'];
            }
        } else {
            $raw = $this->container['projectUserRoleModel']
                ->getActiveProjectsByUser($this->userId);
            $this->visibleProjects = [];
            foreach ($raw as $id => $name) {
                $this->visibleProjects[(int) $id] = $name;
            }
        }

        return array_keys($this->visibleProjects);
    }

    /**
     * Return true when the token user can see the given project.
     */
    public function canSeeProject(int $projectId): bool
    {
        return in_array($projectId, $this->getVisibleProjectIds(), true);
    }

    // -----------------------------------------------------------------------
    // Role-based write checks
    // -----------------------------------------------------------------------

    /**
     * Return true when the user holds at least PROJECT_MEMBER on the project.
     * A custom project role is a member minus restrictions. Viewer and no role are false.
     */
    public function canWriteToProject(int $projectId): bool
    {
        $role = $this->getProjectRole($projectId);

        return $role === Role::PROJECT_MEMBER
            || $role === Role::PROJECT_MANAGER
            || $this->isCustomProjectRole($role);
    }

    /**
     * Custom roles follow column and project creation restrictions.
     * Built-in member and manager roles are not restricted here.
     */
    public function canCreateTaskInColumn(int $projectId, int $columnId): bool
    {
        if (!$this->canWriteToProject($projectId)) {
            return false;
        }

        $role = $this->getProjectRole($projectId);
        if (!$this->isCustomProjectRole($role)) {
            return true;
        }

        if ($columnId > 0) {
            $columnRules = $this->container['columnRestrictionModel']->getAllByRole($projectId, $role);
            foreach ($columnRules as $restriction) {
                if ((int) ($restriction['column_id'] ?? 0) !== $columnId) {
                    continue;
                }
                if (($restriction['rule'] ?? '') === ColumnRestrictionModel::RULE_ALLOW_TASK_CREATION) {
                    return true;
                }
                if (($restriction['rule'] ?? '') === ColumnRestrictionModel::RULE_BLOCK_TASK_CREATION) {
                    return false;
                }
            }
        }

        return !$this->hasProjectRestriction($projectId, $role, ProjectRoleRestrictionModel::RULE_TASK_CREATION);
    }

    /**
     * Custom roles with the task_move restriction cannot move tasks.
     */
    public function canMoveTask(int $projectId): bool
    {
        if (!$this->canWriteToProject($projectId)) {
            return false;
        }

        $role = $this->getProjectRole($projectId);
        if (!$this->isCustomProjectRole($role)) {
            return true;
        }

        return !$this->hasProjectRestriction($projectId, $role, ProjectRoleRestrictionModel::RULE_TASK_MOVE);
    }

    /**
     * Custom roles with the task_change_assignee restriction cannot assign tasks.
     */
    public function canAssignTask(int $projectId): bool
    {
        if (!$this->canWriteToProject($projectId)) {
            return false;
        }

        $role = $this->getProjectRole($projectId);
        if (!$this->isCustomProjectRole($role)) {
            return true;
        }

        return !$this->hasProjectRestriction(
            $projectId,
            $role,
            ProjectRoleRestrictionModel::RULE_TASK_CHANGE_ASSIGNEE
        );
    }

    /**
     * Custom roles with task_update_assigned can update only tasks assigned to them.
     *
     * @param array<string,mixed> $task
     */
    public function canUpdateTask(array $task): bool
    {
        $projectId = (int) $task['project_id'];
        if (!$this->canWriteToProject($projectId)) {
            return false;
        }

        $role = $this->getProjectRole($projectId);
        if (!$this->isCustomProjectRole($role)) {
            return true;
        }

        if ($this->hasProjectRestriction($projectId, $role, ProjectRoleRestrictionModel::RULE_TASK_UPDATE_ASSIGNED)) {
            return (int) ($task['owner_id'] ?? 0) === $this->userId;
        }

        return true;
    }

    /**
     * Return true when the user holds PROJECT_MANAGER on the project.
     */
    public function isProjectManager(int $projectId): bool
    {
        return $this->getProjectRole($projectId) === Role::PROJECT_MANAGER;
    }

    /**
     * Return true when the user may delete the given task.
     *
     * - Project manager: delete any task in the project.
     * - Project member and custom role: only tasks they created.
     * - Custom role with task_remove: never.
     * - Viewer / no role: never.
     *
     * @param array<string,mixed> $task  Row from taskFinderModel->getById()
     */
    public function canDeleteTask(array $task): bool
    {
        $projectId = (int) $task['project_id'];
        $role = $this->getProjectRole($projectId);

        if ($role === Role::PROJECT_MANAGER) {
            return true;
        }

        if ($this->isCustomProjectRole($role)
            && $this->hasProjectRestriction($projectId, $role, ProjectRoleRestrictionModel::RULE_TASK_SUPPRESSION)
        ) {
            return false;
        }

        if ($role === Role::PROJECT_MEMBER || $this->isCustomProjectRole($role)) {
            return (int) ($task['creator_id'] ?? 0) === $this->userId;
        }

        return false;
    }

    /**
     * Return true when the user may modify columns, categories, or swimlanes
     * on the given project (project manager only).
     */
    public function canManageBoard(int $projectId): bool
    {
        return $this->isProjectManager($projectId);
    }

    // -----------------------------------------------------------------------
    // Object-level authorization helpers
    // -----------------------------------------------------------------------

    /**
     * Resolve a task and verify the token user can see its project.
     *
     * Returns the task array on success or null when the task does not exist
     * or the user may not see its project.  Callers should return a generic
     * "Forbidden" error for both null cases to avoid oracle attacks.
     *
     * @return array<string,mixed>|null
     */
    public function authorizeTask(int $taskId): ?array
    {
        $task = $this->container['taskFinderModel']->getById($taskId);
        if (empty($task)) {
            return null;
        }

        if (!$this->canSeeProject((int) $task['project_id'])) {
            return null;
        }

        return $task;
    }

    /**
     * Resolve a column and verify the token user can see its project.
     *
     * @return array<string,mixed>|null
     */
    public function authorizeColumn(int $columnId): ?array
    {
        $column = $this->container['columnModel']->getById($columnId);
        if (empty($column)) {
            return null;
        }

        if (!$this->canSeeProject((int) $column['project_id'])) {
            return null;
        }

        return $column;
    }

    /**
     * Resolve a category and verify the token user can see its project.
     *
     * @return array<string,mixed>|null
     */
    public function authorizeCategory(int $categoryId): ?array
    {
        $category = $this->container['categoryModel']->getById($categoryId);
        if (empty($category)) {
            return null;
        }

        if (!$this->canSeeProject((int) $category['project_id'])) {
            return null;
        }

        return $category;
    }

    /**
     * Resolve a swimlane and verify the token user can see its project.
     *
     * @return array<string,mixed>|null
     */
    public function authorizeSwimlane(int $swimlaneId): ?array
    {
        $swimlane = $this->container['swimlaneModel']->getById($swimlaneId);
        if (empty($swimlane)) {
            return null;
        }

        if (!$this->canSeeProject((int) $swimlane['project_id'])) {
            return null;
        }

        return $swimlane;
    }

    /**
     * Return the project role for the token user on the given project.
     *
     * App-admins are treated as project managers everywhere.
     * Returns empty string when the user has no role.
     */
    public function getProjectRole(int $projectId): string
    {
        if ($this->isAppAdmin()) {
            return Role::PROJECT_MANAGER;
        }

        $role = $this->container['projectUserRoleModel']->getUserRole($projectId, $this->userId);
        return $role ?? '';
    }

    public function applicationRole(): string
    {
        return $this->getAppRole();
    }

    private function isCustomProjectRole(string $role): bool
    {
        return $role !== ''
            && $role !== Role::PROJECT_MANAGER
            && $role !== Role::PROJECT_MEMBER
            && $role !== Role::PROJECT_VIEWER;
    }

    private function hasProjectRestriction(int $projectId, string $role, string $rule): bool
    {
        $rows = $this->container['projectRoleRestrictionModel']->getAllByRole($projectId, $role);
        foreach ($rows as $restriction) {
            if (($restriction['rule'] ?? '') === $rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the token user's application role string.
     */
    private function getAppRole(): string
    {
        if ($this->appRole !== null) {
            return $this->appRole;
        }

        $user = $this->container['userModel']->getById($this->userId);
        $this->appRole = $user['role'] ?? Role::APP_USER;

        return $this->appRole;
    }
}
