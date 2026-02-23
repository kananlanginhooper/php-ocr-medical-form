<?php

namespace App\Http\Controllers;

use App\Services\LabelFinder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class DobOcrController extends Controller
{
    public function __construct(private LabelFinder $labelFinder)
    {
    }

    public function dobOcr()
    {
        try {
            $requestedRecordId = request()->query('record_id');
            if ($requestedRecordId !== null) {
                session(['ocr_review_record_id' => (int) $requestedRecordId]);
            }

            $selectedRecordId = (int) session('ocr_review_record_id', 0);
            $selectedData = $this->getSelectedPendingDataFromSession();
            $selectedDocumentName = $this->resolveFileName($selectedData);
            $previewUrl = null;
            if (!empty($selectedData['fp_image_path']) && is_string($selectedData['fp_image_path'])) {
                $previewUrl = trim($selectedData['fp_image_path']);
            } elseif ($selectedRecordId > 0 && $this->resolvePreviewFilePath($selectedData) !== null) {
                $previewUrl = route('fax.pending-preview', ['pendingId' => $selectedRecordId]);
            }

            $state = session('global_state');
            $state = is_array($state) ? $state : [];
            $locationRaw = (string) ($state['fp_dob_location'] ?? '');
            $location = $this->decodeLocation($locationRaw);

            return view('dob-ocr', [
                'activeMenu' => 'dob',
                'selectedRecordId' => $selectedRecordId > 0 ? $selectedRecordId : null,
                'selectedDocumentName' => $selectedDocumentName,
                'previewUrl' => $previewUrl,
                'locationX' => $location['x'],
                'locationY' => $location['y'],
                'locationWidth' => $location['width'],
                'locationScore' => $location['score'],
                'statusType' => session('statusType'),
                'statusMessage' => session('statusMessage'),
            ]);
        } catch (Throwable $exception) {
            return view('dob-ocr', [
                'activeMenu' => 'dob',
                'selectedRecordId' => null,
                'selectedDocumentName' => null,
                'previewUrl' => null,
                'locationX' => null,
                'locationY' => null,
                'locationWidth' => null,
                'locationScore' => null,
                'statusType' => 'error',
                'statusMessage' => 'Unable to load DOB OCR state. ' . $exception->getMessage(),
            ]);
        }
    }

    public function findDobLabel()
    {
        try {
            $pendingData = $this->getSelectedPendingDataFromSession();
            $imagePath = $this->resolveOcrImagePath($pendingData);

            if (!$imagePath || !file_exists($imagePath)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Image file not found in session state.',
                ], 404);
            }

            $labelResult = $this->labelFinder->findDobHeaderLocation($imagePath);
            if (!$labelResult) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Could not find label "Date Of Birth" in image.',
                ], 400);
            }

            $labelLocationJson = json_encode([
                'label' => [
                    'x' => (int) $labelResult['x'],
                    'y' => (int) $labelResult['y'],
                    'x1' => (int) ($labelResult['x1'] ?? $labelResult['x']),
                    'y1' => (int) ($labelResult['y1'] ?? $labelResult['y']),
                    'width' => (int) (($labelResult['x1'] ?? $labelResult['x']) - $labelResult['x']),
                    'height' => (int) (($labelResult['y1'] ?? $labelResult['y']) - $labelResult['y']),
                    'confidence' => $labelResult['confidence'] ?? null,
                    'header_text' => $labelResult['header_text'] ?? null,
                    'extracted_value' => $labelResult['extracted_value'] ?? null,
                    'source' => 'tesseract',
                ],
            ]);

            $state = session('global_state', []);
            if (!is_array($state)) {
                $state = [];
            }
            $state = array_merge($state, ['gs_current_image_path' => $pendingData['fp_image_path'] ?? null]);
            $state['fp_dob_location'] = $labelLocationJson;
            $state['fp_dob_ocr_score'] = $labelResult['confidence'] ?? null;
            $state['updated_at'] = now()->toDateTimeString();
            session(['global_state' => $state]);

            return response()->json([
                'ok' => true,
                'message' => 'Label location found successfully.',
                'label' => $labelResult,
            ]);
        } catch (Throwable $e) {
            \Log::warning('Error in findDobLabel: ' . $e->getMessage());
            return response()->json([
                'ok' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function getSelectedPendingDataFromSession(): array
    {
        $state = session('global_state', []);
        if (!is_array($state)) {
            return [];
        }

        $data = $state;
        $imagePath = $state['fp_image_path'] ?? $state['gs_current_image_path'] ?? $state['image_path'] ?? null;
        if (is_string($imagePath) && trim($imagePath) !== '') {
            $data['fp_image_path'] = $imagePath;
        }
        $imageName = $state['fp_image_name'] ?? $state['gs_current_image_name'] ?? null;
        if (is_string($imageName) && trim($imageName) !== '') {
            $data['fp_image_name'] = $imageName;
            $data['file_name'] = $data['file_name'] ?? $imageName;
        }

        return $data;
    }

    private function resolveFileName(array $row): ?string
    {
        $priorityColumns = [
            'file_name',
            'filename',
            'fp_image_name',
            'document_name',
            'pdf_name',
            'file_path',
            'pdf_path',
            'file_url',
            'pdf_url',
            'image_path',
            'image_url',
        ];

        foreach ($priorityColumns as $column) {
            if (!isset($row[$column]) || !is_string($row[$column])) {
                continue;
            }

            $value = trim($row[$column]);
            if ($value === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', $value);
            $basename = basename(parse_url($normalized, PHP_URL_PATH) ?: $normalized);

            return $basename !== '' ? $basename : $value;
        }

        return null;
    }

    private function resolvePreviewFilePath(array $row): ?string
    {
        $priorityColumns = [
            'preview_url',
            'thumbnail_url',
            'image_url',
            'fax_image_url',
            'image_path',
            'thumbnail_path',
            'fax_image_path',
            'file_name',
            'filename',
            'document_name',
            'pdf_name',
            'file_url',
            'file_path',
            'pdf_url',
            'pdf_path',
        ];

        foreach ($priorityColumns as $column) {
            if (!isset($row[$column]) || !is_string($row[$column])) {
                continue;
            }

            $value = trim($row[$column]);
            if ($value === '' || !$this->isLikelyImagePath($value)) {
                continue;
            }

            if (Str::startsWith($value, ['http://', 'https://', 'data:image/'])) {
                continue;
            }

            $resolved = $this->resolveLocalFileFromValue($value);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function resolveLocalFileFromValue(string $value): ?string
    {
        $normalized = str_replace('\\', '/', trim($value));
        $parsedPath = parse_url($normalized, PHP_URL_PATH);
        $path = ltrim(is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : $normalized, '/');

        $candidates = [
            $normalized,
            public_path($path),
            storage_path('app/' . $path),
            storage_path('app/public/' . $path),
        ];

        if (Str::startsWith($path, 'storage/')) {
            $afterStorage = Str::after($path, 'storage/');
            $candidates[] = storage_path('app/public/' . $afterStorage);
            $candidates[] = public_path($path);
        }

        if (Str::startsWith($path, 'public/')) {
            $afterPublic = Str::after($path, 'public/');
            $candidates[] = public_path($afterPublic);
            $candidates[] = storage_path('app/public/' . $afterPublic);
        }

        if (!Str::contains($path, '/')) {
            $candidates[] = storage_path('app/public/faxes/' . $path);
            $candidates[] = storage_path('app/public/' . $path);
            $candidates[] = public_path('storage/faxes/' . $path);
            $candidates[] = public_path('storage/' . $path);
            $candidates[] = public_path('fax-images/' . $path);
            $candidates[] = public_path('faxes/' . $path);
            $candidates[] = public_path('uploads/' . $path);
            $candidates[] = public_path($path);
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isLikelyImagePath(string $value): bool
    {
        if (Str::startsWith($value, 'data:image/')) {
            return true;
        }

        $path = parse_url($value, PHP_URL_PATH);
        $candidate = is_string($path) && $path !== '' ? $path : $value;
        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg'], true);
    }

    private function resolveOcrImagePath(array $row): ?string
    {
        $candidates = [
            $row['fp_image_path'] ?? null,
            $row['image_path'] ?? null,
            $row['file_path'] ?? null,
            $row['pdf_path'] ?? null,
            $row['image_url'] ?? null,
            $row['file_url'] ?? null,
            $row['pdf_url'] ?? null,
            $row['fp_image_name'] ?? null,
            $row['filename'] ?? null,
            $row['file_name'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $resolved = $this->resolveLocalFileFromValue($candidate);
            if ($resolved !== null && is_file($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    private function decodeLocation($locationRaw): array
    {
        if (!is_string($locationRaw) || trim($locationRaw) === '') {
            return ['x' => null, 'y' => null, 'width' => null, 'score' => null];
        }

        $decoded = json_decode($locationRaw, true);
        if (!is_array($decoded)) {
            return ['x' => null, 'y' => null, 'width' => null, 'score' => null];
        }

        $label = isset($decoded['label']) && is_array($decoded['label']) ? $decoded['label'] : null;
        $x = $label['x'] ?? $decoded['x'] ?? null;
        $y = $label['y'] ?? $decoded['y'] ?? null;
        $width = $label['width'] ?? $decoded['width'] ?? null;
        $score = $label['confidence'] ?? $decoded['confidence'] ?? null;

        return [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'score' => $score,
        ];
    }
}
