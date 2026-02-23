<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;
use App\Services\LabelFinder;
use App\Services\UnderlineFinder;
use App\Services\AreaCalculator;
use App\Services\OcrStateAccessor;
use App\Services\HandwrittenFinder;


// for "First name ocr" underline is between "first name" and "last name"

class FirstNameOcrController extends Controller
{
    private LabelFinder $labelFinder;
    private UnderlineFinder $underlineFinder;
    private AreaCalculator $areaCalculator;
    private OcrStateAccessor $ocrStateAccessor;
    private HandwrittenFinder $handwrittenFinder;

    public function __construct(
        LabelFinder $labelFinder,
        UnderlineFinder $underlineFinder,
        AreaCalculator $areaCalculator,
        OcrStateAccessor $ocrStateAccessor,
        HandwrittenFinder $handwrittenFinder
    )
    {
        $this->labelFinder = $labelFinder;
        $this->underlineFinder = $underlineFinder;
        $this->areaCalculator = $areaCalculator;
        $this->ocrStateAccessor = $ocrStateAccessor;
        $this->handwrittenFinder = $handwrittenFinder;
    }
    // -------------------------------------------------------------------------
    // Page / action handlers
    // -------------------------------------------------------------------------

    public function firstNameOcr()
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

            $sessionState = session('global_state');
            $globalRowData = is_array($sessionState) ? $sessionState : [];
            $stateValues = $this->ocrStateAccessor->firstName($globalRowData);

            $locationRaw = (string) ($stateValues['location_raw'] ?? '');
            $location = $this->decodeLocation($locationRaw);
            $locationDecoded = json_decode((string) $locationRaw, true);
            $handwrittenLocation = is_array($locationDecoded) && isset($locationDecoded['handwritten']) && is_array($locationDecoded['handwritten'])
                ? $locationDecoded['handwritten']
                : [];
            $ocrGuess = $stateValues['ocr_guess'] ?? null;
            $ocrScore = $stateValues['ocr_score'] ?? null;
            $ocrOptionsRaw = $stateValues['ocr_options_raw'] ?? null;
            $ocrOptions = is_string($ocrOptionsRaw) ? json_decode($ocrOptionsRaw, true) : null;
            if (!is_array($ocrOptions)) {
                $ocrOptions = [];
            }
            $humanValue = $stateValues['human_value'] ?? null;

