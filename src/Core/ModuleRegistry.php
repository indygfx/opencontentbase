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
}
