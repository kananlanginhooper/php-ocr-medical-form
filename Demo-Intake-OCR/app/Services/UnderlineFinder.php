<?php

namespace App\Services;

use Throwable;

class UnderlineFinder
{
    public function stepContext(string $flow = 'First Name OCR'): array
    {
        return [
            'number' => 2,
            'title' => 'Find the Underline',
            'label' => '2) Find the Underline',
            'flow' => $flow,
        ];
    }

    public function detectUnderlineAfterLabel(string $imagePath, array $labelResult): ?array
    {
        if (!is_file($imagePath)) {
            return null;
        }

        $labelLeft = max(0, (int) ($labelResult['x'] ?? 0));
        $labelRight = max($labelLeft + 1, (int) ($labelResult['x1'] ?? ($labelLeft + (int) ($labelResult['width'] ?? 1))));
        $labelTop = max(0, (int) ($labelResult['y'] ?? 0));
        $labelBottom = max($labelTop + 1, (int) ($labelResult['y1'] ?? ($labelTop + (int) ($labelResult['height'] ?? 1))));
        $labelHeight = max(10, $labelBottom - $labelTop);

        [$img, $imgWidth, $imgHeight] = $this->loadImageForPixelScan($imagePath);
        if (!$img || $imgWidth < 2 || $imgHeight < 2) {
            $x = max(0, $labelRight + 5);
            $x1 = min($x + max(80, (int) round($labelHeight * 8)), $imgWidth > 1 ? $imgWidth - 1 : $x + 80);
            $boxHeight = 15;
            $y = max(0, $labelBottom - (int) round($boxHeight / 2));
            $y1 = $y + $boxHeight;
            return [
                'x' => (int) $x,
                'y' => (int) $y,
                'x1' => (int) $x1,
                'y1' => (int) $y1,
                'width' => (int) ($x1 - $x),
                'height' => (int) ($y1 - $y),
                'confidence' => null,
                'source' => 'underline-estimate-no-pixel-scan',
                'anchors' => [
                    'first_name' => [
                        'x' => (int) $labelLeft,
                        'y' => (int) $labelTop,
                        'x1' => (int) $labelRight,
                        'y1' => (int) $labelBottom,
                    ],
                ],
            ];
        }

        $bestLast = null;
        $cmd = 'tesseract ' . escapeshellarg($imagePath) . ' stdout --psm 6 tsv 2>/dev/null';
        $tsv = shell_exec($cmd);
        if (is_string($tsv) && trim($tsv) !== '') {
            $lines = preg_split('/\r\n|\r|\n/', trim($tsv)) ?: [];
            $rows = [];
            for ($i = 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if ($line === '') continue;
                $parts = explode("\t", $line);
                if (count($parts) < 12) continue;
                $text = trim((string) $parts[11]);
                if ($text === '') continue;

                $left = (int) $parts[6];
                $top = (int) $parts[7];
                $width = (int) $parts[8];
                $height = (int) $parts[9];
                $rows[] = [
                    'text_norm' => strtolower(preg_replace('/[^a-z0-9]/i', '', $text)),
                    'left' => $left,
                    'right' => $left + $width,
                    'top' => $top,
                    'bottom' => $top + $height,
                    'center_y' => $top + ($height / 2),
                ];
            }

            $lastCandidates = [];
            for ($i = 0; $i < count($rows); $i++) {
                $row = $rows[$i];
                if ($row['text_norm'] === 'lastname') {
                    $lastCandidates[] = $row; continue;
                }
                if ($row['text_norm'] === 'last' && isset($rows[$i + 1]) && $rows[$i + 1]['text_norm'] === 'name') {
                    $next = $rows[$i + 1];
                    $lastCandidates[] = [
                        'left' => min($row['left'], $next['left']),
                        'right' => max($row['right'], $next['right']),
                        'top' => min($row['top'], $next['top']),
                        'bottom' => max($row['bottom'], $next['bottom']),
                        'center_y' => (min($row['top'], $next['top']) + max($row['bottom'], $next['bottom'])) / 2,
                    ];
                }
            }

            $firstCenterY = ($labelTop + $labelBottom) / 2;
            $lineTolerance = max(8, (int) round($labelHeight * 0.8));
            foreach ($lastCandidates as $candidate) {
                if ($candidate['left'] <= $labelRight) continue;
                if (abs($candidate['center_y'] - $firstCenterY) > $lineTolerance) continue;
                if ($bestLast === null || $candidate['left'] < $bestLast['left']) {
                    $bestLast = $candidate;
                }
            }
        }

        $xStart = min($imgWidth - 2, max(0, $labelRight + 5));
        $anchorRight = $bestLast !== null
            ? min($imgWidth - 1, max($xStart + 20, (int) $bestLast['left'] - 4))
            : null;
        $xEnd = $anchorRight !== null
            ? $anchorRight
            : min($imgWidth - 1, $xStart + max(220, (int) round($labelHeight * 20)));
        $ySearchStart = max(0, $labelBottom - (int) round($labelHeight * 0.5));
        $ySearchEnd = min($imgHeight - 1, $labelBottom + (int) round($labelHeight * 1.2));

        $bestY = max(0, min($imgHeight - 1, $labelBottom));
        $bestCoverage = -1.0;
        $darkThreshold = 170;
        for ($y = $ySearchStart; $y <= $ySearchEnd; $y++) {
            $coverage = $this->rowDarkCoverageRatio($img, $xStart, $xEnd, $y, $darkThreshold);
            if ($coverage > $bestCoverage) {
                $bestCoverage = $coverage;
                $bestY = $y;
            }
        }

        $gapTolerance = max(2, (int) round($labelHeight * 0.18));
        $currentStart = null;
        $currentEnd = null;
        $currentGap = 0;
        $runStart = $xStart;
        $runEnd = $xStart + 20;
        $bestRunWidth = 0;

        for ($x = $xStart; $x <= $xEnd; $x++) {
            $rgb = imagecolorat($img, $x, $bestY);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $luma = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
            $isDark = $luma < $darkThreshold;

            if ($isDark) {
                if ($currentStart === null) {
                    $currentStart = $x;
                }
                $currentEnd = $x;
                $currentGap = 0;
                continue;
            }

            if ($currentStart !== null) {
                $currentGap++;
                if ($currentGap <= $gapTolerance) continue;
                $width = max(0, $currentEnd - $currentStart);
                if ($width > $bestRunWidth) {
                    $bestRunWidth = $width;
                    $runStart = $currentStart;
                    $runEnd = $currentEnd;
                }
                $currentStart = null;
                $currentEnd = null;
                $currentGap = 0;
            }
        }

        if ($currentStart !== null && $currentEnd !== null) {
            $width = max(0, $currentEnd - $currentStart);
            if ($width > $bestRunWidth) {
                $bestRunWidth = $width;
                $runStart = $currentStart;
                $runEnd = $currentEnd;
            }
        }

        if ($bestRunWidth < 12) {
            $runStart = $xStart;
            $runEnd = max($xStart + 20, min($xEnd, $xStart + max(120, (int) round($labelHeight * 10))));
        }

        if ($anchorRight !== null) {
            $x = max(0, min($imgWidth - 2, $xStart));
            $x1 = max($x + 1, min($imgWidth - 1, $anchorRight));
        } else {
            $x = max(0, min($imgWidth - 2, $runStart));
            $x1 = max($x + 1, min($imgWidth - 1, $runEnd));
        }
        $boxHeight = max(15, (int) round($labelHeight * 0.6));
        $y = max(0, min($imgHeight - 1, $bestY - (int) round($boxHeight / 2)));
        $y1 = min($imgHeight - 1, $y + $boxHeight);
        if (($y1 - $y) < 15) {
            $y = max(0, $y1 - 15);
        }

        return [
            'x' => (int) $x,
            'y' => (int) $y,
            'x1' => (int) $x1,
            'y1' => (int) $y1,
            'width' => (int) ($x1 - $x),
            'height' => (int) ($y1 - $y),
            'confidence' => null,
            'source' => 'underline-pixel-scan',
            'anchors' => [
                'first_name' => [
                    'x' => (int) $labelLeft,
                    'y' => (int) $labelTop,
                    'x1' => (int) $labelRight,
                    'y1' => (int) $labelBottom,
                ],
                'last_name' => [
                    'x' => (int) ($bestLast['left'] ?? 0),
                    'y' => (int) ($bestLast['top'] ?? 0),
                    'x1' => (int) ($bestLast['right'] ?? 0),
                    'y1' => (int) ($bestLast['bottom'] ?? 0),
                ],
            ],
        ];
    }

    private function rowDarkCoverageRatio($img, int $xStart, int $xEnd, int $y, int $darkThreshold): float
    {
        $pixels = max(1, $xEnd - $xStart + 1);
        $dark = 0;

        for ($x = $xStart; $x <= $xEnd; $x++) {
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $luma = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
            if ($luma < $darkThreshold) {
                $dark++;
            }
        }

        return $dark / $pixels;
    }

    private function loadImageForPixelScan(string $imagePath): array
    {
        if (!is_file($imagePath) || !function_exists('getimagesize')) {
            return [null, 0, 0];
        }

        $imageInfo = @getimagesize($imagePath);
        if (!$imageInfo || !isset($imageInfo[2])) {
            return [null, 0, 0];
        }

        if (!function_exists('imagecreatefromjpeg') || !function_exists('imagecreatefrompng')) {
            return [null, 0, 0];
        }

        $img = null;
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $img = @imagecreatefromjpeg($imagePath);
                break;
            case IMAGETYPE_PNG:
                $img = @imagecreatefrompng($imagePath);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $img = @imagecreatefromwebp($imagePath);
                }
                break;
        }

        if (!$img) {
            return [null, 0, 0];
        }

        return [$img, imagesx($img), imagesy($img)];
    }
}
