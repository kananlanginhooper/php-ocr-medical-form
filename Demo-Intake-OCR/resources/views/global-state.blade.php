@extends('layouts.app-shell')

@section('content')
    <h1>Global State</h1>
    <p class="lead">Current OCR progress for all records tracked in global_state.</p>

    @if (!empty($statusMessage))
        <div
            style="margin-bottom:16px;border-radius:8px;padding:12px 14px;font-size:14px;
            {{ ($statusType ?? 'success') === 'error' ? 'background:#fdeceb;border:1px solid #f2c8c5;color:#7f1d1d;' : 'background:#e8f6ec;border:1px solid #b7e1c1;color:#184d25;' }}"
        >
            {{ $statusMessage }}
        </div>
    @endif

    @php
        $rows = $rows ?? collect();
        $firstRow = $rows->first();
        $columns = $firstRow ? array_keys((array) $firstRow) : [];
    @endphp

    @if ($rows->isEmpty())
        <div style="border:1px solid #d7dee6;border-radius:8px;padding:14px;background:#f9fbfd;color:#5f6b7a;">
            No records in global_state yet. Process a document through the OCR pipeline to populate this table.
        </div>
    @else
        <div style="overflow:auto;border:1px solid #d7dee6;border-radius:10px;margin-top:16px;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead style="background:#eef3f9;">
                    <tr>
                        @foreach ($columns as $column)
                            <th style="text-align:left;padding:10px 14px;border-bottom:1px solid #d7dee6;white-space:nowrap;">{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr style="{{ $loop->even ? 'background:#f9fbfd;' : '' }}">
                            @foreach ($columns as $column)
                                @php
                                    $value = $row->{$column} ?? '';
                                    if (is_array($value) || is_object($value)) {
                                        $value = json_encode($value);
                                    }
                                    $isEmpty = ($value === null || $value === '');
                                @endphp
                                <td style="padding:10px 14px;border-bottom:1px solid #edf1f6;vertical-align:top;{{ $isEmpty ? 'color:#9aa8b5;' : '' }}">
                                    {{ $isEmpty ? '—' : $value }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p style="margin-top:12px;font-size:13px;color:#5f6b7a;">{{ $rows->count() }} record(s) in global_state.</p>
    @endif
@endsection
