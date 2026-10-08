<?php

/** Even fixture/setup/assertion failures carry actionable MySQL context. */
function calendarMysqlFailureContext(Throwable $e, string $table, string $column): string
{
    $query = $e;
    while (! $query instanceof \Illuminate\Database\QueryException && $query->getPrevious()) $query = $query->getPrevious();
    $state = 'not-applicable'; $driver = 'not-applicable';
    if ($query instanceof \Illuminate\Database\QueryException) {
        $state = $query->errorInfo[0] ?? 'unknown'; $driver = $query->errorInfo[1] ?? 'unknown';
        if (preg_match('/(?:into|update|from|table)\s+`([^`]+)`/i', $query->getSql(), $match)) $table = $match[1];
        if (preg_match('/(?:column|field) [\'`]([^\'`]+)[\'`]/i', $query->getMessage(), $match)) $column = $match[1];
    }
    return "SQLSTATE=$state driver=$driver table=$table column=$column";
}

function calendarMysqlDiagnostic(callable $operation, string $table, string $column): mixed
{
    try { return $operation(); }
    catch (Throwable $e) {
        throw new RuntimeException(calendarMysqlFailureContext($e, $table, $column).' '.$e->getMessage(), 0, $e);
    }
}
