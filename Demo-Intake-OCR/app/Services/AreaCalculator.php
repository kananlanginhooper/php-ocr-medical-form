<?php

namespace App\Services;

use Throwable;

class AreaCalculator
{
    public function normalizeBoxToImageBounds(string $imagePath, array $box): ?array
    {
        $imageSize = @getimagesize($imagePath);
        if (!is_array($imageSize) || !isset($imageSize[0], $imageSize[1])) {
            return null;
        }

        $imgW = max(1, (int) $imageSize[0]);
        $imgH = max(1, (int) $imageSize[1]);

        $x = (int) round((float) ($box['x'] ?? 0));
        $y = (int) round((float) ($box['y'] ?? 0));
        $w = (int) round((float) ($box['width'] ?? ((isset($box['x1']) ? (float) $box['x1'] : 0) - $x)));
        $h = (int) round((float) ($box['height'] ?? ((isset($box['y1']) ? (float) $box['y1'] : 0) - $y)));

        $x = max(0, min($x, $imgW - 1));
        $y = max(0, min($y, $imgH - 1));
        $w = max(1, $w);
        $h = max(1, $h);

        if ($x + $w > $imgW) {
            $w = $imgW - $x;
        }
        if ($y + $h > $imgH) {
            $h = $imgH - $y;
        }
        if ($w <= 0 || $h <= 0) {
            return null;
        }

        return [
            'x' => $x,
            'y' => $y,
            'x1' => $x + $w,
            'y1' => $y + $h,
            'width' => $w,
            'height' => $h,
        ];
    }

    public function extractHandwritingCandidatesFromBox(string $imagePath, array $box, int $limit = 4): array
    {
        $left = (int) ($box['x'] ?? 0);
        $top = (int) ($box['y'] ?? 0);
        $right = (int) ($box['x1'] ?? ($left + (int) ($box['width'] ?? 0)));
        $bottom = (int) ($box['y1'] ?? ($top + (int) ($box['height'] ?? 0)));

        if ($right <= $left || $bottom <= $top) {
            return ['options' => [], 'commands' => []];
        }

        $candidates = [];
        $commands = [];

        $bbox = [
            'x' => $left,
            'y' => $top,
            'w' => max(1, $right - $left),
            'h' => max(1, $bottom - $top),
        ];
        $commands[] = $this->buildPaddleOcrCommand($imagePath, $bbox);
        $tokens = $this->runPaddleOcr($imagePath, $bbox);

        if (!empty($tokens)) {
            usort($tokens, fn ($a, $b) => (($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0)));

            foreach ($tokens as $token) {
                $text = trim((string) ($token['text'] ?? ''));
                if ($text === '') continue;
                $candidates[] = [
                    'text' => $text,
                    'confidence' => round((float) ($token['confidence'] ?? 0), 4),
                ];
            }

            if (count($tokens) > 1) {
                $parts = [];
                $conf = [];
                foreach ($tokens as $token) {
                    $text = trim((string) ($token['text'] ?? ''));
                    if ($text === '') continue;
                    $parts[] = $text;
                    $conf[] = (float) ($token['confidence'] ?? 0);
                }
                if (!empty($parts)) {
                    $candidates[] = [
                        'text' => trim(implode(' ', $parts)),
                        'confidence' => round(array_sum($conf) / max(1, count($conf)), 4),
                    ];
                }
            }
        }

        $deduped = [];
        foreach ($candidates as $candidate) {
            $text = trim((string) ($candidate['text'] ?? ''));
            if ($text === '') continue;

            $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $text));
            if ($key === '') continue;

