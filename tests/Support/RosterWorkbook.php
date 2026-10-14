<?php

namespace Tests\Support;

use ZipArchive;

trait RosterWorkbook
{
    private function workbook(string $sheet, array $extra = [], bool $date1904 = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'roster-xlsx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $entries = [
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr date1904="' . ($date1904 ? '1' : '0') . '"/><sheets><sheet name="Roster" sheetId="7" r:id="rId7"/><sheet name="Ignore" sheetId="8" r:id="rId8"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Target="worksheets/roster.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>',
            'xl/worksheets/roster.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheet . '</sheetData></worksheet>',
        ];
        foreach (array_replace($entries, $extra) as $name => $value) {
            $zip->addFromString($name, $value);
        }
        $zip->close();
        return $path;
    }
}
