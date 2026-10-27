<?php

namespace Kanboard\Plugin\ModelContextProtocol\Model;

use Kanboard\Core\Base;

/**
 * MCP Token Model
 *
 * Only a SHA-256 hash of the token is stored; the token itself is shown
 * once, right after it has been generated.
 *
 * @package  Kanboard\Plugin\ModelContextProtocol\Model
 * @author   Plugin Author
 */
class McpTokenModel extends Base
{
    const TABLE = 'mcp_tokens';

    /**
     * Hash a token for storage and comparison
     *
     * @param  string $token
     * @return string
     */
    public static function hashToken($token)
    {
        return hash('sha256', (string) $token);
    }

    /**
     * Get the active token row without the secret
     *
     * @return array|null
     */
    public function getActiveToken()
    {
        $row = $this->db->table(self::TABLE)
            ->columns('id', 'created_at')
            ->eq('is_active', 1)
            ->findOne();

        return empty($row) ? null : $row;
    }
    
    /**
     * Validate token
     */
    public function validateToken($token)
    {
        if (empty($token) || ! is_string($token)) {
            return false;
        }

        $hash = self::hashToken($token);

        $activeHashes = $this->db->table(self::TABLE)
            ->eq('is_active', 1)
            ->findAllByColumn('token');

        foreach ($activeHashes as $activeHash) {
            if (hash_equals((string) $activeHash, $hash)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Generate new token
     *
     * @return string|false  The new token (not stored anywhere else), or false
     */
    public function generateToken()
    {
        // Deactivate existing tokens
        $this->db->table(self::TABLE)
            ->update(['is_active' => 0]);
        
        // Generate new token
        $token = bin2hex(random_bytes(32));
        
        $saved = $this->db->table(self::TABLE)->persist([
            'token' => self::hashToken($token),
            'is_active' => 1,
            'created_at' => time(),
        ]);

        return $saved ? $token : false;
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
