<?php

require_once __DIR__ . '/McpTestCase.php';
require_once __DIR__ . '/RecordingHttpResponse.php';

use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Http\Request;
use Kanboard\Plugin\ModelContextProtocol\Controller\ModelContextProtocolController;
use Kanboard\Plugin\ModelContextProtocol\Model\McpTokenModel;

class TokenRotationTest extends McpTestCase
{
    public function testRotationIsOffByDefaultAndIgnoresAPastExpiry(): void
    {
        $userId = $this->createUser('rotation-off');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->setExpiresAt($userId, time() - 10);

        $this->assertFalse($this->container['mcpTokenModel']->rotationEnabled());
        $this->assertSame($userId, $this->container['mcpTokenModel']->authenticate($plain));
        $this->assertFalse($this->container['mcpTokenModel']->authenticationExpired());
        $this->assertSame([], $this->unread($userId));
    }

    public function testEnabledRotationExpiresTheTokenAndRejectsIt(): void
    {
        $userId = $this->createUser('rotation-expired');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->container['mcpTokenModel']->saveRotation(true, 30);
        $this->setExpiresAt($userId, time() - 10);

        $this->assertNull($this->container['mcpTokenModel']->authenticate($plain));
        $this->assertTrue($this->container['mcpTokenModel']->authenticationExpired());
        $this->assertCount(1, $this->unread($userId));
    }

    public function testUnknownTokenIsNotReportedAsExpired(): void
    {
        $this->container['mcpTokenModel']->saveRotation(true, 90);

        $this->assertNull($this->container['mcpTokenModel']->authenticate(str_repeat('ab', 32)));
        $this->assertFalse($this->container['mcpTokenModel']->authenticationExpired());
    }

    public function testWarningStartsFourteenDaysBeforeAndIsNotRepeatedTheSameDay(): void
    {
        $userId = $this->createUser('rotation-soon');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $expiresAt = time() + 10 * 86400;
        $this->container['mcpTokenModel']->saveRotation(true, 90);
        $this->setExpiresAt($userId, $expiresAt);

        $this->assertSame($userId, $this->container['mcpTokenModel']->authenticate($plain));
        $notices = $this->unread($userId);
        $this->assertCount(1, $notices);
        $data = json_decode($notices[0]['event_data'], true);
        $this->assertSame($expiresAt, (int) $data['expires_at']);
        $this->assertStringContainsString(date('Y-m-d', $expiresAt), $data['message']);

        $this->container['mcpTokenModel']->notifyUser($userId);
        $this->assertCount(1, $this->unread($userId));
    }

    public function testClearedNoticeIsSentAgainOnALaterDay(): void
    {
        $userId = $this->createUser('rotation-again');
        $this->container['mcpTokenModel']->regenerateForUser($userId);
        $expiresAt = time() + 5 * 86400;
        $this->container['mcpTokenModel']->saveRotation(true, 90);
        $this->setExpiresAt($userId, $expiresAt);
        $this->container['mcpTokenModel']->notifyUser($userId);
        $this->container['db']->table('user_has_unread_notifications')->eq('user_id', $userId)->remove();
        $this->container['db']->table(McpTokenModel::TABLE)->eq('user_id', $userId)->update([
            'rotation_notified_on' => time() - 86400,
        ]);

        $this->container['mcpTokenModel']->notifyUser($userId);

        $this->assertCount(1, $this->unread($userId));
    }

    public function testTokenOutsideTheWarningWindowDoesNotNotify(): void
    {
        $userId = $this->createUser('rotation-later');
        $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->container['mcpTokenModel']->saveRotation(true, 90);
        $this->setExpiresAt($userId, time() + 20 * 86400);

        $this->container['mcpTokenModel']->notifyUser($userId);

        $this->assertSame([], $this->unread($userId));
    }

