<?php
declare(strict_types=1);
namespace ServiFlow\Services;

final class Documents
{
    public static function pdf(string $name, string $content): void
    {
        $options = new \Dompdf\Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new \Dompdf\Dompdf($options);
        $pdf->loadHtml('<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><style>body{font-family:DejaVu Sans;color:#21334b;font-size:11pt} h1{font-size:23pt}table{width:100%;border-collapse:collapse}td,th{text-align:left;border-bottom:1px solid #d8e3eb;padding:8px}p{white-space:pre-wrap}</style><body>'.$content.'</body></html>','UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$name.'.pdf"');
        echo $pdf->output();
    }

    public static function csv(string $name, array $columns, iterable $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$name.'.csv"');
        $out = fopen('php://output','wb');
        fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,$columns,';', '"', '');
        foreach ($rows as $row) {
            $safe = array_map(static function ($value): string {
                $value = (string) $value;
                return preg_match('/^[\x00-\x20]*[=+@-]|^[\r\n\t]/',$value) ? "'".$value : $value;
            },$row);
            fputcsv($out,$safe,';', '"', '');
        }
        fclose($out);
    }
}
