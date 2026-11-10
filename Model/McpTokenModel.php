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

    public const CONFIG_ROTATION_ENABLED = 'mcp_token_rotation_enabled';

    public const CONFIG_ROTATION_DAYS = 'mcp_token_rotation_days';

    public const ROTATION_EVENT = 'mcp.token.rotation';

    public const ROTATION_WARNING_DAYS = 14;

    /** @var int[] */
    public const ROTATION_DAY_OPTIONS = [30, 60, 90, 180, 360];

    private $authenticationExpired = false;

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

        $createdAt = time();
        $inserted = $this->db->table(self::TABLE)->insert([
            'user_id'               => $userId,
            'token'                 => $hashToken,
            'created_at'            => $createdAt,
            'expires_at'            => $this->expiryFrom($createdAt),
            'rotation_notified_on'  => null,
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
        $this->authenticationExpired = false;

        if (empty($token)) {
            return null;
        }

        $lookupHash = hash('sha256', $token);

        $row = $this->db->table(self::TABLE)
            ->eq('token', $lookupHash)
            ->columns('user_id', 'token', 'expires_at', 'created_at', 'rotation_notified_on')
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

        if ($this->rotationEnabled()) {
            $expiresAt = (int) ($row['expires_at'] ?? 0);
            if ($expiresAt > 0 && time() >= $expiresAt) {
                $this->authenticationExpired = true;
                $this->notifyUser($userId);
                return null;
            }
            $this->notifyUser($userId);
        }

        return $userId;
    }

    public function authenticationExpired(): bool
    {
        return $this->authenticationExpired;
    }

    public function rotationEnabled(): bool
    {
        return $this->configModel->getOption(self::CONFIG_ROTATION_ENABLED, '0') === '1';
    }

    public function rotationDays(): int
    {
        $days = (int) $this->configModel->getOption(self::CONFIG_ROTATION_DAYS, '90');

        return in_array($days, self::ROTATION_DAY_OPTIONS, true) ? $days : 90;
    }

    /**
     * @return int|null Unix time, or null when this user has no expiry
     */
    public function expiresAtForUser(int $userId): ?int
    {
        if (!$this->rotationEnabled()) {
            return null;
        }

        $row = $this->db->table(self::TABLE)
            ->eq('user_id', $userId)
            ->findOne();
        if (empty($row) || !isset($row['expires_at']) || $row['expires_at'] === null || $row['expires_at'] === '') {
            return null;
        }

        return (int) $row['expires_at'];
    }

    public function saveRotation(bool $enabled, int $days): void
    {
        if (!in_array($days, self::ROTATION_DAY_OPTIONS, true)) {
            throw new \InvalidArgumentException('Invalid MCP token rotation period');
        }

        $this->configModel->save([
            self::CONFIG_ROTATION_ENABLED => $enabled ? '1' : '0',
            self::CONFIG_ROTATION_DAYS => (string) $days,
        ]);
        $this->rewriteExpiry($enabled, $days);

        if (!$enabled) {
            $this->db->table('user_has_unread_notifications')
                ->eq('event_name', self::ROTATION_EVENT)
                ->remove();
            return;
        }

        $rows = $this->db->table(self::TABLE)->columns('user_id')->findAll();
        foreach ($rows as $row) {
            $this->notifyUser((int) $row['user_id']);
        }
    }

    /**
     * Add one My notifications item when this user's token is inside the
     * 14-day window. An unread item for the same expiry is left in place.
     * A cleared item is sent again on a later day.
     */
    public function notifyUser(int $userId): void
    {
        if ($userId <= 0 || !$this->rotationEnabled() || !$this->userModel->isActive($userId)) {
            return;
        }

        $row = $this->db->table(self::TABLE)->eq('user_id', $userId)->findOne();
        if (empty($row)) {
            return;
        }

        $expiresAt = (int) ($row['expires_at'] ?? 0);
        if ($expiresAt <= 0 || time() < $expiresAt - self::ROTATION_WARNING_DAYS * 86400) {
            return;
        }

        $existing = $this->db->table('user_has_unread_notifications')
            ->eq('user_id', $userId)
            ->eq('event_name', self::ROTATION_EVENT)
            ->findAll();
        foreach ($existing as $event) {
            $data = json_decode((string) ($event['event_data'] ?? ''), true);
            if (is_array($data) && (int) ($data['expires_at'] ?? 0) === $expiresAt) {
                return;
            }
        }

        $notifiedOn = (int) ($row['rotation_notified_on'] ?? 0);
        if ($notifiedOn >= strtotime('today')) {
            return;
        }

        $date = date('Y-m-d', $expiresAt);
        if (time() >= $expiresAt) {
            $message = t('Your MCP token expired on %s. Generate a new one in Settings → Integrations.', $date);
        } else {
            $message = t('Your MCP token expires on %s. Generate a new one in Settings → Integrations.', $date);
        }

        $this->userUnreadNotificationModel->create($userId, self::ROTATION_EVENT, [
            'message' => $message,
            'expires_at' => $expiresAt,
        ]);
        $this->db->table(self::TABLE)->eq('user_id', $userId)->update([
            'rotation_notified_on' => time(),
        ]);
    }

    private function expiryFrom(int $createdAt): ?int
    {
        if (!$this->rotationEnabled()) {
            return null;
        }

        return $createdAt + $this->rotationDays() * 86400;
    }

    private function rewriteExpiry(bool $enabled, int $days): void
    {
        $rows = $this->db->table(self::TABLE)->columns('user_id', 'created_at')->findAll();
        foreach ($rows as $row) {
            $this->db->table(self::TABLE)->eq('user_id', (int) $row['user_id'])->update([
                'expires_at' => $enabled ? (int) $row['created_at'] + $days * 86400 : null,
                'rotation_notified_on' => null,
            ]);
        }
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
