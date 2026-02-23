<?php

namespace App\Services;

use Throwable;

// for "First name ocr" is between "first name" and "last name"
//  for "Last name ocr" is between "last name" and edge
// "Date Of Birth OCR" is between "Date of Birth" and "Gender"

class UnderlineFinder
{
    public function __construct(private LabelFinder $labelFinder)
    {
    }
    public function stepContext(string $flow = 'First Name OCR'): array
    {
        return [
            'number' => 2,
            'title' => 'Find the Underline',
            'label' => '2) Find the Underline',
            'flow' => $flow,
        ];
    }

    public function detectUnderlineAfterLabel(string $imagePath, array $leftLabel, ?array $rightLabel = null, int $defaultWidth = 700): ?array
    {
        if (!is_file($imagePath)) {
            return null;
        }

        $imageInfo = @getimagesize($imagePath);
        if (!$imageInfo) {
            return null;
        }
        $imgWidth = (int) ($imageInfo[0] ?? 0);
        $imgHeight = (int) ($imageInfo[1] ?? 0);
        if ($imgWidth < 1 || $imgHeight < 1) {
            return null;
        }

        if ($leftLabel && (isset($leftLabel['y1']) || (isset($leftLabel['y']) && isset($leftLabel['height'])))) {
            $baselineY = isset($leftLabel['y1']) ? (int) $leftLabel['y1'] : ((int) $leftLabel['y'] + (int) $leftLabel['height']);
        } elseif ($rightLabel && (isset($rightLabel['y1']) || (isset($rightLabel['y']) && isset($rightLabel['height'])))) {
            $baselineY = isset($rightLabel['y1']) ? (int) $rightLabel['y1'] : ((int) $rightLabel['y'] + (int) $rightLabel['height']);
        } else {
            $baselineY = (int) floor($imgHeight / 2);
        }

        $height = 17;
        $y = max(0, min($imgHeight - 1, $baselineY - (int) floor($height / 2)));
        $y1 = min($imgHeight - 1, $y + $height);

        if ($leftLabel) {
            if (isset($leftLabel['x1'])) {
                $leftRight = (int) $leftLabel['x1'];
            } else {
                $leftRight = (int) ($leftLabel['x'] ?? 0) + (int) ($leftLabel['width'] ?? 0);
            }
        } else {
            $leftRight = 0;
        }

        // If no explicit right label provided, try to infer one for common flows.
        if (!$rightLabel && $leftLabel && isset($leftLabel['header_text'])) {
            $ht = strtolower((string) $leftLabel['header_text']);
            if (str_contains($ht, 'first')) {
                $inferred = $this->labelFinder->findlastNameHeaderLocation($imagePath);
                if ($inferred) $rightLabel = $inferred;
            } elseif (str_contains($ht, 'date') || str_contains($ht, 'dob') || str_contains($ht, 'dateofbirth')) {
                $inferred = $this->labelFinder->findGenderHeaderLocation($imagePath);
                if ($inferred) $rightLabel = $inferred;
            }
        }

        if ($rightLabel) {
            if (isset($rightLabel['x'])) {
                $rightLeft = (int) $rightLabel['x'];
            } elseif (isset($rightLabel['x1'])) {
                $rightLeft = (int) $rightLabel['x1'];
            } else {
                $rightLeft = $leftRight + $defaultWidth;
            }
        } else {
            $rightLeft = $leftRight + $defaultWidth;
        }

        $x = max(0, $leftRight + 5);
        if ($rightLabel) {
            $x1 = min($imgWidth - 1, $rightLeft - 5);
        } else {
            $x1 = $x + $defaultWidth;
        }

        if ($x1 < $x + 1) {
            if ($x1 < $imgWidth - 1) {
                $x1 = min($imgWidth - 1, $x + 1);
            } else {
                $x = max(0, $x1 - 1);
            }
        }

        $width = max(1, $x1 - $x);

        $result = [
            'x' => (int) $x,
            'y' => (int) $y,
            'x1' => (int) $x1,
            'y1' => (int) $y1,
            'width' => (int) $width,
            'height' => (int) $height,
            'confidence' => null,
            'source' => 'underline-simple',
            'anchors' => [],
        ];

        if ($leftLabel) {
            $result['anchors']['left_label'] = $leftLabel;
        }
        if ($rightLabel) {
            $result['anchors']['right_label'] = $rightLabel;
        }

        return $result;
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

    // update for only JPG
    private function loadImageForPixelScan(string $imagePath): array
    {
        if (!is_file($imagePath) || !function_exists('getimagesize')) {
            return [null, 0, 0];
        }

        $imageInfo = @getimagesize($imagePath);
        if (!$imageInfo || !isset($imageInfo[2])) {
            return [null, 0, 0];
        }

        if (!function_exists('imagecreatefromjpeg')) {
            return [null, 0, 0];
        }

        $img = null;
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $img = @imagecreatefromjpeg($imagePath);
                break;
        }

        if (!$img) {
            return [null, 0, 0];
        }

        return [$img, imagesx($img), imagesy($img)];
    }
}
