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
     * Löst mehrere Slugs in einem Batch auf (IN-Query statt N+1).
     *
     * @param list<string> $slugs
     * @return array<string, array{url: string}|null> Map slug => Auflösung; fehlende Slugs => null
     */
    public function resolveLinks(Database $db, array $slugs): array;

    /**
     * Hook des Core vor dem Löschen eines content_objects: das Modul räumt
     * seine Detail-Tabelle selbst auf (nicht löschbare Objekte verhindern
     * das Löschen durch Werfen einer Exception).
     */
    public function onDelete(Database $db, string $uuid): void;
}
