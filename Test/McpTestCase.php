<?php

use Kanboard\Core\Plugin\SchemaHandler;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;
use Kanboard\Plugin\ModelContextProtocol\Model\McpTokenModel;

abstract class McpTestCase extends Base
{
    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $loader = new Composer\Autoload\ClassLoader();
        $loader->addPsr4(
            'Kanboard\\Plugin\\ModelContextProtocol\\',
            dirname(__DIR__) . DIRECTORY_SEPARATOR
        );
        $loader->register(true);

        $schema = new SchemaHandler($this->container);
        $schema->loadSchema('ModelContextProtocol');

        $this->container['mcpTokenModel'] = function ($c) {
            return new McpTokenModel($c);
        };
    }

    protected function createUser(string $username, string $role = Role::APP_USER, string $name = ''): int
    {
        $values = [
            'username' => $username,
            'password' => 'secret',
            'role' => $role,
        ];
        if ($name !== '') {
            $values['name'] = $name;
        }

        $id = $this->container['userModel']->create($values);
        $this->assertNotFalse($id);

        return (int) $id;
    }

    protected function adminId(): int
    {
        return (int) $this->container['userModel']->getIdByUsername('admin');
    }

    protected function login(int $userId): void
    {
        $user = $this->container['userModel']->getById($userId);
        $this->assertNotEmpty($user);
        $this->container['userSession']->initialize($user);
    }

    protected function createProject(string $name): int
    {
        $id = $this->container['projectModel']->create(['name' => $name], 0, false);
        $this->assertNotFalse($id);
        $this->assertGreaterThan(0, (int) $id);

        return (int) $id;
    }

    protected function addMember(int $projectId, int $userId, string $role): void
    {
        $this->assertTrue(
            $this->container['projectUserRoleModel']->addUser($projectId, $userId, $role)
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    protected function callTool(int $userId, string $name, array $arguments = [], $id = 1): array
    {
        return $this->mcpRequest($userId, 'tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ], $id);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    protected function mcpRequest(int $userId, string $method, array $params = [], $id = 1): array
    {
        $server = new McpServer($this->container, $userId);
        $response = $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);
        $this->assertIsArray($response);

        return $response;
    }

    /**
     * @param array<string,mixed> $response
     */
    protected function assertForbiddenTool(array $response): void
    {
        $this->assertArrayNotHasKey('error', $response);
        $this->assertTrue($response['result']['isError']);
        $this->assertSame('Forbidden', $response['result']['content'][0]['text']);
    }

    /**
     * @param array<string,mixed> $response
     */
    protected function assertUnknownTool(array $response, string $name): void
    {
        $this->assertArrayNotHasKey('result', $response);
        $this->assertSame(-32602, $response['error']['code']);
        $this->assertSame('Unknown tool: ' . $name, $response['error']['message']);
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    protected function decodeToolResult(array $response): array
    {
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayNotHasKey('error', $response);
        $this->assertArrayNotHasKey('isError', $response['result']);
        $payload = json_decode($response['result']['content'][0]['text'], true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @return string[]
     */
    protected function toolNamesFrom(array $response): array
    {
        $this->assertArrayHasKey('result', $response);
        $names = [];
        foreach ($response['result']['tools'] as $tool) {
            $names[] = $tool['name'];
        }

        return $names;
    }
}
