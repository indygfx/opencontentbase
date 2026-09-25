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
        private readonly string $role
    ) {
        if (!isset(self::HIERARCHY[$role])) {
            throw new \InvalidArgumentException("Unbekannte Rolle: {$role}");
        }
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string)$row['id'],
            (string)$row['username'],
            (string)$row['role']
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

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function hasAtLeast(string $role): bool
    {
        return self::HIERARCHY[$this->role] >= self::HIERARCHY[$role];
    }
}
