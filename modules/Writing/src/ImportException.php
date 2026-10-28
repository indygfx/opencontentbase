<?php
declare(strict_types=1);

namespace Writing;

/**
 * Thrown when a story bundle import fails validation or cannot be read.
 * The message is already translated; the controller shows it on the
 * import form. Nothing is written when this exception is thrown - the
 * importer validates the full bundle before inserting anything.
 */
final class ImportException extends \RuntimeException
{
}
