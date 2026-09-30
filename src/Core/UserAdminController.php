<?php
declare(strict_types=1);

namespace Core;

final class UserAdminController
{
    public function __construct(
        private Database $db,
        private Auth $auth,
        private View $view,
        private Csrf $csrf
    ) {
    }

    public function index(User $user): Response
    {
        return $this->page($user, [
            'error' => null,
            'success' => null,
        ]);
    }

    public function store(User $user): Response
    {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');
        $role = (string)($_POST['role'] ?? User::ROLE_USER);

        $error = $this->validate($username, $password, $confirm, $role);
        if ($error !== null) {
            return $this->page($user, ['error' => $error], 422);
        }
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            [Auth::uuid4(), $username, password_hash($password, PASSWORD_BCRYPT), $role]
        );
        return $this->page($user, ['success' => "User \"{$username}\" created."]);
    }

    public function updateRole(User $user, string $id): Response
    {
        $role = (string)($_POST['role'] ?? '');
        $target = $this->findUser($id);
        if ($target === null) {
            return $this->page($user, ['error' => 'User not found.'], 404);
        }
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_EDITOR, User::ROLE_USER], true)) {
            return $this->page($user, ['error' => 'Invalid role.'], 422);
        }
        if ($target['username'] === 'admin' && $role !== User::ROLE_ADMIN) {
            return $this->page($user, ['error' => 'The admin role cannot be removed from the main admin.'], 422);
        }
        if ($target['id'] === $user->id() && $role !== $user->role()) {
            return $this->page($user, ['error' => 'You cannot change your own role.'], 422);
        }
        $this->db->run('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
        return $this->page($user, ['success' => 'Role updated.']);
    }

    public function resetPassword(User $user, string $id): Response
    {
        $target = $this->findUser($id);
        if ($target === null) {
            return $this->page($user, ['error' => 'User not found.'], 404);
        }
        $password = (string)($_POST['password_new'] ?? '');
        if (strlen($password) < 8) {
            return $this->page($user, ['error' => 'The new password must be at least 8 characters long.'], 422);
        }
        $this->db->run(
            'UPDATE users SET password = ? WHERE id = ?',
            [password_hash($password, PASSWORD_BCRYPT), $id]
        );
        $this->db->run('DELETE FROM sessions WHERE user_id = ?', [$id]);
        return $this->page($user, ['success' => 'Password reset; all of the user\'s sessions have been signed out.']);
    }

    public function destroy(User $user, string $id): Response
    {
        $target = $this->findUser($id);
        if ($target === null) {
            return $this->page($user, ['error' => 'User not found.'], 404);
        }
        if ($target['id'] === $user->id()) {
            return $this->page($user, ['error' => 'You cannot delete your own account.'], 422);
        }
        if ($target['username'] === 'admin') {
            return $this->page($user, ['error' => 'The main admin cannot be deleted.'], 422);
        }
        $this->db->run('DELETE FROM users WHERE id = ?', [$id]);
        return $this->page($user, ['success' => 'User deleted.']);
    }

    private function validate(string $username, string $password, string $confirm, string $role): ?string
    {
        if ($username === '' || mb_strlen($username) < 3) {
            return 'The username must be at least 3 characters long.';
        }
        if (!preg_match('/^[\p{L}\p{Nd}_.-]+$/u', $username)) {
            return 'The username may only contain letters, numbers, underscore, dot and hyphen.';
        }
        if ($password !== $confirm) {
            return 'The passwords do not match.';
        }
        if (strlen($password) < 8) {
            return 'The password must be at least 8 characters long.';
        }
        if (!in_array($role, [User::ROLE_ADMIN, User::ROLE_EDITOR, User::ROLE_USER], true)) {
            return 'Invalid role.';
        }
        $existing = $this->db->one('SELECT id FROM users WHERE username = ?', [$username]);
        if ($existing !== null) {
            return 'This username is already taken.';
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function findUser(string $id): ?array
    {
        return $this->db->one(
            'SELECT id, username, role, created_at FROM users WHERE id = ?',
            [$id]
        );
    }

    /** @param array<string, mixed> $flash */
    private function page(User $user, array $flash, int $status = 200): Response
    {
        $users = $this->db->all(
            'SELECT u.id, u.username, u.role, u.created_at,
                    (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.expires_at > ?) AS active_sessions
             FROM users u
             ORDER BY u.username'
        );
        $content = $this->view->render('templates/users.php', [
            'users' => $users,
            'currentUserId' => $user->id(),
            'csrf' => $this->csrf,
            'error' => $flash['error'] ?? null,
            'success' => $flash['success'] ?? null,
        ]);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => 'User management',
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), $status);
    }
}
