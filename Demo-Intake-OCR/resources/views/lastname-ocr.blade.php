@extends('layouts.app-shell')

@section('content')
    <h1>Last Name OCR</h1>
    <p class="lead">Run OCR using position (x,y), store location JSON in <code>fp_lastname_location</code>, then confirm the extracted text.</p>

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
        $handX = $handX ?? null;
        $handY = $handY ?? null;
        $handWidth = $handWidth ?? null;
        $handHeight = $handHeight ?? null;
        $handScore = $handScore ?? null;
        $ocrGuess = $ocrGuess ?? null;
        $ocrScore = $ocrScore ?? null;
        $ocrOptions = is_array($ocrOptions ?? null) ? $ocrOptions : [];
        $humanValue = $humanValue ?? null;
    @endphp

    @if (empty($selectedRecordId))
        <div style="border:1px solid #d7dee6;border-radius:8px;padding:14px;background:#f9fbfd;color:#5f6b7a;">
            No document selected. Go to Step 2 (Pending Imports), select a document, then come back.
        </div>
    @else
        {{-- Section 1 --}}
        <div style="border:1px solid #d7dee6;border-radius:10px;background:#fff;padding:14px;margin-bottom:12px;">
            <h2 style="margin:0 0 10px;font-size:17px;">1) Find Location Of Last Name Label</h2>
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
                        Saved as JSON into <code>global_state.fp_lastname_location.label</code>.
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

        {{-- Section 2 --}}
        <div style="border:1px solid #d7dee6;border-radius:10px;background:#fff;padding:14px;margin-bottom:12px;">
            <h2 style="margin:0 0 10px;font-size:17px;">2) Find the Underline</h2>
            <div style="display:flex;gap:14px;align-items:flex-start;">
                <div style="flex:0 0 auto;">
                    <button id="run-hand-btn" type="button"
                            style="padding:9px 14px;background:#0c8a43;color:#fff;border:0;border-radius:6px;cursor:pointer;font-weight:600;">
                        Run Step 2
                    </button>
                    <div style="margin-top:10px;font-size:13px;color:#475467;line-height:1.5;">
                        X: <strong id="hand-x">{{ $handX ?? '—' }}</strong>
                        &nbsp;&nbsp;Y: <strong id="hand-y">{{ $handY ?? '—' }}</strong>
                        &nbsp;&nbsp;Width: <strong id="hand-width">{{ $handWidth ?? '—' }}</strong>
                        &nbsp;&nbsp;Score: <strong id="hand-score">{{ $handScore ?? '—' }}</strong>
                    </div>
                    <p style="margin:6px 0 0;color:#5f6b7a;font-size:12px;">
                        Saves only the bounding area into <code>global_state.fp_lastname_location.handwritten</code>.
                    </p>
                </div>
                <div id="hand-log"
                     style="flex:1;min-width:220px;max-height:150px;overflow-y:auto;
                            font-family:monospace;font-size:12px;background:#f4f6f8;
                            border:1px solid #d7dee6;border-radius:6px;padding:8px;color:#344054;">
                    <em style="color:#9aa5b4;">Step 2 output will appear here…</em>
                </div>
            </div>
            <div style="margin-top:12px;">
                <h3 style="margin:0 0 8px;font-size:14px;color:#344054;">Step 2 Preview</h3>
                @if (!empty($publicPreviewUrl))
                    <canvas id="hand-slice-canvas"
                            style="display:block;width:100%;height:100px;border:1px solid #d7dee6;border-radius:8px;background:#eef3f9;"></canvas>
                    <p id="hand-slice-note" style="margin:8px 0 0;font-size:12px;color:#5f6b7a;">Top of document</p>
                @else
                    <div style="border:1px solid #d7dee6;border-radius:8px;padding:12px;background:#fff;color:#5f6b7a;">
                        No preview image available for this record.
                    </div>
                @endif
            </div>
        </div>

        {{-- Section 3 --}}
        <div style="border:1px solid #d7dee6;border-radius:10px;background:#fff;padding:14px;margin-bottom:12px;">
            <h2 style="margin:0 0 10px;font-size:17px;">3) Using Label and Underline, find Handwritten Area</h2>
            <div style="display:flex;gap:14px;align-items:flex-start;">
                <div style="flex:0 0 auto;">
                    <button id="run-area-btn" type="button"
                            style="padding:9px 14px;background:#7a4cff;color:#fff;border:0;border-radius:6px;cursor:pointer;font-weight:600;">
                        Run Step 3
                    </button>
                    <div style="margin-top:10px;font-size:13px;color:#475467;line-height:1.5;">
                        X: <strong id="area-x">{{ $handX ?? '—' }}</strong>
                        &nbsp;&nbsp;Y: <strong id="area-y">{{ $handY ?? '—' }}</strong>
                        &nbsp;&nbsp;Width: <strong id="area-width">{{ $handWidth ?? '—' }}</strong>
                        &nbsp;&nbsp;Height: <strong id="area-height">{{ $handHeight ?? '—' }}</strong>
                        &nbsp;&nbsp;Score: <strong id="area-score">{{ $handScore ?? '—' }}</strong>
                    </div>
                    <p style="margin:6px 0 0;color:#5f6b7a;font-size:12px;">
                        Uses label + underline context to place the handwritten area box.
                    </p>
                </div>
                <div id="area-log"
                     style="flex:1;min-width:220px;max-height:150px;overflow-y:auto;
                            font-family:monospace;font-size:12px;background:#f4f6f8;
                            border:1px solid #d7dee6;border-radius:6px;padding:8px;color:#344054;">
                    <em style="color:#9aa5b4;">Step 3 output will appear here…</em>
                </div>
            </div>
            <div style="margin-top:12px;">
                <h3 style="margin:0 0 8px;font-size:14px;color:#344054;">Step 3 Preview</h3>
                @if (!empty($publicPreviewUrl))
                    <canvas id="area-slice-canvas"
                            style="display:block;width:100%;height:100px;border:1px solid #d7dee6;border-radius:8px;background:#eef3f9;"></canvas>
                    <p id="area-slice-note" style="margin:8px 0 0;font-size:12px;color:#5f6b7a;">Top of document</p>
                @else
                    <div style="border:1px solid #d7dee6;border-radius:8px;padding:12px;background:#fff;color:#5f6b7a;">
                        No preview image available for this record.
                    </div>
                @endif
            </div>
        </div>

        {{-- Section 4 --}}
        <div style="border:1px solid #d7dee6;border-radius:10px;background:#fff;padding:14px;">
            <h2 style="margin:0 0 10px;font-size:17px;">4) Handwriting OCR Candidates</h2>
            <div style="display:flex;gap:14px;align-items:flex-start;margin-bottom:12px;">
                <div style="flex:0 0 auto;">
                    <button id="run-options-btn" type="button"
                            style="padding:9px 14px;background:#1f6feb;color:#fff;border:0;border-radius:6px;cursor:pointer;font-weight:600;">
                        Run Step 4
                    </button>
                    <p style="margin:8px 0 0;color:#5f6b7a;font-size:12px;">
                        Uses Step 3 box and returns the top handwriting options.
                    </p>
                </div>
                <div id="options-log"
                     style="flex:1;min-width:220px;max-height:150px;overflow-y:auto;
                            font-family:monospace;font-size:12px;background:#f4f6f8;
                            border:1px solid #d7dee6;border-radius:6px;padding:8px;color:#344054;">
                    <em style="color:#9aa5b4;">Step 4 output will appear here…</em>
                </div>
            </div>
            <div style="margin:0 0 14px;">
                <h3 style="margin:0 0 8px;font-size:14px;color:#344054;">Step 4 OCR Input (Step 3 Crop)</h3>
                @if (!empty($publicPreviewUrl))
                    <canvas id="options-crop-canvas"
                            style="display:block;width:100%;height:140px;border:1px solid #d7dee6;border-radius:8px;background:#eef3f9;"></canvas>
                    <p id="options-crop-note" style="margin:8px 0 0;font-size:12px;color:#5f6b7a;">Step 3 crop preview</p>
                @else
                    <div style="border:1px solid #d7dee6;border-radius:8px;padding:12px;background:#fff;color:#5f6b7a;">
                        No preview image available for this record.
                    </div>
                @endif
            </div>
        </div>

        <div style="border:1px solid #d7dee6;border-radius:10px;background:#fff;padding:14px;margin-top:12px;">
            <h2 style="margin:0 0 10px;font-size:17px;">5) Select Candidate from OCR Parse</h2>
            <div id="ocr-options-list"
                 style="display:flex;flex-direction:column;gap:10px;margin-bottom:6px;">
                @forelse ($ocrOptions as $option)
                    @php
                        $optText = $option['text'] ?? '—';
                        $optConf = isset($option['confidence']) && is_numeric($option['confidence'])
                            ? number_format(((float) $option['confidence']) * 100, 2) . '%'
                            : '—';
                    @endphp
                    <button type="button"
                            class="ocr-option-item"
                            data-option-text="{{ $option['text'] ?? '' }}"
                            style="text-align:left;padding:10px 12px;border:1px solid #d7dee6;border-radius:8px;background:#fff;cursor:pointer;font-size:35px;line-height:1.1;font-weight:700;">
                        <div >{{ $optText }}</div>
                        <div style="margin-top:4px;font-size:14px;line-height:1.2;font-weight:500;color:#5f6b7a;">
                            Confidence: {{ $optConf }}
                        </div>
                    </button>
                @empty
                    <div style="color:#5f6b7a;font-size:13px;">No OCR candidates yet. Run Step 4.</div>
                @endforelse
            </div>
            <p style="margin:4px 0 0;color:#5f6b7a;font-size:12px;">
                Click a match to copy it into Step 6.
            </p>
        </div>

        <div style="border:1px solid #d7dee6;border-radius:10px;background:#fff;padding:14px;margin-top:12px;">
            <h2 style="margin:0 0 10px;font-size:17px;">6) Human Confirmation</h2>
            <form id="human-confirm-form" action="{{ route('lastname.confirm') }}" method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
                @csrf
                <div style="min-width:260px;flex:1;">
                    <label style="display:block;font-size:12px;color:#475467;margin-bottom:4px;">Confirm Last Name</label>
                    <input id="human-value-input" type="text" name="human_value" value="{{ old('human_value', $humanValue ?: $ocrGuess) }}" required
                           style="width:100%;padding:8px;border:1px solid #d7dee6;border-radius:6px;">
                </div>
                <button id="save-human-btn" type="submit"
                        style="padding:9px 14px;background:#2f9e44;color:#fff;border:0;border-radius:6px;cursor:pointer;font-weight:600;">
                    Save Human Confirm
                </button>
            </form>
        </div>

        <script>
            (function () {
                var csrf = @json(csrf_token());
                var labelUrl = @json(route('lastname.label'));
                var handwrittenUrl = @json(route('lastname.handwritten'));
                var areaUrl = @json(route('lastname.area'));
                var optionsUrl = @json(route('lastname.options'));

                var labelLocation = {
                    x: {{ $locationX !== null ? (float) $locationX : 'null' }},
                    y: {{ $locationY !== null ? (float) $locationY : 'null' }},
                    width: {{ $locationWidth !== null ? (float) $locationWidth : 'null' }},
                    score: {{ $locationScore !== null ? (float) $locationScore : 'null' }}
                };
                var handLocation = {
                    x: {{ $handX !== null ? (float) $handX : 'null' }},
                    y: {{ $handY !== null ? (float) $handY : 'null' }},
                    width: {{ $handWidth !== null ? (float) $handWidth : 'null' }},
                    height: {{ $handHeight !== null ? (float) $handHeight : 'null' }},
                    score: {{ $handScore !== null ? (float) $handScore : 'null' }}
                };
                var areaLocation = {
                    x: {{ $handX !== null ? (float) $handX : 'null' }},
                    y: {{ $handY !== null ? (float) $handY : 'null' }},
                    width: {{ $handWidth !== null ? (float) $handWidth : 'null' }},
                    height: {{ $handHeight !== null ? (float) $handHeight : 'null' }},
                    score: {{ $handScore !== null ? (float) $handScore : 'null' }}
                };
                var step4Box = {
                    x: areaLocation.x,
                    y: areaLocation.y,
                    width: areaLocation.width,
                    height: areaLocation.height
                };
                var labelSliceY = labelLocation.y;
                var handSliceY = (handLocation.y != null && handLocation.height != null)
                    ? (handLocation.y + handLocation.height / 2) : handLocation.y;
                var areaSliceY = areaLocation.y;
                var ocrOptions = @json($ocrOptions);

                var appendLog = function (container, msg, isError) {
                    if (!container) return;
                    var line = document.createElement('div');
                    line.textContent = msg;
                    line.style.color = isError ? '#b42318' : '#344054';
                    container.appendChild(line);
                    container.scrollTop = container.scrollHeight;
                };

                var bindOptionButtons = function () {
                    var input = document.getElementById('human-value-input');
                    var btns = document.querySelectorAll('.ocr-option-item');
                    for (var i = 0; i < btns.length; i++) {
                        btns[i].addEventListener('click', function () {
                            if (!input) return;
                            var val = this.getAttribute('data-option-text') || '';
                            input.value = val;
                            input.focus();
                        });
                    }
                };

                var renderOcrOptions = function (options) {
                    var container = document.getElementById('ocr-options-list');
                    if (!container) return;
                    container.innerHTML = '';
                    if (!options || !options.length) {
                        var empty = document.createElement('div');
                        empty.style.color = '#5f6b7a';
                        empty.style.fontSize = '13px';
                        empty.textContent = 'No OCR candidates returned.';
                        container.appendChild(empty);
                        return;
                    }

                    for (var i = 0; i < options.length; i++) {
                        var option = options[i] || {};
                        var text = (option.text != null) ? String(option.text) : '';
                        var confidence = (option.confidence != null) ? String(option.confidence) : '—';
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'ocr-option-item';
                        btn.setAttribute('data-option-text', text);
                        btn.style.textAlign = 'left';
                        btn.style.padding = '10px 12px';
                        btn.style.border = '1px solid #d7dee6';
                        btn.style.borderRadius = '8px';
                        btn.style.background = '#fff';
                        btn.style.cursor = 'pointer';
                        btn.style.color = '#4e2fab';
                        btn.style.fontSize = '35px';
                        btn.style.lineHeight = '1.1';
                        btn.style.fontWeight = '700';
                        var pct = '—';
                        if (option.confidence != null && !Number.isNaN(Number(option.confidence))) {
                            pct = (Number(option.confidence) * 100).toFixed(2) + '%';
                        }
                        var word = document.createElement('div');
                        word.style.color = '#4e2fab';
                        word.textContent = text;
                        var confText = document.createElement('div');
                        confText.style.marginTop = '4px';
                        confText.style.fontSize = '14px';
                        confText.style.lineHeight = '1.2';
                        confText.style.fontWeight = '500';
                        confText.style.color = '#5f6b7a';
                        confText.textContent = 'Confidence: ' + pct;
                        btn.appendChild(word);
                        btn.appendChild(confText);
                        container.appendChild(btn);
                    }
                    bindOptionButtons();
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

                var renderSliceOnCanvas = function (canvasId, noteId, centerY, box, color, useBoxHeight, fixedHeightPx) {
                    var img = document.getElementById('ocr-source-img');
                    var canvas = document.getElementById(canvasId);
                    var note = document.getElementById(noteId);
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

                    if (centerY != null) {
                        srcY = Math.max(0, Math.min(centerY - (srcHeight / 2), img.naturalHeight - srcHeight));
                        if (note) note.textContent = 'Centred around Y ' + Math.round(centerY) + ' px';
                    } else if (note) {
                        note.textContent = 'Top of document';
                    }

                    ctx.clearRect(0, 0, canvasWidth, canvasHeight);
                    ctx.drawImage(img, srcX, srcY, srcWidth, srcHeight, 0, 0, canvasWidth, canvasHeight);

                    if (box && box.x != null && box.y != null && box.width != null) {
                        var boxHeight = (useBoxHeight && box.height != null && !Number.isNaN(Number(box.height)))
                            ? Number(box.height)
                            : (fixedHeightPx != null ? Number(fixedHeightPx) : Math.max(24, Math.round(img.naturalHeight * 0.018)));
                        var boxX = (Number(box.x) - srcX) * scale;
                        var boxY = (Number(box.y) - srcY) * scale;
                        var boxW = Number(box.width) * scale;
                        var boxH = boxHeight * scale;
                        var stroke = color || '#e11d48';
                        var fill = stroke === '#e11d48' ? 'rgba(225, 29, 72, 0.08)' : 'rgba(12, 138, 67, 0.10)';

                        ctx.save();
                        ctx.strokeStyle = stroke;
                        ctx.lineWidth = 2;
                        ctx.fillStyle = fill;
                        ctx.fillRect(boxX, boxY, boxW, boxH);
                        ctx.strokeRect(boxX, boxY, boxW, boxH);
                        ctx.restore();
                    }
                };

                var renderStep4Crop = function () {
                    var img = document.getElementById('ocr-source-img');
                    var canvas = document.getElementById('options-crop-canvas');
                    var note = document.getElementById('options-crop-note');
                    if (!img || !canvas || !img.naturalWidth || !img.naturalHeight) return;
                    var cropBox = normalizeBoxAgainstImage(step4Box, img.naturalWidth, img.naturalHeight);
                    if (!cropBox) {
                        if (note) note.textContent = 'Run Step 3 to define the crop area.';
                        return;
                    }

                    var sx = cropBox.x;
                    var sy = cropBox.y;
                    var sw = cropBox.width;
                    var sh = cropBox.height;

                    var ctx = canvas.getContext('2d');
                    var cw = Math.max(1, Math.round(canvas.clientWidth || 1));
                    var ch = Math.max(1, Math.round(canvas.clientHeight || 140));
                    if (canvas.width !== cw || canvas.height !== ch) {
                        canvas.width = cw;
                        canvas.height = ch;
                    }

                    ctx.clearRect(0, 0, cw, ch);
                    var scale = Math.min(cw / sw, ch / sh);
                    var dw = sw * scale;
                    var dh = sh * scale;
                    var dx = Math.round((cw - dw) / 2);
                    var dy = Math.round((ch - dh) / 2);
                    ctx.drawImage(img, sx, sy, sw, sh, dx, dy, dw, dh);
                    if (note) note.textContent = 'Crop sent to OCR: x=' + sx + ', y=' + sy + ', w=' + sw + ', h=' + sh;
                };

                var normalizeBoxAgainstImage = function (box, imgW, imgH) {
                    if (!box || box.x == null || box.y == null || box.width == null || box.height == null) {
                        return null;
                    }

                    var x = Math.round(Number(box.x));
                    var y = Math.round(Number(box.y));
                    var w = Math.round(Number(box.width));
                    var h = Math.round(Number(box.height));
                    if (!Number.isFinite(x) || !Number.isFinite(y) || !Number.isFinite(w) || !Number.isFinite(h)) {
                        return null;
                    }

                    x = Math.max(0, Math.min(x, imgW - 1));
                    y = Math.max(0, Math.min(y, imgH - 1));
                    w = Math.max(1, w);
                    h = Math.max(1, h);
                    if (x + w > imgW) w = imgW - x;
                    if (y + h > imgH) h = imgH - y;
                    if (w <= 0 || h <= 0) return null;

                    return { x: x, y: y, width: w, height: h };
                };

                var renderAllCanvases = function () {
                    renderSliceOnCanvas('label-slice-canvas', 'label-slice-note', labelSliceY, labelLocation, '#e11d48', false);
                    renderSliceOnCanvas('hand-slice-canvas', 'hand-slice-note', handSliceY, handLocation, '#0c8a43', true);
                    renderSliceOnCanvas('area-slice-canvas', 'area-slice-note', areaSliceY, areaLocation, '#7a4cff', true);
                    renderStep4Crop();
                };

                (function initImage() {
                    var img = document.getElementById('ocr-source-img');
                    if (!img) return;
                    var draw = function () { renderAllCanvases(); };
                    if (img.complete && img.naturalHeight) {
                        draw();
                    } else {
                        img.addEventListener('load', draw);
                    }
                    window.addEventListener('resize', draw);
                })();
                renderOcrOptions(ocrOptions);
                bindOptionButtons();

                var labelBtn = document.getElementById('run-label-btn');
                var labelLog = document.getElementById('label-log');
                if (labelBtn && labelLog) {
                    labelBtn.addEventListener('click', function () {
                        labelBtn.disabled = true;
                        labelBtn.style.opacity = '0.65';
                        labelLog.innerHTML = '';
                        appendLog(labelLog, 'Running Step 1: Find "Last Name" label...');

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
                                renderAllCanvases();
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

                var handBtn = document.getElementById('run-hand-btn');
                var handLog = document.getElementById('hand-log');
                if (handBtn && handLog) {
                    handBtn.addEventListener('click', function () {
                        handBtn.disabled = true;
                        handBtn.style.opacity = '0.65';
                        handLog.innerHTML = '';
                        appendLog(handLog, 'Running Step 2: Find the underline...');

                        postJson(handwrittenUrl)
                            .then(function (res) {
                                if (!res.ok || !res.body.ok) {
                                    throw new Error(res.body.message || 'Step 2 failed.');
                                }

                                var hand = (res.body && res.body.handwritten) ? res.body.handwritten : {};
                                var width = (hand.width != null)
                                    ? Number(hand.width)
                                    : ((hand.x1 != null && hand.x != null) ? (Number(hand.x1) - Number(hand.x)) : null);

                                document.getElementById('hand-x').textContent = (hand.x ?? '—');
                                document.getElementById('hand-y').textContent = (hand.y ?? '—');
                                document.getElementById('hand-width').textContent = (width != null && !Number.isNaN(width)) ? width : '—';
                                document.getElementById('hand-score').textContent = (hand.confidence ?? '—');

                                var handH = (hand.height != null && !Number.isNaN(Number(hand.height)))
                                    ? Number(hand.height)
                                    : ((hand.y1 != null && hand.y != null) ? (Number(hand.y1) - Number(hand.y)) : null);
                                handLocation = {
                                    x: (hand.x != null) ? Number(hand.x) : null,
                                    y: (hand.y != null) ? Number(hand.y) : null,
                                    width: (width != null && !Number.isNaN(width)) ? width : null,
                                    height: handH,
                                    score: (hand.confidence != null) ? Number(hand.confidence) : null
                                };
                                handSliceY = (handLocation.y != null && handLocation.height != null)
                                    ? (handLocation.y + handLocation.height / 2) : handLocation.y;
                                renderAllCanvases();

                                appendLog(handLog, 'Step 2 complete: underline location updated.');
                            })
                            .catch(function (err) {
                                appendLog(handLog, 'Error: ' + (err.message || 'Step 2 failed.'), true);
                            })
                            .finally(function () {
                                handBtn.disabled = false;
                                handBtn.style.opacity = '1';
                            });
                    });
                }

                var areaBtn = document.getElementById('run-area-btn');
                var areaLog = document.getElementById('area-log');
                if (areaBtn && areaLog) {
                    areaBtn.addEventListener('click', function () {
                        areaBtn.disabled = true;
                        areaBtn.style.opacity = '0.65';
                        areaLog.innerHTML = '';
                        appendLog(areaLog, 'Running Step 3: Using label and underline to find handwritten area...');

                        postJson(areaUrl)
                            .then(function (res) {
                                if (!res.ok || !res.body.ok) {
                                    throw new Error(res.body.message || 'Step 3 failed.');
                                }

                                var hand = (res.body && res.body.handwritten) ? res.body.handwritten : {};
                                var width = (hand.width != null)
                                    ? Number(hand.width)
                                    : ((hand.x1 != null && hand.x != null) ? (Number(hand.x1) - Number(hand.x)) : null);
                                var height = (hand.height != null)
                                    ? Number(hand.height)
                                    : ((hand.y1 != null && hand.y != null) ? (Number(hand.y1) - Number(hand.y)) : null);

                                document.getElementById('area-x').textContent = (hand.x ?? '—');
                                document.getElementById('area-y').textContent = (hand.y ?? '—');
                                document.getElementById('area-width').textContent = (width != null && !Number.isNaN(width)) ? width : '—';
                                document.getElementById('area-height').textContent = (height != null && !Number.isNaN(height)) ? height : '—';
                                document.getElementById('area-score').textContent = (hand.confidence ?? '—');

                                areaLocation = {
                                    x: (hand.x != null) ? Number(hand.x) : null,
                                    y: (hand.y != null) ? Number(hand.y) : null,
                                    width: (width != null && !Number.isNaN(width)) ? width : null,
                                    height: (height != null && !Number.isNaN(height)) ? height : null,
                                    score: (hand.confidence != null) ? Number(hand.confidence) : null
                                };
                                step4Box = {
                                    x: areaLocation.x,
                                    y: areaLocation.y,
                                    width: areaLocation.width,
                                    height: areaLocation.height
                                };
                                areaSliceY = areaLocation.y;
                                renderAllCanvases();

                                appendLog(areaLog, 'Step 3 complete: handwritten area location updated.');
                            })
                            .catch(function (err) {
                                appendLog(areaLog, 'Error: ' + (err.message || 'Step 3 failed.'), true);
                            })
                            .finally(function () {
                                areaBtn.disabled = false;
                                areaBtn.style.opacity = '1';
                            });
                    });
                }

                var optionsBtn = document.getElementById('run-options-btn');
                var optionsLog = document.getElementById('options-log');
                if (optionsBtn && optionsLog) {
                    optionsBtn.addEventListener('click', function () {
                        optionsBtn.disabled = true;
                        optionsBtn.style.opacity = '0.65';
                        optionsLog.innerHTML = '';
                        appendLog(optionsLog, 'Running Step 4: Handwriting OCR in Step 3 box...');

                        var img = document.getElementById('ocr-source-img');
                        var payloadBox = (img && img.naturalWidth && img.naturalHeight)
                            ? normalizeBoxAgainstImage(step4Box, img.naturalWidth, img.naturalHeight)
                            : step4Box;

                        if (payloadBox) {
                            step4Box = payloadBox;
                            renderStep4Crop();
                        }

                        postJson(optionsUrl, {
                            x: payloadBox && payloadBox.x != null ? Number(payloadBox.x) : null,
                            y: payloadBox && payloadBox.y != null ? Number(payloadBox.y) : null,
                            width: payloadBox && payloadBox.width != null ? Number(payloadBox.width) : null,
                            height: payloadBox && payloadBox.height != null ? Number(payloadBox.height) : null
                        })
                            .then(function (res) {
                                if (!res.ok || !res.body.ok) {
                                    var err = new Error(res.body.message || 'Step 4 failed.');
                                    err.commands = (res.body && res.body.commands) ? res.body.commands : [];
                                    err.bboxUsed = (res.body && res.body.bbox_used) ? res.body.bbox_used : null;
                                    throw err;
                                }
                                var options = (res.body && res.body.options) ? res.body.options : [];
                                var commands = (res.body && res.body.commands) ? res.body.commands : [];
                                var bboxUsed = (res.body && res.body.bbox_used) ? res.body.bbox_used : null;
                                if (bboxUsed) {
                                    step4Box = {
                                        x: Number(bboxUsed.x),
                                        y: Number(bboxUsed.y),
                                        width: Number(bboxUsed.width || (bboxUsed.x1 - bboxUsed.x)),
                                        height: Number(bboxUsed.height || (bboxUsed.y1 - bboxUsed.y))
                                    };
                                    renderStep4Crop();
                                    appendLog(optionsLog, 'BBox used: x=' + bboxUsed.x + ', y=' + bboxUsed.y + ', w=' + (bboxUsed.width || (bboxUsed.x1 - bboxUsed.x)) + ', h=' + (bboxUsed.height || (bboxUsed.y1 - bboxUsed.y)));
                                }
                                for (var i = 0; i < commands.length; i++) {
                                    appendLog(optionsLog, 'PaddleOCR command: ' + commands[i]);
                                }
                                renderOcrOptions(options);
                                appendLog(optionsLog, 'Step 4 complete: ' + options.length + ' candidate(s) found.');
                            })
                            .catch(function (err) {
                                if (err && err.commands && err.commands.length) {
                                    for (var i = 0; i < err.commands.length; i++) {
                                        appendLog(optionsLog, 'PaddleOCR command: ' + err.commands[i]);
                                    }
                                }
                                if (err && err.bboxUsed) {
                                    appendLog(optionsLog, 'BBox used: x=' + err.bboxUsed.x + ', y=' + err.bboxUsed.y + ', w=' + (err.bboxUsed.width || (err.bboxUsed.x1 - err.bboxUsed.x)) + ', h=' + (err.bboxUsed.height || (err.bboxUsed.y1 - err.bboxUsed.y)));
                                }
                                appendLog(optionsLog, 'Error: ' + (err.message || 'Step 4 failed.'), true);
                            })
                            .finally(function () {
                                optionsBtn.disabled = false;
                                optionsBtn.style.opacity = '1';
                            });
                    });
                }

                var humanForm = document.getElementById('human-confirm-form');
                var saveHumanBtn = document.getElementById('save-human-btn');
                var humanInput = document.getElementById('human-value-input');
                if (humanForm && saveHumanBtn && humanInput) {
                    humanForm.addEventListener('submit', function (event) {
                        var value = (humanInput.value || '').trim();
                        if (!value) {
                            event.preventDefault();
                            humanInput.focus();
                            return;
                        }

                        humanInput.value = value;
                        saveHumanBtn.disabled = true;
                        saveHumanBtn.style.opacity = '0.65';
                        saveHumanBtn.textContent = 'Saving...';
                    });
                }
            })();
        </script>
    @endif
@endsection
