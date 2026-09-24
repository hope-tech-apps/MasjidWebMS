<?php

namespace App\Support\AlRaziWebsite;

use RuntimeException;

/**
 * The school website export could not be read.
 *
 * Its message is always written by ExportClient and never includes a response
 * body or a record's contents, so it is the ONE exception the sync may put in the
 * log verbatim. Any other exception is logged by class name only: a database
 * error's message carries the query's bound values, which here are a child's
 * details.
 */
class ExportFailed extends RuntimeException
{
}
