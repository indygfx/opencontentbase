<?php

declare(strict_types=1);

namespace Core;

final class AuthController
{
    public function __construct(
        private Auth $auth,
        private View $view,
        private Csrf $csrf,
        private ?Translator $translator = null,
        private ?LoginThrottler $throttler = null
    ) {
    }

    /** @return list<string> */
    private function availableLanguages(): array
    {
        return $this->translator?->availableLanguages() ?? [Translator::FALLBACK];
    }

    public function showLogin(): Response
    {
        $success = null;
        if (trim((string)($_GET['setup'] ?? '')) === 'done') {
            $success = t('core', 'setup_success');
        }
        return Response::html($this->view->render('templates/layout.php', [
            'title' => t('core', 'login_title'),
            'user' => null,
            'csrf' => $this->csrf,
            'content' => $this->view->render('templates/login.php', [
                'csrf' => $this->csrf,
                'error' => null,
                'success' => $success,
            ]),
        ]));
    }

    public function login(): Response
    {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        // Brute-force protection: while the username is blocked, the
        // credentials are not even verified - the block hits correct
        // logins too, so an attacker cannot probe by timing.
        if ($this->throttler !== null) {
            $blockedFor = $this->throttler->isBlocked($username, $ip);
            if ($blockedFor !== null) {
                return $this->loginPage($this->throttleMessage($blockedFor), 429);
            }
        }

        $user = $this->auth->login($username, $password);
        if ($user === null) {
            $this->throttler?->recordFailure($username, $ip);
            return $this->loginPage(t('core', 'login_error'), 401);
        }
        $this->throttler?->recordSuccess($username);
        return Response::redirect('/writing');
    }

    private function throttleMessage(int $seconds): string
    {
        return t('core', 'login_throttled', ['seconds' => (string)$seconds]);
    }

    private function loginPage(string $error, int $status): Response
    {
        return Response::html($this->view->render('templates/layout.php', [
            'title' => t('core', 'login_title'),
            'user' => null,
            'csrf' => $this->csrf,
            'content' => $this->view->render('templates/login.php', [
                'csrf' => $this->csrf,
                'error' => $error,
                'success' => null,
            ]),
        ]), $status);
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
            'title' => t('core', 'profile_title'),
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
            'title' => t('core', 'profile_title'),
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
