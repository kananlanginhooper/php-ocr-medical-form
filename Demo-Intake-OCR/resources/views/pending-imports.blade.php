@extends('layouts.app-shell')

@section('content')
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <style>
        .status-message { margin-bottom:16px;border-radius:8px;padding:12px 14px;font-size:14px; }
        .status-message.success { background:#e8f6ec;border:1px solid #b7e1c1;color:#184d25; }
        .status-message.error { background:#fdeceb;border:1px solid #f2c8c5;color:#7f1d1d; }

        .empty-state { border:1px solid #d7dee6;border-radius:8px;padding:14px;background:#f9fbfd;color:#5f6b7a; }

        .thumb-grid { display:flex;flex-wrap:wrap;gap:16px;margin-top:8px; }
        .thumb-card { display:flex;flex-direction:column;align-items:center;gap:8px;padding:10px;border-radius:10px;cursor:pointer;text-align:center;width:160px;border:2px solid #d7dee6;background:#fff;box-shadow:none; }
        .thumb-card.selected { border:2px solid #0f62d6;background:#e7f0ff;box-shadow:0 0 0 3px rgba(15,98,214,0.15); }
        .thumb-img { width:140px;height:105px;object-fit:cover;border-radius:6px;border:1px solid #d7dee6;background:#edf1f6;display:block; }
        .thumb-fallback { width:140px;height:105px;border-radius:6px;border:1px solid #d7dee6;background:#edf1f6;display:flex;align-items:center;justify-content:center;padding:6px; }
        .thumb-fallback span { font-size:11px;color:#9aa8b5;word-break:break-all; }
        .thumb-label { font-size:11px;font-weight:600;color:#475467;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block; }
        .thumb-card.selected .thumb-label { color:#0b4db1; }
    </style>

    <h1>Pending Imports</h1>
    <p class="lead">Click a document to select it and begin OCR processing.</p>

    @if (!empty($statusMessage))
        <div class="status-message {{ ($statusType ?? 'success') === 'error' ? 'error' : 'success' }}">
            {{ $statusMessage }}
        </div>
    @endif

    @php
        $rows = $rows ?? collect();
        $previewMap = $previewMap ?? collect();
        $selectedRecordId = 0; // Always start with no selection
    @endphp

    @if ($rows->isEmpty())
        <div class="empty-state">
            No pending imports found. Fetch new faxes first.
        </div>
    @else
        <div id="thumb-grid" class="thumb-grid">
            @foreach ($rows as $row)
                @php
                    $rowId      = (int) ($row->id ?? $row->fp_id ?? 0);
                    $isSelected = $selectedRecordId > 0 && $rowId === $selectedRecordId;
                    $previewUrl = $previewMap[$loop->index] ?? null;
                    $label      = $row->fp_image_name ?? $row->fp_id ?? $rowId;
                @endphp

                <div
                    class="thumb-card{{ $isSelected ? ' selected' : '' }}"
                    data-id="{{ $rowId }}"
                    data-image="{{ $row->fp_image_name ?? '' }}"
                    data-image-path="{{ $row->fp_image_path ?? '' }}"
                    data-url="{{ route('fax.select-record', ['recordId' => $rowId]) }}"
                    onclick="selectThumb(this)"
                >
                    @if ($previewUrl)
                        <img
                            src="{{ $previewUrl }}"
                            alt="{{ $label }}"
                            loading="lazy"
                            class="thumb-img"
                        >
                    @else
                        <div class="thumb-fallback">
                            <span>{{ $label }}</span>
                        </div>
                    @endif

                    <span class="thumb-label">{{ $isSelected ? '✓ Selected' : $label }}</span>
                </div>
            @endforeach
        </div>

        <div style="margin-top:20px;">
            <button
                id="confirm-btn"
                disabled
                onclick="confirmSelection()"
                style="padding:10px 24px;font-size:14px;font-weight:700;border-radius:8px;border:1px solid #a8aeb5;background:#a8aeb5;color:#fff;cursor:not-allowed;"
                onmouseover="if(!this.disabled)this.style.background='#0b4db1';"
                onmouseout="if(!this.disabled)this.style.background='#0f62d6';"
            >Confirm Selection</button>
        </div>
    @endif

    <script>
    var selectedImageName = null;
    var selectedImagePath = null;
    var confirmUrl = '{{ route('fax.confirm-import') }}';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    function selectThumb(card) {
        // Optimistic UI update immediately on click
        document.querySelectorAll('.thumb-card').forEach(function(c) {
            c.classList.remove('selected');
            var label = c.querySelector('.thumb-label');
            if (label) {
                var img = c.querySelector('img');
                label.textContent = img ? (img.alt || c.dataset.id) : c.dataset.id;
            }
        });
        card.classList.add('selected');
        var label = card.querySelector('.thumb-label');
        if (label) label.textContent = '✓ Selected';

        selectedImageName = card.dataset.image || null;
        selectedImagePath = card.dataset.imagePath || null;
        var btn = document.getElementById('confirm-btn');
        if (btn) {
            btn.disabled = !selectedImageName;
            if (selectedImageName) {
                btn.style.background = '#0f62d6';
                btn.style.borderColor = '#0b4db1';
                btn.style.cursor = 'pointer';
            } else {
                btn.style.background = '#a8aeb5';
                btn.style.borderColor = '#a8aeb5';
                btn.style.cursor = 'not-allowed';
            }
        }

        fetch(card.dataset.url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                image_name: card.dataset.image || null,
                image_path: card.dataset.imagePath || null
            })
        })
        .then(function(r) {
            if (!r.ok) throw new Error('Server error: ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (!data.ok) throw new Error('Response not ok');
        })
        .catch(function(err) {
            console.error('selectThumb failed:', err);
            card.classList.remove('selected');
            var label = card.querySelector('.thumb-label');
            if (label) {
                var img = card.querySelector('img');
                label.textContent = img ? (img.alt || card.dataset.id) : card.dataset.id;
            }
            selectedImageName = null;
            selectedImagePath = null;
            var btn = document.getElementById('confirm-btn');
            if (btn) {
                btn.disabled = true;
                btn.style.background = '#a8aeb5';
                btn.style.borderColor = '#a8aeb5';
                btn.style.cursor = 'not-allowed';
            }
        });
    }

    function confirmSelection() {
        if (!selectedImageName) return;
        var btn = document.getElementById('confirm-btn');
        btn.disabled = true;
        btn.textContent = 'Saving…';

        fetch(confirmUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({
                image_name: selectedImageName,
                image_path: selectedImagePath
            }),
        })
        .then(function(r) {
            if (!r.ok) throw new Error('Server error: ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (!data.ok) throw new Error('Response not ok');
            btn.textContent = '✓ Confirmed';
            btn.style.background = '#0e7a46';
            btn.style.borderColor = '#0e7a46';
        })
        .catch(function(err) {
            console.error('confirmSelection failed:', err);
            btn.disabled = false;
            btn.textContent = 'Confirm Selection';
        });
    }
    </script>
@endsection
