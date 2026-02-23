@php
    $ocrReviewState = $ocrReviewState ?? [];
    $ocrSelectedRecordId = $ocrSelectedRecordId ?? null;
    $ocrSelectedDocument = $ocrSelectedDocument ?? ['id' => null, 'fileName' => null];

    $formatValue = function ($value) {
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        return $value;
    };

    $formatConfirmed = function ($value) {
        if ($value === null || $value === '') {
            return 'No';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'y', 'confirmed'], true) ? 'Yes' : 'No';
    };
@endphp

<aside style="border:1px solid #d7dee6;border-radius:10px;background:#f9fbfd;padding:14px;">
    <h2 style="margin:0 0 8px;font-size:18px;">OCR Review Panel</h2>
    <p style="margin:0 0 12px;color:#5f6b7a;font-size:13px;">
        @if (!empty($ocrSelectedDocument['id']) || !empty($ocrSelectedDocument['fileName']))
            Selected in Step 2:
            @if (!empty($ocrSelectedDocument['fileName']))
                {{ $ocrSelectedDocument['fileName'] }}
            @else
                Record {{ $ocrSelectedDocument['id'] }}
            @endif
            @if (!empty($ocrSelectedDocument['id']))
                (ID {{ $ocrSelectedDocument['id'] }})
            @endif
            <br>
            Processing this document now.
        @elseif (!empty($ocrSelectedRecordId))
            Record ID: {{ $ocrSelectedRecordId }}<br>
            Processing this document now.
        @else
            Global OCR state
        @endif
    </p>

    @foreach ($ocrReviewState as $field)
        <div style="border:1px solid #d7dee6;border-radius:8px;background:#fff;padding:10px;margin-bottom:10px;">
            <h3 style="margin:0 0 8px;font-size:15px;">{{ $field['label'] }}</h3>
            <div style="font-size:13px;display:grid;grid-template-columns:120px 1fr;gap:6px 8px;">
                <strong style="color:#344054;">OCR Guess</strong>
                <span>{{ $formatValue($field['guess']) ?? '—' }}</span>

                <strong style="color:#344054;">Coordinates</strong>
                <span>{{ $formatValue($field['coords']) ?? '—' }}</span>

                <strong style="color:#344054;">OCR Score</strong>
                <span>{{ $formatValue($field['score']) ?? '—' }}</span>

                <strong style="color:#344054;">Human Value</strong>
                <span>{{ $formatValue($field['human']) ?? '—' }}</span>

                <strong style="color:#344054;">Confirmed</strong>
                <span style="font-weight:600;color:{{ $formatConfirmed($field['confirmed']) === 'Yes' ? '#166534' : '#7f1d1d' }};">
                    {{ $formatConfirmed($field['confirmed']) }}
                </span>
            </div>
        </div>
    @endforeach
</aside>
