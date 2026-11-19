<?php

namespace Tests\Concerns;

use ZipArchive;

/** Gera um .xlsx de verdade (zip + XML) para testar a leitura de planilhas. */
trait BuildsXlsx
{
    /**
     * Strings viram "shared strings" (como o Excel grava); números e datas
     * (número de série) viram células numéricas.
     *
     * @param  array<int, array<int, string|int|float|array{raw:string}>>  $rows
     */
    protected function xlsx(array $rows): string
    {
        $shared = [];
        $sheetRows = '';
        foreach ($rows as $r => $cells) {
            $xmlCells = '';
            foreach ($cells as $c => $value) {
                $ref = chr(65 + $c).($r + 1);
                if (is_array($value)) {
                    // ['raw' => '3.5997685898E10']: célula numérica gravada
                    // exatamente assim (como o Excel faz com telefone digitado como número).
                    $xmlCells .= "<c r=\"{$ref}\"><v>{$value['raw']}</v></c>";
                } elseif (is_string($value)) {
                    $index = array_search($value, $shared, true);
                    if ($index === false) {
                        $shared[] = $value;
                        $index = count($shared) - 1;
                    }
                    $xmlCells .= "<c r=\"{$ref}\" t=\"s\"><v>{$index}</v></c>";
                } else {
                    $xmlCells .= "<c r=\"{$ref}\"><v>{$value}</v></c>";
                }
            }
            $sheetRows .= '<row r="'.($r + 1).'">'.$xmlCells.'</row>';
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Clientes" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s, ENT_XML1).'</t></si>', $shared)).'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();

        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }
}
