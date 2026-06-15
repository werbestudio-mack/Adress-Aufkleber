<?php
declare(strict_types=1);

namespace App;

use TCPDF;

final class LabelPdf
{
    // Avery Zweckform 3481: 70 × 41 mm, 3 × 7 auf A4
    private float $labelW = 70.0;  // mm
    private float $labelH = 41.0;  // mm
    private int   $cols   = 3;
    private int   $rows   = 7;

    // Layout
    private float $padX = 3.0;     // mm Innenabstand
    private float $padY = 3.0;
    private float $leftMargin = 0.0;
    private float $topMargin  = 10.0;
    private float $hGap = 0.0;
    private float $vGap = 0.0;

    // Schrift: 'builtin:dejavusans' ODER 'folder:<name>'
    private string $font = 'builtin:dejavusans';
    private float  $fontSize = 9.0;
    private float  $lineHeightRatio = 1.2;

    public function __construct(array $cfg = [])
    {
        foreach ($cfg as $k => $v) {
            if (property_exists($this, $k)) $this->$k = $v;
        }
    }

    /** @param array<int,array<int,string>> $labels */
    public function generateAvery3481(array $labels, string $outPdf): void
    {
        $perPage = $this->cols * $this->rows;

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('labelmaker');
        $pdf->SetTitle('Avery 3481');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $family = $this->resolveFont($pdf);
        $pdf->SetFont($family, '', $this->fontSize);
        $pdf->setCellHeightRatio($this->lineHeightRatio);

        $n = count($labels);
        for ($i = 0; $i < $n; $i++) {
            $slot = $i % $perPage;
            if ($slot === 0 && $i > 0) $pdf->AddPage();

            $r = intdiv($slot, $this->cols);
            $c = $slot % $this->cols;

            $x = $this->leftMargin + $c * ($this->labelW + $this->hGap);
            $y = $this->topMargin  + $r * ($this->labelH + $this->vGap);

            $xText = $x + $this->padX;
            $yText = $y + $this->padY;
            $wText = $this->labelW - 2 * $this->padX;
            $hText = $this->labelH - 2 * $this->padY;

            $text = implode("\n", $labels[$i]);

            $pdf->MultiCell(
                $wText, $hText, $text,
                0, 'L', false, 1, $xText, $yText,
                true, 0, false, true, $hText, 'T', true
            );
        }

        $pdf->Output($outPdf, 'F');
    }

    /** Gibt den zu verwendenden TCPDF-Font-Family-Name zurück. */
    private function resolveFont(TCPDF $pdf): string
    {
        $f = trim((string)$this->font);
        if ($f === '') return 'dejavusans';

        if (str_starts_with($f, 'folder:')) {
            $folder = substr($f, 7);
            return $this->registerFontFromFolder($folder) ?? 'dejavusans';
        }
        if (str_starts_with($f, 'builtin:')) {
            $family = substr($f, 8) ?: 'dejavusans';
            // Warnung: helvetica hat kein volles Unicode
            return $family;
        }
        // Backward-Compat: alter reiner Name
        return $f;
    }

    /** Versucht, einen TTF/OTF aus fonts/<folder>/ zu registrieren. */
    private function registerFontFromFolder(string $folder): ?string
    {
        $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($base)) return null;

        // Preferiere „regular“, sonst erste TTF/OTF
        $candidates = glob($base . DIRECTORY_SEPARATOR . '*.{ttf,otf,TTF,OTF}', GLOB_BRACE) ?: [];
        if (!$candidates) return null;

        usort($candidates, function($a, $b) {
            $ra = preg_match('/regular|normal/i', basename($a));
            $rb = preg_match('/regular|normal/i', basename($b));
            return $rb <=> $ra; // regular zuerst
        });

        foreach ($candidates as $file) {
            // TCPDF-Font registrieren; TrueTypeUnicode ist für Umlaute nötig
            $family = \TCPDF_FONTS::addTTFfont($file, 'TrueTypeUnicode', '', 96);
            if ($family) return $family;
        }
        return null;
    }
}
