<?php

declare(strict_types=1);

namespace Core;

final class User
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_USER = 'user';

    private const HIERARCHY = [
        self::ROLE_USER => 1,
        self::ROLE_EDITOR => 2,
        self::ROLE_ADMIN => 3,
    ];

    public function __construct(
        private readonly string $id,
        private readonly string $username,
        private readonly string $role,
        private readonly ?string $language = null,
        private readonly ?string $theme = null,
        private readonly ?string $avatar = null
    ) {
        if (!isset(self::HIERARCHY[$role])) {
            throw new \InvalidArgumentException("Unknown role: {$role}");
        }
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string)$row['id'],
            (string)$row['username'],
            (string)$row['role'],
            isset($row['language']) ? (string)$row['language'] : null,
            isset($row['theme']) ? (string)$row['theme'] : null,
            isset($row['avatar']) ? (string)$row['avatar'] : null
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    /** Uploaded avatar file name (data/avatars/), null = initials fallback. */
    public function avatar(): ?string
    {
        return $this->avatar !== null && $this->avatar !== '' ? $this->avatar : null;
    }

    /** Active theme slug; empty/null means no theme (master.css only). */
    public function theme(): string
    {
        return $this->theme !== null && $this->theme !== '' ? $this->theme : ThemeRegistry::NO_THEME;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function hasAtLeast(string $role): bool
    {
        return self::HIERARCHY[$this->role] >= self::HIERARCHY[$role];
    }
}
