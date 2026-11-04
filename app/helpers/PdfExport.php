<?php
// app/helpers/PdfExport.php
// Turns the same sheets ExcelExport takes into a landscape PDF report, so a
// page offers Excel and PDF from one set of data:
//   PdfExport::download('report.pdf', 'Restaurant sales', 'Mon 1 – Sun 7 Sep 2026',
//       ['Orders' => ['headers' => [...], 'rows' => [[...]], 'money' => [i…], 'totals' => [i…]], …],
//       ['Revenue' => 'KES 12,000', …]);   // optional summary boxes
// Column widths follow their content; long text is cut to fit one line.

class PdfExport
{
    public static function download(string $filename, string $title, string $subtitle, array $sheets, array $summary = []): void
    {
        $pdf = self::build($title, $subtitle, $sheets, $summary);
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    public static function build(string $title, string $subtitle, array $sheets, array $summary = []): string
    {
        if (!class_exists('FPDF')) {
            require_once APP_ROOT . '/vendor/fpdf/fpdf.php';
        }
        $pdf = new \FPDF('L', 'mm', 'A4');
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();
        $W = 277; // usable width

        $shop = TenantContext::tenantId() ? (new Models\TenantModel(Database::pdo()))->find(TenantContext::tenantId()) : null;
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->Cell($W, 8, self::t($shop['name'] ?? ''), 0, 1);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell($W, 6, self::t($title), 0, 1);
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell($W, 5, self::t($subtitle . ' · generated ' . date('D j M Y, g:i a') . ' by ' . ($_SESSION['username'] ?? '')), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);

        if ($summary) {
            $bw = $W / min(6, count($summary));
            $i = 0;
            foreach ($summary as $label => $value) {
                if ($i && $i % 6 === 0) $pdf->Ln(14);
                $x = $pdf->GetX(); $y = $pdf->GetY();
                $pdf->SetFillColor(241, 245, 242);
                $pdf->Rect($x, $y, $bw - 2, 12, 'F');
                $pdf->SetFont('Arial', '', 7.5);
                $pdf->SetTextColor(100, 110, 104);
                $pdf->Cell($bw - 2, 5, self::t(strtoupper($label)), 0, 2);
                $pdf->SetFont('Arial', 'B', 10);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->Cell($bw - 2, 6, self::t((string) $value), 0, 0);
                $pdf->SetXY($x + $bw, $y);
                $i++;
            }
            $pdf->Ln(16);
        }

        foreach ($sheets as $name => $sheet) {
            $headers = $sheet['headers'];
            $rows = $sheet['rows'];
            $money = $sheet['money'] ?? [];
            $totals = $sheet['totals'] ?? [];
            if ($pdf->GetY() > 170) $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell($W, 7, self::t($name . ' (' . count($rows) . ')'), 0, 1);
            if (!$rows) {
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->Cell($W, 6, 'Nothing in this period.', 0, 1);
                $pdf->Ln(3);
                continue;
            }
            $fmt = function ($v, int $i) use ($money) {
                if ($v === null || $v === '') return '';
                return in_array($i, $money, true) && is_numeric($v) ? number_format((float) $v, 2) : (string) $v;
            };
            // Widths from content length (header + up to 200 rows), squeezed to the page.
            $len = array_map(fn($h) => max(4, strlen((string) $h)), $headers);
            foreach (array_slice($rows, 0, 200) as $r) {
                foreach (array_values($r) as $i => $v) $len[$i] = max($len[$i] ?? 4, min(48, strlen($fmt($v, $i))));
            }
            $sumLen = array_sum($len);
            $w = array_map(fn($l) => $W * $l / $sumLen, $len);

            $head = function () use ($pdf, $headers, $w) {
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->SetFillColor(34, 120, 70);
                $pdf->SetTextColor(255, 255, 255);
                foreach ($headers as $i => $h) $pdf->Cell($w[$i], 6, self::fit($pdf, self::t((string) $h), $w[$i]), 1, 0, 'L', true);
                $pdf->Ln();
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('Arial', '', 7.5);
            };
            $head();
            $sum = [];
            foreach ($rows as $n => $r) {
                if ($pdf->GetY() > 192) { $pdf->AddPage(); $head(); }
                $pdf->SetFillColor($n % 2 ? 247 : 255, $n % 2 ? 249 : 255, $n % 2 ? 248 : 255);
                foreach (array_values($r) as $i => $v) {
                    if (in_array($i, $totals, true) && is_numeric($v)) $sum[$i] = ($sum[$i] ?? 0) + (float) $v;
                    $num = is_numeric($v) && (in_array($i, $money, true) || in_array($i, $totals, true));
                    $pdf->Cell($w[$i], 5.5, self::fit($pdf, self::t($fmt($v, $i)), $w[$i]), 1, 0, $num ? 'R' : 'L', true);
                }
                $pdf->Ln();
            }
            if ($totals) {
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->SetFillColor(225, 238, 229);
                foreach ($headers as $i => $h) {
                    $txt = $i === 0 ? 'Total' : (isset($sum[$i]) ? (in_array($i, $money, true) ? number_format($sum[$i], 2) : rtrim(rtrim(number_format($sum[$i], 2), '0'), '.')) : '');
                    $pdf->Cell($w[$i], 6, self::t($txt), 1, 0, $i === 0 ? 'L' : 'R', true);
                }
                $pdf->Ln();
            }
            $pdf->Ln(5);
        }

        return $pdf->Output('S');
    }

    /** UTF-8 → the core fonts' Windows-1252. */
    private static function t(string $s): string
    {
        $out = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
        return $out === false ? $s : $out;
    }

    /** Cut text to fit a cell width. */
    private static function fit(\FPDF $pdf, string $s, float $w): string
    {
        if ($pdf->GetStringWidth($s) <= $w - 2) return $s;
        while ($s !== '' && $pdf->GetStringWidth($s . '...') > $w - 2) $s = substr($s, 0, -1);
        return $s . '...';
    }
}
