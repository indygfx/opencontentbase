<?php

declare(strict_types=1);

namespace Core;

final class AuthController
{
    public function __construct(private Auth $auth, private View $view, private Csrf $csrf)
    {
    }

    public function showLogin(): Response
    {
        return Response::html($this->view->render('templates/layout.php', [
            'title' => 'Anmelden',
            'user' => null,
            'csrf' => $this->csrf,
            'content' => $this->view->render('templates/login.php', ['csrf' => $this->csrf]),
        ]));
    }

    public function login(): Response
    {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $user = $this->auth->login($username, $password);
        if ($user === null) {
            return Response::html($this->view->render('templates/layout.php', [
                'title' => 'Anmelden',
                'user' => null,
                'csrf' => $this->csrf,
                'content' => $this->view->render('templates/login.php', [
                    'error' => 'Benutzername oder Passwort falsch.',
                    'csrf' => $this->csrf,
                ]),
            ]), 401);
        }
        return Response::redirect('/pages');
    }

    public function logout(): Response
    {
        $this->auth->logout();
        return Response::redirect('/login');
    }

    public function showProfile(?User $user): Response
    {
        if ($user === null) {
            return Response::redirect('/login');
        }
        return Response::html($this->view->render('templates/layout.php', [
            'title' => 'Profil',
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $this->view->render('templates/profile.php', [
                'user' => $user,
                'csrf' => $this->csrf,
                'error' => null,
                'success' => null,
            ]),
        ]));
    }

    public function changePassword(?User $user): Response
    {
        if ($user === null) {
            return Response::redirect('/login');
        }
        $old = (string)($_POST['password_old'] ?? '');
        $new = (string)($_POST['password_new'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');
        $error = $this->auth->changePassword($user, $old, $new, $confirm);
        $content = $this->view->render('templates/profile.php', [
            'user' => $user,
            'csrf' => $this->csrf,
            'error' => $error === null ? null : $error->getMessage(),
            'success' => $error === null ? 'Passwort geändert. Andere Sitzungen wurden abgemeldet.' : null,
        ]);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => 'Profil',
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), $error === null ? 200 : 422);
    }
}
