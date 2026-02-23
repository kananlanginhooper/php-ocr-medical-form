@extends('layouts.app-shell')

@section('content')
    <h1>Date Of Birth OCR</h1>
    <p class="lead">Run OCR to find the Date Of Birth label location and store JSON in <code>fp_dob_location</code>.</p>

    @if (!empty($statusMessage))
        <div
            style="margin-bottom:16px;border-radius:8px;padding:12px 14px;font-size:14px;
            {{ ($statusType ?? 'success') === 'error' ? 'background:#fdeceb;border:1px solid #f2c8c5;color:#7f1d1d;' : 'background:#e8f6ec;border:1px solid #b7e1c1;color:#184d25;' }}"
        >
            {{ $statusMessage }}
        </div>
    @endif

    @php
        $selectedRecordId = $selectedRecordId ?? null;
        $selectedDocumentName = $selectedDocumentName ?? null;
        $previewUrl = $previewUrl ?? null;
        $publicPreviewUrl = $previewUrl
            ?? (!empty($selectedRecordId) ? route('fax.pending-preview', ['pendingId' => $selectedRecordId]) : null);
        $locationX = $locationX ?? null;
        $locationY = $locationY ?? null;
        $locationWidth = $locationWidth ?? null;
        $locationScore = $locationScore ?? null;
    @endphp

    @if (empty($selectedRecordId))
        <div style="border:1px solid #d7dee6;border-radius:8px;padding:14px;background:#f9fbfd;color:#5f6b7a;">
            No document selected. Go to Step 2 (Pending Imports), select a document, then come back.
        </div>
    @else
        <div style="border:1px solid #d7dee6;border-radius:10px;background:#fff;padding:14px;margin-bottom:12px;">
            <h2 style="margin:0 0 10px;font-size:17px;">1) Find Location Of Date Of Birth Label</h2>
            <div style="display:flex;gap:14px;align-items:flex-start;">
                <div style="flex:0 0 auto;">
                    <button id="run-label-btn" type="button"
                            style="padding:9px 14px;background:#0f62d6;color:#fff;border:0;border-radius:6px;cursor:pointer;font-weight:600;">
                        Run Step 1
                    </button>
                    <div style="margin-top:10px;font-size:13px;color:#475467;line-height:1.5;">
                        X: <strong id="loc-x">{{ $locationX ?? '—' }}</strong>
                        &nbsp;&nbsp;Y: <strong id="loc-y">{{ $locationY ?? '—' }}</strong>
                        &nbsp;&nbsp;Width: <strong id="loc-width">{{ $locationWidth ?? '—' }}</strong>
                        &nbsp;&nbsp;Score: <strong id="loc-score">{{ $locationScore ?? '—' }}</strong>
                    </div>
                    <p style="margin:6px 0 0;color:#5f6b7a;font-size:12px;">
                        Saved as JSON into <code>global_state.fp_dob_location.label</code>.
                    </p>
                </div>
                <div id="label-log"
                     style="flex:1;min-width:220px;max-height:150px;overflow-y:auto;
                            font-family:monospace;font-size:12px;background:#f4f6f8;
                            border:1px solid #d7dee6;border-radius:6px;padding:8px;color:#344054;">
                    <em style="color:#9aa5b4;">Step 1 output will appear here…</em>
                </div>
            </div>
            <div style="margin-top:12px;">
                <h3 style="margin:0 0 8px;font-size:14px;color:#344054;">Step 1 Preview</h3>
                @if (!empty($publicPreviewUrl))
                    <img id="ocr-source-img"
                         src="{{ $publicPreviewUrl }}"
                         alt="Document preview source"
                         style="display:none;">
                    <canvas id="label-slice-canvas"
                            style="display:block;width:100%;height:100px;border:1px solid #d7dee6;border-radius:8px;background:#eef3f9;"></canvas>
                    <p id="label-slice-note" style="margin:8px 0 0;font-size:12px;color:#5f6b7a;">Top of document</p>
                @else
                    <div style="border:1px solid #d7dee6;border-radius:8px;padding:12px;background:#fff;color:#5f6b7a;">
                        No preview image available for this record.
                    </div>
                @endif
            </div>
        </div>

        <script>
            (function () {
                var csrf = @json(csrf_token());
                var labelUrl = @json(route('dob.label'));
                var labelLocation = {
                    x: {{ $locationX !== null ? (float) $locationX : 'null' }},
                    y: {{ $locationY !== null ? (float) $locationY : 'null' }},
                    width: {{ $locationWidth !== null ? (float) $locationWidth : 'null' }},
                    score: {{ $locationScore !== null ? (float) $locationScore : 'null' }}
                };
                var labelSliceY = labelLocation.y;

                var appendLog = function (container, msg, isError) {
                    if (!container) return;
                    var line = document.createElement('div');
                    line.textContent = msg;
                    line.style.color = isError ? '#b42318' : '#344054';
                    container.appendChild(line);
                    container.scrollTop = container.scrollHeight;
                };

                var postJson = function (url, payload) {
                    var req = {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'Accept': 'application/json',
                        }
                    };
                    if (payload && typeof payload === 'object') {
                        req.headers['Content-Type'] = 'application/json';
                        req.body = JSON.stringify(payload);
                    }
                    return fetch(url, req).then(function (r) {
                        return r.json().then(function (body) {
                            return { ok: r.ok, body: body };
                        });
                    });
                };

                var renderSliceOnCanvas = function () {
                    var img = document.getElementById('ocr-source-img');
                    var canvas = document.getElementById('label-slice-canvas');
                    var note = document.getElementById('label-slice-note');
                    if (!img || !canvas || !img.naturalWidth || !img.naturalHeight) return;

                    var ctx = canvas.getContext('2d');
                    var canvasWidth = Math.max(1, Math.round(canvas.clientWidth || 1));
                    var canvasHeight = Math.max(1, Math.round(canvas.clientHeight || 100));
                    if (canvas.width !== canvasWidth || canvas.height !== canvasHeight) {
                        canvas.width = canvasWidth;
                        canvas.height = canvasHeight;
                    }

                    var scale = Math.max(canvasWidth / img.naturalWidth, canvasHeight / img.naturalHeight);
                    var srcWidth = canvasWidth / scale;
                    var srcHeight = canvasHeight / scale;
                    var srcX = Math.max(0, (img.naturalWidth - srcWidth) / 2);
                    var srcY = 0;

                    if (labelSliceY != null) {
                        srcY = Math.max(0, Math.min(labelSliceY - (srcHeight / 2), img.naturalHeight - srcHeight));
                        if (note) note.textContent = 'Centred around Y ' + Math.round(labelSliceY) + ' px';
                    } else if (note) {
                        note.textContent = 'Top of document';
                    }

                    ctx.clearRect(0, 0, canvasWidth, canvasHeight);
                    ctx.drawImage(img, srcX, srcY, srcWidth, srcHeight, 0, 0, canvasWidth, canvasHeight);

                    if (labelLocation.x != null && labelLocation.y != null && labelLocation.width != null) {
                        var boxHeight = Math.max(24, Math.round(img.naturalHeight * 0.018));
                        var boxX = (Number(labelLocation.x) - srcX) * scale;
                        var boxY = (Number(labelLocation.y) - srcY) * scale;
                        var boxW = Number(labelLocation.width) * scale;
                        var boxH = boxHeight * scale;

                        ctx.save();
                        ctx.strokeStyle = '#e11d48';
                        ctx.lineWidth = 2;
                        ctx.fillStyle = 'rgba(225, 29, 72, 0.08)';
                        ctx.fillRect(boxX, boxY, boxW, boxH);
                        ctx.strokeRect(boxX, boxY, boxW, boxH);
                        ctx.restore();
                    }
                };

                (function initImage() {
                    var img = document.getElementById('ocr-source-img');
                    if (!img) return;
                    var draw = function () { renderSliceOnCanvas(); };
                    if (img.complete && img.naturalHeight) {
                        draw();
                    } else {
                        img.addEventListener('load', draw);
                    }
                    window.addEventListener('resize', draw);
                })();

                var labelBtn = document.getElementById('run-label-btn');
                var labelLog = document.getElementById('label-log');
                if (labelBtn && labelLog) {
                    labelBtn.addEventListener('click', function () {
                        labelBtn.disabled = true;
                        labelBtn.style.opacity = '0.65';
                        labelLog.innerHTML = '';
                        appendLog(labelLog, 'Running Step 1: Find "Date Of Birth" label...');

                        postJson(labelUrl)
                            .then(function (res) {
                                if (!res.ok || !res.body.ok) {
                                    throw new Error(res.body.message || 'Step 1 failed.');
                                }
                                var label = (res.body && res.body.label) ? res.body.label : {};
                                var width = (label.width != null)
                                    ? Number(label.width)
                                    : ((label.x1 != null && label.x != null) ? (Number(label.x1) - Number(label.x)) : null);

                                document.getElementById('loc-x').textContent = (label.x ?? '—');
                                document.getElementById('loc-y').textContent = (label.y ?? '—');
                                document.getElementById('loc-width').textContent = (width != null && !Number.isNaN(width)) ? width : '—';
                                document.getElementById('loc-score').textContent = (label.confidence ?? '—');

                                labelLocation = {
                                    x: (label.x != null) ? Number(label.x) : null,
                                    y: (label.y != null) ? Number(label.y) : null,
                                    width: (width != null && !Number.isNaN(width)) ? width : null,
                                    score: (label.confidence != null) ? Number(label.confidence) : null
                                };
                                labelSliceY = labelLocation.y;
                                renderSliceOnCanvas();
                                appendLog(labelLog, 'Step 1 complete: label location and canvas updated.');
                            })
                            .catch(function (err) {
                                appendLog(labelLog, 'Error: ' + (err.message || 'Step 1 failed.'), true);
                            })
                            .finally(function () {
                                labelBtn.disabled = false;
                                labelBtn.style.opacity = '1';
                            });
                    });
                }
            })();
        </script>
    @endif
@endsection
