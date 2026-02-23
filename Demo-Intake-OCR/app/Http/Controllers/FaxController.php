<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class FaxController extends Controller
{
    public function index()
    {
        $hasAvailableFaxes = false;
        try {
            $hasAvailableFaxes = DB::table('available_faxes')->count() > 0;
        } catch (Throwable $exception) {
            $hasAvailableFaxes = false;
        }

        return view('fax-intake', [
            'activeMenu' => 'fetch',
            'hasAvailableFaxes' => $hasAvailableFaxes,
        ]);
    }

    // /fax-intake/check
    public function check()
    {
        try {
            $availableRows  = DB::table('available_faxes')->get();
            $availableCount = $availableRows->count();
            $copiedRows     = collect();
            $duplicateCount = 0;

            if ($availableCount > 0) {
                // Duplicate check: available_faxes.filename must not already exist in faxes_pending.fp_image_name.
                $knownFilenames = DB::table('faxes_pending')
                    ->whereNotNull('fp_image_name')
                    ->pluck('fp_image_name')
                    ->map(fn ($v) => strtolower(trim($v)))
                    ->flip()
                    ->all();

                DB::beginTransaction();
                try {
                    foreach ($availableRows as $row) {
                        $filename       = $row->filename ?? $row->file_name ?? null;
                        $normalizedName = $filename !== null ? strtolower(trim($filename)) : null;

                        if ($normalizedName !== null && isset($knownFilenames[$normalizedName])) {
                            $duplicateCount++;
                            continue;
                        }

                        // available_faxes.faxID → faxes_pending.fp_id
                        // available_faxes.filename → faxes_pending.fp_image_name
                        // Copy image to public/fax-images/ and store the public path in fp_image_path.
                        // Source records in available_faxes are never deleted — read-only source.
                        $publicImagePath = null;
                        if ($filename !== null) {
                            $src  = base_path('fax-images/' . $filename);
                            $dest = public_path('fax-images/' . $filename);
                            if (is_file($src) && copy($src, $dest)) {
                                $publicImagePath = '/fax-images/' . $filename;
                            }
                        }

                        $pendingId = DB::table('faxes_pending')->insertGetId([
                            'fp_id'         => $row->faxID ?? $row->fax_id ?? null,
                            'fp_image_name' => $filename,
                            'fp_image_path' => $publicImagePath,
                        ]);

                        if ($normalizedName !== null) {
                            $knownFilenames[$normalizedName] = true;
                        }

                        $copiedRows->push((object) [
                            'pending_preview_id' => $pendingId,
                            'filename'           => $filename,
                        ]);
                    }

                    DB::commit();
                } catch (Throwable $exception) {
                    DB::rollBack();
                    throw $exception;
                }
            }

            $previewItems    = $this->buildPreviewItems($copiedRows, 'pending');
            $fetchedFileNames = $this->buildFetchedFileNames($copiedRows);
            $copiedCount     = $copiedRows->count();

            return view('fax-intake', [
                'activeMenu'       => 'fetch',
                'statusType'       => 'success',
                'statusMessage'    => "Copied {$copiedCount} of {$availableCount} record(s) from available_faxes to faxes_pending. Skipped {$duplicateCount} duplicate(s) by filename.",
                'faxCount'         => $copiedCount,
                'hasAvailableFaxes' => $availableCount > 0,
                'previewItems'     => $previewItems,
                'fetchedFileNames' => $fetchedFileNames,
            ]);
        } catch (Throwable $exception) {
            return view('fax-intake', [
                'activeMenu'       => 'fetch',
                'statusType'       => 'error',
                'statusMessage'    => 'Unable to check available_faxes. ' . $exception->getMessage(),
                'faxCount'         => 0,
                'hasAvailableFaxes' => false,
                'previewItems'     => collect(),
                'fetchedFileNames' => collect(),
            ]);
        }
    }

    public function preview(int $faxId): BinaryFileResponse
    {
        $row = DB::table('available_faxes')->where('id', $faxId)->first();
        if (!$row) {
            $row = DB::table('available_faxes')->where('faxID', $faxId)->first();
        }
        abort_if(!$row, 404);

        $filePath = $this->resolvePreviewFilePath((array) $row);
        abort_if($filePath === null, 404);

        return response()->file($filePath);
    }

    public function pendingImports()
    {
        try {
            if (!Schema::hasTable('faxes_pending')) {
                return view('pending-imports', [
                    'activeMenu' => 'pending',
                    'rows' => collect(),
                    'previewMap' => collect(),
                    'selectedRecordId' => 0,
                    'statusType' => 'success',
                    'statusMessage' => 'No records found. Table faxes_pending does not exist.',
                ]);
            }

            $query = DB::table('faxes_pending');
            if (Schema::hasColumn('faxes_pending', 'fp_complete')) {
                $query->where('fp_complete', 0);
            }

            $rows = $query->limit(100)->get();
            $selectedRecordId = (int) session('ocr_review_record_id', 0);

            $previewMap = $rows->map(function ($row) {
                $data = (array) $row;

                if (!empty($data['fp_image_path']) && is_string($data['fp_image_path'])) {
                    $path = trim($data['fp_image_path']);
                    if ($path !== '') {
                        return $path;
                    }
                }

                $recordKey = $this->resolvePendingRecordKeyFromRow($row);
                if ($recordKey !== null) {
                    return route('fax.pending-preview', ['pendingId' => $recordKey]);
                }
                return null;
            });

            return view('pending-imports', [
                'activeMenu' => 'pending',
                'rows' => $rows,
                'previewMap' => $previewMap,
                'selectedRecordId' => $selectedRecordId,
                'statusType' => 'success',
                'statusMessage' => $rows->isEmpty()
                    ? 'No records found in faxes_pending.'
                    : 'Loaded pending imports from faxes_pending.',
            ]);
        } catch (Throwable $exception) {
            return view('pending-imports', [
                'activeMenu' => 'pending',
                'rows' => collect(),
                'previewMap' => collect(),
                'selectedRecordId' => 0,
                'statusType' => 'error',
                'statusMessage' => 'Unable to load faxes_pending. ' . $exception->getMessage(),
            ]);
        }
    }

    public function pendingPreview(int $pendingId): BinaryFileResponse
    {
        $row = $this->findPendingRowByRecordKey($pendingId);
        abort_if(!$row, 404);

        $data = (array) $row;

        // fp_image_path is stored as a public-relative path, e.g. /fax-images/IMG_0001.jpg
        if (!empty($data['fp_image_path'])) {
            $path = public_path(ltrim($data['fp_image_path'], '/'));
            if (is_file($path)) {
                return response()->file($path);
            }
        }

        if (!empty($data['fp_image_name'])) {
            $filePath = $this->resolveLocalFileFromValue($data['fp_image_name']);
            if ($filePath !== null) {
                return response()->file($filePath);
            }
        }

        $filePath = $this->resolvePreviewFilePath($data);
        abort_if($filePath === null, 404);

        return response()->file($filePath);
    }

    public function serveFaxImage(int $fpId): BinaryFileResponse
    {
        $row = DB::table('faxes_pending')->where('fp_id', $fpId)->first();
        abort_if(!$row || empty($row->fp_image_name), 404);

        $filePath = $this->resolveLocalFileFromValue($row->fp_image_name);
        abort_if($filePath === null, 404);

        return response()->file($filePath);
    }

    public function selectRecord(Request $request, int $recordId)
    {
        session(['ocr_review_record_id' => $recordId]);

        $state = session('global_state', []);
        if (!is_array($state)) {
            $state = [];
        }
        $imageName = $request->input('image_name');
        $imagePath = $request->input('image_path');
        if (is_string($imageName) && trim($imageName) !== '') {
            $state['gs_current_image_name'] = trim($imageName);
        }
        if (is_string($imagePath) && trim($imagePath) !== '') {
            $state['fp_image_path'] = trim($imagePath);
            $state['gs_current_image_path'] = trim($imagePath);
        }
        $state['updated_at'] = now()->toDateTimeString();
        session(['global_state' => $state]);

        return response()->json(['ok' => true]);
    }

    public function confirmPendingImport(Request $request)
    {
        $imageName = $request->input('image_name');
        $imagePath = $request->input('image_path');

        // store/merge state in session instead of DB table
        $state = session('global_state', []);
        if (!is_array($state)) {
            $state = [];
        }
        if (is_string($imageName) && trim($imageName) !== '') {
            $state['gs_current_image_name'] = trim($imageName);
            $state['fp_image_name'] = trim($imageName);
        }
        if (is_string($imagePath) && trim($imagePath) !== '') {
            $state['fp_image_path'] = trim($imagePath);
            $state['gs_current_image_path'] = trim($imagePath);
        }
        $state['gs_firstname'] = $state['gs_firstname'] ?? '';
        $state['gs_lastname'] = $state['gs_lastname'] ?? '';
        $state['gs_dob'] = $state['gs_dob'] ?? '';
        $state['created_at'] = $state['created_at'] ?? now()->toDateTimeString();
        $state['updated_at'] = now()->toDateTimeString();
        session(['global_state' => $state]);

        return response()->json(['ok' => true]);
    }

    public function resetToDemo()
    {
        try {
            $allTables = collect(Schema::getTableListing());
            $pendingTables = $allTables
                ->filter(fn (string $table) => Str::contains($table, 'pending'))
                ->values();

            if ($pendingTables->isEmpty() && Schema::hasTable('faxes_pending')) {
                $pendingTables = collect(['faxes_pending']);
            }

            if ($pendingTables->isEmpty()) {
                return redirect()->back()->with([
                    'globalStatusType' => 'error',
                    'globalStatusMessage' => 'No pending tables were found to reset.',
                ]);
            }

            $deletedRows = 0;
            foreach ($pendingTables as $table) {
                $deletedRows += DB::table($table)->delete();
            }

            // remove session-based global state
            session()->forget('global_state');

            $deletedFiles = 0;
            $allFiles = Storage::disk('public')->allFiles();
            foreach ($allFiles as $file) {
                Storage::disk('public')->delete($file);
                $deletedFiles++;
            }

            $message = 'Reset complete. Cleared ' . $deletedRows . ' row(s) from: ' . $pendingTables->implode(', ');
            if ($deletedFiles > 0) {
                $message .= '. Deleted ' . $deletedFiles . ' file(s) from storage.';
            }

            return redirect()->back()->with([
                'globalStatusType' => 'success',
                'globalStatusMessage' => $message,
            ]);
        } catch (Throwable $exception) {
            return redirect()->back()->with([
                'globalStatusType' => 'error',
                'globalStatusMessage' => 'Reset failed. ' . $exception->getMessage(),
            ]);
        }
    }

    public function globalState()
    {
        try {
            $state = session('global_state');
            $rows = is_array($state) ? collect([$state]) : collect();

            return view('global-state', [
                'activeMenu' => 'global',
                'rows' => $rows,
            ]);
        } catch (Throwable $exception) {
            return view('global-state', [
                'activeMenu' => 'global',
                'rows' => collect(),
                'statusType' => 'error',
                'statusMessage' => 'Unable to load global_state. ' . $exception->getMessage(),
            ]);
        }
    }

    public function lastNameOcr()
    {
        return $this->ocrStepView('lastname-ocr', 'lastname');
    }

    public function dobOcr()
    {
        return $this->ocrStepView('dob-ocr', 'dob');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function ocrStepView(string $view, string $activeMenu)
    {
        try {
            $rows = DB::table('faxes_pending')->limit(100)->get();
            $previewMap = $rows->map(function ($row) {
                $data = (array) $row;
                $previewUrl = $this->resolvePreviewUrl($data);
                $rowId = $data['fp_id'] ?? null;

                if ($previewUrl === null && $rowId !== null && $this->resolvePreviewFilePath($data) !== null) {
                    $previewUrl = route('fax.pending-preview', ['pendingId' => $rowId]);
                }

                return $previewUrl;
            });

            $requestedRecordId = request()->query('record_id');
            if ($requestedRecordId !== null) {
                session(['ocr_review_record_id' => (int) $requestedRecordId]);
            }

            $selectedRecordId = (int) session('ocr_review_record_id', 0);
            $selectedRow = $selectedRecordId > 0
                ? $rows->first(fn ($row) => (int) ($row->fp_id ?? 0) === $selectedRecordId)
                : null;

            if (!$selectedRow) {
                $selectedRow = $rows->first();
            }

            $selectedRowData = $selectedRow ? (array) $selectedRow : [];
            if (!empty($selectedRowData['fp_id'])) {
                session(['ocr_review_record_id' => (int) $selectedRowData['fp_id']]);
            }

            $globalStateRow = null;
            if (!empty($selectedRowData)) {
                $documentName = $this->pickValue($selectedRowData, ['fp_image_name', 'file_name', 'filename', 'document_name']) ?? $this->resolveFileName($selectedRowData);
                $sessionState = session('global_state');
                if (!empty($documentName) && is_array($sessionState) && ($sessionState['gs_current_image_name'] ?? null) === $documentName) {
                    $globalStateRow = (object) $sessionState;
                }
            }

            $ocrSourceData = $globalStateRow ? (array) $globalStateRow : $selectedRowData;
            $ocrReviewState = $this->buildOcrReviewState($ocrSourceData);
            $selectedDocument = [
                'id' => $selectedRowData['fp_id'] ?? null,
                'fileName' => $this->pickValue($ocrSourceData, [
                    'file_name',
                    'filename',
                    'fp_image_name',
                    'document_name',
                    'pdf_name',
                    'source_file_name',
                    'source_filename',
                ]) ?? $this->resolveFileName($selectedRowData),
            ];

            return view($view, [
                'activeMenu' => $activeMenu,
                'rows' => $rows,
                'previewMap' => $previewMap,
                'ocrReviewState' => $ocrReviewState,
                'ocrSelectedRecordId' => $selectedRowData['fp_id'] ?? null,
                'ocrSelectedDocument' => $selectedDocument,
            ]);
        } catch (Throwable $exception) {
            return view($view, [
                'activeMenu' => $activeMenu,
                'statusType' => 'error',
                'statusMessage' => 'Unable to load data. ' . $exception->getMessage(),
                'rows' => collect(),
                'previewMap' => collect(),
                'ocrReviewState' => $this->buildOcrReviewState([]),
                'ocrSelectedRecordId' => null,
                'ocrSelectedDocument' => ['id' => null, 'fileName' => null],
            ]);
        }
    }

    private function buildOcrReviewState(array $row): array
    {
        return [
            [
                'label' => 'Firstname',
                'guess' => $this->pickValue($row, ['fp_firstname_ocr', 'firstname_ocr', 'first_name_ocr', 'firstname_guess', 'first_name_guess', 'firstname', 'first_name']),
                'coords' => $this->pickValue($row, ['fp_firstname_coords', 'firstname_coords', 'first_name_coords', 'firstname_bbox', 'first_name_bbox', 'firstname_coordinates', 'first_name_coordinates']),
                'score' => $this->pickValue($row, ['fp_firstname_score', 'firstname_score', 'first_name_score', 'firstname_confidence', 'first_name_confidence']),
                'human' => $this->pickValue($row, ['fp_firstname_human', 'firstname_human', 'first_name_human', 'firstname_confirmed_value', 'first_name_confirmed_value']),
                'confirmed' => $this->pickValue($row, ['fp_firstname_confirmed', 'firstname_confirmed', 'first_name_confirmed', 'firstname_human_confirmed', 'first_name_human_confirmed']),
            ],
            [
                'label' => 'Lastname',
                'guess' => $this->pickValue($row, ['fp_lastname_ocr', 'lastname_ocr', 'last_name_ocr', 'lastname_guess', 'last_name_guess', 'lastname', 'last_name']),
                'coords' => $this->pickValue($row, ['fp_lastname_coords', 'lastname_coords', 'last_name_coords', 'lastname_bbox', 'last_name_bbox', 'lastname_coordinates', 'last_name_coordinates']),
                'score' => $this->pickValue($row, ['fp_lastname_score', 'lastname_score', 'last_name_score', 'lastname_confidence', 'last_name_confidence']),
                'human' => $this->pickValue($row, ['fp_lastname_human', 'lastname_human', 'last_name_human', 'lastname_confirmed_value', 'last_name_confirmed_value']),
                'confirmed' => $this->pickValue($row, ['fp_lastname_confirmed', 'lastname_confirmed', 'last_name_confirmed', 'lastname_human_confirmed', 'last_name_human_confirmed']),
            ],
            [
                'label' => 'DOB',
                'guess' => $this->pickValue($row, ['fp_dob_ocr', 'dob_ocr', 'date_of_birth_ocr', 'dob_guess', 'date_of_birth_guess', 'dob', 'date_of_birth']),
                'coords' => $this->pickValue($row, ['fp_dob_coords', 'dob_coords', 'date_of_birth_coords', 'dob_bbox', 'date_of_birth_bbox', 'dob_coordinates', 'date_of_birth_coordinates']),
                'score' => $this->pickValue($row, ['fp_dob_score', 'dob_score', 'date_of_birth_score', 'dob_confidence', 'date_of_birth_confidence']),
                'human' => $this->pickValue($row, ['fp_dob_human', 'dob_human', 'date_of_birth_human', 'dob_confirmed_value', 'date_of_birth_confirmed_value']),
                'confirmed' => $this->pickValue($row, ['fp_dob_confirmed', 'dob_confirmed', 'date_of_birth_confirmed', 'dob_human_confirmed', 'date_of_birth_human_confirmed']),
            ],
        ];
    }

    private function buildPreviewItems(Collection $rows, string $source = 'available'): Collection
    {
        return $rows
            ->map(function ($row) use ($source) {
                $data = (array) $row;
                $previewUrl = $this->resolvePreviewUrl($data);
                $rowId = $data['id'] ?? null;
                $pendingPreviewId = $data['pending_preview_id'] ?? null;

                if ($previewUrl === null && $this->resolvePreviewFilePath($data) !== null) {
                    if ($pendingPreviewId !== null) {
                        $previewUrl = route('fax.pending-preview', ['pendingId' => $pendingPreviewId]);
                    } elseif ($rowId !== null && $source === 'available') {
                        $previewUrl = route('fax.preview', ['faxId' => $rowId]);
                    }
                }

                return [
                    'id' => $rowId,
                    'previewUrl' => $previewUrl,
                    'label' => $data['fax_id'] ?? $data['document_id'] ?? $data['external_id'] ?? null,
                ];
            })
            ->filter(fn (array $item) => !empty($item['previewUrl']))
            ->values();
    }

    private function buildFetchedFileNames(Collection $rows): Collection
    {
        return $rows
            ->map(function ($row, $index) {
                $data = (array) $row;
                return $this->resolveFileName($data) ?? ('Record ' . ($index + 1));
            })
            ->values();
    }

    private function resolvePreviewUrl(array $row): ?string
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
            if ($value === '') {
                continue;
            }

            if (Str::startsWith($value, ['http://', 'https://', 'data:image/'])) {
                return $value;
            }
        }

        return null;
    }

    private function resolveFileName(array $row): ?string
    {
        $priorityColumns = [
            'file_name',
            'filename',
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

    private function pickValue(array $row, array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $row) && $row[$candidate] !== null && $row[$candidate] !== '') {
                return $row[$candidate];
            }
        }

        return null;
    }

    private function resolvePendingRecordKeyFromRow($row): ?int
    {
        $data = (array) $row;
        foreach (['id', 'fp_id'] as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                continue;
            }
            if (is_numeric($data[$key])) {
                $value = (int) $data[$key];
                if ($value > 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function findPendingRowByRecordKey(int $recordKey)
    {
        if ($recordKey <= 0) {
            return null;
        }

        return DB::table('faxes_pending')->where('fp_id', $recordKey)->first();
    }
}
