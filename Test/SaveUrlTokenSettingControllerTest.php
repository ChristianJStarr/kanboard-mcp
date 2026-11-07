<?php

require_once __DIR__ . '/McpTestCase.php';
require_once __DIR__ . '/RecordingHttpResponse.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Plugin\ModelContextProtocol\Controller\ModelContextProtocolController;

class SaveUrlTokenSettingControllerTest extends McpTestCase
{
    public function testGetIs405(): void
    {
        $this->login($this->adminId());
        $response = $this->bindHttp([], [], 'GET');

        $controller = new ModelContextProtocolController($this->container);
        $controller->saveUrlTokenSetting();

        $this->assertSame(405, $response->status);
        $this->assertNull($response->redirectUrl);
        $this->assertFalse($this->container['mcpTokenModel']->urlTokensDisabled());
    }

    public function testPostWithoutReusableCsrfThrowsAccessForbidden(): void
    {
        $this->login($this->adminId());
        $this->bindHttp(['mcp_disable_url_token' => '1', 'target' => 'config'], []);

        $controller = new ModelContextProtocolController($this->container);

        try {
            $controller->saveUrlTokenSetting();
            $this->fail('Expected AccessForbiddenException');
        } catch (AccessForbiddenException $exception) {
            $this->assertFalse($this->container['mcpTokenModel']->urlTokensDisabled());
        }
    }

    public function testNonAdminCannotSave(): void
    {
        $userId = $this->createUser('not-an-admin');
        $this->login($userId);
        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_disable_url_token' => '1',
            'target' => 'user',
        ], []);

        $controller = new ModelContextProtocolController($this->container);

        try {
            $controller->saveUrlTokenSetting();
            $this->fail('Expected AccessForbiddenException');
        } catch (AccessForbiddenException $exception) {
            $this->assertFalse($this->container['mcpTokenModel']->urlTokensDisabled());
        }
    }

    public function testAdminCanDisableAndReenableUrlTokens(): void
    {
        $this->login($this->adminId());
        $response = $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_disable_url_token' => '1',
            'target' => 'config',
        ], []);

        $controller = new ModelContextProtocolController($this->container);
        $controller->saveUrlTokenSetting();

        $this->assertTrue($this->container['mcpTokenModel']->urlTokensDisabled());
        $this->assertSame(
            $this->container['helper']->url->to('ConfigController', 'integrations'),
            $response->redirectUrl
        );
        $this->assertTrue($response->redirectSelf);
        $this->assertSame('MCP URL token setting saved', session_get('flash')['success']);

        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'target' => 'config',
        ], []);
        $controller->saveUrlTokenSetting();
        $this->assertFalse($this->container['mcpTokenModel']->urlTokensDisabled());
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
}
