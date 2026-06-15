<?php
declare(strict_types=1);

namespace App;

final class CsvUtil
{
    public static function ensureCsv(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'csv') return $path;

        $csv = preg_replace('/\.[^.]+$/', '.csv', $path);
        if (!is_file($csv)) {
            if (!is_file($path)) throw new \RuntimeException("Eingabedatei nicht gefunden: $path");
            if (!@copy($path, $csv)) throw new \RuntimeException("Konnte $path nicht nach $csv kopieren.");
        }
        return $csv;
    }

    public static function detectEncoding(string $path): string
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) return 'UTF-8';
        $chunk = fread($fh, 4096) ?: '';
        fclose($fh);

        if (str_starts_with($chunk, "\xEF\xBB\xBF")) return 'UTF-8';
        if (str_starts_with($chunk, "\xFF\xFE"))     return 'UTF-16LE';
        if (str_starts_with($chunk, "\xFE\xFF"))     return 'UTF-16BE';

        $enc = mb_detect_encoding(
            $chunk,
            ['UTF-8','UTF-16LE','UTF-16BE','Windows-1252','ISO-8859-1','ISO-8859-15'],
            true
        );
        return $enc ?: 'Windows-1252'; // Praxisdefault für „ANSI“ unter Windows
    }

    public static function detectDelimiter(string $path, string $enc, ?string $forced): string
    {
        if ($forced !== null && $forced !== '') return $forced;

        $fh = @fopen($path, 'rb');
        if (!$fh) return ';';
        if (strcasecmp($enc, 'UTF-8') !== 0) {
            // Datei-Stream live nach UTF-8 wandeln
            @stream_filter_append($fh, 'convert.iconv.' . $enc . '/UTF-8', STREAM_FILTER_READ);
        }
        $line = fgets($fh, 8192) ?: '';
        fclose($fh);

        if (!mb_check_encoding($line, 'UTF-8')) {
            $line = @mb_convert_encoding($line, 'UTF-8', 'Windows-1252,ISO-8859-1,ISO-8859-15') ?: $line;
        }

        $cands = [';', ',', "\t", '|'];
        $best = ';'; $bestCount = -1;
        foreach ($cands as $d) {
            $cnt = substr_count($line, $d);
            if ($cnt > $bestCount) { $best = $d; $bestCount = $cnt; }
        }
        return $best;
    }

    /** UTF-8-sicheres Rohlesen für Mapping und Erzeugung. */
    public static function readRowsRaw(string $csvPath, string $enc, string $delimiter, ?int $limit = null): array
    {
        $fh = @fopen($csvPath, 'rb');
        if (!$fh) throw new \RuntimeException("CSV nicht lesbar: $csvPath");

        if (strcasecmp($enc, 'UTF-8') !== 0) {
            // robuste Konvertierung des Streams nach UTF-8 (funktioniert auch für UTF-16)
            if (@stream_filter_append($fh, 'convert.iconv.' . $enc . '/UTF-8', STREAM_FILTER_READ) === false) {
                // Fallback: Ganzdatei konvertieren
                $raw = (string)@file_get_contents($csvPath);
                $utf = @mb_convert_encoding($raw, 'UTF-8', $enc) ?: $raw;
                $tmp = fopen('php://temp', 'r+');
                fwrite($tmp, $utf);
                rewind($tmp);
                fclose($fh);
                $fh = $tmp;
            }
        }

        $delimChar = ($delimiter === '\t' || $delimiter === "\\t") ? "\t" : $delimiter;
        $rows = [];
        $count = 0;

        while (($cols = fgetcsv($fh, 0, $delimChar)) !== false) {
            foreach ($cols as &$c) {
                $c = (string)$c;
                if (!mb_check_encoding($c, 'UTF-8')) {
                    $c = @mb_convert_encoding($c, 'UTF-8', 'Windows-1252,ISO-8859-1,ISO-8859-15') ?: $c;
                }
                // Steuerzeichen entfernen (außer Tab/CR/LF)
                $c = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $c);
            }
            unset($c);

            $rows[] = $cols;
            $count++;
            if ($limit !== null && $count >= $limit) break;
        }
        fclose($fh);
        return $rows;
    }

    /** Optional weiter nutzbar: säubert Spalten und entfernt leere. */
    public static function readLabels(string $csvPath, string $enc, string $delimiter): array
    {
        $rows = [];
        foreach (self::readRowsRaw($csvPath, $enc, $delimiter, null) as $cols) {
            $clean = [];
            foreach ($cols as $c) {
                $c = trim($c);
                if ($c !== '') $clean[] = $c;
            }
            if ($clean) $rows[] = $clean;
        }
        return $rows;
    }
}
