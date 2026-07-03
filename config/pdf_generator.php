<?php
/**
 * Professional Salary Slip PDF Generator.
 * Creates company-grade salary slip PDFs natively.
 * Company: Hnin AKari nwe
 */
class SalaryPDF {
    private $content = '';
    private $objects = [];
    private $objectIndex = 2;
    private $pageWidth = 595;
    private $pageHeight = 842;
    private $margin = 45;

    // Company Colors
    private $navy = [30, 41, 59];       // Deep navy - primary
    private $royal = [37, 99, 235];     // Royal blue - accent
    private $gold = [217, 119, 6];      // Gold - highlights
    private $dark = [15, 23, 42];
    private $gray = [100, 116, 139];
    private $lightGray = [248, 250, 252];
    private $medGray = [226, 232, 240];
    private $border = [203, 213, 225];
    private $white = [255, 255, 255];
    private $green = [22, 163, 74];
    private $red = [220, 38, 38];

    private static $fontWidths = [
        32=>0.278,33=>0.278,34=>0.355,35=>0.556,36=>0.556,37=>0.889,38=>0.667,
        39=>0.191,40=>0.333,41=>0.333,42=>0.389,43=>0.584,44=>0.278,45=>0.333,
        46=>0.278,47=>0.278,48=>0.556,49=>0.556,50=>0.556,51=>0.556,52=>0.556,
        53=>0.556,54=>0.556,55=>0.556,56=>0.556,57=>0.556,58=>0.278,59=>0.278,
        60=>0.584,61=>0.584,62=>0.584,63=>0.333,64=>0.611,65=>0.667,66=>0.722,
        67=>0.722,68=>0.667,69=>0.611,70=>0.556,71=>0.722,72=>0.722,73=>0.278,
        74=>0.278,75=>0.667,76=>0.556,77=>0.833,78=>0.722,79=>0.722,80=>0.667,
        81=>0.722,82=>0.667,83=>0.611,84=>0.611,85=>0.722,86=>0.667,87=>0.944,
        88=>0.667,89=>0.667,90=>0.611,91=>0.333,92=>0.278,93=>0.333,94=>0.469,
        95=>0.556,96=>0.333,97=>0.5,98=>0.556,99=>0.5,100=>0.556,101=>0.556,
        102=>0.278,103=>0.556,104=>0.556,105=>0.222,106=>0.222,107=>0.5,108=>0.222,
        109=>0.833,110=>0.556,111=>0.556,112=>0.556,113=>0.556,114=>0.306,115=>0.389,
        116=>0.278,117=>0.556,118=>0.5,119=>0.722,120=>0.5,121=>0.5,122=>0.389,
        123=>0.389,124=>0.278,125=>0.556,126=>0.5,
    ];

    public function __construct() {
        $this->objects[1] = '';
        $this->objects[2] = '';
    }

    private function textWidth($text, $size) {
        $w = 0;
        for ($i = 0; $i < strlen($text); $i++) {
            $c = ord($text[$i]);
            $w += (self::$fontWidths[$c] ?? 0.5) * $size;
        }
        return $w;
    }

