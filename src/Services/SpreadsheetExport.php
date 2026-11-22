<?php
declare(strict_types=1);
namespace ServiFlow\Services;

use ServiFlow\Support\HttpError;

final class SpreadsheetExport
{
    public static function available(): bool
    {
        return class_exists(\ZipArchive::class) && class_exists(\XMLWriter::class);
    }

    public static function send(string $name, array $headings, array $rows): void
    {
        if (!self::available()) {
            throw new HttpError(503,'XLSX requer as extensões ZIP e XMLWriter.');
        }
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0','UTF-8');
        $xml->startElementNs(null,'worksheet','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->startElement('sheetData');
        foreach ([$headings,...$rows] as $row) {
            $xml->startElement('row');
            foreach ($row as $value) {
                $xml->startElement('c');
                $xml->writeAttribute('t','inlineStr');
                $xml->startElement('is');
                $xml->startElement('t');
                $xml->writeAttribute('xml:space','preserve');
                $xml->text(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',(string)$value));
                $xml->endElement();
                $xml->endElement();
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $parts = [
            '[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Relatório" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml'=>$xml->outputMemory(),
        ];
        $tmp = tempnam(sys_get_temp_dir(),'sf_xlsx_');
        if ($tmp === false) {
            throw new HttpError(503,'Não foi possível criar a planilha.');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp,\ZipArchive::OVERWRITE)!==true) {
                throw new HttpError(503,'Não foi possível criar a planilha.');
            }
            foreach ($parts as $path=>$contents) {
                if (!$zip->addFromString($path,$contents)) {
                    throw new HttpError(503,'Não foi possível gravar a planilha.');
                }
            }
            if (!$zip->close()) {
                throw new HttpError(503,'Não foi possível finalizar a planilha.');
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="'.$name.'.xlsx"');
            readfile($tmp);
        } finally {
            unlink($tmp);
        }
    }
}
