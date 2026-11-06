<?php

require_once __DIR__ . '/McpTestCase.php';
require_once __DIR__ . '/RecordingHttpResponse.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Plugin\ModelContextProtocol\Controller\ModelContextProtocolController;

class GenerateTokenControllerTest extends McpTestCase
{
    public function testGetIs405(): void
    {
        $userId = $this->createUser('get-token-user');
        $this->login($userId);
        $response = $this->bindHttp([], [], 'GET');

        $controller = new ModelContextProtocolController($this->container);
        $controller->generateToken();

        $this->assertSame(405, $response->status);
        $this->assertNull($response->redirectUrl);
        $this->assertNull($this->container['mcpTokenModel']->getTokenForUser($userId));
    }

    public function testPostWithoutReusableCsrfThrowsAccessForbidden(): void
    {
        $userId = $this->createUser('missing-csrf');
        $this->login($userId);
        $this->bindHttp(['target' => 'config'], []);

        $controller = new ModelContextProtocolController($this->container);

        try {
            $controller->generateToken();
            $this->fail('Expected AccessForbiddenException');
        } catch (AccessForbiddenException $exception) {
            $this->assertNull($this->container['mcpTokenModel']->getTokenForUser($userId));
        }
    }

    public function testOneTimeCsrfTokenDoesNotSatisfyTheForm(): void
    {
        $userId = $this->createUser('one-time-csrf');
        $this->login($userId);
        $oneTime = $this->container['token']->getCSRFToken();
        $this->bindHttp([
            'csrf_token' => $oneTime,
            'target' => 'config',
        ], []);

        $controller = new ModelContextProtocolController($this->container);

        try {
            $controller->generateToken();
            $this->fail('Expected AccessForbiddenException');
        } catch (AccessForbiddenException $exception) {
            $this->assertNull($this->container['mcpTokenModel']->getTokenForUser($userId));
        }
    }

    public function testReusableCsrfRotatesOnlyTheSessionUser(): void
    {
        $sessionUser = $this->createUser('session-rotator');
        $otherUser = $this->createUser('other-rotator');
        $otherToken = $this->container['mcpTokenModel']->regenerateForUser($otherUser);
        $this->login($sessionUser);

        $response = $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'target' => 'config',
        ], []);

        $controller = new ModelContextProtocolController($this->container);
        $controller->generateToken();

        $rotated = $this->container['mcpTokenModel']->getTokenForUser($sessionUser);
        $this->assertNotNull($rotated);
        $this->assertSame($sessionUser, $this->container['mcpTokenModel']->authenticate($rotated));
        $this->assertSame($otherToken, $this->container['mcpTokenModel']->getTokenForUser($otherUser));
        $this->assertSame($otherUser, $this->container['mcpTokenModel']->authenticate($otherToken));
        $this->assertSame($this->integrationsUrl('config', $sessionUser), $response->redirectUrl);
        $this->assertTrue($response->redirectSelf);
        $this->assertSame('New MCP token generated successfully', session_get('flash')['success']);
    }

    public function testPostedUserIdDoesNotSelectWhoseTokenIsRotated(): void
    {
        $sessionUser = $this->createUser('session-owner');
        $otherUser = $this->createUser('posted-owner');
        $otherToken = $this->container['mcpTokenModel']->regenerateForUser($otherUser);
        $this->login($sessionUser);

        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'target' => 'user',
            'user_id' => (string) $otherUser,
        ], [
            'user_id' => (string) $otherUser,
        ]);

        $controller = new ModelContextProtocolController($this->container);
        $controller->generateToken();

        $rotated = $this->container['mcpTokenModel']->getTokenForUser($sessionUser);
        $this->assertNotNull($rotated);
        $this->assertSame($sessionUser, $this->container['mcpTokenModel']->authenticate($rotated));
        $this->assertSame($otherToken, $this->container['mcpTokenModel']->getTokenForUser($otherUser));
        $this->assertSame(
            $this->integrationsUrl('user', $sessionUser),
            $this->container['response']->redirectUrl
        );
        $this->assertNotSame(
            $this->integrationsUrl('user', $otherUser),
            $this->container['response']->redirectUrl
        );
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
            $url = $this->container['helper']->url->to('UserViewController', 'integrations', [
                'user_id' => $userId,
            ]);
        } else {
            $url = $this->container['helper']->url->to('ConfigController', 'integrations');
        }

        $this->assertStringNotContainsString('plugin=', $url);
        $this->assertStringNotContainsString('ModelContextProtocol', $url);

        return $url;
    }
}
