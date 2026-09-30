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
     * Deletes a content_objects entry transactionally: first the onDelete hook
     * of the owning module (cleaning up the detail table), then the
     * core record. If the module throws, the transaction is rolled back and the
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
                throw new \RuntimeException("Object {$uuid} (type {$type}) not found");
            }
        });
    }
}
