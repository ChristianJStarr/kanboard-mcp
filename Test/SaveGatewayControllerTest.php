<?php

require_once __DIR__ . '/McpTestCase.php';
require_once __DIR__ . '/RecordingHttpResponse.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\ModelContextProtocol\Controller\ModelContextProtocolController;
use Kanboard\Plugin\ModelContextProtocol\Model\McpEndpointGatewayModel;

class SaveGatewayControllerTest extends McpTestCase
{
    public function testGetIs405AndWritesNothing(): void
    {
        $userId = $this->createUser('gateway-get');
        $this->login($userId);
        $response = $this->bindHttp([
            'csrf_token' => 'ignored',
            'gateway_save' => '1',
            'endpoints' => ['get_projects'],
        ], [], 'GET');

        $controller = new ModelContextProtocolController($this->container);
        $controller->saveGateway();

        $this->assertSame(405, $response->status);
        $this->assertNull($response->redirectUrl);
        $this->assertSame([], $this->grantRows($userId));
        $this->assertArrayNotHasKey('success', $this->flash());
    }

    public function testMissingCsrfWritesNothing(): void
    {
        $userId = $this->createUser('gateway-csrf');
        $this->login($userId);
        $this->bindHttp([
            'gateway_save' => '1',
            'target' => 'config',
            'endpoints' => ['get_projects'],
        ], []);

        $controller = new ModelContextProtocolController($this->container);

        try {
            $controller->saveGateway();
            $this->fail('Expected AccessForbiddenException');
        } catch (AccessForbiddenException $exception) {
            $this->assertSame([], $this->grantRows($userId));
            $this->assertArrayNotHasKey('success', $this->flash());
        }
    }

    public function testOneTimeCsrfTokenIsRejected(): void
    {
        $userId = $this->createUser('gateway-onetime');
        $this->login($userId);
        $this->bindHttp([
            'csrf_token' => $this->container['token']->getCSRFToken(),
            'gateway_save' => '1',
            'target' => 'config',
            'endpoints' => ['get_projects'],
        ], []);

        $controller = new ModelContextProtocolController($this->container);

        try {
            $controller->saveGateway();
            $this->fail('Expected AccessForbiddenException');
        } catch (AccessForbiddenException $exception) {
            $this->assertSame([], $this->grantRows($userId));
        }
    }

