<?php
declare(strict_types=1);

namespace Core;

final class Csrf
{
    private const COOKIE = 'cb_csrf';

    public function __construct(private Database $db, private Auth $auth)
    {
    }

    /**
     * Prompt 32: while a session exists, the token stored in sessions.csrf_token
     * is the SINGLE source of truth and the cb_csrf cookie is only a mirror that
     * gets synced to it. (Before, a lost session cookie regenerated a fresh
     * cookie token that no longer matched sessions.csrf_token -> every POST
     * failed with 403 while the persistent session cookie still logged the user
     * in. No rotation: the token stays stable for the whole session lifetime.)
     */
    public function token(): string
    {
        $sessionToken = $this->auth->currentCsrfToken();
        if ($sessionToken !== null) {
            if ($this->cookieValue() !== $sessionToken) {
                $this->setCookieValue($sessionToken);
            }
            return $sessionToken;
        }
        // logged out: cookie-only token (there is no session row to store it in)
        $token = $this->cookieValue();
        if ($token === null) {
            $token = bin2hex(random_bytes(32));
            $this->setCookieValue($token);
        }
        return $token;
    }

    private function cookieValue(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        return is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $token : null;
    }

    private function setCookieValue(string $token): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => 0,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $token;
    }

    public function validate(): bool
    {
        $expected = $this->auth->currentCsrfToken();
        if ($expected === null) {
            $expected = $_COOKIE[self::COOKIE] ?? null;
            if (!is_string($expected) || preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
                return false;
            }
        }
        $given = $_POST['_csrf'] ?? '';
        return is_string($given) && hash_equals($expected, $given);
    }

    public function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e($this->token()) . '">';
    }
}
