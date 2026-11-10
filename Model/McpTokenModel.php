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

    public const REVEAL_SESSION_KEY = 'mcp_token_reveal';

    /**
     * Instance setting: when "1", authenticateToken() ignores $_GET['token'].
     * Missing or "0" keeps the compatibility query-string path.
     */
    public const CONFIG_DISABLE_URL_TOKEN = 'mcp_disable_url_token';

    /**
     * Return true when this user already has a token row.
     * The plaintext is not stored.
     */
    public function hasToken(int $userId): bool
    {
        return $this->db->table(self::TABLE)
            ->eq('user_id', $userId)
            ->exists();
    }

    /**
     * Keep the new plaintext in the session until the integrations page reads it once.
     */
    public function rememberReveal(int $userId, string $plainToken): void
    {
        session_set(self::REVEAL_SESSION_KEY, [
            'user_id' => $userId,
            'token'   => $plainToken,
        ]);
    }

    /**
     * Return the plaintext for this user and remove it from the session.
     */
    public function takeReveal(int $userId): ?string
    {
        if (!session_exists(self::REVEAL_SESSION_KEY)) {
            return null;
        }

        $value = session_get(self::REVEAL_SESSION_KEY);
        session_remove(self::REVEAL_SESSION_KEY);

        if (!is_array($value) || (int) ($value['user_id'] ?? 0) !== $userId) {
            return null;
        }

        $token = $value['token'] ?? '';

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Create or replace the token for a user.
     *
     * Stores only the SHA-256 hash. The plaintext is the return value and is
     * shown once by the caller. A delete-then-insert avoids a DB-specific UPSERT.
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
            'user_id'    => $userId,
            'token'      => $hashToken,
            'created_at' => time(),
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

    /**
     * True when query-string tokens must not authenticate.
     *
     * Reads the settings row directly so a save in the same request is visible.
     */
    public function urlTokensDisabled(): bool
    {
        return $this->configModel->getOption(self::CONFIG_DISABLE_URL_TOKEN, '0') === '1';
    }

    /**
     * Persist the instance-wide URL-token policy. Survives token rotation.
     */
    public function setUrlTokensDisabled(bool $disabled): bool
    {
        return $this->configModel->save([
            self::CONFIG_DISABLE_URL_TOKEN => $disabled ? '1' : '0',
        ]);
    }
}
