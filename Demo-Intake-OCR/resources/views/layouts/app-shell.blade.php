<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Demo Intake OCR' }}</title>
    <style>
        :root {
            --bg: #f1f4f8;
            --panel: #ffffff;
            --line: #d7dee6;
            --text: #101828;
            --muted: #5f6b7a;
            --accent: #0f62d6;
            --accent-strong: #0b4db1;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Arial, sans-serif;
        }

        .shell {
            min-height: 100vh;
            display: flex;
        }

        .sidebar {
            width: 270px;
            background: #0f1724;
            color: #e6edf7;
            padding: 22px 16px;
            border-right: 1px solid #1e293b;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 60;
            overflow: auto;
        }

        .brand {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 22px;
        }

        .pipeline {
            display: flex;
            flex-direction: column;
            margin-bottom: 20px;
            flex: 1;
        }

        .step-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 14px;
            border-radius: 10px;
            text-decoration: none;
            color: #64748b;
            border: 1px solid transparent;
        }

        .step-link:hover:not(.active) {
            background: #162439;
            color: #cbd5e1;
        }

        .step-link.done {
            color: #6ee7b7;
            background: #052e1e;
            border-color: #065f46;
        }

        .step-link.active {
            background: #0f62d6;
            border-color: #3d84f0;
            color: #fff;
        }

        .step-badge {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .step-link.done .step-badge {
            background: #065f46;
            color: #6ee7b7;
        }

        .step-link.active .step-badge {
            background: rgba(255,255,255,0.22);
        }

        .step-info {
            flex: 1;
            min-width: 0;
        }

        .step-label {
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .step-sub {
            font-size: 11px;
            opacity: 0.65;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .step-connector {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 3px 0;
            gap: 0;
        }

        .step-connector::before {
            content: '';
            width: 2px;
            height: 10px;
            background: #1e3a5f;
        }

        .step-connector::after {
            content: '';
            width: 0;
            height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 7px solid #1e3a5f;
        }

        /* Global state top bar */
        .gs-panel {
            position: fixed;
            top: 0;
            left: 270px; /* don't overlap the fixed sidebar */
            right: 0;
            height: 72px;
            background: #f8fafc;
            border-bottom: 1px solid #d7dee6;
            display: flex;
            align-items: center;
            padding: 12px 20px;
            gap: 18px;
            z-index: 50;
            font-size: 13px;
        }

        .gs-row {
            display: flex;
            gap: 18px;
            align-items: center;
            flex: 1;
            overflow: auto;
        }

        .gs-panel-heading {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #8fa0b5;
            margin-bottom: 14px;
        }

        .gs-panel-heading a {
            color: inherit;
            text-decoration: none;
        }

        .gs-panel-heading a:hover {
            color: #0f62d6;
        }

        .gs-field {
            margin-bottom: 14px;
        }

        .gs-field-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #8fa0b5;
            margin-bottom: 3px;
        }

        .gs-field-value {
            font-size: 13px;
            font-weight: 600;
            color: #101828;
            word-break: break-word;
        }

        .gs-field-value.empty {
            color: #b0bec9;
            font-weight: 400;
            font-style: italic;
        }

        .gs-field-value.confirmed {
            color: #0e7a46;
        }

        .gs-divider {
            border: none;
            border-top: 1px solid #d7dee6;
            margin: 10px 0 14px;
        }

        .gs-no-record {
            color: #9aa8b5;
            font-size: 12px;
            font-style: italic;
        }

        .content {
            flex: 1;
            padding: 26px;
            margin-top: 84px; /* make room for top global bar */
            margin-left: 270px; /* make room for fixed sidebar */
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 24px;
        }

        .reset-button {
            border: 1px solid #7f1d1d;
            background: #a61b1b;
            color: #ffffff;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            width: auto;
            margin: 0;
        }

        .reset-button:hover {
            background: #8f1717;
        }

        .gs-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
        }

        .global-status {
            margin-bottom: 12px;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 14px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 30px;
        }

        .lead {
            margin: 0 0 20px;
            color: var(--muted);
            font-size: 15px;
        }


    </style>
</head>
<body>
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">Demo Intake OCR</div>

            @php
                $activeMenu = $activeMenu ?? '';
                $steps = [
                    ['key' => 'fetch',     'label' => 'Fetch New Faxes',  'sub' => 'Pull from fax service',   'route' => 'fax.index',      'humanCol' => null],
                    ['key' => 'pending',   'label' => 'Pending Imports',   'sub' => 'Review & process',        'route' => 'fax.pending',    'humanCol' => null],
                    ['key' => 'firstname', 'label' => 'First Name OCR',    'sub' => 'Extract first name',      'route' => 'firstname.index','humanCol' => 'fp_firstname_human'],
                    ['key' => 'lastname',  'label' => 'Last Name OCR',     'sub' => 'Extract last name',       'route' => 'lastname.index', 'humanCol' => 'fp_lastname_human'],
                    ['key' => 'dob',       'label' => 'Date Of Birth OCR',  'sub' => 'Extract date of birth',   'route' => 'dob.index',      'humanCol' => 'fp_dob_human'],
                ];

                $selectedRecordId = session('ocr_review_record_id');
                $currentRecord = null;
                $currentDocumentName = null;
                
                if ($selectedRecordId) {
                    $pendingRow = \Illuminate\Support\Facades\DB::table('faxes_pending')
                        ->where('fp_id', $selectedRecordId)
                        ->first();
                    
                    if ($pendingRow) {
                        $currentDocumentName = $pendingRow->fp_image_name ?? $pendingRow->file_name ?? $pendingRow->filename ?? null;
                        if ($currentDocumentName) {
                            $sessionState = session('global_state');
                            if (is_array($sessionState) && ($sessionState['gs_current_image_name'] ?? null) === $currentDocumentName) {
                                $currentRecord = (object) $sessionState;
                            }
                        }
                    }
                }
            @endphp

            <div class="pipeline">
                @foreach ($steps as $i => $step)
                    @php
                        $isActive = $step['key'] === $activeMenu;
                        $isDone = false;
                        if (!$isActive) {
                            if ($step['key'] === 'fetch') {
                                $isDone = session('pipeline_fetch_done') === true;
                            } elseif ($step['key'] === 'pending') {
                                $isDone = session('pipeline_pending_done') === true;
                            } else {
                                $isDone = $step['humanCol'] !== null
                                        && $currentRecord !== null
                                        && ($currentRecord->{$step['humanCol']} ?? null) !== null
                                        && ($currentRecord->{$step['humanCol']} ?? '') !== '';
                            }
                        }
                    @endphp
                    <a href="{{ route($step['route']) }}"
                       class="step-link {{ $isActive ? 'active' : ($isDone ? 'done' : '') }}">
                        <span class="step-badge">{{ $isDone ? '✓' : ($i + 1) }}</span>
                        <div class="step-info">
                            <div class="step-label">{{ $step['label'] }}</div>
                            <div class="step-sub">{{ $step['sub'] }}</div>
                        </div>
                    </a>
                    @if (!$loop->last)
                        <div class="step-connector"></div>
                    @endif
                @endforeach
            </div>

            
        </aside>

        <aside class="gs-panel">
            <div class="gs-panel-heading" style="margin-right:10px;">
                <a href="{{ route('global.index') }}">Global State</a>
            </div>

            <div class="gs-row">
                @if ($currentRecord)
                    @php
                        $gsFields = [
                            ['label' => 'Document',   'value' => $currentDocumentName ?? null],
                            ['label' => 'First Name',  'value' => $currentRecord->fp_firstname_human ?? null],
                            ['label' => 'Last Name',   'value' => $currentRecord->fp_lastname_human ?? null],
                            ['label' => 'Date of Birth','value' => $currentRecord->fp_dob_human ?? null],
                        ];
                    @endphp

                    @foreach ($gsFields as $field)
                        <div class="gs-field" style="min-width:140px;">
                            <div class="gs-field-label">{{ $field['label'] }}</div>
                            @if ($field['value'] !== null && $field['value'] !== '')
                                <div class="gs-field-value confirmed">{{ $field['value'] }}</div>
                            @else
                                <div class="gs-field-value empty">—</div>
                            @endif
                        </div>
                    @endforeach
                @else
                    <div class="gs-no-record">No record selected. Choose a document in Pending Imports.</div>
                @endif
            </div>
            <div class="gs-actions">
                <form action="{{ route('fax.reset-demo') }}" method="post" onsubmit="return confirm('Reset demo data by clearing pending tables?');" style="margin:0;">
                    @csrf
                    <button type="submit" class="reset-button">Reset Demo</button>
                </form>
            </div>
        </aside>

        <main class="content">
            @if (session('globalStatusMessage'))
                <div
                    class="global-status"
                    style="{{ session('globalStatusType', 'success') === 'error' ? 'background:#fdeceb;border:1px solid #f2c8c5;color:#7f1d1d;' : 'background:#e8f6ec;border:1px solid #b7e1c1;color:#184d25;' }}"
                >
                    {{ session('globalStatusMessage') }}
                </div>
            @endif

            <section class="panel">
                @yield('content')
            </section>
        </main>
    </div>
</body>
</html>
