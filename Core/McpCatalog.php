<?php
declare(strict_types=1);

namespace Kanboard\Plugin\ModelContextProtocol\Core;

/**
 * Allowlist of MCP tools and resources.
 *
 * This file is included by schema migrations. It must stay side-effect free:
 * constants and pure lookups only, no database, session, or I/O.
 */
final class McpCatalog
{
    public const KIND_TOOL = 'tool';
    public const KIND_RESOURCE = 'resource';

    public const CAP_READ = 'read';
    public const CAP_TASK_WRITE = 'task_write';
    public const CAP_TASK_DELETE = 'task_delete';
    public const CAP_BOARD = 'board';
    public const CAP_CREATE_PROJECT = 'create_project';

    public const GROUP_READ = 'Read';
    public const GROUP_TASK = 'Task changes';
    public const GROUP_BOARD = 'Board setup';
    public const GROUP_PROJECTS = 'Projects';

    /**
     * @var array<int,array{name:string,kind:string,capability:string,defaultEnabled:bool,group:string}>
     */
    public const ENDPOINTS = [
        ['name' => 'get_projects', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_READ, 'defaultEnabled' => true, 'group' => self::GROUP_READ],
        ['name' => 'get_tasks', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_READ, 'defaultEnabled' => true, 'group' => self::GROUP_READ],
        ['name' => 'get_columns', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_READ, 'defaultEnabled' => true, 'group' => self::GROUP_READ],
        ['name' => 'get_categories', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_READ, 'defaultEnabled' => true, 'group' => self::GROUP_READ],
        ['name' => 'get_swimlanes', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_READ, 'defaultEnabled' => true, 'group' => self::GROUP_READ],
        ['name' => 'get_task_details', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_READ, 'defaultEnabled' => true, 'group' => self::GROUP_READ],
        ['name' => 'get_task_comments', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_READ, 'defaultEnabled' => true, 'group' => self::GROUP_READ],
        ['name' => 'get_users', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_READ, 'defaultEnabled' => true, 'group' => self::GROUP_READ],
        ['name' => 'kanboard://projects', 'kind' => self::KIND_RESOURCE, 'capability' => self::CAP_READ, 'defaultEnabled' => true, 'group' => self::GROUP_READ],
        ['name' => 'kanboard://users', 'kind' => self::KIND_RESOURCE, 'capability' => self::CAP_READ, 'defaultEnabled' => true, 'group' => self::GROUP_READ],

        ['name' => 'create_task', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_TASK_WRITE, 'defaultEnabled' => false, 'group' => self::GROUP_TASK],
        ['name' => 'update_task', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_TASK_WRITE, 'defaultEnabled' => false, 'group' => self::GROUP_TASK],
        ['name' => 'move_task', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_TASK_WRITE, 'defaultEnabled' => false, 'group' => self::GROUP_TASK],
        ['name' => 'assign_task', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_TASK_WRITE, 'defaultEnabled' => false, 'group' => self::GROUP_TASK],
        ['name' => 'set_task_due_date', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_TASK_WRITE, 'defaultEnabled' => false, 'group' => self::GROUP_TASK],
        ['name' => 'add_task_comment', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_TASK_WRITE, 'defaultEnabled' => false, 'group' => self::GROUP_TASK],
        ['name' => 'delete_task', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_TASK_DELETE, 'defaultEnabled' => false, 'group' => self::GROUP_TASK],

        ['name' => 'create_column', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_BOARD, 'defaultEnabled' => false, 'group' => self::GROUP_BOARD],
        ['name' => 'update_column', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_BOARD, 'defaultEnabled' => false, 'group' => self::GROUP_BOARD],
        ['name' => 'delete_column', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_BOARD, 'defaultEnabled' => false, 'group' => self::GROUP_BOARD],
        ['name' => 'reorder_columns', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_BOARD, 'defaultEnabled' => false, 'group' => self::GROUP_BOARD],
        ['name' => 'create_category', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_BOARD, 'defaultEnabled' => false, 'group' => self::GROUP_BOARD],
        ['name' => 'update_category', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_BOARD, 'defaultEnabled' => false, 'group' => self::GROUP_BOARD],
        ['name' => 'delete_category', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_BOARD, 'defaultEnabled' => false, 'group' => self::GROUP_BOARD],
        ['name' => 'create_swimlane', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_BOARD, 'defaultEnabled' => false, 'group' => self::GROUP_BOARD],
        ['name' => 'update_swimlane', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_BOARD, 'defaultEnabled' => false, 'group' => self::GROUP_BOARD],
        ['name' => 'delete_swimlane', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_BOARD, 'defaultEnabled' => false, 'group' => self::GROUP_BOARD],

        ['name' => 'create_project', 'kind' => self::KIND_TOOL, 'capability' => self::CAP_CREATE_PROJECT, 'defaultEnabled' => false, 'group' => self::GROUP_PROJECTS],
    ];

    public static function isKnown(string $name): bool
    {
        return self::find($name) !== null;
    }

    /**
     * @return array{name:string,kind:string,capability:string,defaultEnabled:bool,group:string}|null
     */
    public static function find(string $name): ?array
    {
        foreach (self::ENDPOINTS as $endpoint) {
            if ($endpoint['name'] === $name) {
                return $endpoint;
            }
        }

        return null;
    }
}
