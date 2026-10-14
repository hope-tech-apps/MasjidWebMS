<?php

namespace App\Services\Schools;

use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/** Bounded first-sheet reader. No formulas are evaluated and no ZIP entry is extracted. */
class RosterXlsxReader
{
    public const MAX_FILE_BYTES = 2 * 1024 * 1024;
    public const MAX_ENTRY_BYTES = 8 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 32 * 1024 * 1024;
    public const MAX_ENTRIES = 128;
    public const MAX_ROWS = 20001; // header + 20,000 source rows
    public const MAX_COLUMNS = 128;

    public function read(string $path): array
    {
        if (filesize($path) > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('That file is larger than 2 MB.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('This workbook cannot be read. Upload an unencrypted .xlsx file; .xls and .xlsm files are not supported.');
        }
        try {
            $total = 0;
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new InvalidArgumentException('This workbook has too many ZIP entries (maximum 128).');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += $stat['size'];
                if (($stat['encryption_method'] ?? 0) !== 0 || in_array($stat['name'], ['EncryptedPackage', 'EncryptionInfo'], true)) {
                    throw new InvalidArgumentException('Encrypted workbooks are not supported. Upload an unencrypted .xlsx file.');
                }
                if ($stat['size'] > self::MAX_ENTRY_BYTES || $total > self::MAX_TOTAL_BYTES) {
                    throw new InvalidArgumentException('This workbook expands beyond the safe size limit (8 MB per entry, 32 MB total).');
                }
                if (str_ends_with(strtolower($stat['name']), 'vbaproject.bin')) {
                    throw new InvalidArgumentException('Macro workbooks are not supported. Save as .xlsx and upload that file.');
                }
            }
            $book = $this->xml($zip, 'xl/workbook.xml');
            $sheets = $book->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
            if (! $sheets) {
                throw new InvalidArgumentException('This workbook has no worksheet.');
            }
            $attrs = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $id = (string) ($attrs['id'] ?? '');
            if ($id === '') {
                $attrs = $sheets[0]->attributes('http://purl.oclc.org/ooxml/officeDocument/relationships');
                $id = (string) ($attrs['id'] ?? '');
            }
            $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $target = null;
            foreach ($rels->xpath('//*[local-name()="Relationship"]') as $rel) {
                if ((string) $rel['Id'] === $id && $id !== '') {
                    $candidate = (string) $rel['Target'];
                    if ((string) $rel['TargetMode'] === 'External' || str_contains($candidate, '..') || str_contains($candidate, ':') || str_contains($candidate, '\\')) {
                        throw new InvalidArgumentException('The first worksheet must be inside this workbook.');
                    }
                    $target = str_starts_with($candidate, '/') ? ltrim($candidate, '/') : 'xl/' . $candidate;
                }
            }
            if ($target === null) {
                throw new InvalidArgumentException('The first worksheet could not be found.');
            }
            $shared = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->xpath('//*[local-name()="si"]') as $si) {
                    $shared[] = $this->text($si);
                }
            }
            $dateStyles = [];
            if ($zip->locateName('xl/styles.xml') !== false) {
                $styles = $this->xml($zip, 'xl/styles.xml');
                $formats = [];
                foreach ($styles->xpath('//*[local-name()="numFmt"]') as $format) {
                    // Ignore literal text and colour/locale brackets before looking for date tokens.
                    $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', (string) $format['formatCode']);
                    $formats[(int) $format['numFmtId']] = preg_match('/[ymd]/i', $code) === 1;
                }
                foreach ($styles->xpath('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $i => $xf) {
                    $fmt = (int) $xf['numFmtId'];
                    $dateStyles[$i] = in_array($fmt, array_merge(range(14, 22), range(27, 36), range(50, 58)), true) || ($formats[$fmt] ?? false);
                }
            }
            $props = $book->xpath('//*[local-name()="workbookPr"]');
            $date1904 = in_array((string) ($props[0]['date1904'] ?? ''), ['1', 'true'], true);
            $sheet = $this->xml($zip, $target);
            $rows = [];
            $lastRow = 0;
            foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                $rowNumber = isset($row['r']) ? (int) $row['r'] : $lastRow + 1;
                if ($rowNumber <= $lastRow || $rowNumber > self::MAX_ROWS || count($rows) >= self::MAX_ROWS) {
                    throw new InvalidArgumentException('This workbook exceeds the row limit (20,000 data rows) or has invalid row positions.');
                }
                $lastRow = $rowNumber;
                $cells = [];
                $nextColumn = 0;
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    $ref = (string) $cell['r'];
                    $column = $nextColumn;
                    if ($ref !== '') {
                        if (! preg_match('/^([A-Z]+)([1-9][0-9]*)$/', $ref, $match) || (int) $match[2] !== $rowNumber) {
                            throw new InvalidArgumentException('This workbook has an invalid cell position.');
                        }
                        $column = 0;
                        foreach (str_split($match[1]) as $letter) {
                            $column = $column * 26 + ord($letter) - 64;
                            if ($column > self::MAX_COLUMNS) {
                                throw new InvalidArgumentException('This workbook exceeds the column limit (128).');
                            }
                        }
                        $column--;
                    }
                    if ($column < $nextColumn || $column >= self::MAX_COLUMNS) {
                        throw new InvalidArgumentException('This workbook exceeds the column limit (128) or has duplicate cell positions.');
                    }
                    $nextColumn = $column + 1;
                    $values = $cell->xpath('./*[local-name()="v"]');
                    $value = (string) ($values[0] ?? ''); // cached formula value only
                    $type = (string) $cell['t'];
                    if ($type === 's') {
                        if (! ctype_digit($value) || ! array_key_exists((int) $value, $shared)) {
                            throw new InvalidArgumentException('This workbook contains an unreadable shared string.');
                        }
                        $value = $shared[(int) $value];
                    } elseif ($type === 'inlineStr') {
                        $value = $this->text($cell);
                    } elseif ($type === 'e') {
                        throw new InvalidArgumentException('This workbook contains a spreadsheet error. Correct it before importing.');
                    } elseif (($dateStyles[(int) ($cell['s'] ?? 0)] ?? false) && is_numeric($value) && ! in_array($type, ['b', 'str'], true)) {
                        $days = (int) floor((float) $value);
                        if ($days < 0 || $days > 2958465) {
                            throw new InvalidArgumentException('This workbook contains an invalid date.');
                        }
                        $base = $date1904 ? '1904-01-01' : '1899-12-31';
                        if (! $date1904 && $days >= 60) {
                            $days--; // Excel's fictional leap day
                        }
                        $value = (new \DateTimeImmutable($base, new \DateTimeZone('UTC')))->modify("+{$days} days")->format('Y-m-d');
                    }
                    $cells[$column] = $value;
                }
                $width = $cells === [] ? 0 : max(array_keys($cells)) + 1;
                $rows[] = array_replace(array_fill(0, $width, ''), $cells);
            }
            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        // Read at most one byte past the cap: the declared size in the ZIP directory can lie.
        $source = $zip->getFromName($name, self::MAX_ENTRY_BYTES + 1);
        if ($source !== false && strlen($source) > self::MAX_ENTRY_BYTES) {
            throw new InvalidArgumentException('This workbook expands beyond the safe size limit (8 MB per entry, 32 MB total).');
        }
        if ($source === false || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $source)) {
            throw new InvalidArgumentException('This workbook contains missing or unsafe XML.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            // Detect DTDs through the parser as well, including UTF-16 XML. Neither
            // parser loads a DTD, substitutes entities or accesses the network.
            $reader = new \XMLReader;
            if (! $reader->XML($source, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new InvalidArgumentException('This workbook contains unreadable XML.');
            }
            $reader->setParserProperty(\XMLReader::LOADDTD, false);
            $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
            try {
                while ($reader->read()) {
                    if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                        throw new InvalidArgumentException('This workbook contains missing or unsafe XML.');
                    }
                }
            } finally {
                $reader->close();
            }
            $xml = simplexml_load_string($source, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if ($xml === false) {
                throw new InvalidArgumentException('This workbook contains unreadable XML.');
            }
            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function text(SimpleXMLElement $element): string
    {
        $nodes = $element->xpath('./*[local-name()="t"] | ./*[local-name()="r"]/*[local-name()="t"] | ./*[local-name()="is"]/*[local-name()="t"] | ./*[local-name()="is"]/*[local-name()="r"]/*[local-name()="t"]');
        return implode('', array_map(fn ($t) => (string) $t, $nodes));
    }
}
