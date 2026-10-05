<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>GW Face Enrollment</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f4f6fa; color: #222; }
        .wrap { max-width: 820px; margin: 0 auto; padding: 20px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .subtitle { color: #666; font-size: 13px; margin-bottom: 20px; }
        .card { background: #fff; border: 1px solid #e4e8ef; border-radius: 10px; padding: 18px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
        .card h2 { font-size: 15px; margin: 0 0 12px; color: #444; text-transform: uppercase; letter-spacing: 0.5px; }
        label { display: block; font-size: 13px; color: #555; margin-bottom: 4px; }
        input[type=text], input[type=number] {
            width: 100%; padding: 10px 12px; font-size: 15px; border: 1px solid #d8dde6; border-radius: 6px; background: #fafbfd;
        }
        input:focus { outline: none; border-color: #005AFC; background: #fff; }
        .row { display: flex; gap: 8px; align-items: flex-end; }
        .row > * { flex: 1; }
        .row > button { flex: 0 0 auto; }
        button {
            padding: 10px 18px; font-size: 14px; font-weight: 600; border: 0; border-radius: 6px; cursor: pointer;
            background: #005AFC; color: #fff; transition: background 0.15s;
        }
        button:hover:not(:disabled) { background: #0047c9; }
        button:disabled { background: #9aa4b6; cursor: not-allowed; }
        button.secondary { background: #6c757d; }
        button.secondary:hover:not(:disabled) { background: #565d64; }
        button.danger { background: #d32f2f; }
        button.danger:hover:not(:disabled) { background: #b12828; }
        .hint { font-size: 12px; color: #888; margin-top: 6px; }
        .gwInfo { background: #eef7ee; border: 1px solid #c8e0c8; color: #1f5022; padding: 10px 12px; border-radius: 6px; margin-top: 10px; font-size: 14px; white-space: pre-line; }
        .gwError { background: #fdeaea; border-color: #e8c4c4; color: #8a1f1f; }
        #captureArea { display: flex; flex-direction: column; align-items: center; gap: 12px; }
        video, #previewCanvas, #previewImg {
            width: 100%; max-width: 480px; border-radius: 8px; background: #000; display: block;
        }
        #previewImg { background: #eee; }
        .tabs { display: flex; gap: 4px; margin-bottom: 12px; border-bottom: 1px solid #e4e8ef; }
        .tab { padding: 8px 16px; cursor: pointer; font-size: 14px; color: #666; border-bottom: 2px solid transparent; }
        .tab.active { color: #005AFC; border-bottom-color: #005AFC; font-weight: 600; }
        .btnRow { display: flex; gap: 8px; flex-wrap: wrap; }
        .hidden { display: none !important; }
        #enrollStatus { margin-top: 12px; padding: 10px 12px; border-radius: 6px; font-size: 14px; }
        #enrollStatus.ok { background: #eef7ee; color: #1f5022; border: 1px solid #c8e0c8; }
        #enrollStatus.err { background: #fdeaea; color: #8a1f1f; border: 1px solid #e8c4c4; }
        .enrollList { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
        .enrollItem { border: 1px solid #e4e8ef; border-radius: 8px; overflow: hidden; background: #fff; }
        .enrollItem img { width: 100%; height: 140px; object-fit: cover; display: block; background: #eee; }
        .enrollItem .meta { padding: 8px; font-size: 11px; color: #666; }
        .enrollItem .meta .date { margin-bottom: 6px; }
        .enrollItem button { width: 100%; padding: 6px; font-size: 12px; }
        .empty { color: #999; font-size: 13px; padding: 20px; text-align: center; }
    </style>
</head>
<body>

<div class="wrap">
    <h1>GW Face Enrollment</h1>
    <div class="subtitle">Register a GW's face for daily-activity attendance verification.</div>

    <!-- STEP 1: GW LOOKUP -->
    <div class="card">
        <h2>Step 1 — GW Lookup</h2>
        <label for="gwId">Monthly Assign GW ID</label>
        <div class="row">
            <input type="number" id="gwId" placeholder="e.g. 1234" min="1">
            <button id="lookupBtn">Lookup</button>
        </div>
        <div id="gwResult" class="hidden"></div>
    </div>

    <!-- STEP 2: CAPTURE -->
    <div class="card hidden" id="captureCard">
        <h2>Step 2 — Capture Face Photo</h2>
        <div class="tabs">
            <div class="tab active" data-tab="webcam">📷 Webcam</div>
            <div class="tab" data-tab="upload">📁 Upload / Mobile Camera</div>
        </div>

        <div id="tab-webcam" class="tabContent">
            <div id="captureArea">
                <video id="video" autoplay playsinline muted></video>
                <canvas id="previewCanvas" class="hidden"></canvas>
                <div class="btnRow">
                    <button id="startCamBtn">Start Camera</button>
                    <button id="captureBtn" disabled>Capture</button>
                    <button id="retakeBtn" class="secondary hidden">Retake</button>
                </div>
            </div>
            <div class="hint">Face the camera directly in good lighting. Only one face should be visible.</div>
        </div>

        <div id="tab-upload" class="tabContent hidden">
            <input type="file" id="fileInput" accept="image/*" capture="user">
            <img id="previewImg" class="hidden" alt="preview">
            <div class="hint">On mobile, this opens the camera. On desktop, it opens a file picker.</div>
        </div>
    </div>

    <!-- STEP 3: SUBMIT -->
    <div class="card hidden" id="submitCard">
        <h2>Step 3 — Submit Enrollment</h2>
        <div class="btnRow">
            <button id="enrollBtn" disabled>Enroll Face</button>
            <button id="clearBtn" class="secondary">Clear Photo</button>
        </div>
        <div id="enrollStatus" class="hidden"></div>
    </div>

    <!-- STEP 4: EXISTING -->
    <div class="card hidden" id="listCard">
        <h2>Existing Enrollments</h2>
        <div id="enrollList" class="enrollList"></div>
    </div>
</div>

<script>
// -------------------- STATE --------------------
let currentGw = null;
let capturedBase64 = null;
let mediaStream = null;

// -------------------- DOM --------------------
const $ = id => document.getElementById(id);
const gwIdEl       = $('gwId');
const gwResult     = $('gwResult');
const captureCard  = $('captureCard');
const submitCard   = $('submitCard');
const listCard     = $('listCard');
const video        = $('video');
const canvas       = $('previewCanvas');
const startCamBtn  = $('startCamBtn');
const captureBtn   = $('captureBtn');
const retakeBtn    = $('retakeBtn');
const fileInput    = $('fileInput');
const previewImg   = $('previewImg');
const enrollBtn    = $('enrollBtn');
const clearBtn     = $('clearBtn');
const enrollStatus = $('enrollStatus');
const enrollList   = $('enrollList');

// -------------------- API --------------------
async function api(action, opts = {}) {
    const res = await fetch('enroll_api.php?action=' + action + (opts.query || ''), {
        method: opts.method || 'GET',
        headers: opts.body ? {'Content-Type': 'application/json'} : {},
        body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    return res.json().catch(() => ({ success: false, error: 'Invalid response' }));
}

// -------------------- TABS --------------------
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        document.querySelectorAll('.tabContent').forEach(c => c.classList.add('hidden'));
        $('tab-' + tab.dataset.tab).classList.remove('hidden');
        resetCapture();
    });
});

// -------------------- LOOKUP --------------------
$('lookupBtn').addEventListener('click', doLookup);
gwIdEl.addEventListener('keydown', e => { if (e.key === 'Enter') doLookup(); });

async function doLookup() {
    const id = parseInt(gwIdEl.value, 10);
    if (!id || id <= 0) {
        showGwResult('Enter a valid GW ID', true);
        return;
    }
    gwResult.classList.remove('hidden');
    gwResult.textContent = 'Looking up…';
    gwResult.className = 'gwInfo';

    const data = await api('lookup', { query: '&gw_id=' + id });
    if (!data || !data.success) {
        currentGw = null;
        captureCard.classList.add('hidden');
        submitCard.classList.add('hidden');
        listCard.classList.add('hidden');
        showGwResult((data && data.error) || 'Lookup failed', true);
        return;
    }

    currentGw = data.gw;
    const flags = [];
    if (String(currentGw.checkin_default) === '1') flags.push('default');
    if (String(currentGw.checkin_face) === '1')    flags.push('face');
    const flagTxt = flags.length ? flags.join(' + ') : 'none';
    const statusTxt = String(currentGw.status) === '1' ? 'active' : 'inactive';

    showGwResult(
        '✓ GW #' + currentGw.id + ' — ' + currentGw.fullname +
        '\nCode: ' + (currentGw.code || '—') +
        ' · Status: ' + statusTxt +
        ' · Check-in methods enabled: ' + flagTxt,
        false
    );
    captureCard.classList.remove('hidden');
    submitCard.classList.remove('hidden');
    listCard.classList.remove('hidden');
    loadEnrollments();
}

function showGwResult(msg, isError) {
    gwResult.classList.remove('hidden');
    gwResult.className = 'gwInfo' + (isError ? ' gwError' : '');
    gwResult.textContent = msg;
}

// -------------------- WEBCAM --------------------
startCamBtn.addEventListener('click', async () => {
    try {
        mediaStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 960 } },
            audio: false,
        });
        video.srcObject = mediaStream;
        startCamBtn.disabled = true;
        captureBtn.disabled = false;
    } catch (e) {
        alert('Could not access camera: ' + e.message);
    }
});

captureBtn.addEventListener('click', () => {
    const w = video.videoWidth, h = video.videoHeight;
    if (!w || !h) { alert('Camera not ready yet.'); return; }
    canvas.width = w; canvas.height = h;
    canvas.getContext('2d').drawImage(video, 0, 0, w, h);

    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    capturedBase64 = dataUrl.split(',')[1];

    video.classList.add('hidden');
    canvas.classList.remove('hidden');
    captureBtn.classList.add('hidden');
    retakeBtn.classList.remove('hidden');
    enrollBtn.disabled = false;

    stopCamera();
});

retakeBtn.addEventListener('click', () => {
    resetCapture();
    startCamBtn.click();
});

function stopCamera() {
    if (mediaStream) {
        mediaStream.getTracks().forEach(t => t.stop());
        mediaStream = null;
    }
    video.srcObject = null;
}

// -------------------- FILE UPLOAD --------------------
fileInput.addEventListener('change', () => {
    const f = fileInput.files[0];
    if (!f) return;
    const reader = new FileReader();
    reader.onload = () => {
        previewImg.src = reader.result;
        previewImg.classList.remove('hidden');
        capturedBase64 = reader.result.split(',')[1];
        enrollBtn.disabled = false;
    };
    reader.readAsDataURL(f);
});

// -------------------- RESET CAPTURE --------------------
function resetCapture() {
    capturedBase64 = null;
    enrollBtn.disabled = true;
    enrollStatus.classList.add('hidden');
    stopCamera();
    video.classList.remove('hidden');
    canvas.classList.add('hidden');
    startCamBtn.disabled = false;
    captureBtn.disabled = true;
    captureBtn.classList.remove('hidden');
    retakeBtn.classList.add('hidden');
    fileInput.value = '';
    previewImg.src = '';
    previewImg.classList.add('hidden');
}

clearBtn.addEventListener('click', resetCapture);

// -------------------- ENROLL --------------------
enrollBtn.addEventListener('click', async () => {
    if (!currentGw || !capturedBase64) return;
    enrollBtn.disabled = true;
    enrollStatus.classList.remove('hidden');
    enrollStatus.className = 'gwInfo';
    enrollStatus.textContent = 'Processing… (face detection may take a few seconds)';

    const data = await api('enroll', {
        method: 'POST',
        body: { gw_id: currentGw.id, image: capturedBase64 }
    });

    if (data && data.success) {
        enrollStatus.className = 'ok';
        enrollStatus.textContent = '✓ Enrolled successfully (encoding #' + data.encoding_id + ').';
        resetCapture();
        loadEnrollments();
    } else {
        enrollStatus.className = 'err';
        enrollStatus.textContent = '✗ ' + ((data && data.error) || 'Enrollment failed');
        enrollBtn.disabled = false;
    }
});

// -------------------- LIST & DELETE --------------------
async function loadEnrollments() {
    if (!currentGw) return;
    enrollList.innerHTML = '<div class="empty">Loading…</div>';
    const data = await api('list', { query: '&gw_id=' + currentGw.id });
    if (!data || !data.success) {
        enrollList.innerHTML = '<div class="empty">Failed to load.</div>';
        return;
    }
    if (!data.enrollments || data.enrollments.length === 0) {
        enrollList.innerHTML = '<div class="empty">No face enrolled yet for this GW.</div>';
        return;
    }
    enrollList.innerHTML = data.enrollments.map(e => `
        <div class="enrollItem" data-id="${e.id}">
            <img src="enroll_api.php?action=photo&enc_id=${e.id}" alt="enrollment ${e.id}">
            <div class="meta">
                <div class="date">#${e.id} · ${e.created_at}</div>
                <button class="danger" onclick="deleteEnrollment(${e.id})">Delete</button>
            </div>
        </div>
    `).join('');
}

async function deleteEnrollment(id) {
    if (!confirm('Delete enrollment #' + id + '? This cannot be undone from the UI.')) return;
    const data = await api('delete', { method: 'POST', body: { id } });
    if (data && data.success) {
        loadEnrollments();
    } else {
        alert((data && data.error) || 'Delete failed');
    }
}
window.deleteEnrollment = deleteEnrollment;

window.addEventListener('beforeunload', stopCamera);
</script>

</body>
</html>