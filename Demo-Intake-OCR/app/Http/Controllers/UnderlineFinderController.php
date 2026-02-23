<?php

namespace App\Http\Controllers;

use App\Services\LabelFinder;
use App\Services\UnderlineFinder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class UnderlineFinderController extends Controller
{
    public function __construct(private LabelFinder $labelFinder, private UnderlineFinder $underlineFinder)
    {
    }

    public function firstUnderline()
    {
        try {
            $pendingData = $this->getSelectedPendingDataFromSession();
            $imagePath = $this->resolveOcrImagePath($pendingData);
            if (!$imagePath || !file_exists($imagePath)) {
                return response()->json(['ok' => false, 'message' => 'Image file not found.'], 404);
            }

            $left = $this->labelFinder->findFirstNameHeaderLocation($imagePath);
            $right = $this->labelFinder->findlastNameHeaderLocation($imagePath);

            $underline = $this->underlineFinder->detectUnderlineAfterLabel($imagePath, $left, $right, 700);
            if (!$underline) {
                return response()->json(['ok' => false, 'message' => 'Could not detect underline.'], 400);
            }

            return response()->json(['ok' => true, 'underline' => $underline]);
        } catch (Throwable $e) {
            \Log::warning('Error in firstUnderline: ' . $e->getMessage());
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function lastUnderline()
    {
        try {
            $pendingData = $this->getSelectedPendingDataFromSession();
            $imagePath = $this->resolveOcrImagePath($pendingData);
            if (!$imagePath || !file_exists($imagePath)) {
                return response()->json(['ok' => false, 'message' => 'Image file not found.'], 404);
            }

            $left = $this->labelFinder->findlastNameHeaderLocation($imagePath);
            $underline = $this->underlineFinder->detectUnderlineAfterLabel($imagePath, $left, null, 700);
            if (!$underline) {
                return response()->json(['ok' => false, 'message' => 'Could not detect underline.'], 400);
            }

            return response()->json(['ok' => true, 'underline' => $underline]);
        } catch (Throwable $e) {
            \Log::warning('Error in lastUnderline: ' . $e->getMessage());
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function dobUnderline()
    {
        try {
            $pendingData = $this->getSelectedPendingDataFromSession();
            $imagePath = $this->resolveOcrImagePath($pendingData);
            if (!$imagePath || !file_exists($imagePath)) {
                return response()->json(['ok' => false, 'message' => 'Image file not found.'], 404);
            }

            $left = $this->labelFinder->findDobHeaderLocation($imagePath);
            $right = $this->labelFinder->findGenderHeaderLocation($imagePath);

            $underline = $this->underlineFinder->detectUnderlineAfterLabel($imagePath, $left, $right, 700);
            if (!$underline) {
                return response()->json(['ok' => false, 'message' => 'Could not detect underline.'], 400);
            }

            return response()->json(['ok' => true, 'underline' => $underline]);
        } catch (Throwable $e) {
            \Log::warning('Error in dobUnderline: ' . $e->getMessage());
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
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
}
