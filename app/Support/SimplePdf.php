<?php

namespace App\Support;

class SimplePdf
{
    private const PAGE_WIDTH = 595;

    private const PAGE_HEIGHT = 842;

    private const MARGIN_X = 42;

    private const MARGIN_TOP = 48;

    private const MARGIN_BOTTOM = 48;

    /**
     * @param  array<int, string>  $lines
     */
    public static function make(string $title, array $lines): string
    {
        $pages = [];
        $current = [];
        $y = self::PAGE_HEIGHT - self::MARGIN_TOP;

        $append = function (string $text, int $size = 10, bool $bold = false) use (&$pages, &$current, &$y): void {
            if ($y < self::MARGIN_BOTTOM) {
                $pages[] = $current;
                $current = [];
                $y = self::PAGE_HEIGHT - self::MARGIN_TOP;
            }

            $current[] = [
                'text' => $text,
                'size' => $size,
                'bold' => $bold,
                'x' => self::MARGIN_X,
                'y' => $y,
            ];
            $y -= $size + 7;
        };

        $append($title, 16, true);
        $append('Generato il '.now()->format('d/m/Y H:i'), 9);
        $append('', 8);

        foreach ($lines as $line) {
            foreach (self::wrap($line, 104) as $wrapped) {
                $append($wrapped, 9);
            }
        }

        if ($current !== []) {
            $pages[] = $current;
        }

        return self::render($pages);
    }

    /**
     * @return array<int, string>
     */
    private static function wrap(string $text, int $length): array
    {
        if ($text === '') {
            return [''];
        }

        return explode("\n", wordwrap($text, $length, "\n", true));
    }

    /**
     * @param  array<int, array<int, array{text: string, size: int, bold: bool, x: int, y: int}>>  $pages
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

            $stream = collect($page)
                ->map(fn (array $line): string => sprintf(
                    "BT /%s %d Tf %d %d Td (%s) Tj ET",
                    $line['bold'] ? 'F2' : 'F1',
                    $line['size'],
                    $line['x'],
                    $line['y'],
                    self::escapeText($line['text']),
                ))
                ->implode("\n");

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
