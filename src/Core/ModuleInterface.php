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
}
