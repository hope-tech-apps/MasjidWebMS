<?php

namespace App\Services\Schools;

use InvalidArgumentException;

/** Converts file cells into the existing service's eight-field rows, without interpreting people. */
class RosterFileAdapter
{
    public function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        if (! in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            throw new InvalidArgumentException('Upload a CSV or unencrypted .xlsx file. .xls and .xlsm files are not supported.');
        }
        if (filesize($path) > RosterXlsxReader::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('That file is larger than 2 MB.');
        }
        if ($extension === 'xlsx') {
            $grid = (new RosterXlsxReader)->read($path);
        } else {
            $fh = fopen($path, 'r');
            if ($fh === false) throw new InvalidArgumentException('Cannot read that file.');
            $grid = [];
            try {
                while (($line = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                    if (count($grid) >= RosterXlsxReader::MAX_ROWS || count($line) > RosterXlsxReader::MAX_COLUMNS) {
                        throw new InvalidArgumentException('This file exceeds the limit of 20,000 data rows or 128 columns.');
                    }
                    $grid[] = array_map(fn ($cell) => (string) $cell, $line);
                }
            } finally { fclose($fh); }
        }
        if ($grid === []) throw new InvalidArgumentException('The file is empty.');
        foreach ($grid as $line) {
            foreach ($line as $cell) {
                if (! mb_check_encoding($cell, 'UTF-8')) throw new InvalidArgumentException(RosterImportService::NOT_UTF8);
            }
        }
        $headers = array_shift($grid);
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($headers[0] ?? ''));
        if (count(array_filter($headers, fn ($v) => trim($v) !== '')) === 0) throw new InvalidArgumentException('The file has no header row.');
        // Keep each data row's own row number in the file (header = row 1), so a refusal names the
        // spreadsheet row the office sees, even after blank rows and second-parent splits.
        $data = [];
        $lines = [];
        foreach ($grid as $i => $row) {
            if (count(array_filter($row, fn ($v) => trim($v) !== '')) === 0) continue;
            $data[] = $row;
            $lines[] = $i + 2;
        }
        return compact('headers', 'data', 'lines');
    }

    public function validateMapping(array $headers, array $mapping): array
    {
        $result = [];
        $used = [];
        foreach ((new RosterColumnMatcher)->fields() as $field) {
            $index = $mapping[$field] ?? null;
            if ($index !== null && (! is_int($index) || $index < 0 || $index >= count($headers))) {
                throw new InvalidArgumentException('Choose a file column for ' . str_replace('_', ' ', $field) . '.');
            }
            if ($index !== null && in_array($index, $used, true)) {
                throw new InvalidArgumentException('Each file column can fill only one field.');
            }
            if ($index !== null) $used[] = $index;
            $result[$field] = $index;
        }
        foreach (['class', 'student_first_name'] as $field) {
            if ($result[$field] === null) {
                throw new InvalidArgumentException('Choose a column for ' . str_replace('_', ' ', $field) . ' before previewing.');
            }
        }
        return $result;
    }

    public function rows(array $table, array $mapping): array
    {
        $mapping = $this->validateMapping($table['headers'], $mapping);
        $rows = [];
        foreach ($table['data'] as $i => $cells) {
            // A second parent split from this row reports the same source row.
            $row = [RosterImportService::SOURCE_LINE => (int) ($table['lines'][$i] ?? $i + 2)];
            foreach (RosterImportService::HEADERS as $field) {
                $row[$field] = $this->cell($cells, $mapping[$field]);
            }
            $rows[] = $row;
            $second = [];
            foreach (array_slice(RosterImportService::HEADERS, 4) as $field) {
                $second[$field] = $this->cell($cells, $mapping[str_replace('guardian_', 'guardian_2_', $field)]);
            }
            if (count(array_filter($second, fn ($value) => $value !== '')) > 0) {
                $rows[] = array_replace($row, $second);
            }
        }
        return $rows;
    }

    private function cell(array $cells, ?int $index): string
    {
        return $index === null ? '' : ltrim(trim((string) ($cells[$index] ?? '')), "'");
    }
}
