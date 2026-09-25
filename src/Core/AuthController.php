<?php

declare(strict_types=1);

namespace Core;

final class AuthController
{
    public function __construct(private Auth $auth, private View $view)
    {
    }

    public function showLogin(): Response
    {
        return Response::html($this->view->render('templates/layout.php', [
            'title' => 'Anmelden',
            'user' => null,
            'content' => $this->view->render('templates/login.php'),
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
                'content' => $this->view->render('templates/login.php', [
                    'error' => 'Benutzername oder Passwort falsch.',
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
}
