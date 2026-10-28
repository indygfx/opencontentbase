<?php
declare(strict_types=1);

namespace Writing;

/**
 * Thrown when the export cannot run because the server environment is
 * not capable of it (e.g. the PHP ZIP extension is not installed).
 * The controller maps this to a friendly, translated error page
 * instead of letting a fatal error escape.
 */
final class ExportUnavailableException extends \RuntimeException
{
}
