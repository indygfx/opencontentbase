<?php

declare(strict_types=1);

namespace Core;

final class ModuleRegistry
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    public function register(ModuleInterface $module): void
    {
        if (isset($this->modules[$module->id()])) {
            throw new \InvalidArgumentException("Modul {$module->id()} bereits registriert");
        }
        $this->modules[$module->id()] = $module;
    }

    /** @return array<string, ModuleInterface> */
    public function all(): array
    {
        return $this->modules;
    }

    public function has(string $id): bool
    {
        return isset($this->modules[$id]);
    }

    public function get(string $id): ModuleInterface
    {
        if (!isset($this->modules[$id])) {
            throw new \InvalidArgumentException("Unbekanntes Modul: {$id}");
        }
        return $this->modules[$id];
    }

    /**
     * Dispatcht [[type:slug]] an das registrierte Modul, das den ContentType bedient.
     *
     * @return array{url: string}|null
     */
    public function resolveLink(Database $db, string $type, string $slug): ?array
    {
        foreach ($this->modules as $module) {
            if (in_array($type, $module->contentTypes(), true)) {
                return $module->resolveLink($db, $slug);
            }
        }
        return null;
    }

    /**
     * Batch dispatch: collects slugs per content type and resolves them with one
     * Modul-Aufruf (IN-Query) auf, statt pro Link ein resolveLink (N+1).
     *
     * @param array<string, list<string>> $references Map type => slugs
     * @return array<string, array<string, array{url: string}|null>> Map type => (slug => resolution)
     */
    public function resolveLinks(Database $db, array $references): array
    {
        $result = [];
        foreach ($references as $type => $slugs) {
            $slugs = array_values(array_unique($slugs));
            $module = $this->moduleForType($type);
            $result[$type] = $module === null
                ? array_fill_keys($slugs, null)
                : $module->resolveLinks($db, $slugs);
        }
        return $result;
    }

    /**
     * Triggert den onDelete-Hook aller Module, deren ContentTypes der Type bedient,
     * so module detail tables are cleaned up before the core deletion.
     */
    public function notifyDelete(Database $db, string $type, string $uuid): void
    {
        foreach ($this->modules as $module) {
            if (in_array($type, $module->contentTypes(), true)) {
                $module->onDelete($db, $uuid);
            }
        }
    }

    private function moduleForType(string $type): ?ModuleInterface
    {
        foreach ($this->modules as $module) {
            if (in_array($type, $module->contentTypes(), true)) {
                return $module;
            }
        }
        return null;
    }
}
