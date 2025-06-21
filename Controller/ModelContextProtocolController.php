<?php

namespace Kanboard\Plugin\ModelContextProtocol\Controller;

use Kanboard\Core\Base;
use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;

/**
 * Model Context Protocol Controller
 *
 * @package  Kanboard\Plugin\ModelContextProtocol\Controller
 * @author   Plugin Author
 */
class ModelContextProtocolController extends Base
{
    /**
     * Handle MCP requests for different transport types
     */
    public function handle()
    {
        // Add CORS headers immediately for all requests
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, HEAD');
        header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, X-Requested-With, Origin, User-Agent, Cache-Control, Pragma');
        header('Access-Control-Expose-Headers: Content-Type, Cache-Control, Expires, Pragma');
        header('Access-Control-Max-Age: 86400');
        
        // Handle OPTIONS preflight immediately
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        
        // Validate token first
        if (!$this->validateToken()) {
            $this->sendError(401, 'Unauthorized');
            return;
        }
        
        // Determine transport type based on Accept header and User-Agent
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Check if this is an SSE request - only if SSE is preferred over JSON
        if (strpos($acceptHeader, 'text/event-stream') !== false && 
            strpos($acceptHeader, 'application/json') === false) {
            $this->handleSSE();
            return;
        }
        
        // Default to Streamable HTTP for JSON or mixed accept headers
        $this->handleStreamableHttp();
    }
    
    /**
     * Validate the MCP token
     */
    private function validateToken()
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
    private function handleStreamableHttp()
    {
        // Set proper headers for Streamable HTTP
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Handle GET requests - just return server status, don't send MCP capabilities
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo json_encode([
                'status' => 'MCP Server Ready',
                'transport' => 'streamable-http',
                'protocol' => 'Model Context Protocol',
                'version' => '2024-11-05'
            ]);
            exit;
        }
        
        // Handle POST requests - JSON-RPC
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = file_get_contents('php://input');
            
            $request = json_decode($input, true);
            
            if (!$request) {
                $this->sendJsonRpcError(-32700, 'Parse error', null);
                return;
            }
            
            try {
                // Initialize MCP server and handle request
                $mcpServer = new McpServer($this->container);
                
                $response = $mcpServer->handleRequest($request);
                
                // Handle notifications (null responses)
                if ($response === null) {
                    http_response_code(204); // No Content
                    exit;
                }
                
                echo json_encode($response);
                exit;
                
            } catch (Exception $e) {
                // Send proper error response
                http_response_code(500);
                echo json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'] ?? null,
                    'error' => [
                        'code' => -32603,
                        'message' => 'Internal error: ' . $e->getMessage()
                    ]
                ]);
                exit;
            } catch (Error $e) {
                // Send proper error response
                http_response_code(500);
                echo json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $request['id'] ?? null,
                    'error' => [
                        'code' => -32603,
                        'message' => 'Fatal error: ' . $e->getMessage()
                    ]
                ]);
                exit;
            }
        }
        
        $this->sendError(405, 'Method Not Allowed');
    }
    
    /**
     * Handle SSE requests from Cursor IDE
     */
    private function handleSSE()
    {
        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Accept, Authorization, Content-Type, Last-Event-ID, X-Requested-With');
        
        // Disable buffering
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Send initial connection acknowledgment
        echo "event: connect\n";
        echo "data: {\"type\": \"connect\", \"message\": \"MCP Server connected\"}\n\n";
        flush();
        
        // Keep connection alive
        $counter = 0;
        while (true) {
            // Send heartbeat every 30 seconds
            if ($counter % 30 === 0) {
                echo "event: heartbeat\n";
                echo "data: {\"type\": \"heartbeat\", \"timestamp\": " . time() . "}\n\n";
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
    private function sendJsonRpcError($code, $message, $id)
    {
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
    private function sendError($code, $message)
    {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit;
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