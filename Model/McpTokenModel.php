<?php

namespace Kanboard\Plugin\ModelContextProtocol\Model;

use Kanboard\Core\Base;

/**
 * Per-user MCP Token Model
 *
 * One active token per Kanboard user.  The token is the sole credential
 * that authenticates MCP requests; it carries the user's identity and
 * grants exactly the permissions that user holds inside Kanboard.
 *
 * @package  Kanboard\Plugin\ModelContextProtocol\Model
 */
class McpTokenModel extends Base
{
    const TABLE = 'mcp_tokens';

    /**
     * Return the plaintext token for a user, or null when none has been minted yet.
     *
     * The database only stores the SHA-256 hash; this column (`token_plain`)
     * stores the one-time plaintext value so the UI can display it.
     * It is functionally a cached copy of the last value returned by
     * regenerateForUser(); it is NOT used for authentication.
     *
     * @param  int $userId
     * @return string|null
     */
    public function getTokenForUser(int $userId): ?string
    {
        $value = $this->db->table(self::TABLE)
            ->eq('user_id', $userId)
            ->findOneColumn('token_plain');

        return $value ?: null;
    }

    /**
     * Create or replace the token for a user.
     *
     * Stores a SHA-256 hash in `token` (used for authentication) and the
     * plaintext in `token_plain` (used only for display on the integrations
     * page).  A delete-then-insert pattern avoids needing DB-specific UPSERT.
     *
     * @param  int    $userId
     * @return string The new plaintext token value.
     */
    public function regenerateForUser(int $userId): string
    {
        $plainToken = bin2hex(random_bytes(32));
        $hashToken  = hash('sha256', $plainToken);

        $this->db->startTransaction();

        // Remove the old row first so the unique constraints are satisfied
        $this->db->table(self::TABLE)->eq('user_id', $userId)->remove();

        $inserted = $this->db->table(self::TABLE)->insert([
            'user_id'     => $userId,
            'token'       => $hashToken,
            'token_plain' => $plainToken,
            'created_at'  => time(),
        ]);

        if (!$inserted) {
            // Rollback and restore caller context — old token remains valid
            $this->db->cancelTransaction();
            throw new \RuntimeException('Failed to persist new MCP token; previous token remains active.');
        }

        $this->db->closeTransaction();

        return $plainToken;
    }

    /**
     * Validate a token and return the owning user id.
     *
     * Looks up by SHA-256 hash, then uses hash_equals() for constant-time
     * comparison so timing attacks cannot distinguish "wrong token" from
     * "unknown hash".
     *
     * Returns null when the token is unknown or its user is no longer active.
     *
     * @param  string $token  The raw (plaintext) bearer token
     * @return int|null
     */
    public function authenticate(string $token): ?int
    {
        if (empty($token)) {
            return null;
        }

        $lookupHash = hash('sha256', $token);

        $row = $this->db->table(self::TABLE)
            ->eq('token', $lookupHash)
            ->columns('user_id', 'token')
            ->findOne();

        if (empty($row)) {
            return null;
        }

        // Constant-time comparison between the hash we computed and the
        // hash that was stored — prevents timing oracle attacks
        if (!hash_equals($row['token'], $lookupHash)) {
            return null;
        }

        $userId = (int) $row['user_id'];

        // Fail closed: deny if the user account is inactive or missing
        if (!$this->userModel->isActive($userId)) {
            return null;
        }

        return $userId;
    }
}
