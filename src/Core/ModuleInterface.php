<?php

declare(strict_types=1);

namespace Core;

interface ModuleInterface
{
    public function id(): string;

    /** @return list<array{method: string, pattern: string, handler: callable, roles: list<string>}> */
    public function routes(Router $router): array;

    /** @return list<callable(Database): void> */
    public function migrations(): array;

    /** @return list<string> */
    public function contentTypes(): array;

    /** @return array{url: string}|null */
    public function resolveLink(Database $db, string $slug): ?array;

    /**
     * Resolves multiple slugs in one batch (IN query instead of N+1).
     *
     * @param list<string> $slugs
     * @return array<string, array{url: string}|null> Map slug => resolution; missing slugs => null
     */
    public function resolveLinks(Database $db, array $slugs): array;

    /**
     * Core hook before deleting a content_object: the module cleans up
     * its own detail table (objects that must not be deleted can abort
     * the deletion by throwing an exception).
     */
    public function onDelete(Database $db, string $uuid): void;
}
