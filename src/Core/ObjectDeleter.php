<?php

declare(strict_types=1);

namespace Core;

final class ObjectDeleter
{
    public function __construct(
        private Database $db,
        private ModuleRegistry $registry
    ) {
    }

    /**
     * Löscht ein content_objects-Objekt transaktional: erst der onDelete-Hook
     * des zuständigen Moduls (Aufräumen der Detail-Tabelle), dann der
     * Core-Datensatz. Wirft das Modul, rollt die Transaktion zurück und das
     * Objekt bleibt erhalten.
     */
    public function delete(string $type, string $uuid): void
    {
        $this->db->transaction(function (Database $db) use ($type, $uuid): void {
            $this->registry->notifyDelete($db, $type, $uuid);
            $deleted = $db->run(
                'DELETE FROM content_objects WHERE id = ? AND type = ?',
                [$uuid, $type]
            )->rowCount();
            if ($deleted === 0) {
                throw new \RuntimeException("Objekt {$uuid} (Typ {$type}) nicht gefunden");
            }
        });
    }
}
