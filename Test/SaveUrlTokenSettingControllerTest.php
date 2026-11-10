<?php

require_once __DIR__ . '/McpTestCase.php';
require_once __DIR__ . '/RecordingHttpResponse.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Plugin\ModelContextProtocol\Controller\ModelContextProtocolController;
use Kanboard\Plugin\ModelContextProtocol\Model\McpTokenModel;

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
        $adminId = $this->adminId();
        $plain = $this->container['mcpTokenModel']->regenerateForUser($adminId);
        $this->login($adminId);
        $response = $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_disable_url_token' => '1',
            'target' => 'config',
        ], []);

        $controller = new ModelContextProtocolController($this->container);
        $controller->saveUrlTokenSetting();

        $this->assertTrue($this->container['mcpTokenModel']->urlTokensDisabled());
        $this->assertStoredUrlTokenSetting('1');
        $this->assertSame(
            $this->container['helper']->url->to('ConfigController', 'integrations'),
            $response->redirectUrl
        );
        $this->assertTrue($response->redirectSelf);
        $this->assertSame('MCP URL token setting saved', session_get('flash')['success']);
        $this->assertSame($adminId, $this->container['mcpTokenModel']->authenticate($plain));

        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'target' => 'config',
        ], []);
        $controller->saveUrlTokenSetting();
        $this->assertFalse($this->container['mcpTokenModel']->urlTokensDisabled());
        $this->assertStoredUrlTokenSetting('0');
        $this->assertSame($adminId, $this->container['mcpTokenModel']->authenticate($plain));
        $this->assertNull($this->container['mcpTokenModel']->takeReveal($adminId));

        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_disable_url_token' => '0',
            'target' => 'config',
        ], []);
        $controller->saveUrlTokenSetting();
        $this->assertFalse($this->container['mcpTokenModel']->urlTokensDisabled());
        $this->assertStoredUrlTokenSetting('0');
        $this->assertSame($adminId, $this->container['mcpTokenModel']->authenticate($plain));
    }

    public function testAdminPostBothZeroAndOneDisablesUrlTokens(): void
    {
        $adminId = $this->adminId();
        $plain = $this->container['mcpTokenModel']->regenerateForUser($adminId);
        $this->login($adminId);
        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_disable_url_token' => ['0', '1'],
            'target' => 'config',
        ], []);

        $controller = new ModelContextProtocolController($this->container);
        $controller->saveUrlTokenSetting();

        $this->assertTrue($this->container['mcpTokenModel']->urlTokensDisabled());
        $this->assertStoredUrlTokenSetting('1');
        $this->assertSame($adminId, $this->container['mcpTokenModel']->authenticate($plain));
        $this->assertNull($this->container['mcpTokenModel']->takeReveal($adminId));

        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_disable_url_token' => ['0'],
            'target' => 'config',
        ], []);
        $controller->saveUrlTokenSetting();
        $this->assertFalse($this->container['mcpTokenModel']->urlTokensDisabled());
        $this->assertStoredUrlTokenSetting('0');
        $this->assertSame($adminId, $this->container['mcpTokenModel']->authenticate($plain));
    }

    public function testNonAdminCannotReenableUrlTokens(): void
    {
        $this->assertTrue($this->container['mcpTokenModel']->setUrlTokensDisabled(true));
        $userId = $this->createUser('not-an-admin-reenable');
        $this->login($userId);
        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_disable_url_token' => '0',
            'target' => 'user',
        ], []);

        $controller = new ModelContextProtocolController($this->container);

        try {
            $controller->saveUrlTokenSetting();
            $this->fail('Expected AccessForbiddenException');
        } catch (AccessForbiddenException $exception) {
            $this->assertTrue($this->container['mcpTokenModel']->urlTokensDisabled());
            $this->assertStoredUrlTokenSetting('1');
        }
    }

    public function testUrlTokenCheckboxSubmitsOnChangeWithDistinctHiddenName(): void
    {
        $panel = file_get_contents(dirname(__DIR__) . '/Template/config/integration.php');
        $forms = file_get_contents(dirname(__DIR__) . '/Template/config/forms.php');
        $this->assertIsString($panel);
        $this->assertIsString($forms);

        $this->assertMatchesRegularExpression(
            '/<input type="hidden"\s+name="mcp_disable_url_token_present"\s+value="1"\s+form="mcp-url-token-form">/',
            $panel
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input type="hidden"[^>]*name="mcp_disable_url_token"/',
            $panel
        );
        $this->assertStringContainsString('id="mcp-disable-url-token"', $panel);
        $this->assertStringNotContainsString('onchange=', $panel);
        $this->assertStringContainsString('disabled="disabled"', $panel);
        $plugin = file_get_contents(dirname(__DIR__) . '/Plugin.php');
        $script = file_get_contents(dirname(__DIR__) . '/Assets/js/url-token-setting.js');
        $this->assertIsString($plugin);
        $this->assertIsString($script);
        $this->assertStringContainsString('plugins/ModelContextProtocol/Assets/js/url-token-setting.js', $plugin);
        $this->assertStringContainsString("getElementById('mcp-disable-url-token')", $script);
        $this->assertStringContainsString("getElementById('mcp-url-token-form')", $script);
        $this->assertStringContainsString('requestSubmit', $script);
        $this->assertStringContainsString('id="mcp-save-url-token-setting"', $panel);
        $this->assertStringNotContainsString('name="mcp_disable_url_token"', $forms);
        $this->assertStringContainsString('id="mcp-url-token-form"', $forms);
    }

    private function assertStoredUrlTokenSetting(string $expected): void
    {
        $row = $this->container['db']->table('settings')
            ->eq('option', McpTokenModel::CONFIG_DISABLE_URL_TOKEN)
            ->findOne();
        $this->assertNotEmpty($row);
        $this->assertSame($expected, $row['value']);
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
