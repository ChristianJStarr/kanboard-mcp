<?php

namespace Kanboard\Plugin\ModelContextProtocol\Core;

use Kanboard\Core\Base;
use Exception;

/**
 * MCP Server for Kanboard
 * Implements Model Context Protocol JSON-RPC 2.0 specification
 * Provides comprehensive project management tools for AI assistants
 */
class McpServer extends Base
{
    public function __construct($container)
    {
        $this->container = $container;
    }
    
    /**
     * Handle MCP JSON-RPC request
     */
    public function handleRequest($request)
    {
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            return $this->errorResponse(-32600, 'Invalid Request', isset($request['id']) ? $request['id'] : null);
        }
        
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;
        
        try {
            switch ($method) {
                case 'initialize':
                    return $this->initialize($params, $id);
                    
                case 'initialized':
                    return $this->initialized($id);
                    
                case 'tools/list':
                    return $this->listTools($id);
                    
                case 'tools/call':
                    return $this->callTool($params, $id);
                    
                case 'resources/list':
                    return $this->listResources($id);
                    
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
        } catch (Exception $e) {
            return $this->errorResponse(-32603, 'Internal error: ' . $e->getMessage(), $id);
        }
    }
    
    /**
     * Initialize MCP server
     */
    private function initialize($params, $id)
    {
        try {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => [
                        'tools' => (object)[],
                        'resources' => (object)[]
                    ],
                    'serverInfo' => [
                        'name' => 'Kanboard MCP Server',
                        'version' => '1.0.0'
                    ]
                ]
            ];
        } catch (Exception $e) {
            return $this->errorResponse(-32603, 'Initialize failed: ' . $e->getMessage(), $id);
        }
    }
    
    /**
     * Handle initialized notification
     */
    private function initialized($id)
    {
        // This is a notification, so we don't return a response
        return null;
    }
    
    /**
     * List available tools
     */
    private function listTools($id)
    {
        $tools = [
            [
                'name' => 'get_projects',
                'description' => 'Get all projects',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'create_project',
                'description' => 'Create a new project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Project name'],
                        'description' => ['type' => 'string', 'description' => 'Project description']
                    ],
                    'required' => ['name']
                ]
            ],
            [
                'name' => 'get_tasks',
                'description' => 'Get tasks from a project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ],
            [
                'name' => 'create_task',
                'description' => 'Create a new task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'title' => ['type' => 'string', 'description' => 'Task title'],
                        'description' => ['type' => 'string', 'description' => 'Task description'],
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID']
                    ],
                    'required' => ['project_id', 'title']
                ]
            ],
            [
                'name' => 'update_task',
                'description' => 'Update an existing task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'title' => ['type' => 'string', 'description' => 'Task title'],
                        'description' => ['type' => 'string', 'description' => 'Task description'],
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID']
                    ],
                    'required' => ['task_id']
                ]
            ],
            [
                'name' => 'get_columns',
                'description' => 'Get columns for a project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ],
            [
                'name' => 'move_task',
                'description' => 'Move a task to a different column',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'column_id' => ['type' => 'integer', 'description' => 'Target column ID']
                    ],
                    'required' => ['task_id', 'column_id']
                ]
            ],
            [
                'name' => 'get_task_details',
                'description' => 'Get detailed information about a specific task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID']
                    ],
                    'required' => ['task_id']
                ]
            ],
            [
                'name' => 'delete_task',
                'description' => 'Delete a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID']
                    ],
                    'required' => ['task_id']
                ]
            ],
            [
                'name' => 'assign_task',
                'description' => 'Assign a task to a user',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'user_id' => ['type' => 'integer', 'description' => 'User ID']
                    ],
                    'required' => ['task_id', 'user_id']
                ]
            ],
            [
                'name' => 'set_task_due_date',
                'description' => 'Set due date for a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'due_date' => ['type' => 'string', 'description' => 'Due date in YYYY-MM-DD format']
                    ],
                    'required' => ['task_id', 'due_date']
                ]
            ],
            [
                'name' => 'add_task_comment',
                'description' => 'Add a comment to a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'comment' => ['type' => 'string', 'description' => 'Comment text']
                    ],
                    'required' => ['task_id', 'comment']
                ]
            ],
            [
                'name' => 'get_users',
                'description' => 'Get all users in the system',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'get_task_comments',
                'description' => 'Get all comments for a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID']
                    ],
                    'required' => ['task_id']
                ]
            ],
            // Administrative Tools - Column Management
            [
                'name' => 'create_column',
                'description' => 'Add new columns to projects',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'title' => ['type' => 'string', 'description' => 'Column title'],
                        'task_limit' => ['type' => 'integer', 'description' => 'Task limit (0 for unlimited)'],
                        'description' => ['type' => 'string', 'description' => 'Column description']
                    ],
                    'required' => ['project_id', 'title']
                ]
            ],
            [
                'name' => 'update_column',
                'description' => 'Modify column settings',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID'],
                        'title' => ['type' => 'string', 'description' => 'Column title'],
                        'task_limit' => ['type' => 'integer', 'description' => 'Task limit (0 for unlimited)'],
                        'description' => ['type' => 'string', 'description' => 'Column description']
                    ],
                    'required' => ['column_id']
                ]
            ],
            [
                'name' => 'delete_column',
                'description' => 'Remove columns',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID']
                    ],
                    'required' => ['column_id']
                ]
            ],
            [
                'name' => 'reorder_columns',
                'description' => 'Change column positions',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'column_ids' => ['type' => 'array', 'description' => 'Array of column IDs in desired order']
                    ],
                    'required' => ['project_id', 'column_ids']
                ]
            ],
            // Administrative Tools - Category Management
            [
                'name' => 'create_category',
                'description' => 'Add task categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'name' => ['type' => 'string', 'description' => 'Category name'],
                        'description' => ['type' => 'string', 'description' => 'Category description']
                    ],
                    'required' => ['project_id', 'name']
                ]
            ],
            [
                'name' => 'update_category',
                'description' => 'Modify categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'Category ID'],
                        'name' => ['type' => 'string', 'description' => 'Category name'],
                        'description' => ['type' => 'string', 'description' => 'Category description']
                    ],
                    'required' => ['category_id']
                ]
            ],
            [
                'name' => 'delete_category',
                'description' => 'Remove categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'Category ID']
                    ],
                    'required' => ['category_id']
                ]
            ],
            [
                'name' => 'get_categories',
                'description' => 'List project categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ],
            // Administrative Tools - Swimlane Management
            [
                'name' => 'create_swimlane',
                'description' => 'Add swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'name' => ['type' => 'string', 'description' => 'Swimlane name'],
                        'description' => ['type' => 'string', 'description' => 'Swimlane description']
                    ],
                    'required' => ['project_id', 'name']
                ]
            ],
            [
                'name' => 'update_swimlane',
                'description' => 'Modify swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'swimlane_id' => ['type' => 'integer', 'description' => 'Swimlane ID'],
                        'name' => ['type' => 'string', 'description' => 'Swimlane name'],
                        'description' => ['type' => 'string', 'description' => 'Swimlane description']
                    ],
                    'required' => ['swimlane_id']
                ]
            ],
            [
                'name' => 'delete_swimlane',
                'description' => 'Remove swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'swimlane_id' => ['type' => 'integer', 'description' => 'Swimlane ID']
                    ],
                    'required' => ['swimlane_id']
                ]
            ],
            [
                'name' => 'get_swimlanes',
                'description' => 'List project swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ]
        ];
        
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => $tools
            ]
        ];
    }
    
    /**
     * Call a tool
     */
    private function callTool($params, $id)
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];
        
        try {
            $result = null;
            
            switch ($toolName) {
                case 'get_projects':
                    $projects = $this->container['projectModel']->getAll();
                    $result = array_values($projects);
                    break;
                    
                case 'create_project':
                    $projectId = $this->container['projectModel']->create([
                        'name' => $arguments['name'],
                        'description' => $arguments['description'] ?? ''
                    ]);
                    $result = ['project_id' => $projectId];
                    break;
                    
                case 'get_tasks':
                    $tasks = $this->container['taskModel']->getAll($arguments['project_id']);
                    $result = array_values($tasks);
                    break;
                    
                case 'create_task':
                    $taskData = [
                        'project_id' => $arguments['project_id'],
                        'title' => $arguments['title'],
                        'description' => $arguments['description'] ?? ''
                    ];
                    if (isset($arguments['column_id'])) {
                        $taskData['column_id'] = $arguments['column_id'];
                    }
                    $taskId = $this->container['taskCreationModel']->create($taskData);
                    $result = ['task_id' => $taskId];
                    break;
                    
                case 'update_task':
                    $taskData = ['id' => $arguments['task_id']];
                    if (isset($arguments['title'])) $taskData['title'] = $arguments['title'];
                    if (isset($arguments['description'])) $taskData['description'] = $arguments['description'];
                    if (isset($arguments['column_id'])) $taskData['column_id'] = $arguments['column_id'];
                    
                    $updateResult = $this->container['taskModificationModel']->update($taskData);
                    $result = ['success' => $updateResult];
                    break;
                    
                case 'get_columns':
                    $columns = $this->container['columnModel']->getAll($arguments['project_id']);
                    $result = array_values($columns);
                    break;
                    
                case 'move_task':
                    $moveResult = $this->container['taskPositionModel']->movePosition(
                        $arguments['project_id'] ?? null,
                        $arguments['task_id'],
                        $arguments['column_id'],
                        1
                    );
                    $result = ['success' => $moveResult];
                    break;
                    
                case 'get_task_details':
                    $task = $this->container['taskFinderModel']->getById($arguments['task_id']);
                    $result = $task;
                    break;
                    
                case 'delete_task':
                    $deleteResult = $this->container['taskModel']->remove($arguments['task_id']);
                    $result = ['success' => $deleteResult];
                    break;
                    
                case 'assign_task':
                    $assignResult = $this->container['taskModificationModel']->update([
                        'id' => $arguments['task_id'],
                        'owner_id' => $arguments['user_id']
                    ]);
                    $result = ['success' => $assignResult];
                    break;
                    
                case 'set_task_due_date':
                    $dueDateResult = $this->container['taskModificationModel']->update([
                        'id' => $arguments['task_id'],
                        'date_due' => $arguments['due_date']
                    ]);
                    $result = ['success' => $dueDateResult];
                    break;
                    
                case 'add_task_comment':
                    $commentId = $this->container['commentModel']->create([
                        'task_id' => $arguments['task_id'],
                        'comment' => $arguments['comment'],
                        'user_id' => $this->container['userSession']->getId()
                    ]);
                    $result = ['comment_id' => $commentId];
                    break;
                    
                case 'get_users':
                    $users = $this->container['userModel']->getAll();
                    $result = array_values($users);
                    break;
                    
                case 'get_task_comments':
                    $comments = $this->container['commentModel']->getAll($arguments['task_id']);
                    $result = array_values($comments);
                    break;
                    
                // Administrative Tools - Column Management
                case 'create_column':
                    $columnId = $this->container['columnModel']->create(
                        $arguments['project_id'],
                        $arguments['title'],
                        $arguments['task_limit'] ?? 0,
                        $arguments['description'] ?? ''
                    );
                    $result = ['column_id' => $columnId];
                    break;
                    
                case 'update_column':
                    $updateData = [];
                    if (isset($arguments['title'])) $updateData['title'] = $arguments['title'];
                    if (isset($arguments['task_limit'])) $updateData['task_limit'] = $arguments['task_limit'];
                    if (isset($arguments['description'])) $updateData['description'] = $arguments['description'];
                    $updateResult = $this->container['columnModel']->update($arguments['column_id'], $updateData);
                    $result = ['success' => $updateResult];
                    break;
                    
                case 'delete_column':
                    $deleteResult = $this->container['columnModel']->remove($arguments['column_id']);
                    $result = ['success' => $deleteResult];
                    break;
                    
                case 'reorder_columns':
                    $reorderResult = $this->container['columnModel']->changePosition($arguments['project_id'], $arguments['column_ids']);
                    $result = ['success' => $reorderResult];
                    break;
                    
                // Administrative Tools - Category Management
                case 'create_category':
                    $categoryId = $this->container['categoryModel']->create(
                        $arguments['project_id'],
                        $arguments['name'],
                        $arguments['description'] ?? ''
                    );
                    $result = ['category_id' => $categoryId];
                    break;
                    
                case 'update_category':
                    $updateData = ['id' => $arguments['category_id']];
                    if (isset($arguments['name'])) $updateData['name'] = $arguments['name'];
                    if (isset($arguments['description'])) $updateData['description'] = $arguments['description'];
                    $updateResult = $this->container['categoryModel']->update($updateData);
                    $result = ['success' => $updateResult];
                    break;
                    
                case 'delete_category':
                    $deleteResult = $this->container['categoryModel']->remove($arguments['category_id']);
                    $result = ['success' => $deleteResult];
                    break;
                    
                case 'get_categories':
                    $categories = $this->container['categoryModel']->getAll($arguments['project_id']);
                    $result = array_values($categories);
                    break;
                    
                // Administrative Tools - Swimlane Management
                case 'create_swimlane':
                    $swimlaneId = $this->container['swimlaneModel']->create(
                        $arguments['project_id'],
                        $arguments['name'],
                        $arguments['description'] ?? ''
                    );
                    $result = ['swimlane_id' => $swimlaneId];
                    break;
                    
                case 'update_swimlane':
                    $updateData = [];
                    if (isset($arguments['name'])) $updateData['name'] = $arguments['name'];
                    if (isset($arguments['description'])) $updateData['description'] = $arguments['description'];
                    $updateResult = $this->container['swimlaneModel']->update($arguments['swimlane_id'], $updateData);
                    $result = ['success' => $updateResult];
                    break;
                    
                case 'delete_swimlane':
                    $deleteResult = $this->container['swimlaneModel']->remove($arguments['swimlane_id']);
                    $result = ['success' => $deleteResult];
                    break;
                    
                case 'get_swimlanes':
                    $swimlanes = $this->container['swimlaneModel']->getAll($arguments['project_id']);
                    $result = array_values($swimlanes);
                    break;
                    
                default:
                    return $this->errorResponse(-32601, 'Tool not found: ' . $toolName, $id);
            }
            
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($result, JSON_PRETTY_PRINT)
                        ]
                    ]
                ]
            ];
            
        } catch (Exception $e) {
            return $this->errorResponse(-32603, 'Tool execution failed: ' . $e->getMessage(), $id);
        } catch (Error $e) {
            return $this->errorResponse(-32603, 'Tool execution fatal error: ' . $e->getMessage(), $id);
        }
    }
    
    /**
     * List available resources
     */
    private function listResources($id)
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'resources' => [
                    [
                        'uri' => 'kanboard://projects',
                        'name' => 'Project List',
                        'description' => 'List of all projects',
                        'mimeType' => 'application/json'
                    ],
                    [
                        'uri' => 'kanboard://users',
                        'name' => 'User List',
                        'description' => 'List of all users',
                        'mimeType' => 'application/json'
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Read a resource
     */
    private function readResource($params, $id)
    {
        $uri = $params['uri'] ?? '';
        
        try {
            switch ($uri) {
                case 'kanboard://projects':
                    $projects = $this->container['projectModel']->getAll();
                    $content = json_encode(array_values($projects), JSON_PRETTY_PRINT);
                    break;
                    
                case 'kanboard://users':
                    $users = $this->container['userModel']->getAll();
                    $content = json_encode(array_values($users), JSON_PRETTY_PRINT);
                    break;
                    
                default:
                    return $this->errorResponse(-32602, 'Resource not found: ' . $uri, $id);
            }
            
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'application/json',
                            'text' => $content
                        ]
                    ]
                ]
            ];
        } catch (Exception $e) {
            return $this->errorResponse(-32603, 'Resource read failed: ' . $e->getMessage(), $id);
        }
    }
    
    /**
     * List offerings (Cursor-specific)
     */
    private function listOfferings($id)
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'offerings' => []
            ]
        ];
    }
    
    /**
     * Handle ping
     */
    private function ping($id)
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'message' => 'pong'
            ]
        ];
    }
    
    /**
     * Get tools list for internal use
     */
    private function getToolsList()
    {
        // For internal use if needed
        return [];
    }
    
    /**
     * Get resources list for internal use
     */
    private function getResourcesList()
    {
        return [
            [
                'uri' => 'kanboard://projects',
                'name' => 'Project List',
                'description' => 'List of all projects',
                'mimeType' => 'application/json'
            ],
            [
                'uri' => 'kanboard://users',
                'name' => 'User List',
                'description' => 'List of all users',
                'mimeType' => 'application/json'
            ]
        ];
    }
    
    /**
     * Create error response
     */
    private function errorResponse($code, $message, $id)
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ];
    }
} 