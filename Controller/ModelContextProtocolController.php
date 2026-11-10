<?php
declare(strict_types=1);

namespace Kanboard\Plugin\ModelContextProtocol\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\ModelContextProtocol\Core\McpAccess;
use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;
use Kanboard\Plugin\ModelContextProtocol\Model\McpTokenModel;
use InvalidArgumentException;
use Throwable;

/**
 * Model Context Protocol Controller
 *
 * @package  Kanboard\Plugin\ModelContextProtocol\Controller
 */
class ModelContextProtocolController extends BaseController
{
    /**
     * @var array<int,string>
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    // -----------------------------------------------------------------------
    // MCP handle endpoint (public, token-authenticated)
    // -----------------------------------------------------------------------

    /**
     * Handle MCP requests for different transport types.
     */
    public function handle(): void
    {
        // Security: never let the token appear in Referer headers sent to
        // third-party origins embedded in server-side error pages.
        header('Referrer-Policy: no-referrer');

        // CORS headers for all responses
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, X-Requested-With, Origin, User-Agent, Cache-Control, Pragma');
        header('Access-Control-Expose-Headers: Content-Type, Cache-Control, Expires, Pragma');
        header('Access-Control-Max-Age: 86400');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        if (!$this->isValidOrigin()) {
            $this->sendError(403, 'Forbidden: invalid origin');
            return;
        }

        $userId = $this->authenticateToken();
        if ($userId === null) {
            if ($this->mcpTokenModel->authenticationExpired()) {
                header('WWW-Authenticate: Bearer error="invalid_token", error_description="The MCP token has expired"');
                $this->sendError(401, t('The MCP token has expired'));
            }
            $this->sendError(401, 'Unauthorized');
            return;
        }

        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($requestMethod === 'POST') {
            $this->handleStreamableHttp($userId);
            return;
        }

        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';

        if ($requestMethod === 'GET' && strpos($acceptHeader, 'text/event-stream') !== false) {
            $this->handleSSE();
            return;
        }

        $this->sendError(405, 'Method Not Allowed');
    }

    // -----------------------------------------------------------------------
    // Token management (requires authenticated session)
    // -----------------------------------------------------------------------

    /**
     * Regenerate the MCP token for the currently logged-in user.
     *
     * Requires APP_USER role and a POST body with the reusable CSRF token.
     * Bound to the session user only — a posted user_id never selects the account.
     */
    public function generateToken(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->status(405);
            return;
        }

        $this->checkReusableCSRFParam();

        $userId = $this->userSession->getId();

        $plainToken = $this->mcpTokenModel->regenerateForUser($userId);
        $this->mcpTokenModel->rememberReveal((int) $userId, $plainToken);
        $this->flash->success(t('New MCP token generated successfully'));