            $confidence = (float) ($candidate['confidence'] ?? 0);
            if (!isset($deduped[$key]) || $confidence > (float) $deduped[$key]['confidence']) {
                $deduped[$key] = [
                    'text' => $text,
                    'confidence' => $confidence,
                ];
            }
        }

        $options = array_values($deduped);
        usort($options, fn ($a, $b) => (($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0)));

        return [
            'options' => array_slice($options, 0, max(1, $limit)),
            'commands' => array_values(array_unique($commands)),
        ];
    }

    public function runPaddleOcr(string $imagePath, ?array $bbox = null): array
    {
        try {
            $cmd = $this->buildPaddleOcrCommand($imagePath, $bbox);
            $output = shell_exec($cmd);

            if (empty($output)) {
                return [];
            }

            $raw = trim((string) $output);
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $start = strpos($raw, '[');
                $end = strrpos($raw, ']');
                if ($start !== false && $end !== false && $end >= $start) {
                    $jsonSlice = substr($raw, $start, ($end - $start) + 1);
                    $decoded = json_decode($jsonSlice, true);
                }
            }
            if (!is_array($decoded)) {
                \Log::warning('PaddleOCR bbox output was not valid JSON.', [
                    'cmd' => $cmd,
                    'output_preview' => mb_substr((string) $output, 0, 500),
                ]);
                return [];
            }

            $results = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) continue;
                $text = trim((string) ($row['text'] ?? ''));
                if ($text === '') continue;
                $results[] = [
                    'text' => $text,
                    'confidence' => (float) ($row['confidence'] ?? 0),
                    'x' => 0,
                    'y' => 0,
                    'x1' => 0,
                    'y1' => 0,
                ];
            }

            return $results;
        } catch (Throwable $e) {
            \Log::warning('Error running PaddleOCR: ' . $e->getMessage());
            return [];
        }
    }

    public function buildPaddleOcrCommand(string $imagePath, ?array $bbox = null): string
    {
        if ($bbox === null) {
            $imageSize = @getimagesize($imagePath);
            $width = is_array($imageSize) && isset($imageSize[0]) ? (int) $imageSize[0] : 1;
            $height = is_array($imageSize) && isset($imageSize[1]) ? (int) $imageSize[1] : 1;
            $bbox = ['x' => 0, 'y' => 0, 'w' => max(1, $width), 'h' => max(1, $height)];
        }

        $x = max(0, (int) ($bbox['x'] ?? 0));
        $y = max(0, (int) ($bbox['y'] ?? 0));
        $w = max(1, (int) ($bbox['w'] ?? 1));
        $h = max(1, (int) ($bbox['h'] ?? 1));

        $appBase = base_path();
        $workspaceRoot = dirname($appBase);
        $scriptPathCandidates = [
            $workspaceRoot . '/paddleOCR/PaddleOCRwithBoundingBox.py',
            $appBase . '/paddleOCR/PaddleOCRwithBoundingBox.py',
            $appBase . '/PaddleOCRwithBoundingBox.py',
            $workspaceRoot . '/PaddleOCRwithBoundingBox.py',
        ];
        $scriptPath = $scriptPathCandidates[0];
        foreach ($scriptPathCandidates as $candidate) {
            if (is_file($candidate)) { $scriptPath = $candidate; break; }
        }

        $pythonCandidates = [
            $workspaceRoot . '/ocr-env/bin/python',
            $workspaceRoot . '/paddleOCR/.venv/bin/python',
            $workspaceRoot . '/paddleOCR/ocr-env/bin/python',
            $appBase . '/ocr-env/bin/python',
        ];
        $pythonBin = $pythonCandidates[0];
        foreach ($pythonCandidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) { $pythonBin = $candidate; break; }
        }

        return 'PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK=True '
            . escapeshellarg($pythonBin) . ' ' . escapeshellarg($scriptPath)
            . ' ' . escapeshellarg($imagePath)
            . ' --x ' . $x
            . ' --y ' . $y
            . ' --w ' . $w
            . ' --h ' . $h
            . ' 2>/dev/null';
    }

    public function parsePaddleOcrOutput(string $output): array
    {
        $results = [];
        $lines   = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (preg_match('/\[\[\[\s*([\d.]+)\s*,\s*([\d.]+)\s*\],\s*\[\s*([\d.]+)\s*,\s*([\d.]+)\s*\],\s*\[\s*([\d.]+)\s*,\s*([\d.]+)\s*\],\s*\[\s*([\d.]+)\s*,\s*([\d.]+)\s*\]\],\s*\(\s*[\'\"](.+?)[\'\"]\s*,\s*([\d.]+)\s*\)\s*\]/', $line, $matches)) {
                $points = [
                    [(float) $matches[1], (float) $matches[2]],
                    [(float) $matches[3], (float) $matches[4]],
                    [(float) $matches[5], (float) $matches[6]],
                    [(float) $matches[7], (float) $matches[8]],
                ];
                $xs = array_column($points, 0);
                $ys = array_column($points, 1);
                $text = trim((string) $matches[9]);
                $confidence = (float) $matches[10];

                if ($text !== '') {
                    $results[] = [
                        'text' => $text,
                        'confidence' => $confidence,
                        'x' => (int) floor(min($xs)),
                        'y' => (int) floor(min($ys)),
                        'x1' => (int) ceil(max($xs)),
                        'y1' => (int) ceil(max($ys)),
                    ];
                }
                continue;
            }

            if (preg_match('/text:\s*["\']?([^"\']+)["\']?\s*,?\s*confidence:\s*([\d.]+)/i', $line, $matches)) {
                $text = trim($matches[1]);
                $confidence = (float) $matches[2];

                if (!empty($text)) {
                    $results[] = [
                        'text' => $text,
                        'confidence' => $confidence,
                        'x' => 0,
                        'y' => 0,
                        'x1' => 0,
                        'y1' => 0,
                    ];
                }
            }
        }

        return $results;
    }

    public function collectPaddleTokensInsideRegion(array $paddleResults, int $left, int $top, int $right, int $bottom): array
    {
        $tokens = [];

        foreach ($paddleResults as $result) {
            $text = trim((string) ($result['text'] ?? ''));
            if ($text === '') continue;

            $x = (int) ($result['x'] ?? 0);
            $y = (int) ($result['y'] ?? 0);
            $x1 = (int) ($result['x1'] ?? $x);
            $y1 = (int) ($result['y1'] ?? $y);
            $centerX = (int) round(($x + $x1) / 2);
            $centerY = (int) round(($y + $y1) / 2);

            if ($centerX < $left || $centerX > $right || $centerY < $top || $centerY > $bottom) continue;

            $tokens[] = [
                'text' => $text,
                'x' => $x,
                'y' => $y,
                'x1' => $x1,
                'y1' => $y1,
                'confidence' => (float) ($result['confidence'] ?? 0),
            ];
        }

        return $tokens;
    }

    public function detectHandwrittenAreaBetweenLabels(string $imagePath, array $labelResult, ?array $underline = null): ?array
    {
        if (!is_file($imagePath)) {
            return null;
        }

        try {
            $firstLeft = (int) ($labelResult['x'] ?? 0);
            $firstTop = (int) ($labelResult['y'] ?? 0);
            $firstRight = (int) ($labelResult['x1'] ?? ($firstLeft + (int) ($labelResult['width'] ?? 0)));
            $firstBottom = (int) ($labelResult['y1'] ?? ($firstTop + (int) ($labelResult['height'] ?? 0)));

            if ($firstRight <= $firstLeft || $firstBottom <= $firstTop) {
                return null;
            }

            // Prefer underline from Step 2 (session) when available; fallback to scan.
            $underline = is_array($underline) ? $underline : null;
            if (!$underline || !isset($underline['x'], $underline['x1'])) {
                $underlineFinder = new UnderlineFinder();
                $underline = $underlineFinder->detectUnderlineAfterLabel($imagePath, $labelResult);
            }
            if (!$underline || !isset($underline['x'], $underline['x1'])) {
                return null;
            }

            $boxLeft = (int) $underline['x'];
            $boxRight = (int) $underline['x1'];

            if ($boxRight <= $boxLeft) {
                return null;
            }

            $labelBaselineY = $firstBottom;
            $underlineY = (int) round((
                (int) ($underline['y'] ?? $labelBaselineY)
                + (int) ($underline['y1'] ?? ($underline['y'] ?? $labelBaselineY))
            ) / 2);

            // Step 3 geometry:
            // bottom starts 8px below label baseline, and area expands upward ~150px from underline.
            $boxBottom = max(0, max($labelBaselineY + 8, $underlineY));
            $boxTop = max(0, $underlineY - 170);
            if ($boxTop >= $boxBottom) {
                $boxBottom = $boxTop + 1;
            }
            $maxHeight = 100;
            if (($boxBottom - $boxTop) > $maxHeight) {
                $boxTop = max(0, $boxBottom - $maxHeight);
            }

            $imageSize = @getimagesize($imagePath);
            if (is_array($imageSize) && isset($imageSize[0], $imageSize[1])) {
                $imgWidth = max(1, (int) $imageSize[0]);
                $imgHeight = max(1, (int) $imageSize[1]);
                $boxLeft = min(max(0, $boxLeft), $imgWidth - 1);
                $boxRight = min(max($boxLeft + 1, $boxRight), $imgWidth - 1);
                if ($boxBottom > $imgHeight - 1) {
                    $overflow = $boxBottom - ($imgHeight - 1);
                    $boxTop = max(0, $boxTop - $overflow);
                    $boxBottom = $imgHeight - 1;
                }
                $boxBottom = min(max($boxTop + 1, $boxBottom), $imgHeight - 1);
            }

            return [
                'x' => (int) $boxLeft,
                'y' => (int) $boxTop,
                'x1' => (int) $boxRight,
                'y1' => (int) $boxBottom,
                'anchors' => [
                    'first_name' => [
                        'x' => $firstLeft,
                        'y' => $firstTop,
                        'x1' => $firstRight,
                        'y1' => $firstBottom,
                    ],
                    'underline' => [
                        'x' => (int) ($underline['x'] ?? 0),
                        'y' => (int) ($underline['y'] ?? 0),
                        'x1' => (int) ($underline['x1'] ?? 0),
                        'y1' => (int) ($underline['y1'] ?? 0),
                    ],
                ],
            ];
        } catch (Throwable $e) {
            \Log::warning('Error detecting area between labels: ' . $e->getMessage());
            return null;
        }
    }

    public function detectHandwrittenAreaAfterLabel(?string $imagePath, array $labelResult, ?array $underline = null): ?array
    {
        try {
            if (!$imagePath || !file_exists($imagePath)) {
                return null;
            }

            $area = $this->detectHandwrittenAreaBetweenLabels($imagePath, $labelResult, $underline);
            if (!$area) {
                return null;
            }

            return [
                'x' => (int) $area['x'],
                'y' => (int) $area['y'],
                'x1' => (int) $area['x1'],
                'y1' => (int) $area['y1'],
                'width' => (int) $area['x1'] - (int) $area['x'],
                'height' => (int) $area['y1'] - (int) $area['y'],
                'confidence' => null,
                'source' => 'first-last-label-gap',
                'underline' => null,
                'anchors' => $area['anchors'] ?? null,
            ];
        } catch (Throwable $e) {
            \Log::warning('Error detecting handwritten area after label: ' . $e->getMessage());
            return null;
        }
    }
}
