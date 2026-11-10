<?php

namespace Kanboard\Plugin\ModelContextProtocol\Model;

use Kanboard\Core\Base;
use Kanboard\Plugin\ModelContextProtocol\Core\McpAccess;
use Kanboard\Plugin\ModelContextProtocol\Core\McpCatalog;
use Throwable;

/**
 * Per-user on/off switch for catalog endpoints.
 *
 * A missing row means the catalog default (read on, write off).
 * Off is a stored enabled=0. Rows are never deleted here: deleting a read
 * row would turn that read back on.
 */
class McpEndpointGatewayModel extends Base
{
    const TABLE = 'mcp_endpoint_grants';

    /**
     * True only when the endpoint is known and effectively enabled.
     *
     * A query failure is thrown. Callers must not treat that as "enabled"
     * and must not dispatch the tool.
     */
    public function isEnabled(int $userId, string $endpoint): bool
    {
        if (!McpCatalog::isKnown($endpoint)) {
            return false;
        }

        return $this->enabledMap($userId)[$endpoint];
    }

    /**
     * Effective on/off for every catalog endpoint, read with one query.
     *
     * A query failure is thrown. Callers must not treat that as "enabled".
     *
     * @return array<string,bool>
     */
    public function enabledMap(int $userId): array
    {
        try {
            $rows = $this->db->table(self::TABLE)
                ->eq('user_id', $userId)
                ->findAll();
        } catch (Throwable $exception) {
            throw new \RuntimeException('MCP endpoint grant lookup failed.', 0, $exception);
        }

        $stored = [];
        foreach ($rows as $row) {
            $stored[$row['endpoint']] = (int) $row['enabled'] === 1;
        }

        $map = [];
        foreach (McpCatalog::ENDPOINTS as $endpoint) {
            $map[$endpoint['name']] = $stored[$endpoint['name']] ?? $endpoint['defaultEnabled'];
        }

        return $map;
    }

    /**
     * Catalog names whose capability the access object allows.
     *
     * @return string[]
     */
    public function allowedNames(McpAccess $access): array
    {
        $names = [];
        foreach (McpCatalog::ENDPOINTS as $endpoint) {
            if ($access->allowsCapability($endpoint['capability'])) {
                $names[] = $endpoint['name'];
            }
        }

        return $names;
    }

    /**
     * Checkbox rows for the session user's own gateway form.
     *
     * @return array<int,array{name:string,group:string,enabled:bool}>
     */
    public function rowsForUser(int $userId): array
    {
        $access = new McpAccess($this->container, $userId);
        $allowed = array_flip($this->allowedNames($access));
        $enabled = $this->enabledMap($userId);
        $rows = [];

        foreach (McpCatalog::ENDPOINTS as $endpoint) {
            if (!isset($allowed[$endpoint['name']])) {
                continue;
            }

            $rows[] = [
                'name' => $endpoint['name'],
                'group' => $endpoint['group'],
                'enabled' => $enabled[$endpoint['name']],
            ];
        }

        return $rows;
    }

    /**
     * Validate the whole posted list, then upsert every allowed endpoint.
     *
     * Endpoints outside $allowedNames are left untouched. Nothing is deleted.
     *
     * @param string[] $allowedNames
     * @param array<int,mixed> $postedNames
     */
    public function save(int $userId, array $allowedNames, array $postedNames): void
    {
        $allowed = [];
        foreach ($allowedNames as $name) {
            if (!is_string($name) || !McpCatalog::isKnown($name)) {
                throw new \InvalidArgumentException('Could not save MCP access.');
            }
            $allowed[$name] = true;
        }

        foreach ($postedNames as $name) {
            if (!is_string($name) || !isset($allowed[$name])) {
                throw new \InvalidArgumentException('Could not save MCP access.');
            }
        }

        $posted = [];
        foreach ($postedNames as $name) {
            $posted[$name] = true;
        }

        $this->db->startTransaction();

        try {
            foreach (array_keys($allowed) as $name) {
                $this->upsert($userId, $name, isset($posted[$name]) ? 1 : 0);
            }
            $this->db->closeTransaction();
        } catch (Throwable $exception) {
            $this->db->cancelTransaction();
            if ($exception instanceof \RuntimeException) {
                throw $exception;
            }
            throw new \RuntimeException('MCP endpoint grant upsert failed.', 0, $exception);
        }
    }

    private function upsert(int $userId, string $endpoint, int $enabled): void
    {
        $exists = $this->db->table(self::TABLE)
            ->eq('user_id', $userId)
            ->eq('endpoint', $endpoint)
            ->exists();

        if ($exists) {
            $ok = $this->db->table(self::TABLE)
                ->eq('user_id', $userId)
                ->eq('endpoint', $endpoint)
                ->update(['enabled' => $enabled]);
        } else {
            $ok = $this->db->table(self::TABLE)->insert([
                'user_id' => $userId,
                'endpoint' => $endpoint,
                'enabled' => $enabled,
            ]);
        }

        if ($ok !== true) {
            throw new \RuntimeException('MCP endpoint grant upsert failed.');
        }
    }
}
