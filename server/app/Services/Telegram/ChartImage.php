<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;

/** Exact measurement plot. No browser session, public URL or AI is involved. */
class ChartImage
{
    public const WIDTH = 1280;
    public const HEIGHT = 740;

    public function render(array $chart): string
    {
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        $boldFont = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        if (!extension_loaded('gd') || !function_exists('imagettftext') || !is_file($font)) {
            throw new \RuntimeException('Chart rendering requires GD/FreeType and DejaVu Sans. Rebuild the server image.');
        }
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($image === false) throw new \RuntimeException('Cannot allocate chart image');
        try {
            imageantialias($image, true);
            $color = fn (array $rgb) => imagecolorallocate($image, ...$rgb);
            $white = $color([255, 255, 255]); $ink = $color([25, 39, 59]);
            $muted = $color([106, 122, 145]); $purple = $color([103, 85, 218]);
            $grid = $color([230, 235, 243]); $pause = $color([238, 241, 247]);
            $charging = $color([246, 214, 168]);
            $red = $color([191, 58, 74]);
            imagefill($image, 0, 0, $white);
            $text = function (string $s, int $x, int $y, int $size = 18, ?int $c = null, bool $bold = false) use ($image, $font, $boldFont, $ink): void {
                imagettftext($image, $size, 0, $x, $y, $c ?? $ink, $bold && is_file($boldFont) ? $boldFont : $font, $s);
            };
            $text('Grandma', 38, 45, 20, $ink, true); $text('Health', 184, 45, 20, $red, true);
            $text('Пульс за '.($chart['hours'] ?? 6).' часов', 38, 95, 28);
            $stamp = fn (int $ms, string $format) => CarbonImmutable::createFromTimestampMs($ms, $chart['timezone'])->format($format);
            $text($stamp($chart['from_ms'], 'd.m.Y H:i:s').' — '.$stamp($chart['to_ms'], 'd.m.Y H:i:s').' · '.$chart['timezone'], 38, 129, 17, $muted);
            $s = $chart['report']['pulse'];
            $number = fn ($n) => $n === null ? 'нет данных' : str_replace('.', ',', (string) $n);
            $cards = [['Уникальных замеров', (string) $s['count']],
                ['Диапазон, уд/мин', $s['count'] ? $s['min_bpm'].'–'.$s['max_bpm'] : 'нет данных'],
                ['Среднее полученных замеров', $number($s['mean_bpm'])]];
            foreach ($cards as $i => [$label, $value]) {
                $x = 38 + $i * 406;
                $text($label, $x, 171, 15, $muted); $text($value, $x, 211, 28);
            }
            $left = 80; $right = 1240; $top = 264; $bottom = 584;
            $min = min(40, (int) (floor(($s['min_bpm'] ?? 60) / 10) * 10));
            $max = max(110, (int) (ceil(($s['max_bpm'] ?? 90) / 10) * 10 + 10));
            $xAt = fn (int $at) => (int) round($left + ($at - $chart['from_ms']) / ($chart['to_ms'] - $chart['from_ms']) * ($right - $left));
            $yAt = fn (int $bpm) => (int) round($bottom - ($bpm - $min) / ($max - $min) * ($bottom - $top));
            $points = $chart['points'];
            // Shade long gaps, including empty edges. Never connect across a gap.
            $previous = $chart['from_ms'];
            foreach (array_merge(array_column($points, 0), [$chart['to_ms']]) as $at) {
                if ($at - $previous > $chart['gap_ms']) imagefilledrectangle($image, $xAt($previous), $top, $xAt($at), $bottom, $pause);
                $previous = $at;
            }
            foreach ($chart['charging']['intervals'] ?? [] as [$a, $b]) {
                $a = max($a, $chart['from_ms']); $b = min($b, $chart['to_ms']);
                if ($a < $b) imagefilledrectangle($image, $xAt($a), $top, $xAt($b), $bottom, $charging);
            }
            for ($i = 0; $i <= 4; $i++) {
                $value = (int) round($min + ($max - $min) * $i / 4); $y = $yAt($value);
                imageline($image, $left, $y, $right, $y, $grid); $text((string) $value, 28, $y + 6, 15, $muted);
            }
            $text('уд/мин', 38, 248, 14, $muted);
            imagesetthickness($image, 2);
            foreach ($points as $i => [$at, $bpm]) {
                $x = $xAt($at); $y = $yAt($bpm);
                $chargerBetween = $i > 0 && array_filter($chart['charging']['intervals'] ?? [],
                    fn ($p) => $p[0] < $at && $p[1] > $points[$i - 1][0]) !== [];
                if ($i > 0 && !$chargerBetween && $at - $points[$i - 1][0] <= $chart['gap_ms']) {
                    imageline($image, $xAt($points[$i - 1][0]), $yAt($points[$i - 1][1]), $x, $y, $purple);
                }
                imagefilledellipse($image, $x, $y, 4, 4, $purple);
            }
            imagesetthickness($image, 1);
            for ($i = 0; $i <= 4; $i++) {
                $at = (int) round($chart['from_ms'] + ($chart['to_ms'] - $chart['from_ms']) * $i / 4);
                $x = $xAt($at); $label = $stamp($at, 'H:i');
                $text($label, max(38, min(1180, $x - 26)), 616, 16, $muted);
            }
            if (!$points) $text('В этом периоде нет полученных замеров', 334, 425, 23, $muted);
            imagefilledrectangle($image, 38, 651, 62, 654, $purple); $text('Измеренный пульс', 72, 658, 15, $muted);
            imagefilledrectangle($image, 326, 641, 350, 657, $pause); $text('Пауза без замеров более 10 минут', 360, 658, 15, $muted);
            imagefilledrectangle($image, 870, 641, 894, 657, $charging); $text('Часы на зарядке', 904, 658, 15, $muted);
            $text('Линия соединяет отдельные замеры. Пульс между точками неизвестен.', 38, 699, 15, $muted);
            ob_start();
            try {
                if (!imagepng($image, null, 6)) throw new \RuntimeException('Cannot encode chart PNG');
                return (string) ob_get_contents();
            } finally { ob_end_clean(); }
        } finally { imagedestroy($image); }
    }
}
