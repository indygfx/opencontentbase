<?php
declare(strict_types=1);

namespace Core;

/**
 * First-start setup wizard: while the users table is empty, App
 * redirects every request to GET /setup, which shows the "create the
 * first admin account" form. POST /setup creates the first user with
 * role admin (CSRF-protected via the guest token mechanism); once any
 * user exists, both routes return 404 forever. The users table is the
 * single source of truth - no cookies or flags.
 */
final class SetupController
{
    public function __construct(
        private Database $db,
        private View $view,
        private Csrf $csrf
    ) {
    }

    public function show(): Response
    {
        if (!$this->isFirstStart()) {
            return $this->gone();
        }
        return $this->page(t('core', 'setup_title'), ['error' => null]);
    }

    public function create(): Response
    {
        if (!$this->isFirstStart()) {
            return $this->gone();
        }
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');

        $creator = new UserCreator($this->db);
        $error = null;
        $id = $creator->create($username, $password, $confirm, User::ROLE_ADMIN, $error);
        if ($id === null) {
            return $this->page(t('core', 'setup_title'), ['error' => $error], 422);
        }
        return Response::redirect('/login?setup=done');
    }

    private function isFirstStart(): bool
    {
        $row = $this->db->one('SELECT COUNT(*) AS c FROM users');
        return (int)($row['c'] ?? 0) === 0;
    }

    /** Once any user exists the setup route is gone for good. */
    private function gone(): Response
    {
        $content = $this->view->render('templates/error.php', [
            'code' => 404,
            'message' => t('core', 'setup_already_done'),
        ]);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => '404',
            'user' => null,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), 404);
    }

    /** @param array<string, mixed> $data */
    private function page(string $title, array $data, int $status = 200): Response
    {
        $data['csrf'] = $this->csrf;
        $content = $this->view->render('templates/setup.php', $data);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => $title,
            'user' => null,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), $status);
    }
}
