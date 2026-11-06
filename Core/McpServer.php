<?php
declare(strict_types=1);

namespace Kanboard\Plugin\ModelContextProtocol\Core;

use Kanboard\Core\Base;
use Kanboard\Model\ColumnModel;
use Kanboard\Model\TaskModel;
use InvalidArgumentException;
use Throwable;
use Traversable;

/**
 * MCP Server for Kanboard
 *
 * Implements Model Context Protocol JSON-RPC 2.0 specification.
 * Every tool and resource is scoped to the authenticated user supplied via
 * $userId; no global data is ever returned.
 */
class McpServer extends Base
{
    private const LATEST_PROTOCOL_VERSION = '2025-11-25';

    /**
     * @var array<int,string>
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    private int $userId;
    private McpAccess $access;

    public function __construct($container, int $userId)
    {
        $this->container = $container;
        $this->userId    = $userId;
        $this->access    = new McpAccess($container, $userId);
    }

    // -----------------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------------

    public function handleRequest(array $request): ?array
    {
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            return $this->errorResponse(-32600, 'Invalid Request', $request['id'] ?? null);
        }

        if (isset($request['params']) && !is_array($request['params'])) {
            return $this->errorResponse(-32602, 'Invalid params', $request['id'] ?? null);
        }

        $method = isset($request['method']) && is_string($request['method']) ? $request['method'] : '';
        $params = $request['params'] ?? [];
        $id     = $request['id'] ?? null;

        try {
            switch ($method) {
                case 'initialize':
                    return $this->initialize($params, $id);

                case 'notifications/initialized':
                case 'initialized':
                    return $this->initialized();

                case 'tools/list':
                    return $this->listTools($params, $id);

                case 'tools/call':
                    return $this->callTool($params, $id);

                case 'resources/list':
                    return $this->listResources($params, $id);

                case 'resources/templates/list':
                    return $this->listResourceTemplates($params, $id);

                case 'resources/read':
                    return $this->readResource($params, $id);

                case 'ListOfferings':
                case 'listOfferings':
                    return $this->listOfferings($id);

                case 'ping':
                    return $this->ping($id);

                default:
                    return $this->errorResponse(-32601, 'Method not found: ' . $method, $id);
            }
        } catch (Throwable $exception) {
            $this->logThrowable('Unhandled MCP request failure', $exception);
            return $this->errorResponse(-32603, 'Internal error', $id);
        }
    }

    // -----------------------------------------------------------------------
    // Protocol
    // -----------------------------------------------------------------------

    private function initialize(array $params, int|string|null $id): array
    {
        $requestedVersion = isset($params['protocolVersion']) && is_string($params['protocolVersion'])
            ? $params['protocolVersion']
            : null;

        $negotiatedVersion = in_array($requestedVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $requestedVersion
            : self::LATEST_PROTOCOL_VERSION;

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'protocolVersion' => $negotiatedVersion,
                'capabilities'    => [
                    'tools'     => ['listChanged' => false],
                    'resources' => ['listChanged' => false],
                ],
                'serverInfo' => [
                    'name'        => 'kanboard-mcp',
                    'title'       => 'Kanboard MCP Server',
                    'version'     => '1.1.2',
                    'description' => 'Kanboard project-management tools and resources via MCP.',
                ],
                'instructions' => 'Use the available Kanboard tools to manage projects, tasks, columns, categories, and swimlanes. You can only see and modify boards you have access to.',
            ],
        ];
    }

    private function initialized(): ?array
    {
        return null;
    }

    // -----------------------------------------------------------------------
    // Tool list
    // -----------------------------------------------------------------------

    private function listTools(array $params, int|string|null $id): array
    {
        $cursor = $params['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) {
            return $this->errorResponse(-32602, 'Invalid params: cursor must be a string', $id);
        }

        $tools = [
            [
                'name'        => 'get_projects',
                'description' => 'Get all projects visible to you',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false],
            ],
            [
                'name'        => 'create_project',
                'description' => 'Create a new project (requires app-admin or app-manager role)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'        => ['type' => 'string', 'description' => 'Project name'],
                        'description' => ['type' => 'string', 'description' => 'Project description'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name'        => 'get_tasks',
                'description' => 'Get tasks from a project',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'status_id'  => [
                            'type'        => 'integer',
                            'description' => 'Status ID (1 for active, 0 for archived)',
                            'enum'        => [TaskModel::STATUS_CLOSED, TaskModel::STATUS_OPEN],
                            'default'     => TaskModel::STATUS_OPEN,
                        ],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name'        => 'create_task',
                'description' => 'Create a new task',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'project_id'  => ['type' => 'integer', 'description' => 'Project ID'],
                        'title'       => ['type' => 'string',  'description' => 'Task title'],
                        'description' => ['type' => 'string',  'description' => 'Task description'],
                        'column_id'   => ['type' => 'integer', 'description' => 'Column ID'],
                    ],
                    'required' => ['project_id', 'title'],
                ],
            ],
            [
                'name'        => 'update_task',
                'description' => 'Update an existing task',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id'     => ['type' => 'integer', 'description' => 'Task ID'],
                        'title'       => ['type' => 'string',  'description' => 'Task title'],
                        'description' => ['type' => 'string',  'description' => 'Task description'],
                        'column_id'   => ['type' => 'integer', 'description' => 'Column ID'],
                    ],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name'        => 'get_columns',
                'description' => 'Get columns for a project',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['project_id' => ['type' => 'integer', 'description' => 'Project ID']],
                    'required'   => ['project_id'],
                ],
            ],
            [
                'name'        => 'move_task',
                'description' => 'Move a task to a different column',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'task_id'    => ['type' => 'integer', 'description' => 'Task ID'],
                        'column_id'  => ['type' => 'integer', 'description' => 'Target column ID'],
                    ],
                    'required' => ['project_id', 'task_id', 'column_id'],
                ],
            ],
            [
                'name'        => 'get_task_details',
                'description' => 'Get detailed information about a specific task',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['task_id' => ['type' => 'integer', 'description' => 'Task ID']],
                    'required'   => ['task_id'],
                ],
            ],
            [
                'name'        => 'delete_task',
                'description' => 'Delete a task (managers can delete any task; members can only delete their own)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['task_id' => ['type' => 'integer', 'description' => 'Task ID']],
                    'required'   => ['task_id'],
                ],
            ],
            [
                'name'        => 'assign_task',
                'description' => 'Assign a task to a user',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'user_id' => ['type' => 'integer', 'description' => 'User ID'],
                    ],
                    'required' => ['task_id', 'user_id'],
                ],
            ],
            [
                'name'        => 'set_task_due_date',
                'description' => 'Set due date for a task',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id'  => ['type' => 'integer', 'description' => 'Task ID'],
                        'due_date' => ['type' => 'string',  'description' => 'Due date in YYYY-MM-DD format'],
                    ],
                    'required' => ['task_id', 'due_date'],
                ],
            ],
            [
                'name'        => 'add_task_comment',
                'description' => 'Add a comment to a task',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'comment' => ['type' => 'string',  'description' => 'Comment text'],
                    ],
                    'required' => ['task_id', 'comment'],
                ],
            ],
            [
                'name'        => 'get_users',
                'description' => 'Get users (returns id, username, name only). Admins see all active users; others see members of their visible projects.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false],
            ],
            [
                'name'        => 'get_task_comments',
                'description' => 'Get all comments for a task',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['task_id' => ['type' => 'integer', 'description' => 'Task ID']],
                    'required'   => ['task_id'],
                ],
            ],
            // Column management (project manager only)
            [
                'name'        => 'create_column',
                'description' => 'Add a new column to a project (project manager only)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'project_id'  => ['type' => 'integer', 'description' => 'Project ID'],
                        'title'       => ['type' => 'string',  'description' => 'Column title'],
                        'task_limit'  => ['type' => 'integer', 'description' => 'Task limit (0 for unlimited)'],
                        'description' => ['type' => 'string',  'description' => 'Column description'],
                    ],
                    'required' => ['project_id', 'title'],
                ],
            ],
            [
                'name'        => 'update_column',
                'description' => 'Modify column settings (project manager only)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'column_id'       => ['type' => 'integer', 'description' => 'Column ID'],
                        'title'           => ['type' => 'string',  'description' => 'Column title'],
                        'task_limit'      => ['type' => 'integer', 'description' => 'Task limit (0 for unlimited)'],
                        'description'     => ['type' => 'string',  'description' => 'Column description'],
                        'hide_in_dashboard' => ['type' => 'integer', 'description' => '1 to hide from dashboard, 0 to show'],
                    ],
                    'required' => ['column_id'],
                ],
            ],
            [
                'name'        => 'delete_column',
                'description' => 'Remove a column (project manager only)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['column_id' => ['type' => 'integer', 'description' => 'Column ID']],
                    'required'   => ['column_id'],
                ],
            ],
            [
                'name'        => 'reorder_columns',
                'description' => 'Change column positions (project manager only)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'column_ids' => ['type' => 'array',   'description' => 'Array of column IDs in desired order'],
                    ],
                    'required' => ['project_id', 'column_ids'],
                ],
            ],
            // Category management (project manager only)
            [
                'name'        => 'create_category',
                'description' => 'Add a task category (project manager only)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'project_id'  => ['type' => 'integer', 'description' => 'Project ID'],
                        'name'        => ['type' => 'string',  'description' => 'Category name'],
                        'description' => ['type' => 'string',  'description' => 'Category description'],
                    ],
                    'required' => ['project_id', 'name'],
                ],
            ],
            [
                'name'        => 'update_category',
                'description' => 'Modify a category (project manager only)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'Category ID'],
                        'name'        => ['type' => 'string',  'description' => 'Category name'],
                        'description' => ['type' => 'string',  'description' => 'Category description'],
                    ],
                    'required' => ['category_id'],
                ],
            ],
            [
                'name'        => 'delete_category',
                'description' => 'Remove a category (project manager only)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['category_id' => ['type' => 'integer', 'description' => 'Category ID']],
                    'required'   => ['category_id'],
                ],
            ],
            [
                'name'        => 'get_categories',
                'description' => 'List project categories',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['project_id' => ['type' => 'integer', 'description' => 'Project ID']],
                    'required'   => ['project_id'],
                ],
            ],
            // Swimlane management (project manager only)
            [
                'name'        => 'create_swimlane',
                'description' => 'Add a swimlane (project manager only)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'project_id'  => ['type' => 'integer', 'description' => 'Project ID'],
                        'name'        => ['type' => 'string',  'description' => 'Swimlane name'],
                        'description' => ['type' => 'string',  'description' => 'Swimlane description'],
                    ],
                    'required' => ['project_id', 'name'],
                ],
            ],
            [
                'name'        => 'update_swimlane',
                'description' => 'Modify a swimlane (project manager only)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'swimlane_id' => ['type' => 'integer', 'description' => 'Swimlane ID'],
                        'name'        => ['type' => 'string',  'description' => 'Swimlane name'],
                        'description' => ['type' => 'string',  'description' => 'Swimlane description'],
                    ],
                    'required' => ['swimlane_id'],
                ],
            ],
            [
                'name'        => 'delete_swimlane',
                'description' => 'Remove a swimlane (project manager only)',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'project_id'  => ['type' => 'integer', 'description' => 'Project ID'],
                        'swimlane_id' => ['type' => 'integer', 'description' => 'Swimlane ID'],
                    ],
                    'required' => ['project_id', 'swimlane_id'],
                ],
            ],
            [
                'name'        => 'get_swimlanes',
                'description' => 'List project swimlanes',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['project_id' => ['type' => 'integer', 'description' => 'Project ID']],
                    'required'   => ['project_id'],
                ],
            ],
        ];

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => ['tools' => $tools],
        ];
    }

    // -----------------------------------------------------------------------
    // Tool dispatch
    // -----------------------------------------------------------------------

    private function callTool(array $params, int|string|null $id): array
    {
        if (!isset($params['name']) || !is_string($params['name']) || trim($params['name']) === '') {
            return $this->errorResponse(-32602, 'Invalid params: name is required and must be a non-empty string', $id);
        }

        if (array_key_exists('arguments', $params) && !is_array($params['arguments'])) {
            return $this->errorResponse(-32602, 'Invalid params: arguments must be an object', $id);
        }

        $toolName  = $params['name'];
        $arguments = $params['arguments'] ?? [];

        try {
            return $this->dispatchTool($toolName, $arguments, $id);
        } catch (InvalidArgumentException $exception) {
            return $this->createToolExecutionErrorResponse($exception->getMessage(), $id);
        } catch (Throwable $exception) {
            $this->logThrowable(sprintf('Tool execution failed for %s', $toolName), $exception);
            return $this->createToolExecutionErrorResponse('Tool execution failed', $id);
        }
    }

    /**
     * @param array<string,mixed> $args
     */
    private function dispatchTool(string $toolName, array $args, int|string|null $id): array
    {
        switch ($toolName) {

            // ----------------------------------------------------------------
            case 'get_projects':
                $ids      = $this->access->getVisibleProjectIds();
                $projects = [];
                foreach ($ids as $pid) {
                    $p = $this->container['projectModel']->getById($pid);
                    if (!empty($p)) {
                        $projects[] = $p;
                    }
                }
                return $this->createSuccessResponse(array_values($projects), $id);

            // ----------------------------------------------------------------
            case 'create_project':
                if (!$this->access->canCreateProject()) {
                    return $this->forbiddenResponse($id);
                }

                if (!isset($args['name']) || !is_string($args['name']) || trim($args['name']) === '') {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: name must be a non-empty string', $id);
                }

                $projectId = $this->container['projectModel']->create([
                    'name'        => trim($args['name']),
                    'description' => $args['description'] ?? '',
                ]);
                return $this->createSuccessResponse(['project_id' => $projectId], $id);

            // ----------------------------------------------------------------
            case 'get_tasks':
                $projectId = $this->requirePositiveInt($args, 'project_id', $id);
                if ($projectId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                }

                if (!$this->access->canSeeProject($projectId)) {
                    return $this->forbiddenResponse($id);
                }

                $statusId = array_key_exists('status_id', $args)
                    ? (int) $args['status_id']
                    : TaskModel::STATUS_OPEN;

                if (!in_array($statusId, [TaskModel::STATUS_OPEN, TaskModel::STATUS_CLOSED], true)) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: status_id must be 0 or 1', $id);
                }

                $tasks = $this->container['taskFinderModel']->getAll($projectId, $statusId);
                return $this->createSuccessResponse(array_values($tasks), $id);

            // ----------------------------------------------------------------
            case 'create_task':
                $projectId = $this->requirePositiveInt($args, 'project_id', $id);
                if ($projectId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                }

                if (!isset($args['title']) || !is_string($args['title']) || trim($args['title']) === '') {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: title must be a non-empty string', $id);
                }

                if (!$this->access->canWriteToProject($projectId)) {
                    return $this->forbiddenResponse($id);
                }

                $taskData = [
                    'project_id'  => $projectId,
                    'title'       => trim($args['title']),
                    'description' => $args['description'] ?? '',
                    'creator_id'  => $this->userId,
                ];

                if (isset($args['column_id'])) {
                    $colId  = (int) $args['column_id'];
                    $column = $this->access->authorizeColumn($colId);
                    if ($column === null || (int) $column['project_id'] !== $projectId) {
                        return $this->forbiddenResponse($id);
                    }
                    $taskData['column_id'] = $colId;
                }

                $taskId = $this->container['taskCreationModel']->create($taskData);
                return $this->createSuccessResponse(['task_id' => $taskId], $id);

            // ----------------------------------------------------------------
            case 'update_task':
                $taskId = $this->requirePositiveInt($args, 'task_id', $id);
                if ($taskId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                }

                $task = $this->access->authorizeTask($taskId);
                if ($task === null) {
                    return $this->forbiddenResponse($id);
                }

                if (!$this->access->canWriteToProject((int) $task['project_id'])) {
                    return $this->forbiddenResponse($id);
                }

                $taskData = ['id' => $taskId];
                if (isset($args['title']))       $taskData['title']       = $args['title'];
                if (isset($args['description'])) $taskData['description'] = $args['description'];

                if (isset($args['column_id'])) {
                    $colId  = (int) $args['column_id'];
                    $column = $this->access->authorizeColumn($colId);
                    if ($column === null || (int) $column['project_id'] !== (int) $task['project_id']) {
                        return $this->forbiddenResponse($id);
                    }
                    $taskData['column_id'] = $colId;
                }

                $ok = $this->container['taskModificationModel']->update($taskData);
                return $this->createSuccessResponse(['success' => $ok], $id);

            // ----------------------------------------------------------------
            case 'get_columns':
                $projectId = $this->requirePositiveInt($args, 'project_id', $id);
                if ($projectId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                }

                if (!$this->access->canSeeProject($projectId)) {
                    return $this->forbiddenResponse($id);
                }

                $columns = $this->container['columnModel']->getAll($projectId);
                return $this->createSuccessResponse(array_values($columns), $id);

            // ----------------------------------------------------------------
            case 'move_task':
                $projectId = $this->requirePositiveInt($args, 'project_id', $id);
                $taskId    = $this->requirePositiveInt($args, 'task_id',    $id);
                $columnId  = $this->requirePositiveInt($args, 'column_id',  $id);

                if ($projectId === null || $taskId === null || $columnId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: project_id, task_id, and column_id must be positive integers', $id);
                }

                $task = $this->access->authorizeTask($taskId);
                if ($task === null || (int) $task['project_id'] !== $projectId) {
                    return $this->forbiddenResponse($id);
                }

                if (!$this->access->canWriteToProject($projectId)) {
                    return $this->forbiddenResponse($id);
                }

                $column = $this->access->authorizeColumn($columnId);
                if ($column === null || (int) $column['project_id'] !== $projectId) {
                    return $this->forbiddenResponse($id);
                }

                $ok = $this->container['taskPositionModel']->movePosition($projectId, $taskId, $columnId, 1);
                return $this->createSuccessResponse(['success' => $ok], $id);

            // ----------------------------------------------------------------
            case 'get_task_details':
                $taskId = $this->requirePositiveInt($args, 'task_id', $id);
                if ($taskId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                }

                $task = $this->access->authorizeTask($taskId);
                if ($task === null) {
                    return $this->forbiddenResponse($id);
                }

                return $this->createSuccessResponse($task, $id);

            // ----------------------------------------------------------------
            case 'delete_task':
                $taskId = $this->requirePositiveInt($args, 'task_id', $id);
                if ($taskId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                }

                $task = $this->access->authorizeTask($taskId);
                if ($task === null) {
                    return $this->forbiddenResponse($id);
                }

                if (!$this->access->canDeleteTask($task)) {
                    return $this->forbiddenResponse($id);
                }

                $ok = $this->container['taskModel']->remove($taskId);
                return $this->createSuccessResponse(['success' => $ok], $id);

            // ----------------------------------------------------------------
            case 'assign_task':
                $taskId    = $this->requirePositiveInt($args, 'task_id', $id);
                $assigneeId = $this->requirePositiveInt($args, 'user_id', $id);

                if ($taskId === null || $assigneeId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: task_id and user_id must be positive integers', $id);
                }

                $task = $this->access->authorizeTask($taskId);
                if ($task === null) {
                    return $this->forbiddenResponse($id);
                }

                $projectId = (int) $task['project_id'];

                if (!$this->access->canWriteToProject($projectId)) {
                    return $this->forbiddenResponse($id);
                }

                // Only assignable users (active members/managers, including groups)
                $assignable = $this->container['projectUserRoleModel']->getAssignableUsers($projectId);
                if (!array_key_exists($assigneeId, $assignable)) {
                    return $this->createToolExecutionErrorResponse('User is not an assignable member of this project', $id);
                }

                $ok = $this->container['taskModificationModel']->update(['id' => $taskId, 'owner_id' => $assigneeId]);
                return $this->createSuccessResponse(['success' => $ok], $id);

            // ----------------------------------------------------------------
            case 'set_task_due_date':
                $taskId = $this->requirePositiveInt($args, 'task_id', $id);
                if ($taskId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                }

                if (!isset($args['due_date']) || !is_string($args['due_date']) || trim($args['due_date']) === '') {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: due_date must be a non-empty string', $id);
                }

                $task = $this->access->authorizeTask($taskId);
                if ($task === null) {
                    return $this->forbiddenResponse($id);
                }

                if (!$this->access->canWriteToProject((int) $task['project_id'])) {
                    return $this->forbiddenResponse($id);
                }

                $ok = $this->container['taskModificationModel']->update(['id' => $taskId, 'date_due' => $args['due_date']]);
                return $this->createSuccessResponse(['success' => $ok], $id);

            // ----------------------------------------------------------------
            case 'add_task_comment':
                $taskId = $this->requirePositiveInt($args, 'task_id', $id);
                if ($taskId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                }

                if (!isset($args['comment']) || !is_string($args['comment']) || trim($args['comment']) === '') {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: comment must be a non-empty string', $id);
                }

                $task = $this->access->authorizeTask($taskId);
                if ($task === null) {
                    return $this->forbiddenResponse($id);
                }

                if (!$this->access->canWriteToProject((int) $task['project_id'])) {
                    return $this->forbiddenResponse($id);
                }

                $commentId = $this->container['commentModel']->create([
                    'task_id' => $taskId,
                    'comment' => trim($args['comment']),
                    'user_id' => $this->userId, // always the token owner
                ]);
                return $this->createSuccessResponse(['comment_id' => $commentId], $id);

            // ----------------------------------------------------------------
            case 'get_users':
                return $this->createSuccessResponse($this->getSafeUsers(), $id);

            // ----------------------------------------------------------------
            case 'get_task_comments':
                $taskId = $this->requirePositiveInt($args, 'task_id', $id);
                if ($taskId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                }

                $task = $this->access->authorizeTask($taskId);
                if ($task === null) {
                    return $this->forbiddenResponse($id);
                }

                $comments = $this->container['commentModel']->getAll($taskId);
                return $this->createSuccessResponse(array_values($comments), $id);

            // ----------------------------------------------------------------
            // Column management (project manager only)
            // ----------------------------------------------------------------
            case 'create_column':
                $projectId = $this->requirePositiveInt($args, 'project_id', $id);
                if ($projectId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                }

                if (!isset($args['title']) || !is_string($args['title']) || trim($args['title']) === '') {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: title must be a non-empty string', $id);
                }

                if (!$this->access->canManageBoard($projectId)) {
                    return $this->forbiddenResponse($id);
                }

                $columnId = $this->container['columnModel']->create(
                    $projectId,
                    trim($args['title']),
                    $args['task_limit'] ?? 0,
                    $args['description'] ?? ''
                );
                return $this->createSuccessResponse(['column_id' => $columnId], $id);

            // ----------------------------------------------------------------
            case 'update_column':
                $columnId = $this->requirePositiveInt($args, 'column_id', $id);
                if ($columnId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: column_id must be a positive integer', $id);
                }

                $column = $this->access->authorizeColumn($columnId);
                if ($column === null) {
                    return $this->forbiddenResponse($id);
                }

                if (!$this->access->canManageBoard((int) $column['project_id'])) {
                    return $this->forbiddenResponse($id);
                }

                $title         = $args['title']       ?? $column['title'];
                $taskLimit     = isset($args['task_limit'])      ? (int) $args['task_limit']      : (int) $column['task_limit'];
                $description   = $args['description'] ?? $column['description'];
                $hideInDash    = isset($args['hide_in_dashboard']) ? (int) $args['hide_in_dashboard'] : (int) ($column['hide_in_dashboard'] ?? 0);

                $ok = $this->container['columnModel']->update($columnId, $title, $taskLimit, $description, $hideInDash);
                return $this->createSuccessResponse(['success' => $ok], $id);

            // ----------------------------------------------------------------
            case 'delete_column':
                $columnId = $this->requirePositiveInt($args, 'column_id', $id);
                if ($columnId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: column_id must be a positive integer', $id);
                }

                $column = $this->access->authorizeColumn($columnId);
                if ($column === null) {
                    return $this->forbiddenResponse($id);
                }

                if (!$this->access->canManageBoard((int) $column['project_id'])) {
                    return $this->forbiddenResponse($id);
                }

                $ok = $this->container['columnModel']->remove($columnId);
                return $this->createSuccessResponse(['success' => $ok], $id);

            // ----------------------------------------------------------------
            case 'reorder_columns':
                return $this->handleReorderColumns($args, $id);

            // ----------------------------------------------------------------
            // Category management (project manager only)
            // ----------------------------------------------------------------
            case 'create_category':
                $projectId = $this->requirePositiveInt($args, 'project_id', $id);
                if ($projectId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                }

                if (!isset($args['name']) || !is_string($args['name']) || trim($args['name']) === '') {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: name must be a non-empty string', $id);
                }

                if (!$this->access->canManageBoard($projectId)) {
                    return $this->forbiddenResponse($id);
                }

                $categoryId = $this->container['categoryModel']->create([
                    'project_id'  => $projectId,
                    'name'        => trim($args['name']),
                    'description' => $args['description'] ?? '',
                ]);
                return $this->createSuccessResponse(['category_id' => $categoryId], $id);

            // ----------------------------------------------------------------
            case 'update_category':
                $categoryId = $this->requirePositiveInt($args, 'category_id', $id);
                if ($categoryId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: category_id must be a positive integer', $id);
                }

                $category = $this->access->authorizeCategory($categoryId);
                if ($category === null) {
                    return $this->forbiddenResponse($id);
                }

                if (!$this->access->canManageBoard((int) $category['project_id'])) {
                    return $this->forbiddenResponse($id);
                }

                $updateData = ['id' => $categoryId];
                if (isset($args['name']))        $updateData['name']        = $args['name'];
                if (isset($args['description'])) $updateData['description'] = $args['description'];

                $ok = $this->container['categoryModel']->update($updateData);
                return $this->createSuccessResponse(['success' => $ok], $id);

            // ----------------------------------------------------------------
            case 'delete_category':
                $categoryId = $this->requirePositiveInt($args, 'category_id', $id);
                if ($categoryId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: category_id must be a positive integer', $id);
                }

                $category = $this->access->authorizeCategory($categoryId);
                if ($category === null) {
                    return $this->forbiddenResponse($id);
                }

                if (!$this->access->canManageBoard((int) $category['project_id'])) {
                    return $this->forbiddenResponse($id);
                }

                $ok = $this->container['categoryModel']->remove($categoryId);
                return $this->createSuccessResponse(['success' => $ok], $id);

            // ----------------------------------------------------------------
            case 'get_categories':
                $projectId = $this->requirePositiveInt($args, 'project_id', $id);
                if ($projectId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                }

                if (!$this->access->canSeeProject($projectId)) {
                    return $this->forbiddenResponse($id);
                }

                $categories = $this->container['categoryModel']->getAll($projectId);
                return $this->createSuccessResponse(array_values($categories), $id);

            // ----------------------------------------------------------------
            // Swimlane management (project manager only)
            // ----------------------------------------------------------------
            case 'create_swimlane':
                $projectId = $this->requirePositiveInt($args, 'project_id', $id);
                if ($projectId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                }

                if (!isset($args['name']) || !is_string($args['name']) || trim($args['name']) === '') {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: name must be a non-empty string', $id);
                }

                if (!$this->access->canManageBoard($projectId)) {
                    return $this->forbiddenResponse($id);
                }

                $swimlaneId = $this->container['swimlaneModel']->create(
                    $projectId,
                    trim($args['name']),
                    $args['description'] ?? ''
                );
                return $this->createSuccessResponse(['swimlane_id' => $swimlaneId], $id);

            // ----------------------------------------------------------------
            case 'update_swimlane':
                $swimlaneId = $this->requirePositiveInt($args, 'swimlane_id', $id);
                if ($swimlaneId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: swimlane_id must be a positive integer', $id);
                }

                $swimlane = $this->access->authorizeSwimlane($swimlaneId);
                if ($swimlane === null) {
                    return $this->forbiddenResponse($id);
                }

                if (!$this->access->canManageBoard((int) $swimlane['project_id'])) {
                    return $this->forbiddenResponse($id);
                }

                $updateData = [];
                if (isset($args['name']))        $updateData['name']        = $args['name'];
                if (isset($args['description'])) $updateData['description'] = $args['description'];

                $ok = $this->container['swimlaneModel']->update($swimlaneId, $updateData);
                return $this->createSuccessResponse(['success' => $ok], $id);

            // ----------------------------------------------------------------
            case 'delete_swimlane':
                $projectId  = $this->requirePositiveInt($args, 'project_id',  $id);
                $swimlaneId = $this->requirePositiveInt($args, 'swimlane_id', $id);

                if ($projectId === null || $swimlaneId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: project_id and swimlane_id must be positive integers', $id);
                }

                $swimlane = $this->access->authorizeSwimlane($swimlaneId);
                if ($swimlane === null || (int) $swimlane['project_id'] !== $projectId) {
                    return $this->forbiddenResponse($id);
                }

                if (!$this->access->canManageBoard($projectId)) {
                    return $this->forbiddenResponse($id);
                }

                $ok = $this->container['swimlaneModel']->remove($projectId, $swimlaneId);
                return $this->createSuccessResponse(['success' => $ok], $id);

            // ----------------------------------------------------------------
            case 'get_swimlanes':
                $projectId = $this->requirePositiveInt($args, 'project_id', $id);
                if ($projectId === null) {
                    return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                }

                if (!$this->access->canSeeProject($projectId)) {
                    return $this->forbiddenResponse($id);
                }

                $swimlanes = $this->container['swimlaneModel']->getAll($projectId);
                return $this->createSuccessResponse(array_values($swimlanes), $id);

            // ----------------------------------------------------------------
            default:
                return $this->errorResponse(-32602, 'Unknown tool: ' . $toolName, $id);
        }
    }

    // -----------------------------------------------------------------------
    // Resources
    // -----------------------------------------------------------------------

    private function listResources(array $params, int|string|null $id): array
    {
        $cursor = $params['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) {
            return $this->errorResponse(-32602, 'Invalid params: cursor must be a string', $id);
        }

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'resources' => [
                    [
                        'uri'         => 'kanboard://projects',
                        'name'        => 'Project List',
                        'description' => 'List of projects visible to you',
                        'mimeType'    => 'application/json',
                    ],
                    [
                        'uri'         => 'kanboard://users',
                        'name'        => 'User List',
                        'description' => 'List of users you can interact with',
                        'mimeType'    => 'application/json',
                    ],
                ],
            ],
        ];
    }

    private function listResourceTemplates(array $params, int|string|null $id): array
    {
        $cursor = $params['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) {
            return $this->errorResponse(-32602, 'Invalid params: cursor must be a string', $id);
        }

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => ['resourceTemplates' => []],
        ];
    }

    private function readResource(array $params, int|string|null $id): array
    {
        $uri = $params['uri'] ?? '';

        try {
            switch ($uri) {
                case 'kanboard://projects':
                    $ids  = $this->access->getVisibleProjectIds();
                    $rows = [];
                    foreach ($ids as $pid) {
                        $p = $this->container['projectModel']->getById($pid);
                        if (!empty($p)) {
                            $rows[] = $p;
                        }
                    }
                    $content = json_encode(array_values($rows), JSON_PRETTY_PRINT);
                    break;

                case 'kanboard://users':
                    $content = json_encode($this->getSafeUsers(), JSON_PRETTY_PRINT);
                    break;

                default:
                    return $this->errorResponse(-32002, 'Resource not found', $id, ['uri' => $uri]);
            }

            return [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => [
                    'contents' => [
                        ['uri' => $uri, 'mimeType' => 'application/json', 'text' => $content],
                    ],
                ],
            ];
        } catch (Throwable $exception) {
            $this->logThrowable('Resource read failed', $exception);
            return $this->errorResponse(-32603, 'Resource read failed', $id);
        }
    }

    // -----------------------------------------------------------------------
    // User list helper
    // -----------------------------------------------------------------------

    /**
     * Return a safe subset of user data: id, username, name only.
     *
     * App-admin: all active users.
     * Others: distinct members of visible projects.
     *
     * @return array<int,array{id:int,username:string,name:string}>
     */
    private function getSafeUsers(): array
    {
        $safe = [];

        if ($this->access->isAppAdmin()) {
            $rows = $this->container['userModel']
                ->getQuery()
                ->eq('is_active', 1)
                ->columns('id', 'username', 'name')
                ->findAll();
            foreach ($rows as $row) {
                $safe[(int) $row['id']] = [
                    'id'       => (int)    $row['id'],
                    'username' => (string) $row['username'],
                    'name'     => (string) ($row['name'] ?? ''),
                ];
            }
        } else {
            foreach ($this->access->getVisibleProjectIds() as $pid) {
                $members = $this->container['projectUserRoleModel']->getAllUsers($pid);
                foreach ($members as $uid => $displayName) {
                    if (isset($safe[$uid])) {
                        continue;
                    }
                    $user = $this->container['userModel']->getById($uid);
                    if (!empty($user) && $this->container['userModel']->isActive($uid)) {
                        $safe[$uid] = [
                            'id'       => (int)    $user['id'],
                            'username' => (string) $user['username'],
                            'name'     => (string) ($user['name'] ?? ''),
                        ];
                    }
                }
            }
        }

        return array_values($safe);
    }

    // -----------------------------------------------------------------------
    // Misc protocol helpers
    // -----------------------------------------------------------------------

    private function listOfferings(int|string|null $id): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['offerings' => []]];
    }

    private function ping(int|string|null $id): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) []];
    }

    // -----------------------------------------------------------------------
    // Column reorder (extracted, with pre-auth gate)
    // -----------------------------------------------------------------------

    private function handleReorderColumns(array $arguments, int|string|null $id): array
    {
        try {
            [$projectId, $columnIds] = $this->sanitizeColumnReorderArguments($arguments);
        } catch (InvalidArgumentException $exception) {
            return $this->createAndLogFailure(
                'Invalid params for reorder_columns',
                $id,
                $exception->getMessage(),
                ['arguments' => $arguments]
            );
        }

        // Auth check before any state is revealed
        if (!$this->access->canManageBoard($projectId)) {
            return $this->forbiddenResponse($id);
        }

        /** @var ColumnModel $columnModel */
        $columnModel = $this->container['columnModel'];
        $currentIds  = $this->getColumnIdsSnapshot($columnModel, $projectId);

        if (($mismatchError = $this->checkColumnMismatch($columnIds, $currentIds)) !== null) {
            return $this->createAndLogFailure(
                'State mismatch for reorder_columns',
                $id,
                $mismatchError,
                ['project_id' => $projectId, 'requested' => $columnIds, 'current' => $currentIds]
            );
        }

        try {
            foreach ($columnIds as $index => $columnId) {
                $position = $index + 1;
                if ($columnModel->changePosition($projectId, $columnId, $position) !== true) {
                    throw new \RuntimeException("Kanboard core rejected position change for column {$columnId} to {$position}.");
                }
            }
        } catch (Throwable $exception) {
            return $this->createAndLogFailure(
                'Execution failed for reorder_columns',
                $id,
                'Column reorder failed during execution.',
                ['project_id' => $projectId, 'requested' => $columnIds],
                $exception
            );
        }

        return $this->createSuccessResponse(['success' => true], $id);
    }

    // -----------------------------------------------------------------------
    // Response builders
    // -----------------------------------------------------------------------

    private function forbiddenResponse(int|string|null $id): array
    {
        return $this->createToolExecutionErrorResponse('Forbidden', $id);
    }

    private function errorResponse(int $code, string $message, int|string|null $id, mixed $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    private function createToolExecutionErrorResponse(string $message, int|string|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'content' => [['type' => 'text', 'text' => $message]],
                'isError' => true,
            ],
        ];
    }

    private function createSuccessResponse(mixed $result, int|string|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'content' => [
                    ['type' => 'text', 'text' => json_encode($result, JSON_PRETTY_PRINT)],
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Input validation helpers
    // -----------------------------------------------------------------------

    /**
     * Return a positive integer from $args[$key], or null on failure.
     *
     * @param array<string,mixed> $args
     */
    private function requirePositiveInt(array $args, string $key, int|string|null $id): ?int
    {
        if (!array_key_exists($key, $args)) {
            return null;
        }
        return $this->filterPositiveInteger($args[$key]);
    }

    private function filterPositiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value)) {
            $filtered = filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $filtered !== false ? (int) $filtered : null;
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Column reorder helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $arguments
     * @return array{0:int,1:array<int,int>}
     */
    private function sanitizeColumnReorderArguments(array $arguments): array
    {
        if (!array_key_exists('project_id', $arguments)) {
            throw new InvalidArgumentException('Invalid params: project_id is required and must be a positive integer');
        }

        $projectId = $this->filterPositiveInteger($arguments['project_id']);
        if ($projectId === null) {
            throw new InvalidArgumentException('Invalid params: project_id must be a positive integer');
        }

        if (!array_key_exists('column_ids', $arguments)) {
            throw new InvalidArgumentException('Invalid params: column_ids must be a non-empty array of positive integers');
        }

        $columnIdArgument = $arguments['column_ids'];

        if (is_string($columnIdArgument)) {
            $columnIdArgument = array_values(array_filter(
                array_map('trim', explode(',', $columnIdArgument)),
                static fn(string $value): bool => $value !== ''
            ));
        } elseif ($columnIdArgument instanceof Traversable) {
            $columnIdArgument = iterator_to_array($columnIdArgument, false);
        }

        if (!is_array($columnIdArgument) || $columnIdArgument === []) {
            throw new InvalidArgumentException('Invalid params: column_ids must be a non-empty array of positive integers');
        }

        $columnIds = [];
        foreach (array_values($columnIdArgument) as $index => $rawColumnId) {
            $columnId = $this->filterPositiveInteger($rawColumnId);
            if ($columnId === null) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid params: column_ids[%d] must be a positive integer',
                    $index
                ));
            }
            $columnIds[] = $columnId;
        }

        if (count($columnIds) !== count(array_unique($columnIds))) {
            throw new InvalidArgumentException('Invalid params: column_ids must contain unique identifiers');
        }

        return [$projectId, $columnIds];
    }

    private function checkColumnMismatch(array $requested, array $current): ?string
    {
        if (count($requested) !== count($current)) {
            return 'Invalid params: number of columns does not match.';
        }

        $missing = array_values(array_diff($current, $requested));
        $unknown = array_values(array_diff($requested, $current));

        if (empty($missing) && empty($unknown)) {
            return null;
        }

        $details = [];
        if (!empty($missing)) {
            $details[] = 'missing ids [' . implode(', ', $missing) . ']';
        }
        if (!empty($unknown)) {
            $details[] = 'unknown ids [' . implode(', ', $unknown) . ']';
        }

        return 'Invalid params: column sets do not match (' . implode('; ', $details) . ').';
    }

    /**
     * @param ColumnModel|object $columnModel
     * @return array<int,int>
     */
    private function getColumnIdsSnapshot($columnModel, int $projectId): array
    {
        if (!is_object($columnModel) || !method_exists($columnModel, 'getAll')) {
            return [];
        }

        try {
            $columns = $columnModel->getAll($projectId);
        } catch (Throwable $exception) {
            $this->logThrowable(sprintf('Failed to fetch column snapshot for project %d', $projectId), $exception);
            return [];
        }

        if (!is_array($columns)) {
            return [];
        }

        $filtered = array_filter(
            $columns,
            static fn($column): bool => is_array($column) && isset($column['id']) && (int) $column['id'] > 0
        );

        return array_values(array_map(
            static fn(array $column): int => (int) $column['id'],
            $filtered
        ));
    }

    // -----------------------------------------------------------------------
    // Logging helpers
    // -----------------------------------------------------------------------

    private function createAndLogFailure(
        string $logMessage,
        int|string|null $id,
        string $responseMessage,
        array $context,
        ?Throwable $exception = null
    ): array {
        $logContext = ['context' => $context, 'mcp_channel' => 'tool_failure'];
        if ($exception !== null) {
            $logContext['exception'] = $exception;
        }
        $this->logError($logMessage, $logContext);
        return $this->createToolExecutionErrorResponse($responseMessage, $id);
    }

    private function logError(string $message, array $context = []): void
    {
        if (isset($this->container['logger'])) {
            $this->container['logger']->error($message, $context);
        }
    }

    private function logThrowable(string $message, Throwable $throwable): void
    {
        if (isset($this->container['logger'])) {
            $this->container['logger']->error($message, ['exception' => $throwable]);
        }
    }
}
