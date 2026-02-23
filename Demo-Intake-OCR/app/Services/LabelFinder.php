<?php

namespace App\Services;

use Throwable;

class LabelFinder
{
    public function findFirstNameHeaderLocation(string $imagePath): ?array
    {
        try {
            if (!is_file($imagePath)) {
                return null;
            }

            $cmd = 'tesseract ' . escapeshellarg($imagePath) . ' stdout --psm 6 tsv 2>/dev/null';
            $tsv = shell_exec($cmd);
            if (!is_string($tsv) || trim($tsv) === '') {
                return null;
            }

            $lines = preg_split('/\r\n|\r|\n/', trim($tsv)) ?: [];
            if (count($lines) < 2) {
                return null;
            }

            $rows = [];
            for ($i = 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if ($line === '') continue;
                $parts = explode("\t", $line);
                if (count($parts) < 12) continue;
                $text = trim((string) $parts[11]);
                if ($text === '') continue;

                $rows[] = [
                    'text' => $text,
                    'text_norm' => strtolower(preg_replace('/[^a-z0-9]/i', '', $text)),
                    'left' => (int) $parts[6],
                    'top' => (int) $parts[7],
                    'width' => (int) $parts[8],
                    'height' => (int) $parts[9],
                    'conf' => is_numeric($parts[10]) ? (float) $parts[10] : null,
                ];
            }

            if (empty($rows)) return null;

            $best = null;
            for ($i = 0; $i < count($rows); $i++) {
                $current = $rows[$i];
                $token = $current['text_norm'];

                if ($token === 'firstname') {
                    $best = $current;
                    break;
                }

                if ($token === 'first' && isset($rows[$i + 1])) {
                    $next = $rows[$i + 1];
                    if ($next['text_norm'] === 'name') {
                        $left = min($current['left'], $next['left']);
                        $top = min($current['top'], $next['top']);
                        $right = max($current['left'] + $current['width'], $next['left'] + $next['width']);
                        $bottom = max($current['top'] + $current['height'], $next['top'] + $next['height']);
                        $best = [
                            'text' => $current['text'] . ' ' . $next['text'],
                            'left' => $left,
                            'top' => $top,
                            'width' => max(1, $right - $left),
                            'height' => max(1, $bottom - $top),
                            'conf' => max((float) ($current['conf'] ?? 0), (float) ($next['conf'] ?? 0)),
                        ];
                        break;
                    }
                }
            }

            if ($best === null) return null;

            return [
                'x' => $best['left'],
                'y' => $best['top'],
                'x1' => $best['left'] + $best['width'],
                'y1' => $best['top'] + $best['height'],
                'confidence' => $best['conf'],
                'header_text' => $best['text'] ?? 'First Name',
                'extracted_value' => null,
            ];
        } catch (Throwable $e) {
            \Log::warning('Error in LabelFinder::findFirstNameHeaderLocation: ' . $e->getMessage());
            return null;
        }
    }

    public function findlastNameHeaderLocation(string $imagePath): ?array
    {
        // For last name we reuse similar logic but search for 'lastname' or 'last' + 'name'
        try {
            if (!is_file($imagePath)) {
                return null;
            }

            $cmd = 'tesseract ' . escapeshellarg($imagePath) . ' stdout --psm 6 tsv 2>/dev/null';
            $tsv = shell_exec($cmd);
            if (!is_string($tsv) || trim($tsv) === '') {
                return null;
            }

            $lines = preg_split('/\r\n|\r|\n/', trim($tsv)) ?: [];
            if (count($lines) < 2) return null;

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
                    'text' => $text,
                    'text_norm' => strtolower(preg_replace('/[^a-z0-9]/i', '', $text)),
                    'left' => $left,
                    'right' => $left + $width,
                    'top' => $top,
                    'bottom' => $top + $height,
                    'center_y' => $top + ($height / 2),
                ];
            }

            // find candidate where text_norm is 'lastname' or 'last'+'name'
            $best = null;
            for ($i = 0; $i < count($rows); $i++) {
                $r = $rows[$i];
                if ($r['text_norm'] === 'lastname') {
                    $best = $r; break;
                }
                if ($r['text_norm'] === 'last' && isset($rows[$i+1]) && $rows[$i+1]['text_norm'] === 'name') {
                    $next = $rows[$i+1];
                    $left = min($r['left'], $next['left']);
                    $right = max($r['right'], $next['right']);
                    $top = min($r['top'], $next['top']);
                    $bottom = max($r['bottom'], $next['bottom']);
                    $best = [
                        'left' => $left,
                        'top' => $top,
                        'width' => max(1, $right - $left),
                        'height' => max(1, $bottom - $top),
                        'text' => $r['text'] . ' ' . ($next['text'] ?? ''),
                        'conf' => null,
                    ];
                    break;
                }
            }

