<?php
// app/helpers/ExcelExport.php
// Real .xlsx downloads (Office Open XML, built with ZipArchive — no library).
// Numbers stay numbers (sum/filter in Excel), dates stay text in a sortable
// "Y-m-d H:i:s" form, the header row is bold and frozen.
//
//   ExcelExport::download('sales-2026-10.xlsx', [
//       'Sales' => ['headers' => ['Receipt', 'Date & time', 'Total'], 'rows' => [[...], ...]],
//       'By staff' => [...],                       // more sheets optional
//   ]);

class ExcelExport
{
    /** Send the workbook and exit. $sheets: title => ['headers' => [...], 'rows' => [[...], ...]] */
    public static function download(string $filename, array $sheets): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        self::write($tmp, $sheets);
        while (ob_get_level() > 0) {
            ob_end_clean(); // PathConfig's output buffer must not touch binary output
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.\-]+/', '-', $filename) . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public static function write(string $path, array $sheets): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the Excel file.');
        }
        $names = [];
        foreach (array_keys($sheets) as $i => $title) {
            $t = mb_substr(preg_replace('#[\[\]\*\?/\\\\:]#', ' ', (string) $title), 0, 31) ?: 'Sheet' . ($i + 1);
            while (in_array($t, $names, true)) $t = mb_substr($t, 0, 28) . ' ' . ($i + 1);
            $names[] = $t;
        }
        $n = count($names);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . implode('', array_map(fn($i) => '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, $n)))
            . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            . implode('', array_map(fn($i) => '<sheet name="' . self::esc($names[$i - 1]) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>', range(1, $n)))
            . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(fn($i) => '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>', range(1, $n)))
            . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Styles: 0 normal, 1 bold header (grey fill), 2 money (#,##0.00), 3 bold money (totals row)
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF7"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/></cellXfs>'
            . '</styleSheet>');

        $i = 0;
        foreach ($sheets as $sheet) {
            $i++;
            $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', self::sheetXml($sheet['headers'] ?? [], $sheet['rows'] ?? [], $sheet['money'] ?? [], $sheet['totals'] ?? []));
        }
        $zip->close();
    }

    /**
     * $money: column indexes formatted as money. $totals: column indexes summed
     * in a bold "Total" row under the data.
     */
    private static function sheetXml(array $headers, array $rows, array $money, array $totals): string
    {
        $widths = array_map(fn($h) => max(10, mb_strlen((string) $h) + 2), $headers);
        foreach (array_slice($rows, 0, 300) as $r) {
            foreach (array_values($r) as $c => $v) {
                $widths[$c] = min(60, max($widths[$c] ?? 10, mb_strlen((string) $v) + 2));
            }
        }
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
           . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
           . '<cols>' . implode('', array_map(fn($c, $w) => '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>', array_keys($widths), $widths)) . '</cols>'
           . '<sheetData>';
        $x .= self::row(1, $headers, 1, []);
        $r = 1;
        foreach ($rows as $row) {
            $x .= self::row(++$r, array_values($row), 0, $money);
        }
        if ($totals && $rows) {
            $cells = array_fill(0, count($headers), null);
            $cells[0] = 'Total';
            $out = '<row r="' . ($r + 1) . '">';
            foreach ($cells as $c => $v) {
                $ref = self::col($c) . ($r + 1);
                if (in_array($c, $totals, true)) {
                    $out .= '<c r="' . $ref . '" s="3"><f>SUM(' . self::col($c) . '2:' . self::col($c) . $r . ')</f></c>';
                } elseif ($v !== null) {
                    $out .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . self::esc($v) . '</t></is></c>';
                }
            }
            $x .= $out . '</row>';
        }
        return $x . '</sheetData>'
             . ($headers ? '<autoFilter ref="A1:' . self::col(count($headers) - 1) . max(1, $r) . '"/>' : '')
             . '</worksheet>';
    }

    private static function row(int $r, array $cells, int $style, array $money): string
    {
        $out = '<row r="' . $r . '">';
        foreach ($cells as $c => $v) {
            $ref = self::col($c) . $r;
            if ($v === null || $v === '') {
                continue;
            }
            if ($style === 0 && (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v) && strlen($v) < 15 && !preg_match('/^0\d/', $v)))) {
                $out .= '<c r="' . $ref . '"' . (in_array($c, $money, true) ? ' s="2"' : '') . '><v>' . $v . '</v></c>';
            } else {
                $out .= '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . self::esc($v) . '</t></is></c>';
            }
        }
        return $out . '</row>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function esc($v): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v);
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