        $target = $this->request->getRawValue('target');
        $this->redirectToIntegrations(is_string($target) ? $target : '', $userId);
    }

    /**
     * Save the instance-wide "disable token in URL" setting.
     *
     * Admins only. Regular users see the checkbox as read-only.
     * A posted user_id never selects the account; the session user is used
     * only for the integrations redirect.
     */
    public function saveUrlTokenSetting(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->status(405);
            return;
        }

        $this->checkReusableCSRFParam();

        if (!$this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        $userId = $this->userSession->getId();
        $posted = $this->request->getRawValue('mcp_disable_url_token');
        $disabled = $this->postedUrlTokensDisabled($posted);

        $this->mcpTokenModel->setUrlTokensDisabled($disabled);
        $this->flash->success(t('MCP URL token setting saved'));

        $target = $this->request->getRawValue('target');
        $this->redirectToIntegrations(is_string($target) ? $target : '', $userId);
    }

    /**
     * Save the instance-wide MCP token rotation period.
     *
     * Admins only. Days must be one of 30, 60, 90, 180, or 360.
     */
    public function saveTokenRotation(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->status(405);
            return;
        }

        $this->checkReusableCSRFParam();

        if (!$this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        $userId = $this->userSession->getId();
        $target = $this->request->getRawValue('target');
        $target = is_string($target) ? $target : '';
        $enabled = $this->postedFlagOn($this->request->getRawValue('mcp_token_rotation_enabled'));
        $days = $this->request->getRawValue('mcp_token_rotation_days');
        $dayCount = is_string($days) ? (int) $days : 0;

        if (!in_array($dayCount, McpTokenModel::ROTATION_DAY_OPTIONS, true)) {
            $this->flash->failure(t('Choose 30, 60, 90, 180, or 360 days'));
            $this->redirectToIntegrations($target, $userId);
            return;
        }

        $this->mcpTokenModel->saveRotation($enabled, $dayCount);
        $this->flash->success(t('MCP token rotation saved'));
        $this->redirectToIntegrations($target, $userId);
    }

    /**
     * Save the URL-token policy and the rotation period together.
     *
     * Admins only. A period outside 30, 60, 90, 180, or 360 changes nothing.
     */
    public function saveInstanceSettings(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->status(405);
            return;
        }

        $this->checkReusableCSRFParam();

        if (!$this->userSession->isAdmin()) {
            throw new AccessForbiddenException();
        }

        $userId = $this->userSession->getId();
        $target = $this->request->getRawValue('target');
        $target = is_string($target) ? $target : '';
        $days = $this->request->getRawValue('mcp_token_rotation_days');
        $dayCount = is_string($days) ? (int) $days : 0;

        if (!in_array($dayCount, McpTokenModel::ROTATION_DAY_OPTIONS, true)) {
            $this->flash->failure(t('Choose 30, 60, 90, 180, or 360 days'));
            $this->redirectToIntegrations($target, $userId);
            return;
        }

        // The settings form always posts this flag. A request that omits it
        // must not turn URL tokens back on.
        if ($this->postedFlagOn($this->request->getRawValue('mcp_disable_url_token_present'))) {
            $this->mcpTokenModel->setUrlTokensDisabled(
                $this->postedUrlTokensDisabled($this->request->getRawValue('mcp_disable_url_token'))
            );
        }
        $this->mcpTokenModel->saveRotation(
            $this->postedFlagOn($this->request->getRawValue('mcp_token_rotation_enabled')),
            $dayCount
        );
        $this->flash->success(t('MCP settings saved'));
        $this->redirectToIntegrations($target, $userId);
    }

    /**
     * Redirect through the integrations whitelist. A posted URL is never used.
     *
     * Kanboard 1.2.45 UrlHelper::build() appends every parameter when
     * Route::findUrl() misses. settings/integrations and
     * user/:user_id/integrations are core routes, found only when plugin
     * is omitted. plugin=ModelContextProtocol skips them and the next
     * request looks for ConfigController inside this plugin.
     */
    private function redirectToIntegrations(string $target, int $userId): void
    {
        if ($target === 'user') {
            $this->response->redirect(
                $this->helper->url->to('UserViewController', 'integrations', [
                    'user_id' => $userId,
                ]),
                true
            );
            return;
        }

        $this->response->redirect(
            $this->helper->url->to('ConfigController', 'integrations'),
            true
        );
    }

    /**
     * True when the disable-URL-token checkbox was posted as on.
     *
     * A missing field or "0" means URL tokens stay allowed. Duplicate names
     * (hidden 0 plus checkbox 1) can arrive as a string or as a list;
     * Request::getRawValue() returns $_POST as PHP parsed it. Any "1" counts
     * as checked so a first-wins string or a two-value list still saves on.
     *
     * @param mixed $posted
     */
    private function postedUrlTokensDisabled($posted): bool
    {
        if (is_array($posted)) {
            foreach ($posted as $value) {
                if ($this->postedUrlTokensDisabled($value)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($posted) && $posted === '1';
    }

    /**
     * True when a checkbox posted "1", including a duplicate-name list.
     *
     * @param mixed $posted
     */
    private function postedFlagOn($posted): bool
    {
        if (is_array($posted)) {
            foreach ($posted as $value) {
                if ($this->postedFlagOn($value)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($posted) && $posted === '1';
    }

    /**
     * Save the session user's endpoint toggles.
     *
     * A hidden gateway_save flag is required. The posted list is validated
     * before any write. user_id never selects another account.
     */
    public function saveGateway(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->status(405);
            return;
        }

        $this->checkReusableCSRFParam();

        $userId = (int) $this->userSession->getId();
        $form = $this->request->getRawFormValues();
        $this->rejectForeignUserId($userId, $form);

        $target = isset($form['target']) && is_string($form['target']) ? $form['target'] : '';

        if (!isset($form['gateway_save']) || !is_string($form['gateway_save']) || $form['gateway_save'] !== '1') {
            $this->redirectToIntegrations($target, $userId);
            return;
        }

        $posted = [];
        if (array_key_exists('endpoints', $form)) {
            $posted = $this->flatStringList($form['endpoints']);
            if ($posted === null) {
                $this->flash->failure(t('Could not save MCP access'));
                $this->redirectToIntegrations($target, $userId);
                return;
            }
        }

        $allowed = $this->mcpEndpointGatewayModel->allowedNames(new McpAccess($this->container, $userId));

        try {
            $this->mcpEndpointGatewayModel->save($userId, $allowed, $posted);
        } catch (Throwable $exception) {
            if (!$exception instanceof InvalidArgumentException) {
                $this->logger->error('MCP access save failed', ['exception' => $exception]);
            }
            $this->flash->failure(t('Could not save MCP access'));
            $this->redirectToIntegrations($target, $userId);
            return;
        }

        $this->flash->success(t('MCP access saved'));
        $this->redirectToIntegrations($target, $userId);
    }

    /**
     * Query-string and raw POST user_id must be absent or this session user.
     *
     * @param array<string,mixed> $form
     */
    private function rejectForeignUserId(int $userId, array $form): void
    {
        if (array_key_exists('user_id', $form) && !$this->isSameUserId($form['user_id'], $userId)) {
            throw new AccessForbiddenException();
        }

        if (array_key_exists('user_id', $_GET) && !$this->isSameUserId($_GET['user_id'], $userId)) {
            throw new AccessForbiddenException();
        }
    }

    private function isSameUserId(mixed $value, int $userId): bool
    {
        if (is_int($value)) {
            return $value === $userId;
        }

        if (is_string($value)) {
            return $value === (string) $userId;
        }

        return false;
    }

    /**
     * A JSON/form list of strings, or null when the shape is not that list.
     *
     * @param mixed $value
     * @return string[]|null
     */
    private function flatStringList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $names = [];
        $expected = 0;
        foreach ($value as $key => $item) {
            if ($key !== $expected || !is_string($item)) {
                return null;
            }
            $names[] = $item;
            $expected++;
        }

        return $names;
    }

    // -----------------------------------------------------------------------
    // Streamable HTTP transport
    // -----------------------------------------------------------------------

    private function handleStreamableHttp(int $userId): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->sendError(405, 'Method Not Allowed');
            return;
        }

        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $protocolHeader = $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '';
        if ($protocolHeader !== '' && !in_array($protocolHeader, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            $this->sendError(400, 'Unsupported MCP protocol version');
            return;
        }

        $input = file_get_contents('php://input');

        $request = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendJsonRpcError(-32700, 'Parse error', null, 400);
            return;
        }

        if (!is_array($request)) {
            $this->sendJsonRpcError(-32600, 'Invalid Request', null, 400);
            return;
        }

        if ($this->isListArray($request)) {
            $this->sendJsonRpcError(-32600, 'Batch requests are not supported', null, 400);
            return;
        }

        // Accept client notifications/responses with 202, as required by Streamable HTTP.
        if (!array_key_exists('method', $request) && (array_key_exists('result', $request) || array_key_exists('error', $request))) {
            http_response_code(202);
            exit;
        }

        $isNotification = !array_key_exists('id', $request);

        try {
            $mcpServer = new McpServer($this->container, $userId);
            $response  = $mcpServer->handleRequest($request);

            if ($isNotification || $response === null) {
                http_response_code(202);
                exit;
            }

            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        } catch (Throwable $exception) {
            http_response_code(500);
            $this->sendJsonRpcError(-32603, 'Internal error', $request['id'] ?? null, 500);
            return;
        }
    }

    // -----------------------------------------------------------------------
    // SSE transport (keep-alive, no data plane)
    // -----------------------------------------------------------------------

    private function handleSSE(): void
    {
        header('Content-Type: text/event-stream');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, Last-Event-ID, X-Requested-With');
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');

        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_implicit_flush(true);

        $eventId = bin2hex(random_bytes(8));
        echo "id: {$eventId}\n";
        echo "data:\n\n";
        flush();

        $counter = 0;
        while (true) {
            if ($counter % 30 === 0) {
                echo ': heartbeat ' . time() . "\n\n";
                flush();
            }

            if (connection_aborted()) {
                break;
            }

            sleep(1);
            $counter++;
        }
    }

    // -----------------------------------------------------------------------
    // Token authentication
    // -----------------------------------------------------------------------

    /**
     * Extract and validate the bearer token.
     *
     * Prefer Authorization: Bearer from HTTP_AUTHORIZATION, then
     * REDIRECT_HTTP_AUTHORIZATION (PHP-CGI / rewrite). When URL tokens are
     * allowed, also accept `token` on the query string. If both header and
     * query are present and differ, reject the request.
     *
     * Returns the authenticated user id, or null on failure.
     * The token value is never written to a log.
     */
    private function authenticateToken(): ?int
    {
        $headerToken = $this->bearerTokenFromAuthorizationHeader();
        $queryToken  = $this->queryStringToken();

        if ($headerToken !== null && $queryToken !== null && $headerToken !== $queryToken) {
            // Two tokens that disagree – reject to avoid ambiguity
            return null;
        }

        $token = $headerToken ?? $queryToken;

        if ($token === null || $token === '') {
            return null;
        }

        return $this->mcpTokenModel->authenticate($token);
    }

    /**
     * Bearer token from the incoming Authorization header, or null.
     */
    private function bearerTokenFromAuthorizationHeader(): ?string
    {
        $authHeader = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] !== '') {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] !== '') {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if ($authHeader === '' || stripos($authHeader, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($authHeader, 7));

        return $token === '' ? null : $token;
    }

    /**
     * Query-string token when the instance still allows that compatibility path.
     */
    private function queryStringToken(): ?string
    {
        if ($this->mcpTokenModel->urlTokensDisabled()) {
            return null;
        }

        if (!isset($_GET['token']) || $_GET['token'] === '') {
            return null;
        }

        return (string) $_GET['token'];
    }

    // -----------------------------------------------------------------------
    // Origin check (DNS-rebinding protection)
    // -----------------------------------------------------------------------

    private function isValidOrigin(): bool
    {
        $originHeader = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($originHeader === '') {
            return true;
        }

        $origin = parse_url($originHeader);
        if ($origin === false || !isset($origin['scheme'], $origin['host'])) {
            return false;
        }

        $originScheme = strtolower($origin['scheme']);
        if ($originScheme !== 'http' && $originScheme !== 'https') {
            return false;
        }

        $originHost = strtolower($origin['host']);
        $originPort = isset($origin['port'])
            ? (int) $origin['port']
            : ($originScheme === 'https' ? 443 : 80);

        $hostHeader = $_SERVER['HTTP_HOST'] ?? '';
        if ($hostHeader === '') {
            return false;
        }

        $hostParts   = explode(':', $hostHeader, 2);
        $requestHost = strtolower($hostParts[0]);
        $requestPort = isset($hostParts[1]) && ctype_digit($hostParts[1])
            ? (int) $hostParts[1]
            : ($this->isHttpsRequest() ? 443 : 80);

        return $originHost === $requestHost && $originPort === $requestPort;
    }

    private function isHttpsRequest(): bool
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        }

        return isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '';
    }

    // -----------------------------------------------------------------------
    // Response helpers
    // -----------------------------------------------------------------------

    private function sendJsonRpcError(int $code, string $message, int|string|null $id, int $httpStatus = 200): void
    {
        http_response_code($httpStatus);
        header('Content-Type: application/json');

        echo json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ]);
        exit;
    }

    private function sendError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }

    /**
     * @param array<mixed> $value
     */
    private function isListArray(array $value): bool
    {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }
}