    public function testMissingGatewaySaveWritesNothing(): void
    {
        $userId = $this->createUser('gateway-sentinel');
        $this->login($userId);
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $response = $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'target' => 'config',
            'endpoints' => ['get_projects'],
        ], []);

        $controller = new ModelContextProtocolController($this->container);
        $controller->saveGateway();

        $this->assertSame([], $this->grantRows($userId));
        $this->assertArrayNotHasKey('success', $this->flash());
        $this->assertArrayNotHasKey('failure', $this->flash());
        $this->assertSame($plain, $this->container['mcpTokenModel']->getTokenForUser($userId));
        $this->assertSame($this->integrationsUrl('config', $userId), $response->redirectUrl);
    }

    public function testForeignUserIdWritesNothing(): void
    {
        $sessionUser = $this->createUser('gateway-session');
        $otherUser = $this->createUser('gateway-other');
        $this->insertGrant($otherUser, 'get_projects', 1);
        $this->insertGrant($otherUser, 'create_task', 0);
        $before = $this->grantRows($otherUser);
        $this->login($sessionUser);

        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'gateway_save' => '1',
            'target' => 'user',
            'user_id' => (string) $otherUser,
            'endpoints' => ['get_projects'],
        ], []);

        try {
            (new ModelContextProtocolController($this->container))->saveGateway();
            $this->fail('Expected AccessForbiddenException for a posted user id');
        } catch (AccessForbiddenException $exception) {
            $this->assertSame([], $this->grantRows($sessionUser));
            $this->assertSame($before, $this->grantRows($otherUser));
        }

        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'gateway_save' => '1',
            'endpoints' => ['get_projects'],
        ], [
            'user_id' => (string) $otherUser,
        ]);

        try {
            (new ModelContextProtocolController($this->container))->saveGateway();
            $this->fail('Expected AccessForbiddenException for a query user id');
        } catch (AccessForbiddenException $exception) {
            $this->assertSame([], $this->grantRows($sessionUser));
            $this->assertSame($before, $this->grantRows($otherUser));
        }

        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'gateway_save' => '1',
            'user_id' => [(string) $sessionUser],
            'endpoints' => ['get_projects'],
        ], []);

        try {
            (new ModelContextProtocolController($this->container))->saveGateway();
            $this->fail('Expected AccessForbiddenException for a non-scalar user id');
        } catch (AccessForbiddenException $exception) {
            $this->assertSame([], $this->grantRows($sessionUser));
            $this->assertSame($before, $this->grantRows($otherUser));
            $this->assertArrayNotHasKey('success', $this->flash());
        }
    }

    public function testUnknownNameWritesNothing(): void
    {
        $userId = $this->createUser('gateway-unknown');
        $this->login($userId);
        $response = $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'gateway_save' => '1',
            'target' => 'config',
            'endpoints' => ['get_projects', 'not_a_tool'],
        ], []);

        (new ModelContextProtocolController($this->container))->saveGateway();

        $this->assertSame([], $this->grantRows($userId));
        $this->assertSame('Could not save MCP access', $this->flash()['failure']);
        $this->assertStringNotContainsString('not_a_tool', $this->flash()['failure']);
        $this->assertArrayNotHasKey('success', $this->flash());
        $this->assertSame($this->integrationsUrl('config', $userId), $response->redirectUrl);
    }

    public function testViewerCannotEnableCreateProjectOrCreateColumn(): void
    {
        $projectId = $this->createProject('Viewer gateway');
        $viewerId = $this->createUser('gateway-viewer');
        $this->addMember($projectId, $viewerId, Role::PROJECT_VIEWER);
        $this->login($viewerId);

        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'gateway_save' => '1',
            'target' => 'user',
            'endpoints' => ['get_projects', 'create_project', 'create_column'],
        ], []);

        (new ModelContextProtocolController($this->container))->saveGateway();

        $this->assertSame([], $this->grantRows($viewerId));
        $this->assertSame('Could not save MCP access', $this->flash()['failure']);
        $this->assertStringNotContainsString('create_project', $this->flash()['failure']);
        $this->assertStringNotContainsString('create_column', $this->flash()['failure']);
        $this->assertArrayNotHasKey('success', $this->flash());
    }

    public function testSessionToggleDoesNotTouchAnotherUser(): void
    {
        $sessionUser = $this->createUser('gateway-self');
        $otherUser = $this->createUser('gateway-neighbor');
        $this->insertGrant($otherUser, 'get_projects', 0);
        $this->insertGrant($otherUser, 'get_tasks', 1);
        $before = $this->grantRows($otherUser);
        $this->login($sessionUser);

        $allowed = $this->gateway()->allowedNames($this->accessFor($sessionUser));
        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'gateway_save' => '1',
            'target' => 'config',
            'endpoints' => ['get_projects'],
        ], []);

        (new ModelContextProtocolController($this->container))->saveGateway();

        $this->assertSame($before, $this->grantRows($otherUser));
        $stored = $this->enabledMap($sessionUser);
        $this->assertSame(array_values($allowed), array_keys($this->rowsInAllowedOrder($sessionUser, $allowed)));
        $this->assertSame(1, $stored['get_projects']);
        $this->assertSame(0, $stored['get_tasks']);
        $this->assertArrayNotHasKey('create_project', $stored);
        $this->assertSame('MCP access saved', $this->flash()['success']);
    }

    public function testSuccessfulPostPersistsOnlyAllowedNames(): void
    {
        $userId = $this->createUser('gateway-success');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->login($userId);
        $allowed = $this->gateway()->allowedNames($this->accessFor($userId));
        $posted = array_values(array_filter(
            $allowed,
            static function (string $name): bool {
                return $name === 'get_projects' || $name === 'kanboard://users';
            }
        ));

        $response = $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'gateway_save' => '1',
            'target' => 'user',
            'endpoints' => $posted,
        ], []);

        (new ModelContextProtocolController($this->container))->saveGateway();

        $stored = $this->enabledMap($userId);
        $names = array_keys($stored);
        sort($names);
        $expected = $allowed;
        sort($expected);
        $this->assertSame($expected, $names);
        foreach ($allowed as $name) {
            $this->assertSame(in_array($name, $posted, true) ? 1 : 0, $stored[$name]);
        }
        $this->assertArrayNotHasKey('create_project', $stored);
        $this->assertArrayNotHasKey('create_column', $stored);
        $this->assertSame('MCP access saved', $this->flash()['success']);
        $this->assertSame($plain, $this->container['mcpTokenModel']->getTokenForUser($userId));
        $this->assertSame($this->integrationsUrl('user', $userId), $response->redirectUrl);
        $this->assertNotSame(
            $this->integrationsUrl('user', $userId + 1),
            $response->redirectUrl
        );
    }

    public function testUncheckingAReadStoresDisabledAndTheNextCallStaysDenied(): void
    {
        $projectId = $this->createProject('Unchecked read');
        $userId = $this->createUser('gateway-uncheck');
        $this->addMember($projectId, $userId, Role::PROJECT_VIEWER);
        $this->login($userId);

        $allowed = $this->gateway()->allowedNames($this->accessFor($userId));
        $posted = array_values(array_diff($allowed, ['get_tasks']));
        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'gateway_save' => '1',
            'target' => 'config',
            'endpoints' => $posted,
        ], []);

        (new ModelContextProtocolController($this->container))->saveGateway();

        $row = $this->container['db']->table(McpEndpointGatewayModel::TABLE)
            ->eq('user_id', $userId)
            ->eq('endpoint', 'get_tasks')
            ->findOne();
        $this->assertSame(0, (int) $row['enabled']);
        $this->assertForbiddenTool($this->callTool($userId, 'get_tasks', ['project_id' => $projectId]));
        $this->assertForbiddenTool($this->callTool($userId, 'get_tasks', ['project_id' => $projectId]));
        $this->decodeToolResult($this->callTool($userId, 'get_projects'));
    }

    public function testMissingEndpointsDisablesEveryAllowedEndpoint(): void
    {
        $userId = $this->createUser('gateway-none');
        $this->login($userId);
        $this->enableEndpoints($userId, ['get_projects', 'get_tasks']);
        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'gateway_save' => '1',
            'target' => 'config',
        ], []);

        (new ModelContextProtocolController($this->container))->saveGateway();

        $stored = $this->enabledMap($userId);
        $this->assertSame(0, $stored['get_projects']);
        $this->assertSame(0, $stored['get_tasks']);
        $this->assertForbiddenTool($this->callTool($userId, 'get_projects'));
        $this->assertSame('MCP access saved', $this->flash()['success']);
    }

    public function testEndpointsThatAreNotAFlatListWriteNothing(): void
    {
        $userId = $this->createUser('gateway-shape');
        $this->login($userId);
        $shapes = [
            'get_projects',
            ['get_projects' => 'get_projects'],
            [['get_projects']],
            ['get_projects', 1],
        ];

        foreach ($shapes as $endpoints) {
            $this->bindHttp([
                'csrf_token' => $this->container['token']->getReusableCSRFToken(),
                'gateway_save' => '1',
                'target' => 'config',
                'endpoints' => $endpoints,
            ], []);

            (new ModelContextProtocolController($this->container))->saveGateway();

            $this->assertSame([], $this->grantRows($userId));
            $this->assertSame('Could not save MCP access', $this->flash()['failure']);
            $this->assertArrayNotHasKey('success', $this->flash());
        }
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $get
     */
    private function bindHttp(array $post, array $get, string $method = 'POST'): RecordingHttpResponse
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = $post;
        $_GET = $get;
        $response = new RecordingHttpResponse();
        $this->container['response'] = $response;
        $this->container['request'] = new Request($this->container, $_SERVER, $get, $post);

        return $response;
    }

    private function integrationsUrl(string $target, int $userId): string
    {
        if ($target === 'user') {
            return $this->container['helper']->url->to('UserViewController', 'integrations', [
                'plugin' => 'ModelContextProtocol',
                'user_id' => $userId,
            ]);
        }

        return $this->container['helper']->url->to('ConfigController', 'integrations', [
            'plugin' => 'ModelContextProtocol',
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function flash(): array
    {
        if (!session_exists('flash')) {
            return [];
        }

        $flash = session_get('flash');

        return is_array($flash) ? $flash : [];
    }

    /**
     * @return array<string,int>
     */
    private function enabledMap(int $userId): array
    {
        $map = [];
        foreach ($this->grantRows($userId) as $row) {
            $map[$row['endpoint']] = (int) $row['enabled'];
        }

        return $map;
    }

    /**
     * @param string[] $allowed
     * @return array<string,int>
     */
    private function rowsInAllowedOrder(int $userId, array $allowed): array
    {
        $stored = $this->enabledMap($userId);
        $ordered = [];
        foreach ($allowed as $name) {
            $this->assertArrayHasKey($name, $stored);
            $ordered[$name] = $stored[$name];
        }

        return $ordered;
    }
}