    public function testDisablingRotationClearsNoticesAndAcceptsTheToken(): void
    {
        $userId = $this->createUser('rotation-disabled');
        $plain = $this->container['mcpTokenModel']->regenerateForUser($userId);
        $this->container['mcpTokenModel']->saveRotation(true, 30);
        $this->setExpiresAt($userId, time() + 3 * 86400);
        $this->container['mcpTokenModel']->notifyUser($userId);
        $this->assertCount(1, $this->unread($userId));

        $this->container['mcpTokenModel']->saveRotation(false, 30);

        $this->assertSame([], $this->unread($userId));
        $this->assertSame($userId, $this->container['mcpTokenModel']->authenticate($plain));
        $this->assertNull($this->container['mcpTokenModel']->expiresAtForUser($userId));
    }

    public function testAdminSavesUrlPolicyAndRotationTogether(): void
    {
        $this->login($this->adminId());
        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_disable_url_token_present' => '1',
            'mcp_disable_url_token' => '1',
            'mcp_token_rotation_enabled' => '1',
            'mcp_token_rotation_days' => '60',
            'target' => 'config',
        ], []);

        $controller = new ModelContextProtocolController($this->container);
        $controller->saveInstanceSettings();

        $this->assertTrue($this->container['mcpTokenModel']->urlTokensDisabled());
        $this->assertTrue($this->container['mcpTokenModel']->rotationEnabled());
        $this->assertSame(60, $this->container['mcpTokenModel']->rotationDays());
    }

    public function testRotationSaveWithoutTheSettingsFlagKeepsUrlTokensDisabled(): void
    {
        $this->login($this->adminId());
        $this->assertTrue($this->container['mcpTokenModel']->setUrlTokensDisabled(true));
        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_token_rotation_enabled' => '1',
            'mcp_token_rotation_days' => '90',
            'target' => 'config',
        ], []);

        $controller = new ModelContextProtocolController($this->container);
        $controller->saveInstanceSettings();

        $this->assertTrue($this->container['mcpTokenModel']->urlTokensDisabled());
        $this->assertTrue($this->container['mcpTokenModel']->rotationEnabled());
    }

    public function testAdminCanSaveRotationAndABadPeriodIsRejected(): void
    {
        $adminId = $this->adminId();
        $this->login($adminId);
        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_token_rotation_enabled' => '1',
            'mcp_token_rotation_days' => '180',
            'target' => 'config',
        ], []);

        $controller = new ModelContextProtocolController($this->container);
        $controller->saveTokenRotation();

        $this->assertTrue($this->container['mcpTokenModel']->rotationEnabled());
        $this->assertSame(180, $this->container['mcpTokenModel']->rotationDays());

        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_token_rotation_enabled' => '1',
            'mcp_token_rotation_days' => '14',
            'target' => 'config',
        ], []);
        $controller->saveTokenRotation();

        $this->assertSame(180, $this->container['mcpTokenModel']->rotationDays());
    }

    public function testNonAdminCannotSaveRotation(): void
    {
        $userId = $this->createUser('rotation-member');
        $this->login($userId);
        $this->bindHttp([
            'csrf_token' => $this->container['token']->getReusableCSRFToken(),
            'mcp_token_rotation_enabled' => '1',
            'mcp_token_rotation_days' => '30',
            'target' => 'user',
        ], []);

        $controller = new ModelContextProtocolController($this->container);

        try {
            $controller->saveTokenRotation();
            $this->fail('Expected AccessForbiddenException');
        } catch (AccessForbiddenException $exception) {
            $this->assertFalse($this->container['mcpTokenModel']->rotationEnabled());
        }
    }

    private function setExpiresAt(int $userId, int $expiresAt): void
    {
        $this->assertTrue($this->container['db']->table(McpTokenModel::TABLE)->eq('user_id', $userId)->update([
            'expires_at' => $expiresAt,
            'rotation_notified_on' => null,
        ]));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function unread(int $userId): array
    {
        return $this->container['db']->table('user_has_unread_notifications')
            ->eq('user_id', $userId)
            ->eq('event_name', McpTokenModel::ROTATION_EVENT)
            ->findAll();
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
