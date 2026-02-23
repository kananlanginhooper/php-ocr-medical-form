<?php

namespace App\Services;

class HandwrittenFinder
{
    public function __construct(private UnderlineFinder $underlineFinder)
    {
    }

    public function findAfterLabel(
        array $state,
        ?string $imagePath,
        string $locationField,
        ?string $currentImagePath = null,
        bool $includeStep = false,
        string $flow = ''
    ): array {
        $step = $includeStep ? $this->underlineFinder->stepContext($flow !== '' ? $flow : 'OCR') : null;

        if (!is_string($imagePath) || $imagePath === '' || !is_file($imagePath)) {
            return $this->error('Image file not found in session state.', 404, $step);
        }

        $locationData = !empty($state[$locationField]) ? json_decode((string) $state[$locationField], true) : null;
        if (!$locationData || !isset($locationData['label'])) {
            return $this->error('Label location not found. Run Step 1 first.', 400, $step);
        }

        $labelResult = $locationData['label'];
        $underlineResult = $this->underlineFinder->detectUnderlineAfterLabel($imagePath, $labelResult);
        if (!$underlineResult) {
            return $this->error('Could not find underline after label.', 400, $step);
        }

        $currentLocationData = !empty($state[$locationField]) ? (json_decode((string) $state[$locationField], true) ?? []) : [];
        $currentLocationData['handwritten'] = [
            'x' => (int) $underlineResult['x'],
            'y' => (int) $underlineResult['y'],
            'x1' => (int) ($underlineResult['x1'] ?? $underlineResult['x']),
            'y1' => (int) ($underlineResult['y1'] ?? $underlineResult['y']),
            'width' => (int) (($underlineResult['x1'] ?? $underlineResult['x']) - $underlineResult['x']),
            'height' => (int) (($underlineResult['y1'] ?? $underlineResult['y']) - $underlineResult['y']),
            'confidence' => $underlineResult['confidence'] ?? null,
            'source' => $underlineResult['source'] ?? 'underline-scan',
            'underline' => $underlineResult,
            'manual' => false,
        ];

        $updatedState = $state;
        $updatedState[$locationField] = json_encode($currentLocationData);
        $updatedState['updated_at'] = now()->toDateTimeString();
        if (is_string($currentImagePath) && $currentImagePath !== '') {
            $updatedState['gs_current_image_path'] = $currentImagePath;
        }

        $payload = [
            'ok' => true,
            'message' => 'Underline found successfully.',
            'handwritten' => $underlineResult,
        ];
        if ($step !== null) {
            $payload['step'] = $step;
        }

        return [
            'ok' => true,
            'status' => 200,
            'payload' => $payload,
            'state' => $updatedState,
        ];
    }

    private function error(string $message, int $status, ?array $step): array
    {
        $payload = [
            'ok' => false,
            'message' => $message,
        ];
        if ($step !== null) {
            $payload['step'] = $step;
        }

        return [
            'ok' => false,
            'status' => $status,
            'payload' => $payload,
            'state' => null,
        ];
    }
}
