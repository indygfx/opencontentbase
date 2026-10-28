<?php
declare(strict_types=1);

namespace Core;

/**
 * Brute-force protection for the login.
 *
 * Per-username choice: the counter and the block apply to the USERNAME,
 * not the IP. Rationale: this app typically runs on small, private
 * hosting behind a single office/home NAT, where an IP block would lock
 * out legitimate users together with the attacker; and targeted
 * attacks on a known account are the realistic threat for a writing
 * tool. The IP is still recorded for forensics (and a future IP rule
 * can be added without schema changes).
 *
 * Rule: 5 failed attempts for a username within 15 minutes block that
 * username for 1 minute; every further 5 failures double the block,
 * capped at 15 minutes (1 -> 2 -> 4 -> 8 -> 15). Only failures from the
 * last 24 hours count; rows older than that are deleted on every
 * write. A successful login clears the username's rows.
 */
final class LoginThrottler
{
    private const WINDOW_MINUTES = 15;
    private const ATTEMPTS_PER_BLOCK = 5;
    private const RETENTION_HOURS = 24;
    private const BLOCK_STEPS = [60, 120, 240, 480, 900];

    public function __construct(private Database $db)
    {
    }

    /**
     * Seconds the username is still blocked for, or null when it is not
     * currently blocked.
     */
    public function isBlocked(string $username, string $ip): ?int
    {
        $latestBlock = $this->db->one(
            'SELECT created_at FROM login_attempts
             WHERE username = ? AND created_at > ?
             ORDER BY created_at DESC LIMIT 1',
            [$username, $this->threshold15MinutesAgo()]
        );
        if ($latestBlock === null) {
            return null;
        }
        // Find the newest row that is part of the blocking streak and
        // determine how many failures the block is based on.
        $blockCount = $this->blockStepFor($username);
        if ($blockCount === 0) {
            return null;
        }
        $blockSeconds = self::BLOCK_STEPS[min(count(self::BLOCK_STEPS) - 1, $blockCount - 1)];
        $blockedUntil = strtotime((string)$latestBlock['created_at'] . ' UTC') + $blockSeconds;
        $remaining = $blockedUntil - time();
        return $remaining > 0 ? $remaining : null;
    }

    /** Records a failed login attempt and prunes old rows. */
    public function recordFailure(string $username, string $ip): void
    {
        $this->db->run(
            'INSERT INTO login_attempts (id, username, ip, created_at) VALUES (?, ?, ?, ?)',
            [Auth::uuid4(), $username, $ip, gmdate('Y-m-d H:i:s')]
        );
        $this->prune();
    }

    /** A successful login clears the username's recorded failures. */
    public function recordSuccess(string $username): void
    {
        $this->db->run('DELETE FROM login_attempts WHERE username = ?', [$username]);
    }

    /**
     * How many blocking units (of ATTEMPTS_PER_BLOCK failures within
     * the window) the username has accumulated.
     */
    private function blockStepFor(string $username): int
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS c FROM login_attempts
             WHERE username = ? AND created_at > ?',
            [$username, $this->threshold15MinutesAgo()]
        );
        $failures = (int)($row['c'] ?? 0);
        if ($failures < self::ATTEMPTS_PER_BLOCK) {
            return 0;
        }
        return (int)floor($failures / self::ATTEMPTS_PER_BLOCK);
    }

    private function threshold15MinutesAgo(): string
    {
        return gmdate('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);
    }

    /** Deletes rows older than the 24 h retention window. */
    private function prune(): void
    {
        $this->db->run(
            'DELETE FROM login_attempts WHERE created_at < ?',
            [gmdate('Y-m-d H:i:s', time() - self::RETENTION_HOURS * 3600)]
        );
    }
}
