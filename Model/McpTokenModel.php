<?php

namespace Kanboard\Plugin\ModelContextProtocol\Model;

use Kanboard\Core\Base;

/**
 * MCP Token Model
 *
 * @package  Kanboard\Plugin\ModelContextProtocol\Model
 * @author   Plugin Author
 */
class McpTokenModel extends Base
{
    const TABLE = 'mcp_tokens';
    
    /**
     * Get current token
     */
    public function getCurrentToken()
    {
        return $this->db->table(self::TABLE)
            ->eq('is_active', 1)
            ->findOneColumn('token');
    }
    
    /**
     * Validate token
     */
    public function validateToken($token)
    {
        if (empty($token)) {
            return false;
        }
        
        return $this->db->table(self::TABLE)
            ->eq('token', $token)
            ->eq('is_active', 1)
            ->exists();
    }
    
    /**
     * Generate new token
     */
    public function generateToken()
    {
        // Deactivate existing tokens
        $this->db->table(self::TABLE)
            ->update(['is_active' => 0]);
        
        // Generate new token
        $token = bin2hex(random_bytes(32));
        
        return $this->db->table(self::TABLE)->persist([
            'token' => $token,
            'is_active' => 1,
            'created_at' => time(),
        ]);
    }
    
    /**
     * Check if token exists
     */
    public function tokenExists()
    {
        return $this->db->table(self::TABLE)
            ->eq('is_active', 1)
            ->exists();
    }
} 