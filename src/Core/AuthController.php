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
        private ?LoginThrottler $throttler = null,
        private ?ThemeRegistry $themes = null,
        private ?string $basePath = null
    ) {
    }

    private function images(): ImageStore
    {
        return new ImageStore($this->basePath ?? dirname(__DIR__, 2));
    }

    /** @return list<string> */
    private function availableLanguages(): array
    {
        return $this->translator?->availableLanguages() ?? [Translator::FALLBACK];
    }

    private function themeRegistry(): ThemeRegistry
    {
        return $this->themes ?? new ThemeRegistry(dirname(__DIR__, 2));
    }

    /** @return list<array<string, mixed>> */
    private function availableThemes(): array
    {
        return $this->themeRegistry()->scan();
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
                'availableThemes' => $this->availableThemes(),
                'activeTheme' => $this->themeRegistry()->activeTheme($user),
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
            'availableThemes' => $this->availableThemes(),
            'activeTheme' => $this->themeRegistry()->activeTheme($user),
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

    /** GET /avatars/{user} - serves the stored avatar image of any user (roles: user). */
    public function avatarImage(?User $user, string $targetId): Response
    {
        if ($user === null) {
            return Response::redirect('/login');
        }
        $name = $this->auth->avatarOf($targetId);
        if ($name === null) {
            return $this->notFound();
        }
        $file = $this->images()->path('avatars', $name);
        $mime = $file === null ? null : $this->images()->contentType('avatars', $name);
        if ($file === null || $mime === null) {
            return $this->notFound();
        }
        return new Response((string)file_get_contents($file), 200, ['Content-Type' => $mime]);
    }

    /** POST /profile/avatar - self-service avatar upload (mirrors the character image upload). */
    public function uploadAvatar(?User $user): Response
    {
        if ($user === null) {
            return Response::redirect('/login');
        }
        $file = $_FILES['avatar'] ?? null;
        $ext = $file !== null && is_array($file) ? $this->images()->validateUpload($file) : null;
        $error = match (true) {
            $file === null || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                => t('core', 'profile_avatar_no_file'),
            ($file['size'] ?? 0) > ImageStore::MAX_BYTES
                => t('core', 'profile_avatar_too_big'),
            $ext === null
                => t('core', 'profile_avatar_invalid'),
            default => null,
        };
        if ($error !== null) {
            return $this->profilePage($user, $error, null, 422);
        }
        $old = $this->auth->avatarOf($user->id());
        if ($old !== null) {
            $this->images()->delete('avatars', $old);
        }
        $name = $user->id() . '.' . $ext; // user ids are UUIDs - the name is traversal-safe, store() basenames anyway
        $this->images()->store($file, 'avatars', $name);
        $this->auth->setAvatar($user, $name);
        return $this->profilePage($user, null, t('core', 'avatar_changed'), 200);
    }

    /** POST /profile/avatar/delete - back to the initials fallback. */
    public function removeAvatar(?User $user): Response
    {
        if ($user === null) {
            return Response::redirect('/login');
        }
        $old = $this->auth->avatarOf($user->id());
        if ($old !== null) {
            $this->images()->delete('avatars', $old);
        }
        $this->auth->setAvatar($user, null);
        return $this->profilePage($user, null, t('core', 'avatar_removed'), 200);
    }

    private function profilePage(User $user, ?string $error, ?string $success, int $status): Response
    {
        $user = $this->auth->refreshUser($user);
        $content = $this->view->render('templates/profile.php', [
            'user' => $user,
            'csrf' => $this->csrf,
            'availableLanguages' => $this->availableLanguages(),
            'availableThemes' => $this->availableThemes(),
            'activeTheme' => $this->themeRegistry()->activeTheme($user),
            'error' => $error,
            'success' => $success,
        ]);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => t('core', 'profile_title'),
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), $status);
    }

    private function notFound(): Response
    {
        return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function changeTheme(?User $user): Response
    {
        if ($user === null) {
            return Response::redirect('/login');
        }
        $theme = trim((string)($_POST['theme'] ?? ''));
        $ok = $this->auth->setTheme($user, $theme);
        if ($ok) {
            $user = $this->auth->refreshUser($user); // render with the fresh theme immediately
        }
        $content = $this->view->render('templates/profile.php', [
            'user' => $user,
            'csrf' => $this->csrf,
            'availableLanguages' => $this->availableLanguages(),
            'availableThemes' => $this->availableThemes(),
            'activeTheme' => $this->themeRegistry()->activeTheme($user),
            'error' => $ok ? null : t('core', 'profile_theme_invalid'),
            'success' => $ok ? t('core', 'profile_theme_changed') : null,
        ]);
        return Response::html($this->view->render('templates/layout.php', [
            'title' => t('core', 'profile_title'),
            'user' => $user,
            'csrf' => $this->csrf,
            'content' => $content,
        ]), $ok ? 200 : 422);
    }
}
