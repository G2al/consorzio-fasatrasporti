<?php

namespace App\Support;

class SimplePdf
{
    private const PAGE_WIDTH = 595;

    private const PAGE_HEIGHT = 842;

    private const MARGIN_X = 34;

    private const MARGIN_TOP = 42;

    private const MARGIN_BOTTOM = 42;

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, string>>  $rows
     */
    public static function table(string $title, array $columns, array $rows): string
    {
        $pages = [];
        $page = [];
        $y = self::PAGE_HEIGHT - self::MARGIN_TOP;
        $pageNumber = 1;
        $columnWidths = self::columnWidths(count($columns));

        $startPage = function () use (&$page, &$y, &$pageNumber, $title, $columns, $columnWidths): void {
            $page = [];
            $y = self::PAGE_HEIGHT - self::MARGIN_TOP;

            self::drawHeader($page, $title, $y);
            self::drawTableHeader($page, $columns, $columnWidths, $y);
            $pageNumber++;
        };

        $finishPage = function () use (&$pages, &$page, &$pageNumber): void {
            self::drawFooter($page, $pageNumber - 1);
            $pages[] = $page;
        };

        $startPage();

        if ($rows === []) {
            self::drawText($page, 'Nessun documento disponibile.', self::MARGIN_X, $y, 11, true, [0.32, 0.37, 0.35]);
        }

        foreach ($rows as $index => $row) {
            $wrapped = self::wrapRow($row, $columnWidths);
            $lineCount = max(array_map('count', $wrapped));
            $height = max(34, 15 + ($lineCount * 11));

            if (($y - $height) < self::MARGIN_BOTTOM) {
                $finishPage();
                $startPage();
            }

            $background = $index % 2 === 0 ? [0.98, 0.99, 0.98] : [1, 1, 1];
            self::drawRect($page, self::MARGIN_X, $y - $height + 8, array_sum($columnWidths), $height, $background);

            $x = self::MARGIN_X;
            foreach ($wrapped as $columnIndex => $lines) {
                $textY = $y - 10;
                foreach ($lines as $line) {
                    self::drawText($page, $line, $x + 7, $textY, 8, false, [0.09, 0.13, 0.12]);
                    $textY -= 11;
                }

                if ($columnIndex < count($wrapped) - 1) {
                    self::drawLine($page, $x + $columnWidths[$columnIndex], $y + 8, $x + $columnWidths[$columnIndex], $y - $height + 8, [0.86, 0.9, 0.89]);
                }

                $x += $columnWidths[$columnIndex];
            }

            self::drawLine($page, self::MARGIN_X, $y - $height + 8, self::MARGIN_X + array_sum($columnWidths), $y - $height + 8, [0.86, 0.9, 0.89]);
            $y -= $height;
        }

        $finishPage();

        return self::render($pages);
    }

    /**
     * Compatibility for older call sites.
     *
     * @param  array<int, string>  $lines
     */
    public static function make(string $title, array $lines): string
    {
        return self::table($title, ['Dettagli'], array_map(fn (string $line): array => [$line], $lines));
    }

