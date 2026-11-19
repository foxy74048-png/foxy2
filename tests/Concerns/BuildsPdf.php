<?php

namespace Tests\Concerns;

/**
 * Gera um PDF simples de verdade (texto posicionado, fonte Helvetica,
 * WinAnsi) — como um relatório ou extrato em "tabela" exportado em PDF.
 */
trait BuildsPdf
{
    /**
     * @param  array<int, array<int, string>>  $rows  cada item vira uma linha da página; cada célula, uma coluna
     * @param  array<int, int>  $columnX  posição horizontal de cada coluna (a borda direita, nas colunas alinhadas à direita)
     * @param  array<int, int>  $rightAligned  colunas alinhadas à direita, como os valores num extrato
     */
    protected function pdf(array $rows, array $columnX = [40, 170, 330, 460], array $rightAligned = []): string
    {
        $content = "BT\n/F1 9 Tf\n";
        $y = 800;
        foreach ($rows as $cells) {
            foreach ($cells as $i => $text) {
                if ($text === '') {
                    continue;
                }
                $escaped = strtr(mb_convert_encoding($text, 'Windows-1252', 'UTF-8'), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
                $x = $columnX[$i] ?? (40 + $i * 110);
                if (in_array($i, $rightAligned, true)) {
                    $x -= (int) round(strlen($text) * 5); // ~largura da Helvetica 9
                }
                $content .= "1 0 0 1 {$x} {$y} Tm ({$escaped}) Tj\n";
            }
            $y -= 14;
        }
        $content .= "ET\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