            return view('firstname-ocr', [
                'activeMenu' => 'firstname',
                'selectedRecordId' => $selectedRecordId > 0 ? $selectedRecordId : null,
                'selectedDocumentName' => $selectedDocumentName,
                'previewUrl' => $previewUrl,
                'locationX' => $location['x'],
                'locationY' => $location['y'],
                'locationWidth' => $location['width'],
                'locationScore' => $location['score'],
                'handX' => $handwrittenLocation['x'] ?? null,
                'handY' => $handwrittenLocation['y'] ?? null,
                'handWidth' => $handwrittenLocation['width'] ?? (
                    isset($handwrittenLocation['x1'], $handwrittenLocation['x'])
                        ? ((int) $handwrittenLocation['x1'] - (int) $handwrittenLocation['x'])
                        : null
                ),
                'handHeight' => $handwrittenLocation['height'] ?? (
                    isset($handwrittenLocation['y1'], $handwrittenLocation['y'])
                        ? ((int) $handwrittenLocation['y1'] - (int) $handwrittenLocation['y'])
                        : null
                ),
                'handScore' => $handwrittenLocation['confidence'] ?? null,
                'ocrGuess' => $ocrGuess,
                'ocrScore' => $ocrScore,
                'ocrOptions' => $ocrOptions,
                'humanValue' => $humanValue,
                'statusType' => session('statusType'),
                'statusMessage' => session('statusMessage'),
            ]);
        } catch (Throwable $exception) {
            return view('firstname-ocr', [
                'activeMenu' => 'firstname',
                'selectedRecordId' => null,
                'selectedDocumentName' => null,
                'previewUrl' => null,
                'locationX' => null,
                'locationY' => null,
                'locationWidth' => null,
                'locationScore' => null,
                'handX' => null,
                'handY' => null,
                'handWidth' => null,
                'handHeight' => null,
                'handScore' => null,
                'ocrGuess' => null,
                'ocrScore' => null,
                'ocrOptions' => [],
                'humanValue' => null,
                'statusType' => 'error',
                'statusMessage' => 'Unable to load first name OCR state. ' . $exception->getMessage(),
            ]);
        }
    }

    /**
     * Step 1: OCR to find the label location using Tesseract
     */
    public function findFirstNameLabel()
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

            // Step 1: Use Tesseract to find the "First Name:" label location
            $labelResult = $this->labelFinder->findFirstNameHeaderLocation($imagePath);

            if (!$labelResult) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Could not find label "First Name:" in image.',
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

            // Update session-based global state
            $state = session('global_state', []);
            if (!is_array($state)) $state = [];
            $state = array_merge($state, ['gs_current_image_path' => $pendingData['fp_image_path'] ?? null]);
            $state['fp_firstname_location'] = $labelLocationJson;
            $state['fp_firstname_ocr_score'] = $labelResult['confidence'] ?? null;
            $state['updated_at'] = now()->toDateTimeString();
            session(['global_state' => $state]);

            return response()->json([
                'ok' => true,
                'message' => 'Label location found successfully.',
                'label' => $labelResult,
            ]);
        } catch (Throwable $e) {
            \Log::warning('Error in findFirstNameLabel: ' . $e->getMessage());
            return response()->json([
                'ok' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Step 2: find underline location after First Name label
     */
    public function findFirstNameHandwritten()
    {
        try {
            $pendingData = $this->getSelectedPendingDataFromSession();
            $imagePath = $this->resolveOcrImagePath($pendingData);
            $globalState = session('global_state');
            $state = session('global_state', []);
            if (!is_array($state)) $state = [];
            $result = $this->handwrittenFinder->findAfterLabel(
                $state,
                $imagePath,
                'fp_firstname_location',
                $pendingData['fp_image_path'] ?? null,
                true,
                'First Name OCR'
            );

            if (is_array($result['state'] ?? null)) {
                session(['global_state' => $result['state']]);
            }

            return response()->json($result['payload'] ?? ['ok' => false, 'message' => 'Unknown error.'], (int) ($result['status'] ?? 500));
        } catch (Throwable $e) {
            \Log::warning('Error in findFirstNameHandwritten: ' . $e->getMessage());
            return response()->json([
                'ok' => false,
                'step' => $this->underlineFinder->stepContext('First Name OCR'),
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Step 3: use label + underline context to estimate handwritten area
     */
    public function findFirstNameArea()
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

            $globalState = session('global_state');
            $locationData = is_array($globalState) && !empty($globalState['fp_firstname_location'])
                ? json_decode($globalState['fp_firstname_location'], true)
                : null;

            if (!$locationData || !isset($locationData['label'])) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Label location not found. Run Step 1 first.',
                ], 400);
            }

            $labelResult = $locationData['label'];
            $storedUnderline = null;
            if (isset($locationData['handwritten']) && is_array($locationData['handwritten'])) {
                $storedUnderline = is_array($locationData['handwritten']['underline'] ?? null)
                    ? $locationData['handwritten']['underline']
                    : $locationData['handwritten'];
            }
            $handwrittenResult = $this->areaCalculator->detectHandwrittenAreaAfterLabel($imagePath, $labelResult, $storedUnderline);

            if (!$handwrittenResult) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Could not find handwritten first-name area after label.',
                ], 400);
            }

            $handBox = $this->areaCalculator->normalizeBoxToImageBounds($imagePath, [
                'x' => (int) ($handwrittenResult['x'] ?? 0),
                'y' => (int) ($handwrittenResult['y'] ?? 0),
                'width' => (int) ($handwrittenResult['width'] ?? 0),
                'height' => (int) ($handwrittenResult['height'] ?? 0),
            ]);
            if ($handBox !== null) {
                $padding = 10;
                if ($padding > 0) {
                    $handBox = $this->normalizeBoxToImageBounds($imagePath, [
                        'x' => (int) ($handBox['x'] ?? 0),
                        'y' => (int) (($handBox['y'] ?? 0) - $padding),
                        'width' => (int) ($handBox['width'] ?? 0),
                        'height' => (int) (($handBox['height'] ?? 0) + ($padding * 2)),
                    ]) ?? $handBox;
                }

                $handwrittenResult['x'] = (int) $handBox['x'];
                $handwrittenResult['y'] = (int) $handBox['y'];
                $handwrittenResult['x1'] = (int) $handBox['x1'];
                $handwrittenResult['y1'] = (int) $handBox['y1'];
                $handwrittenResult['width'] = (int) $handBox['width'];
                $handwrittenResult['height'] = (int) $handBox['height'];
            }

            $currentLocationData = [];
            if (!empty($globalState['fp_firstname_location'])) {
                $currentLocationData = json_decode($globalState['fp_firstname_location'], true) ?? [];
            }
            $currentLocationData['handwritten'] = [
                'x' => (int) $handwrittenResult['x'],
                'y' => (int) $handwrittenResult['y'],
                'x1' => (int) ($handwrittenResult['x1'] ?? $handwrittenResult['x']),
                'y1' => (int) ($handwrittenResult['y1'] ?? $handwrittenResult['y']),
                'width' => (int) (($handwrittenResult['x1'] ?? $handwrittenResult['x']) - $handwrittenResult['x']),
                'height' => (int) (($handwrittenResult['y1'] ?? $handwrittenResult['y']) - $handwrittenResult['y']),
                'confidence' => $handwrittenResult['confidence'] ?? null,
                'source' => $handwrittenResult['source'] ?? 'first-last-label-gap',
                'underline' => $handwrittenResult['underline'] ?? null,
                'anchors' => $handwrittenResult['anchors'] ?? null,
                'manual' => false,
            ];

            $state = session('global_state', []);
            if (!is_array($state)) $state = [];
            $state = array_merge($state, [
                'gs_current_image_path' => $pendingData['fp_image_path'] ?? null,
                'fp_firstname_location' => json_encode($currentLocationData),
                'updated_at' => now()->toDateTimeString(),
            ]);
            session(['global_state' => $state]);

            return response()->json([
                'ok' => true,
                'message' => 'Handwritten first-name area found successfully.',
                'handwritten' => $handwrittenResult,
            ]);
        } catch (Throwable $e) {
            \Log::warning('Error in findFirstNameArea: ' . $e->getMessage());
            return response()->json([
                'ok' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Step 4: run handwriting OCR inside the Step 3 box and return top options
     */
    public function findFirstNameOptions(Request $request)
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

            $globalState = session('global_state');
            $locationData = is_array($globalState) && !empty($globalState['fp_firstname_location'])
                ? json_decode($globalState['fp_firstname_location'], true)
                : null;

            $box = is_array($locationData) && isset($locationData['handwritten']) && is_array($locationData['handwritten'])
                ? $locationData['handwritten']
                : null;

            // Prefer the box currently displayed in Step 4 UI when provided.
            $requestBox = $request->validate([
                'x' => ['nullable', 'numeric'],
                'y' => ['nullable', 'numeric'],
                'width' => ['nullable', 'numeric'],
                'height' => ['nullable', 'numeric'],
            ]);
            if (
                isset($requestBox['x'], $requestBox['y'], $requestBox['width'], $requestBox['height']) &&
                $requestBox['width'] > 0 &&
                $requestBox['height'] > 0
            ) {
                $box = [
                    'x' => (int) round((float) $requestBox['x']),
                    'y' => (int) round((float) $requestBox['y']),
                    'x1' => (int) round((float) $requestBox['x'] + (float) $requestBox['width']),
                    'y1' => (int) round((float) $requestBox['y'] + (float) $requestBox['height']),
                    'width' => (int) round((float) $requestBox['width']),
                    'height' => (int) round((float) $requestBox['height']),
                ];
            }

            if (!$box || !isset($box['x'], $box['y'])) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Step 3 area not found. Run Step 3 first.',
                ], 400);
            }

            $normalizedBox = $this->normalizeBoxToImageBounds($imagePath, $box);
            if ($normalizedBox === null) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Step 3 area is outside image bounds.',
                    'bbox_used' => $box,
                ], 400);
            }
            $box = $normalizedBox;

            $ocrResult = $this->areaCalculator->extractHandwritingCandidatesFromBox($imagePath, $box, 4);
            $options = is_array($ocrResult['options'] ?? null) ? $ocrResult['options'] : [];
            $commands = is_array($ocrResult['commands'] ?? null) ? $ocrResult['commands'] : [];
            if (empty($options)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No handwriting options found in the Step 3 area.',
                    'commands' => $commands,
                    'bbox_used' => $box,
                ], 400);
            }

            $top = $options[0];

            $state = session('global_state', []);
            if (!is_array($state)) $state = [];
            $state = array_merge($state, [
                'gs_current_image_path' => $pendingData['fp_image_path'] ?? null,
                'fp_firstname_ocr' => $top['text'],
                'fp_firstname_ocr_score' => $top['confidence'],
                'fp_firstname_ocr_options' => json_encode($options),
                'updated_at' => now()->toDateTimeString(),
            ]);
            session(['global_state' => $state]);

            return response()->json([
                'ok' => true,
                'message' => 'Handwriting OCR complete.',
                'options' => $options,
                'commands' => $commands,
                'bbox_used' => $box,
            ]);
        } catch (Throwable $e) {
            \Log::warning('Error in findFirstNameOptions: ' . $e->getMessage());
            return response()->json([
                'ok' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function runFirstNameOcr(Request $request)
    {
        $validated = $request->validate([
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
        ]);

        $selectedRecordId = (int) session('ocr_review_record_id', 0);
        if ($selectedRecordId <= 0) {
            return redirect()->route('firstname.index')->with([
                'statusType' => 'error',
                'statusMessage' => 'No document selected. Select a document in Step 2 first.',
            ]);
        }

        // session-based state always available

        $pendingData = $this->getSelectedPendingDataFromSession();
        $filename = $this->resolveFileName($pendingData);
        $guess = $this->mockFirstNameGuess($filename);
        $score = $this->mockOcrScore($guess, $selectedRecordId);

        $globalState = session('global_state');
        $existingLocationData = [];
        if (is_array($globalState) && !empty($globalState['fp_firstname_location'])) {
            $existingLocationData = json_decode($globalState['fp_firstname_location'], true) ?: [];
        }

        $existingLocationData['handwritten'] = [
            'x' => (float) $validated['x'],
            'y' => (float) $validated['y'],
            'manual' => true,
        ];

        $locationJson = json_encode($existingLocationData);

        $state = session('global_state', []);
        if (!is_array($state)) $state = [];
        $state = array_merge($state, [
            'gs_current_image_path' => ($pendingData['fp_image_path'] ?? ($state['gs_current_image_path'] ?? null)),
            'fp_firstname_location' => $locationJson,
            'fp_firstname_ocr' => $guess,
            'fp_firstname_ocr_score' => $score,
            'fp_firstname_score' => $score,
            'updated_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ]);
        session(['global_state' => $state]);

        return redirect()->route('firstname.index')->with([
            'statusType' => 'success',
            'statusMessage' => 'OCR run complete. Saved handwritten coordinates.',
        ]);
    }

    public function confirmFirstNameOcr(Request $request)
    {
        $validated = $request->validate([
            'human_value' => ['required', 'string', 'max:255'],
        ]);

        $selectedRecordId = (int) session('ocr_review_record_id', 0);
        if ($selectedRecordId <= 0) {
            return redirect()->route('firstname.index')->with([
                'statusType' => 'error',
                'statusMessage' => 'No document selected. Select a document in Step 2 first.',
            ]);
        }

        // session-based state always available

        $pendingData = $this->getSelectedPendingDataFromSession();

        $state = session('global_state', []);
        if (!is_array($state)) $state = [];
        $state = array_merge($state, [
            'gs_current_image_path' => ($pendingData['fp_image_path'] ?? ($state['gs_current_image_path'] ?? null)),
            'fp_firstname_human' => $validated['human_value'],
            'fp_firstname_confirmed' => 1,
            'updated_at' => now()->toDateTimeString(),
            'created_at' => $state['created_at'] ?? now()->toDateTimeString(),
        ]);
        session(['global_state' => $state]);

        return redirect()->route('firstname.index')->with([
            'statusType' => 'success',
            'statusMessage' => 'Human confirmation saved.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers: shared with FaxController (copied)
    // -------------------------------------------------------------------------

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
        $handwritten = isset($decoded['handwritten']) && is_array($decoded['handwritten']) ? $decoded['handwritten'] : null;

        $x = $label['x'] ?? $handwritten['x'] ?? $decoded['x'] ?? null;
        $y = $label['y'] ?? $handwritten['y'] ?? $decoded['y'] ?? null;
        $width = $label['width'] ?? $handwritten['width'] ?? $decoded['width'] ?? null;
        $score = $label['confidence'] ?? $handwritten['confidence'] ?? $decoded['confidence'] ?? null;

        return [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'score' => $score,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers: First Name OCR specific
    // -------------------------------------------------------------------------

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

    private function mockFirstNameGuess(?string $filename): string
    {
        if (is_string($filename) && trim($filename) !== '') {
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $parts = preg_split('/[^a-zA-Z]+/', $base) ?: [];
            foreach ($parts as $part) {
                if (strlen($part) >= 2) {
                    return ucfirst(strtolower($part));
                }
            }
        }

        return 'Unknown';
    }

    private function mockOcrScore(string $guess, int $recordId): string
    {
        $seed = abs(crc32($guess . '|' . $recordId));
        $value = 0.70 + (($seed % 29) / 100);
        return number_format($value, 2, '.', '');
    }

    private function findFirstNameHeaderLocation(string $imagePath): ?array
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
            // skip header row
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

                if ($token === 'firstname') {
                    $best = $current;
                    break;
                }

                // Pair "first" + "name" when OCR splits words
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

            if ($best === null) {
                // Final fallback: estimate underline span from First Name -> Last Name labels on the same row.
                $cmd = 'tesseract ' . escapeshellarg($imagePath) . ' stdout --psm 6 tsv 2>/dev/null';
                $tsv = shell_exec($cmd);
                if (is_string($tsv) && trim($tsv) !== '') {
                    $lines = preg_split('/\r\n|\r|\n/', trim($tsv)) ?: [];
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
                            $lastCandidates[] = $row;
                            continue;
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
                    $bestLast = null;
                    foreach ($lastCandidates as $candidate) {
                        if ($candidate['left'] <= $labelRight) {
                            continue;
                        }
                        if (abs($candidate['center_y'] - $firstCenterY) > $lineTolerance) {
                            continue;
                        }
                        if ($bestLast === null || $candidate['left'] < $bestLast['left']) {
                            $bestLast = $candidate;
                        }
                    }

                    if ($bestLast !== null) {
                        $fx = max(0, $labelRight + 5);
                        $fx1 = max($fx + 10, (int) $bestLast['left'] - 5);
                        $fy = min($imgHeight - 2, max(0, $labelBottom + max(6, (int) round($labelHeight * 0.9))));
                        $fy1 = min($imgHeight - 1, $fy + 2);

                        return [
                            'x' => (int) $fx,
                            'y' => (int) $fy,
                            'x1' => (int) $fx1,
                            'y1' => (int) $fy1,
                            'width' => (int) ($fx1 - $fx),
                            'height' => (int) ($fy1 - $fy),
                            'confidence' => null,
                            'source' => 'underline-estimate-label-gap',
                        ];
                    }
                }

                return null;
            }

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
            \Log::warning('Error in findFirstNameHeaderLocation: ' . $e->getMessage());
            return null;
        }
    }

    private function detectHandwrittenAreaAfterLabel(?string $imagePath, array $labelResult): ?array
    {
        try {
            if (!$imagePath || !file_exists($imagePath)) {
                return null;
            }

            $area = $this->detectHandwrittenAreaBetweenLabels($imagePath, $labelResult);
            if (!$area) {
                return null;
            }

            return [
                'x'          => (int) $area['x'],
                'y'          => (int) $area['y'],
                'x1'         => (int) $area['x1'],
                'y1'         => (int) $area['y1'],
                'width'      => (int) $area['x1'] - (int) $area['x'],
                'height'     => (int) $area['y1'] - (int) $area['y'],
                'confidence' => null,
                'source'     => 'first-last-label-gap',
                'underline'  => null,
                'anchors'    => $area['anchors'] ?? null,
            ];
        } catch (Throwable $e) {
            \Log::warning('Error detecting handwritten first-name area: ' . $e->getMessage());
            return null;
        }
    }

    private function extractHandwritingCandidatesFromBox(string $imagePath, array $box, int $limit = 4): array
    {
        $left = (int) ($box['x'] ?? 0);
        $top = (int) ($box['y'] ?? 0);
        $right = (int) ($box['x1'] ?? ($left + (int) ($box['width'] ?? 0)));
        $bottom = (int) ($box['y1'] ?? ($top + (int) ($box['height'] ?? 0)));

        if ($right <= $left || $bottom <= $top) {
            return ['options' => [], 'commands' => []];
        }

        $candidates = [];
        $tokens = [];
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
                if ($text === '') {
                    continue;
                }
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
                    if ($text === '') {
                        continue;
                    }
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
            if ($text === '') {
                continue;
            }

            $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $text));
            if ($key === '') {
                continue;
            }

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

    private function normalizeBoxToImageBounds(string $imagePath, array $box): ?array
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

    private function runPaddleOcr(string $imagePath, ?array $bbox = null): array
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
                // Be tolerant if Python prints non-JSON lines around the JSON payload.
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
                if (!is_array($row)) {
                    continue;
                }
                $text = trim((string) ($row['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
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

    private function buildPaddleOcrCommand(string $imagePath, ?array $bbox = null): string
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
            if (is_file($candidate)) {
                $scriptPath = $candidate;
                break;
            }
        }

        $pythonCandidates = [
            $workspaceRoot . '/ocr-env/bin/python',
            $workspaceRoot . '/paddleOCR/.venv/bin/python',
            $workspaceRoot . '/paddleOCR/ocr-env/bin/python',
            $appBase . '/ocr-env/bin/python',
        ];
        $pythonBin = $pythonCandidates[0];
        foreach ($pythonCandidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $pythonBin = $candidate;
                break;
            }
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

    private function parsePaddleOcrOutput(string $output): array
    {
        $results = [];
        $lines   = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (
                preg_match(
                    '/\[\[\[\s*([\d.]+)\s*,\s*([\d.]+)\s*\],\s*\[\s*([\d.]+)\s*,\s*([\d.]+)\s*\],\s*\[\s*([\d.]+)\s*,\s*([\d.]+)\s*\],\s*\[\s*([\d.]+)\s*,\s*([\d.]+)\s*\]\],\s*\(\s*[\'"](.+?)[\'"]\s*,\s*([\d.]+)\s*\)\s*\]/',
                    $line,
                    $matches
                )
            ) {
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
                        'text'       => $text,
                        'confidence' => $confidence,
                        'x'          => (int) floor(min($xs)),
                        'y'          => (int) floor(min($ys)),
                        'x1'         => (int) ceil(max($xs)),
                        'y1'         => (int) ceil(max($ys)),
                    ];
                }
                continue;
            }

            if (preg_match('/text:\s*["\']?([^"\']+)["\']?\s*,?\s*confidence:\s*([\d.]+)/i', $line, $matches)) {
                $text = trim($matches[1]);
                $confidence = (float) $matches[2];

                if (!empty($text)) {
                    $results[] = [
                        'text'       => $text,
                        'confidence' => $confidence,
                        'x'          => 0,
                        'y'          => 0,
                        'x1'         => 0,
                        'y1'         => 0,
                    ];
                }
            }
        }

        return $results;
    }

    private function collectPaddleTokensInsideRegion(
        array $paddleResults,
        int $left,
        int $top,
        int $right,
        int $bottom
    ): array {
        $tokens = [];

        foreach ($paddleResults as $result) {
            $text = trim((string) ($result['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $x = (int) ($result['x'] ?? 0);
            $y = (int) ($result['y'] ?? 0);
            $x1 = (int) ($result['x1'] ?? $x);
            $y1 = (int) ($result['y1'] ?? $y);
            $centerX = (int) round(($x + $x1) / 2);
            $centerY = (int) round(($y + $y1) / 2);

            if ($centerX < $left || $centerX > $right || $centerY < $top || $centerY > $bottom) {
                continue;
            }

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

    private function detectHandwrittenAreaBetweenLabels(string $imagePath, array $labelResult): ?array
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

            // Single source of line position: detectUnderlineAfterLabel.
            $underline = $this->detectUnderlineAfterLabel($imagePath, $labelResult);
            if (!$underline || !isset($underline['x'], $underline['x1'])) {
                return null;
            }

            $firstHeight = max(1, $firstBottom - $firstTop);
            $boxLeft = (int) $underline['x'];
            $boxRight = (int) $underline['x1'];

            if ($boxRight <= $boxLeft) {
                return null;
            }

            $boxHeight = min(125, max(95, $firstHeight * 1.27));
            // Step 3 bottom aligns to Step 1 marker bottom (First Name label y1).
            $boxBottom = max(0, $firstBottom + 3);
            $boxTop = max(0, $boxBottom - $boxHeight);

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
            \Log::warning('Error detecting area between First Name and Last Name: ' . $e->getMessage());
            return null;
        }
    }

    private function detectUnderlineAfterLabel(string $imagePath, array $labelResult): ?array
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
                    $lastCandidates[] = $row;
                    continue;
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
                if ($candidate['left'] <= $labelRight) {
                    continue;
                }
                if (abs($candidate['center_y'] - $firstCenterY) > $lineTolerance) {
                    continue;
                }
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
                if ($currentGap <= $gapTolerance) {
                    continue;
                }
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

        // Keep Step 2 span anchored by labels when Last Name is available:
        // from right after First Name label to just before Last Name label.
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

    private function buildFirstNameLocationJson(?array $labelResult, array $userInput, ?array $handwrittenResult): array
    {
        $location = [
            'label' => null,
            'handwritten' => [
                'x' => (float) $userInput['x'],
                'y' => (float) $userInput['y'],
                'manual' => true,
            ],
        ];

        if ($labelResult && isset($labelResult['x'], $labelResult['y'])) {
            $location['label'] = [
                'x'              => (int) $labelResult['x'],
                'y'              => (int) $labelResult['y'],
                'x1'             => (int) ($labelResult['x1'] ?? $labelResult['x']),
                'y1'             => (int) ($labelResult['y1'] ?? $labelResult['y']),
                'width'          => (int) (($labelResult['x1'] ?? $labelResult['x']) - $labelResult['x']),
                'height'         => (int) (($labelResult['y1'] ?? $labelResult['y']) - $labelResult['y']),
                'confidence'     => $labelResult['confidence'] ?? null,
                'header_text'    => $labelResult['header_text'] ?? null,
                'extracted_value' => $labelResult['extracted_value'] ?? null,
                'source'         => 'tesseract',
            ];
        }

        if ($handwrittenResult && !empty($handwrittenResult['text'])) {
            $location['handwritten'] = [
                'x'          => (int) $handwrittenResult['x'],
                'y'          => (int) $handwrittenResult['y'],
                'x1'         => (int) ($handwrittenResult['x1'] ?? $handwrittenResult['x']),
                'y1'         => (int) ($handwrittenResult['y1'] ?? $handwrittenResult['y']),
                'width'      => (int) (($handwrittenResult['x1'] ?? $handwrittenResult['x']) - $handwrittenResult['x']),
                'height'     => (int) (($handwrittenResult['y1'] ?? $handwrittenResult['y']) - $handwrittenResult['y']),
                'confidence' => $handwrittenResult['confidence'] ?? null,
                'text'       => $handwrittenResult['text'],
                'source'     => 'paddleocr',
                'manual'     => false,
            ];
        }

        return $location;
    }
}
