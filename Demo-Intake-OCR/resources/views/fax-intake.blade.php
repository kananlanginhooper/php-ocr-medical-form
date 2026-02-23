@extends('layouts.app-shell')

@section('content')
    <h1>Fetch New Faxes</h1>
    <p class="lead">Mock API action. Button press simulates a fax-service check and reads <code>available_faxes</code>.</p>

    @php
        $hasAvailableFaxes = $hasAvailableFaxes ?? false;
    @endphp

    <form action="{{ route('fax.check') }}" method="post">
        @csrf
        <button
            type="submit"
            style="width:100%;border:0;border-radius:12px;padding:28px 18px;background:{{ $hasAvailableFaxes ? '#2f9e44' : '#0f62d6' }};color:#fff;font-size:28px;font-weight:700;cursor:pointer;"
        >
            Get new PDFs from Fax Service
        </button>
    </form>

    @if (!empty($statusMessage))
        <div
            style="margin-top:18px;border-radius:8px;padding:12px 14px;font-size:14px;
            {{ ($statusType ?? 'success') === 'error' ? 'background:#fdeceb;border:1px solid #f2c8c5;color:#7f1d1d;' : 'background:#e8f6ec;border:1px solid #b7e1c1;color:#184d25;' }}"
        >
            {{ $statusMessage }}
        </div>
    @endif

    @php
        $previewItems = $previewItems ?? collect();
        $faxCount = $faxCount ?? 0;
        $fetchedFileNames = $fetchedFileNames ?? collect();
    @endphp

    @if ($previewItems->isNotEmpty())
        <div style="margin-top:20px;">
            <h2 style="margin:0 0 12px;font-size:20px;">Fetched Image Previews</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;">
                @foreach ($previewItems as $item)
                    <figure style="margin:0;border:1px solid #d7dee6;border-radius:10px;overflow:hidden;background:#fff;">
                        <img
                            src="{{ $item['previewUrl'] }}"
                            alt="Fetched fax preview"
                            loading="lazy"
                            style="display:block;width:100%;height:190px;object-fit:cover;background:#edf1f6;"
                        >
                        @if (!empty($item['label']) || !empty($item['id']))
                            <figcaption style="padding:8px 10px;font-size:12px;color:#5f6b7a;border-top:1px solid #edf1f6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                {{ $item['label'] ?? ('ID ' . $item['id']) }}
                            </figcaption>
                        @endif
                    </figure>
                @endforeach
            </div>
        </div>

    @endif

    @if ($fetchedFileNames->isNotEmpty())
        <div style="margin-top:14px;border:1px solid #d7dee6;border-radius:10px;padding:12px;background:#fff;">
            <h3 style="margin:0 0 8px;font-size:16px;">Fetched Files</h3>
            <ul style="margin:0;padding-left:18px;color:#364152;font-size:14px;">
                @foreach ($fetchedFileNames as $fileName)
                    <li style="margin:3px 0;">{{ $fileName }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
