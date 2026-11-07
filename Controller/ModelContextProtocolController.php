<?php
declare(strict_types=1);

namespace Kanboard\Plugin\ModelContextProtocol\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;
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
        $disabled = is_string($posted) && $posted === '1';

        $this->mcpTokenModel->setUrlTokensDisabled($disabled);
        $this->flash->success(t('MCP URL token setting saved'));

        $target = $this->request->getRawValue('target');
        $this->redirectToIntegrations(is_string($target) ? $target : '', $userId);
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