            if ($best === null) return null;

            $left = $best['left'];
            $top = $best['top'];
            $w = $best['width'] ?? ($best['right'] - $best['left']);
            $h = $best['height'] ?? 1;

            return [
                'x' => (int) $left,
                'y' => (int) $top,
                'x1' => (int) ($left + $w),
                'y1' => (int) ($top + $h),
                'confidence' => $best['conf'] ?? null,
                'header_text' => $best['text'] ?? 'Last Name',
                'extracted_value' => null,
            ];
        } catch (Throwable $e) {
            \Log::warning('Error in LabelFinder::findlastNameHeaderLocation: ' . $e->getMessage());
            return null;
        }
    }

    public function findDobHeaderLocation(string $imagePath): ?array
    {
        try {
            if (!is_file($imagePath)) {
                return null;
            }

            $cmd = 'tesseract ' . escapeshellarg($imagePath) . ' stdout --psm 6 tsv 2>/dev/null';
            $tsv = shell_exec($cmd);
            if (!is_string($tsv) || trim($tsv) === '') {
                return null;
            }

            $lines = preg_split('/\r\n|\r|\n/', trim($tsv)) ?: [];
            if (count($lines) < 2) {
                return null;
            }

            $rows = [];
            for ($i = 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if ($line === '') {
                    continue;
                }
                $parts = explode("\t", $line);
                if (count($parts) < 12) {
                    continue;
                }
                $text = trim((string) $parts[11]);
                if ($text === '') {
                    continue;
                }

                $rows[] = [
                    'text' => $text,
                    'text_norm' => strtolower(preg_replace('/[^a-z0-9]/i', '', $text)),
                    'left' => (int) $parts[6],
                    'top' => (int) $parts[7],
                    'width' => (int) $parts[8],
                    'height' => (int) $parts[9],
                    'conf' => is_numeric($parts[10]) ? (float) $parts[10] : null,
                ];
            }

            if (empty($rows)) {
                return null;
            }

            $best = null;
            for ($i = 0; $i < count($rows); $i++) {
                $current = $rows[$i];
                $token = $current['text_norm'];

                if ($token === 'dob' || $token === 'dateofbirth') {
                    $best = $current;
                    break;
                }

                if (
                    $token === 'date'
                    && isset($rows[$i + 1], $rows[$i + 2])
                    && $rows[$i + 1]['text_norm'] === 'of'
                    && $rows[$i + 2]['text_norm'] === 'birth'
                ) {
                    $mid = $rows[$i + 1];
                    $next = $rows[$i + 2];
                    $left = min($current['left'], $mid['left'], $next['left']);
                    $top = min($current['top'], $mid['top'], $next['top']);
                    $right = max($current['left'] + $current['width'], $mid['left'] + $mid['width'], $next['left'] + $next['width']);
                    $bottom = max($current['top'] + $current['height'], $mid['top'] + $mid['height'], $next['top'] + $next['height']);
                    $best = [
                        'text' => $current['text'] . ' ' . $mid['text'] . ' ' . $next['text'],
                        'left' => $left,
                        'top' => $top,
                        'width' => max(1, $right - $left),
                        'height' => max(1, $bottom - $top),
                        'conf' => max(
                            (float) ($current['conf'] ?? 0),
                            (float) ($mid['conf'] ?? 0),
                            (float) ($next['conf'] ?? 0)
                        ),
                    ];
                    break;
                }
            }

            if ($best === null) {
                return null;
            }

            return [
                'x' => $best['left'],
                'y' => $best['top'],
                'x1' => $best['left'] + $best['width'],
                'y1' => $best['top'] + $best['height'],
                'confidence' => $best['conf'] ?? null,
                'header_text' => $best['text'] ?? 'Date Of Birth',
                'extracted_value' => null,
            ];
        } catch (Throwable $e) {
            \Log::warning('Error in LabelFinder::findDobHeaderLocation: ' . $e->getMessage());
            return null;
        }
    }
}
