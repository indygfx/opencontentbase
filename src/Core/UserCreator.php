<?php
declare(strict_types=1);

namespace Core;

/**
 * Shared user creation for the setup wizard, the admin UI and the CLI:
 * one validation path (username >= 3 chars, allowed charset, password
 * >= 8 chars, confirm match, duplicate check, role allowlist) and one
 * insert. Callers only decide about transport (HTTP form vs. CLI).
 */
final class UserCreator
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Validates and inserts a user. Returns the new user's id, or null
     * with $error filled when validation fails.
     *
     * @return string|null the new user id, or null on validation error
     */
    public function create(string $username, string $password, string $confirm, string $role, ?string &$error = null): ?string
    {
        $error = $this->validate($username, $password, $confirm, $role);
        if ($error !== null) {
            return null;
        }
        $id = Auth::uuid4();
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            [$id, $username, password_hash($password, PASSWORD_BCRYPT), $role]
        );
        return $id;
    }

    /** Validation only - shared with the setup wizard and the admin UI. */
    public function validate(string $username, string $password, string $confirm, string $role): ?string
    {
        if ($username === '' || mb_strlen($username) < 3) {
            return t('core', 'users_error_username_short');
        }
        if (!preg_match('/^[\p{L}\p{Nd}_.-]+$/u', $username)) {
            return t('core', 'users_error_username_chars');
        }
        if ($password !== $confirm) {
            return t('core', 'users_error_confirm');
        }
        if (strlen($password) < 8) {
            return t('core', 'users_error_password');
        }
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_EDITOR, User::ROLE_USER], true)) {
            return t('core', 'users_error_role');
        }
        $existing = $this->db->one('SELECT id FROM users WHERE username = ?', [$username]);
        if ($existing !== null) {
            return t('core', 'users_error_duplicate');
        }
        return null;
    }
}
