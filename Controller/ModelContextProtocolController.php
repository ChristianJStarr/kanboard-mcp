<?php
declare(strict_types=1);

namespace Kanboard\Plugin\ModelContextProtocol\Controller;

use Kanboard\Core\Base;
use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;
use Throwable;

/**
 * Model Context Protocol Controller
 *
 * @package  Kanboard\Plugin\ModelContextProtocol\Controller
 * @author   Plugin Author
 */
class ModelContextProtocolController extends Base
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

    /**
     * Handle MCP requests for different transport types
     */
    public function handle()
    {
        // Add CORS headers immediately for all requests
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, X-Requested-With, Origin, User-Agent, Cache-Control, Pragma');
        header('Access-Control-Expose-Headers: Content-Type, Cache-Control, Expires, Pragma');
        header('Access-Control-Max-Age: 86400');
        
        // Handle OPTIONS preflight immediately
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        if (!$this->isValidOrigin()) {
            $this->sendError(403, 'Forbidden: invalid origin');
            return;
        }
        
        // Validate token first
        if (!$this->validateToken()) {
            $this->sendError(401, 'Unauthorized');
            return;
        }
        
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($requestMethod === 'POST') {
            $this->handleStreamableHttp();
            return;
        }

        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';

        if ($requestMethod === 'GET' && strpos($acceptHeader, 'text/event-stream') !== false) {
            $this->handleSSE();
            return;
        }

        $this->sendError(405, 'Method Not Allowed');
    }
    
    /**
     * Validate the MCP token
     */
    private function validateToken(): bool
    {
        $token = $_GET['token'] ?? '';
        
        if (empty($token)) {
            return false;
        }
        
        if (!$this->mcpTokenModel->validateToken($token)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Handle Streamable HTTP transport
     */
    private function handleStreamableHttp(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->sendError(405, 'Method Not Allowed');
            return;
        }

        // Set proper headers for Streamable HTTP
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
            $mcpServer = new McpServer($this->container);
            $response = $mcpServer->handleRequest($request);

            if ($isNotification || $response === null) {
                http_response_code(202);
                exit;
            }

            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        } catch (Throwable $exception) {
            http_response_code(500);
            $this->sendJsonRpcError(-32603, 'Internal error', isset($request['id']) ? $request['id'] : null, 500);
            return;
        }
    }
    
    /**
     * Handle SSE requests from Cursor IDE
     */
    private function handleSSE(): void
    {
        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, Last-Event-ID, X-Requested-With');
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');

        // Disable buffering
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_implicit_flush(true);
        
        // Prime reconnect semantics with an event id and empty data payload.
        $eventId = bin2hex(random_bytes(8));
        echo "id: {$eventId}\n";
        echo "data:\n\n";
        flush();

        // Keep connection alive with lightweight comment heartbeats.
        $counter = 0;
        while (true) {
            if ($counter % 30 === 0) {
                echo ': heartbeat ' . time() . "\n\n";
                flush();
            }

            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }
            
            sleep(1);
            $counter++;
        }
    }
    
    /**
     * Send JSON-RPC error response
     */
    private function sendJsonRpcError(int $code, string $message, int|string|null $id, int $httpStatus = 200): void
    {
        http_response_code($httpStatus);
        header('Content-Type: application/json');

        $response = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ];
        
        echo json_encode($response);
        exit;
    }
    
    /**
     * Send HTTP error response
     */
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

        $hostParts = explode(':', $hostHeader, 2);
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
    
    /**
     * Settings page
     */
    public function settings()
    {
        $this->ensureTablesExist();
        
        $this->response->html($this->helper->layout->config('modelcontextprotocol:config/integration', [
            'title' => 'Model Context Protocol Settings',
            'tokens' => $this->mcpTokenModel->getAll(),
            'mcp_url' => $this->getMcpUrl()
        ]));
    }
    
    /**
     * Ensure database tables exist
     */
    private function ensureTablesExist()
    {
        $pdo = $this->db->getConnection();
        
        // Check if the table exists
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='mcp_tokens'");
        $stmt->execute();
        
        if (!$stmt->fetch()) {
            // Create table if it doesn't exist
            $sql = "CREATE TABLE mcp_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                is_active INTEGER DEFAULT 1
            )";
            
            $pdo->exec($sql);
        }
    }
    
    /**
     * Generate new token
     */
    public function generateToken()
    {
        $this->ensureTablesExist();
        
        $name = $this->request->getStringParam('name', 'Default Token');
        $token = bin2hex(random_bytes(32));
        
        $result = $this->mcpTokenModel->create([
            'token' => $token,
            'name' => $name,
            'created_at' => time(),
            'is_active' => 1
        ]);
        
        if ($result) {
            $this->flash->success('Token generated successfully');
        } else {
            $this->flash->failure('Failed to generate token');
        }
        
        $this->response->redirect($this->helper->url->to('ModelContextProtocolController', 'settings', ['plugin' => 'ModelContextProtocol']));
    }
    
    /**
     * Get MCP URL
     */
    private function getMcpUrl()
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = $this->helper->url->to('ModelContextProtocolController', 'handle', ['plugin' => 'ModelContextProtocol']);
        
        return $protocol . $host . $basePath;
    }
    
} 
