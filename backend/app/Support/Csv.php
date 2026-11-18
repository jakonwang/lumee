<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV downloads for Excel: UTF-8 BOM, and cells that look like formulas are neutralised. */
class Csv
{
    /**
     * @param  iterable<array>  $rows  generator/collection of rows (streamed, never held in memory at once)
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function cell(mixed $v): string
    {
        $s = match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'yes' : 'no',
            $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i:s'),
            is_array($v) => implode(', ', $v),
            default => (string) $v,
        };

        // =, +, -, @ at the start make spreadsheets evaluate the cell; numbers are fine
        return preg_match('/^[=+\-@\t\r]/', $s) && ! is_numeric($s) ? "'".$s : $s;
    }
}
