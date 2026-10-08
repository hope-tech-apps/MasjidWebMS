<?php

namespace Tests\Support;

use Illuminate\Database\QueryException;

final class ClassSubjectWorkerFailure
{
    public static function identifiers(QueryException $error): array
    {
        preg_match('/\b(?:into|update|from)\s+[`"]?([a-zA-Z_][a-zA-Z0-9_]*)/i', $error->getSql(), $table);
        preg_match('/\b(?:Field|column)\s+[\'"`]([a-zA-Z_][a-zA-Z0-9_]*)[\'"`]/i', (string) ($error->errorInfo[2] ?? ''), $column);
        return ['sqlstate' => $error->errorInfo[0] ?? null, 'driver_code' => $error->errorInfo[1] ?? null,
            'table' => $table[1] ?? null, 'column' => $column[1] ?? null];
    }
}