    private static function drawHeader(array &$page, string $title, int|float &$y): void
    {
        self::drawRect($page, 0, self::PAGE_HEIGHT - 92, self::PAGE_WIDTH, 92, [0.06, 0.45, 0.41]);
        self::drawRect($page, 0, self::PAGE_HEIGHT - 98, self::PAGE_WIDTH, 6, [0.88, 0.66, 0.26]);
        self::drawText($page, 'CONSORZIO FASA TRASPORTI', self::MARGIN_X, self::PAGE_HEIGHT - 36, 10, true, [1, 1, 1]);
        self::drawText($page, $title, self::MARGIN_X, self::PAGE_HEIGHT - 58, 16, true, [1, 1, 1]);
        self::drawText($page, 'Report documentale - '.now()->format('d/m/Y H:i'), self::MARGIN_X, self::PAGE_HEIGHT - 77, 9, false, [0.85, 0.96, 0.94]);
        $y = self::PAGE_HEIGHT - 118;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, int>  $widths
     */
    private static function drawTableHeader(array &$page, array $columns, array $widths, int|float &$y): void
    {
        $height = 24;
        self::drawRect($page, self::MARGIN_X, $y - $height + 8, array_sum($widths), $height, [0.91, 0.96, 0.95]);

        $x = self::MARGIN_X;
        foreach ($columns as $index => $column) {
            self::drawText($page, $column, $x + 7, $y - 8, 8, true, [0.05, 0.36, 0.33]);
            $x += $widths[$index];
        }

        self::drawLine($page, self::MARGIN_X, $y - $height + 8, self::MARGIN_X + array_sum($widths), $y - $height + 8, [0.06, 0.45, 0.41], 1.2);
        $y -= $height + 6;
    }

    private static function drawFooter(array &$page, int $pageNumber): void
    {
        self::drawLine($page, self::MARGIN_X, 30, self::PAGE_WIDTH - self::MARGIN_X, 30, [0.86, 0.9, 0.89]);
        self::drawText($page, 'Consorzio FASA Trasporti', self::MARGIN_X, 18, 8, false, [0.38, 0.45, 0.42]);
        self::drawText($page, 'Pagina '.$pageNumber, self::PAGE_WIDTH - 82, 18, 8, false, [0.38, 0.45, 0.42]);
    }

    /**
     * @return array<int, int>
     */
    private static function columnWidths(int $columns): array
    {
        return match ($columns) {
            3 => [205, 205, 117],
            4 => [150, 170, 137, 70],
            default => array_fill(0, max($columns, 1), (int) floor((self::PAGE_WIDTH - (self::MARGIN_X * 2)) / max($columns, 1))),
        };
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<int, int>  $widths
     * @return array<int, array<int, string>>
     */
    private static function wrapRow(array $row, array $widths): array
    {
        return array_map(
            fn (string $value, int $index): array => self::wrap($value, max(12, (int) floor(($widths[$index] ?? 100) / 4.4))),
            array_values($row),
            array_keys(array_values($row)),
        );
    }

    /**
     * @return array<int, string>
     */
    private static function wrap(string $text, int $length): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['-'];
        }

        return explode("\n", wordwrap($text, $length, "\n", true));
    }

    private static function drawText(array &$page, string $text, int|float $x, int|float $y, int $size = 10, bool $bold = false, array $color = [0, 0, 0]): void
    {
        $page[] = sprintf(
            "%.3F %.3F %.3F rg BT /%s %d Tf %.2F %.2F Td (%s) Tj ET",
            $color[0],
            $color[1],
            $color[2],
            $bold ? 'F2' : 'F1',
            $size,
            $x,
            $y,
            self::escapeText($text),
        );
    }

    private static function drawRect(array &$page, int|float $x, int|float $y, int|float $width, int|float $height, array $color): void
    {
        $page[] = sprintf(
            "%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f",
            $color[0],
            $color[1],
            $color[2],
            $x,
            $y,
            $width,
            $height,
        );
    }

    private static function drawLine(array &$page, int|float $x1, int|float $y1, int|float $x2, int|float $y2, array $color, float $width = 0.5): void
    {
        $page[] = sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S",
            $color[0],
            $color[1],
            $color[2],
            $width,
            $x1,
            $y1,
            $x2,
            $y2,
        );
    }

    /**
     * @param  array<int, array<int, string>>  $pages
     */
    private static function render(array $pages): string
    {
        $objects = [];
        $pageObjectNumbers = [];
        $fontRegularObject = 3;
        $fontBoldObject = 4;
        $nextObject = 5;

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[$fontRegularObject] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$fontBoldObject] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        foreach ($pages as $page) {
            $contentObject = $nextObject++;
            $pageObject = $nextObject++;
            $pageObjectNumbers[] = $pageObject;
            $stream = implode("\n", $page);

            $objects[$contentObject] = "<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream";
            $objects[$pageObject] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $fontRegularObject,
                $fontBoldObject,
                $contentObject,
            );
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $object): string => "{$object} 0 R", $pageObjectNumbers)).'] /Count '.count($pageObjectNumbers).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_keys($objects) as $number) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private static function escapeText(string $text): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) ?: $text;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }
}
