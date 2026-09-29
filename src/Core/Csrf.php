<?php
declare(strict_types=1);

namespace Core;

final class Csrf
{
    private const COOKIE = 'cb_csrf';

    public function __construct(private Database $db, private Auth $auth)
    {
    }

    public function token(): string
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1) {
            return $token;
        }
        $token = bin2hex(random_bytes(32));
        setcookie(self::COOKIE, $token, [
            'expires' => 0,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $token;
        $sessionId = $this->auth->currentSessionId();
        if ($sessionId !== null) {
            $this->db->run(
                'UPDATE sessions SET csrf_token = ? WHERE id = ? AND csrf_token IS NULL',
                [$token, $sessionId]
            );
        }
        return $token;
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
