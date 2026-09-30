<?php

declare(strict_types=1);

namespace Core;

final class Auth
{
    private const COOKIE = 'cb_session';
    private const LIFETIME_DAYS = 30;

    public function __construct(private Database $db)
    {
    }

    public function login(string $username, string $password): ?User
    {
        $row = $this->db->one(
            'SELECT id, username, password, role FROM users WHERE username = ?',
            [$username]
        );
        if ($row === null || !password_verify($password, (string)$row['password'])) {
            return null;
        }

        $token = $this->randomToken();
        $this->db->run(
            'DELETE FROM sessions WHERE token_hash = ?',
            [hash('sha256', (string)($_COOKIE[self::COOKIE] ?? ''))]
        );
        $csrf = $_COOKIE['cb_csrf'] ?? '';
        $csrf = is_string($csrf) && preg_match('/^[0-9a-f]{64}$/', $csrf) === 1 ? $csrf : null;
        $this->db->run(
            'INSERT INTO sessions (id, user_id, token_hash, expires_at, csrf_token) VALUES (?, ?, ?, ?, ?)',
            [
                self::uuid4(),
                (string)$row['id'],
                hash('sha256', $token),
                gmdate('Y-m-d H:i:s', time() + self::LIFETIME_DAYS * 86400),
                $csrf,
            ]
        );

        $this->setCookie($token, time() + self::LIFETIME_DAYS * 86400);
        return User::fromRow($row);
    }

    public function logout(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($token) && $token !== '') {
            $this->db->run(
                'DELETE FROM sessions WHERE token_hash = ?',
                [hash('sha256', $token)]
            );
        }
        $this->setCookie('', time() - 86400);
    }

    public function user(): ?User
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || $token === '') {
            return null;
        }
        $row = $this->db->one(
            'SELECT u.id, u.username, u.role
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = ?
                AND s.expires_at > ?',
            [hash('sha256', $token), gmdate('Y-m-d H:i:s')]
        );
        return $row === null ? null : User::fromRow($row);
    }

    public function currentSessionId(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || $token === '') {
            return null;
        }
        $row = $this->db->one(
            'SELECT id FROM sessions WHERE token_hash = ?',
            [hash('sha256', $token)]
        );
        return $row === null ? null : (string)$row['id'];
    }

    public function currentCsrfToken(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || $token === '') {
            return null;
        }
        $row = $this->db->one(
            'SELECT csrf_token FROM sessions WHERE token_hash = ?',
            [hash('sha256', $token)]
        );
        return $row === null ? null : (isset($row['csrf_token']) ? (string)$row['csrf_token'] : null);
    }

    public function changePassword(User $user, string $old, string $new, string $confirm): ?\InvalidArgumentException
    {
        if ($new !== $confirm) {
            return new \InvalidArgumentException('The new passwords do not match.');
        }
        if (strlen($new) < 8) {
            return new \InvalidArgumentException('The new password must be at least 8 characters long.');
        }
        $row = $this->db->one('SELECT password FROM users WHERE id = ?', [$user->id()]);
        if ($row === null || !password_verify($old, (string)$row['password'])) {
            return new \InvalidArgumentException('The current password is wrong.');
        }
        if (password_verify($new, (string)$row['password'])) {
            return new \InvalidArgumentException('The new password must differ from the current one.');
        }
        $this->db->run(
            'UPDATE users SET password = ? WHERE id = ?',
            [password_hash($new, PASSWORD_BCRYPT), $user->id()]
        );
        $current = $this->currentSessionId();
        if ($current !== null) {
            $this->db->run(
                'DELETE FROM sessions WHERE user_id = ? AND id != ?',
                [$user->id(), $current]
            );
        }
        return null;
    }

    public function ensureInitialAdmin(): void
    {
        $row = $this->db->one('SELECT COUNT(*) AS c FROM users');
        if ((int)($row['c'] ?? 0) > 0) {
            return;
        }
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            [
                self::uuid4(),
                'admin',
                password_hash('admin', PASSWORD_BCRYPT),
                User::ROLE_ADMIN,
            ]
        );
    }

    private function setCookie(string $token, int $expires): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => $expires,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function randomToken(): string
    {
        return bin2hex(random_bytes(64));
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
