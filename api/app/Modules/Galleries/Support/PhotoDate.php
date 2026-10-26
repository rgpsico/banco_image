<?php

namespace App\Modules\Galleries\Support;

use Carbon\CarbonImmutable;

/**
 * Datas da galeria. O "dia" de uma foto é o dia no fuso do negócio (padrão America/Sao_Paulo), não o de UTC:
 * uma foto das 22h de segunda não pode cair em terça só porque em UTC já virou o dia.
 */
class PhotoDate
{
    public static function tz(): string
    {
        return (string) config('mediahub.galleries.timezone', 'America/Sao_Paulo');
    }

    /** Hoje, no fuso do negócio, como YYYY-MM-DD. */
    public static function today(): string
    {
        return CarbonImmutable::now(self::tz())->format('Y-m-d');
    }

    /** Começo e fim (UTC) do dia local informado. Null se a data for inválida. */
    public static function dayRange(string $date): ?array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::tz());
        } catch (\Throwable) {
            return null;
        }

        if (! $start || $start->format('Y-m-d') !== $date) {
            return null;
        }

        return [$start->utc(), $start->addDay()->utc()];
    }

    /** Offset do fuso no formato do MySQL ("-03:00"), para agrupar por dia local. */
    public static function offset(): string
    {
        return CarbonImmutable::now(self::tz())->format('P');
    }

    /** Data/hora em que a foto foi tirada (EXIF), em UTC. Null se não houver ou não for confiável. */
    public static function fromFile(string $path, string $mime): ?CarbonImmutable
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return null;
        }

        $exif = @exif_read_data($path, 'EXIF');
        $raw = is_array($exif) ? ($exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? null) : null;

        if (! is_string($raw) || ! preg_match('/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $raw, $m)) {
            return null;
        }

        try {
            // O relógio da câmera não tem fuso: vale o horário local do negócio.
            $at = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6], self::tz())->utc();
        } catch (\Throwable) {
            return null;
        }

        // Relógio de câmera zerado (1970/2000) ou no futuro não serve.
        return $at->year >= 2000 && $at->lessThanOrEqualTo(CarbonImmutable::now()->addDay()) ? $at : null;
    }
}
