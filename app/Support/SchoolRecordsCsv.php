<?php

namespace App\Support;

/**
 * The mechanics every school-records CSV shares.
 *
 * Extracted rather than repeated because each of these is a defect this project
 * either hit or came within one review of shipping, and a second writer that
 * forgets one is a silently corrupt export.
 */
class SchoolRecordsCsv
{
    /**
     * Open a CSV stream with a UTF-8 BOM.
     *
     * THE BOM IS NOT OPTIONAL HERE. This system's whole point includes Arabic:
     * letter drills are Arabic glyphs by definition, sūrah names are Arabic, and
     * many student names are. `Content-Type: charset=UTF-8` does not survive
     * save-to-disk-and-double-click on Windows Excel, which reads a BOM-less
     * file as CP-1252 and renders every Arabic name as mojibake. The donation
     * export escapes this only because donor names happen to be Latin.
     *
     * @return resource
     */
    public static function open()
    {
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        return $out;
    }

    /**
     * Write one row.
     *
     * The empty `$escape` is deliberate and is NOT the PHP default. PHP's
     * `fputcsv` defaults to a backslash escape, which is not RFC 4180: a cell
     * holding a backslash immediately before a quote is written so the quote is
     * not doubled, and re-parses as garbage. Rare in a donor name; entirely
     * plausible in a lesson plan body or a teacher's comment. On PHP 8.4 the
     * default also raises a deprecation, and in a streamed response a notice is
     * printed INTO the CSV body.
     *
     * @param  resource  $out
     * @param  array<int, mixed>  $row
     */
    public static function row($out, array $row): void
    {
        fputcsv($out, $row, ',', '"', '');
    }

    /**
     * A cell that came from a human and could be read as a formula.
     *
     * Excel and Sheets execute a cell beginning `=`, `+`, `-`, `@` or a control
     * character, so a teacher comment — or an uploaded filename, which the
     * uploader chooses — becomes code in the receiving school's spreadsheet. The
     * leading apostrophe is the standard neutralisation.
     *
     * Use this for ANY free text and for any label a person can author.
     */
    public static function text(mixed $value): string
    {
        $s = (string) ($value ?? '');

        if ($s === '') {
            return '';
        }

        return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'" . $s : $s;
    }

    /**
     * A number, a date or an id — never guarded.
     *
     * `text()` on a negative number turns `-2` into `'-2`, which Excel stores as
     * TEXT: a school totalling a behaviour-points column then gets a silently
     * wrong sum. Behaviour points are legitimately negative, so this split is
     * load-bearing rather than tidy.
     */
    public static function num(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * Walk a query in id order, refusing any query that carries an ORDER BY.
     *
     * `chunkById` rewrites ordering to `id asc` ONLY when an existing order's
     * column string matches the cursor column exactly. Any other order survives
     * as the PRIMARY sort and demotes the id to a tiebreaker — so the cursor
     * reads the last row in THAT order rather than the greatest id, and pages
     * silently skip and duplicate rows. The donation export documents the hazard
     * in a comment; a comment cannot stop a scope, a relation default, or a
     * shared query helper from adding one later.
     *
     * So it is asserted instead, and a violation is a loud failure rather than
     * an export that is quietly short.
     */
    public static function each($query, callable $fn, int $size = 500): void
    {
        $orders = $query->toBase()->orders;

        if (! empty($orders)) {
            throw new \LogicException(
                'A school-records query must carry no ORDER BY: chunkById paginates on the '
                . 'primary key, and any other ordering makes it skip and duplicate rows. '
                . 'Sort in the receiving system, not here.'
            );
        }

        $query->chunkById($size, function ($chunk) use ($fn) {
            foreach ($chunk as $row) {
                $fn($row);
            }
        });
    }
}
