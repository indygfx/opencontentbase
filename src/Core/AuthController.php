<?php

declare(strict_types=1);

namespace Core;

final class AuthController
{
    public function __construct(
        private Auth $auth,
        private View $view,
        private Csrf $csrf,
        private ?Translator $translator = null
    ) {
    }

    /** @return list<string> */
    private function availableLanguages(): array
    {
        return $this->translator?->availableLanguages() ?? [Translator::FALLBACK];
    }

    public function showLogin(): Response
    {
        return Response::html($this->view->render('templates/layout.php', [
            t('core', 'login_title'),
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
                t('core', 'login_title'),
                'user' => null,
                'csrf' => $this->csrf,
                'content' => $this->view->render('templates/login.php', [
                    t('core', 'login_error'),
                    'csrf' => $this->csrf,
                ]),
            ]), 401);
        }
        return Response::redirect('/writing');
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
            t('core', 'profile_title'),
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $this->view->render('templates/profile.php', [
                'user' => $user,
                'csrf' => $this->csrf,
                'availableLanguages' => $this->availableLanguages(),
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
            'availableLanguages' => $this->availableLanguages(),
            'error' => $error === null ? null : $error->getMessage(),
            'success' => $error === null ? t('core', 'profile_password_changed') : null,
        ]);
        return Response::html($this->view->render('templates/layout.php', [
            t('core', 'profile_title'),
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), $error === null ? 200 : 422);
    }

    public function changeLanguage(?User $user): Response
    {
        if ($user === null) {
            return Response::redirect('/login');
        }
        $language = trim((string)($_POST['language'] ?? ''));
        $ok = $this->auth->setLanguage($user, $language);
        $content = $this->view->render('templates/profile.php', [
            'user' => $user,
            'csrf' => $this->csrf,
            'availableLanguages' => $this->availableLanguages(),
            'error' => $ok ? null : t('core', 'profile_language_invalid'),
            'success' => $ok ? t('core', 'profile_language_changed') : null,
        ]);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => t('core', 'profile_title'),
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), $ok ? 200 : 422);
    }
}