    private function escapeText($text) {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function color($rgb, $fill = true) {
        $op = $fill ? 'rg' : 'RG';
        return sprintf("%.3f %.3f %.3f %s ", $rgb[0]/255, $rgb[1]/255, $rgb[2]/255, $op);
    }

    private function rect($x, $y, $w, $h, $fill = null, $stroke = null, $strokeWidth = 0.5) {
        $s = '';
        if ($fill) $s .= $this->color($fill);
        if ($stroke) $s .= $this->color($stroke, false) . sprintf("%.2f w ", $strokeWidth);
        $s .= sprintf("n %.2f %.2f %.2f %.2f re ", $x, $this->pageHeight - $y - $h, $w, $h);
        $s .= ($fill && $stroke) ? "B " : ($fill ? "f " : "S ");
        $this->content .= $s;
    }

    private function text($x, $y, $str, $size = 10, $color = null, $align = 'left', $bold = false) {
        $color = $color ?? $this->dark;
        $font = $bold ? '/F2' : '/F1';
        $tw = $this->textWidth($str, $size);
        if ($align === 'center') $x -= $tw / 2;
        elseif ($align === 'right') $x -= $tw;
        $this->content .= "BT ";
        $this->content .= sprintf("%s %d Tf ", $font, $size);
        $this->content .= $this->color($color);
        $this->content .= sprintf("%.2f %.2f Td ", $x, $this->pageHeight - $y);
        $this->content .= sprintf("(%s) Tj ", $this->escapeText($str));
        $this->content .= "ET ";
    }

    private function line($x1, $y1, $x2, $y2, $color = null, $width = 0.5) {
        $color = $color ?? $this->border;
        $this->content .= $this->color($color, false);
        $this->content .= sprintf("%.2f w n %.2f %.2f m %.2f %.2f l S ", $width, $x1, $this->pageHeight - $y1, $x2, $this->pageHeight - $y2);
    }

    // ─── Generate Professional Salary Slip ─────────────
    public function generate($data, $monthName, $year) {
        $cw = $this->pageWidth - 2 * $this->margin;
        $x = $this->margin;
        $y = 30;
        $center = $this->pageWidth / 2;

        // ═══════════════════════════════════════════════
        // TOP BORDER LINE
        // ═══════════════════════════════════════════════
        $this->rect($x, $y, $cw, 4, $this->navy);
        $y += 14;

        // ═══════════════════════════════════════════════
        // COMPANY LOGO + HEADER
        // ═══════════════════════════════════════════════

        // Logo: Stylized "HN" monogram in a shield/badge shape
        $logoX = $center - 22;
        $logoY = $y;
        $logoSize = 44;

        // Shield outline
        $this->content .= $this->color($this->navy);
        $this->content .= sprintf("n %.2f %.2f m ", $logoX + $logoSize/2, $this->pageHeight - $logoY);
        $this->content .= sprintf("%.2f %.2f l ", $logoX + $logoSize, $this->pageHeight - $logoY - 8);
        $this->content .= sprintf("%.2f %.2f l ", $logoX + $logoSize, $this->pageHeight - $logoY - $logoSize * 0.65);
        $this->content .= sprintf("%.2f %.2f l ", $logoX + $logoSize/2, $this->pageHeight - $logoY - $logoSize);
        $this->content .= sprintf("%.2f %.2f l ", $logoX, $this->pageHeight - $logoY - $logoSize * 0.65);
        $this->content .= sprintf("%.2f %.2f l ", $logoX, $this->pageHeight - $logoY - 8);
        $this->content .= "h f ";

        // Inner shield (white)
        $is = $logoSize - 6;
        $ix = $logoX + 3;
        $iy = $logoY + 3;
        $this->content .= $this->color($this->white);
        $this->content .= sprintf("n %.2f %.2f m ", $ix + $is/2, $this->pageHeight - $iy);
        $this->content .= sprintf("%.2f %.2f l ", $ix + $is, $this->pageHeight - $iy - 5);
        $this->content .= sprintf("%.2f %.2f l ", $ix + $is, $this->pageHeight - $iy - $is * 0.65);
        $this->content .= sprintf("%.2f %.2f l ", $ix + $is/2, $this->pageHeight - $iy - $is);
        $this->content .= sprintf("%.2f %.2f l ", $ix, $this->pageHeight - $iy - $is * 0.65);
        $this->content .= sprintf("%.2f %.2f l ", $ix, $this->pageHeight - $iy - 5);
        $this->content .= "h f ";

        // "HN" letters inside shield
        $this->text($logoX + $logoSize/2, $logoY + 10, 'HN', 16, $this->navy, 'center', true);

        // Gold accent bar under logo
        $this->rect($logoX + 8, $logoY + $logoSize + 4, $logoSize - 16, 3, $this->gold);

        $y += $logoSize + 18;

        // Company name
        $this->text($center, $y, 'HNIN AKARI NWE', 22, $this->navy, 'center', true);
        $y += 16;
        $this->text($center, $y, 'Payroll Management System', 9, $this->gray, 'center');
        $y += 12;
        $this->text($center, $y, 'Yangon, Myanmar  |  +95 9 123 456 789  |  info@hninakarinwe.com', 7, $this->gray, 'center');
        $y += 8;

        // Decorative line
        $this->line($x, $y, $x + $cw, $y, $this->navy, 1.5);
        $y += 3;
        $this->line($x, $y, $x + $cw, $y, $this->gold, 0.5);
        $y += 14;

        // ═══════════════════════════════════════════════
        // DOCUMENT TITLE
        // ═══════════════════════════════════════════════
        $this->rect($x, $y, $cw, 28, $this->navy);
        $this->text($center, $y + 9, "SALARY SLIP  —  {$monthName} {$year}", 13, $this->white, 'center', true);
        $y += 42;

        // ═══════════════════════════════════════════════
        // EMPLOYEE INFORMATION BOX
        // ═══════════════════════════════════════════════
        $boxH = 65;
        $this->rect($x, $y, $cw, $boxH, $this->white, $this->border, 0.75);

        // Left column
        $lx = $x + 15;
        $rx = $x + $cw / 2 + 10;

        // Left row 1
        $this->text($lx, $y + 12, 'Employee Name', 7, $this->gray);
        $this->text($lx, $y + 24, $data['name'] ?? '', 11, $this->dark, 'left', true);

        // Right row 1
        $this->text($rx, $y + 12, 'Employee Code', 7, $this->gray);
        $this->text($rx, $y + 24, $data['employee_code'] ?? '', 11, $this->dark, 'left', true);

        // Separator
        $this->line($lx, $y + 33, $x + $cw - 15, $y + 33, $this->medGray, 0.5);

        // Left row 2
        $this->text($lx, $y + 42, 'Department', 7, $this->gray);
        $this->text($lx, $y + 54, $data['department'] ?? 'General', 10, $this->dark);

        // Right row 2
        $this->text($rx, $y + 42, 'Pay Period', 7, $this->gray);
        $this->text($rx, $y + 54, "{$monthName} {$year}", 10, $this->dark, 'left', true);

        $y += $boxH + 16;

        // ═══════════════════════════════════════════════
        // EARNINGS TABLE
        // ═══════════════════════════════════════════════
        // Section header
        $this->rect($x, $y, $cw / 2 - 2, 20, $this->royal);
        $this->text($x + 12, $y + 6, 'EARNINGS', 9, $this->white, 'left', true);
        $y += 24;

        $earnings = [
            ['Basic Salary', $data['basic_salary'] ?? 0],
            ['Overtime Pay', $data['ot_amount'] ?? 0],
            ['Bonuses', $data['bonus_amount'] ?? 0],
        ];

        $rowH = 20;
        $alt = false;
        foreach ($earnings as $i => $e) {
            $bg = $alt ? $this->lightGray : $this->white;
            $this->rect($x, $y, $cw / 2 - 2, $rowH, $bg);
            $this->text($x + 12, $y + 6, $e[0], 9, $this->dark);
            $this->text($x + $cw / 2 - 14, $y + 6, '$' . number_format($e[1], 2), 9, $this->dark, 'right');
            $this->line($x, $y + $rowH, $x + $cw / 2 - 2, $y + $rowH, $this->medGray, 0.25);
            $y += $rowH;
            $alt = !$alt;
        }

        // Gross total
        $this->rect($x, $y, $cw / 2 - 2, 22, $this->lightGray);
        $this->line($x, $y, $x + $cw / 2 - 2, $y, $this->border, 0.75);
        $this->text($x + 12, $y + 6, 'GROSS SALARY', 9, $this->navy, 'left', true);
        $this->text($x + $cw / 2 - 14, $y + 5, '$' . number_format($data['gross_salary'] ?? 0, 2), 11, $this->navy, 'right', true);
        $y += 30;

        // ═══════════════════════════════════════════════
        // DEDUCTIONS TABLE
        // ═══════════════════════════════════════════════
        $y -= 30 + count($earnings) * $rowH - 24 + 22 + 8;
        $dx = $x + $cw / 2 + 2;

        $this->rect($dx, $y, $cw / 2 - 2, 20, $this->red);
        $this->text($dx + 12, $y + 6, 'DEDUCTIONS', 9, $this->white, 'left', true);
        $y += 24;

        $deductions = [
            ['Deductions', $data['deduction_amount'] ?? 0],
        ];

        $alt = false;
        foreach ($deductions as $i => $d) {
            $bg = $alt ? $this->lightGray : $this->white;
            $this->rect($dx, $y, $cw / 2 - 2, $rowH, $bg);
            $this->text($dx + 12, $y + 6, $d[0], 9, $this->dark);
            $this->text($dx + $cw / 2 - 14, $y + 6, '-$' . number_format($d[1], 2), 9, $this->red, 'right');
            $this->line($dx, $y + $rowH, $dx + $cw / 2 - 2, $y + $rowH, $this->medGray, 0.25);
            $y += $rowH;
            $alt = !$alt;
        }

        // Deduction total
        $this->rect($dx, $y, $cw / 2 - 2, 22, $this->lightGray);
        $this->line($dx, $y, $dx + $cw / 2 - 2, $y, $this->border, 0.75);
        $this->text($dx + 12, $y + 6, 'TOTAL DEDUCTIONS', 9, $this->red, 'left', true);
        $this->text($dx + $cw / 2 - 14, $y + 5, '-$' . number_format($data['deduction_amount'] ?? 0, 2), 11, $this->red, 'right', true);

        // ═══════════════════════════════════════════════
        // NET SALARY BOX
        // ═══════════════════════════════════════════════
        $y += 36;
        $this->rect($x, $y, $cw, 40, $this->navy);
        $this->text($x + 20, $y + 14, 'NET SALARY', 14, $this->white, 'left', true);

        // Dollar amount
        $netStr = '$' . number_format($data['net_salary'] ?? 0, 2);
        $this->text($x + $cw - 20, $y + 12, $netStr, 18, $this->gold, 'right', true);

        $y += 54;

        // ═══════════════════════════════════════════════
        // PAYMENT STATUS
        // ═══════════════════════════════════════════════
        $badgeW = 80;
        $badgeX = $center - $badgeW / 2;
        $this->rect($badgeX, $y, $badgeW, 22, $this->green);
        $this->text($center, $y + 6, 'PAYMENT CONFIRMED', 9, $this->white, 'center', true);

        $y += 40;

        // ═══════════════════════════════════════════════
        // SIGNATURE SECTION
        // ═══════════════════════════════════════════════
        $this->line($x, $y, $x + $cw, $y, $this->border, 0.5);
        $y += 12;

        $sigLeft = $x + 60;
        $sigRight = $x + $cw - 60;
        $sigCenter = $x + $cw / 2;

        // Left signature
        $this->line($sigLeft - 40, $y + 30, $sigLeft + 40, $y + 30, $this->dark, 0.5);
        $this->text($sigLeft, $y + 36, "Employee's Signature", 7, $this->gray, 'center');

        // Right signature
        $this->line($sigRight - 40, $y + 30, $sigRight + 40, $y + 30, $this->dark, 0.5);
        $this->text($sigRight, $y + 36, 'Authorized Signature', 7, $this->gray, 'center');

        // Date
        $this->text($center, $y + 36, 'Date: ' . date('d/m/Y'), 7, $this->gray, 'center');

        $y += 52;

        // ═══════════════════════════════════════════════
        // FOOTER
        // ═══════════════════════════════════════════════
        $this->line($x, $y, $x + $cw, $y, $this->navy, 0.5);
        $y += 8;
        $this->text($center, $y, 'This is a computer-generated document and does not require a signature.', 7, $this->gray, 'center');
        $y += 10;
        $this->text($center, $y, 'For inquiries, contact HR Department at hr@hninakarinwe.com', 7, $this->gray, 'center');
        $y += 12;
        $this->text($center, $y, '© ' . date('Y') . ' Hnin AKari nwe. All rights reserved.', 7, $this->gray, 'center');

        return $this->render();
    }

    private function render() {
        $stream = $this->content;
        $helveticaRef = $this->addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
        $helveticaBoldRef = $this->addObject("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");
        $contentRef = $this->addObject(sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($stream), $stream));
        $pageRef = $this->addObject(sprintf(
            "<< /Type /Page /Parent 3 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> >>",
            $this->pageWidth, $this->pageHeight, $contentRef, $helveticaRef, $helveticaBoldRef
        ));
        $this->objects[3] = sprintf("<< /Type /Pages /Kids [%d 0 R] /Count 1 >>", $pageRef);
        $this->objects[1] = "<< /Type /Catalog /Pages 3 0 R >>";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        ksort($this->objects);
        foreach ($this->objects as $num => $obj) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$obj}\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $objCount = max(array_keys($this->objects)) + 1;
        $pdf .= "xref\n0 {$objCount}\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $objCount; $i++) {
            $offset = $offsets[$i] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$objCount} /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";
        return $pdf;
    }

    private function addObject($body) {
        $num = $this->objectIndex++;
        $this->objects[$num] = $body;
        return $num;
    }
}
