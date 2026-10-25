<style>
    /* Barcode modal styles */
    .barcode-modal-overlay {
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.6);
        backdrop-filter: blur(4px);
        z-index: 1000;
        display: flex; align-items: center; justify-content: center;
        padding: 1rem;
        animation: fadeIn 0.2s ease;
    }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

    .barcode-modal {
        background: white;
        border-radius: 24px;
        padding: 2rem;
        max-width: 400px;
        width: 100%;
        text-align: center;
        animation: slideUp 0.3s ease;
        box-shadow: 0 25px 60px rgba(0,0,0,0.3);
    }
    .barcode-modal-header { margin-bottom: 1.25rem; }
    .barcode-modal-icon {
        width: 64px; height: 64px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 0.75rem;
        font-size: 1.75rem;
    }
    .barcode-modal-icon.loan { background: linear-gradient(135deg, #eff6ff, #dbeafe); color: #2563eb; }
    .barcode-modal-icon.return { background: linear-gradient(135deg, #f5f3ff, #ede9fe); color: #7c3aed; }

    .barcode-qr-wrap {
        background: #f8fafc;
        border-radius: 16px;
        padding: 1.25rem;
        margin: 0.75rem 0;
        border: 2px dashed #e2e8f0;
    }
    .barcode-qr-wrap img, .barcode-qr-wrap svg {
        max-width: 200px; width: 100%; height: auto;
        margin: 0 auto; display: block;
    }

    /* Countdown timer */
    .countdown-wrap {
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        border-radius: 12px;
        padding: 0.75rem 1rem;
        margin: 0.75rem 0;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .countdown-wrap.expired {
        background: linear-gradient(135deg, #fee2e2, #fecaca);
    }
    .countdown-time {
        font-size: 1.4rem; font-weight: 800;
        color: #92400e;
        font-variant-numeric: tabular-nums;
    }
    .countdown-wrap.expired .countdown-time { color: #991b1b; }

    .token-display {
        font-size: 0.65rem;
        font-family: monospace;
        color: #94a3b8;
        word-break: break-all;
        margin-top: 8px;
    }
</style>

<!-- ══ BARCODE MODAL COMPONENT ══ -->
<div id="barcode-modal-overlay" class="barcode-modal-overlay" style="display:none" onclick="closeBarcodeModal(event)">
    <div class="barcode-modal" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="barcode-modal-header">
            <div class="barcode-modal-icon" id="modal-icon">📦</div>
            <h2 class="text-xl font-bold text-gray-900 mb-1" id="modal-title">Barcode Peminjaman</h2>
            <p class="text-sm text-gray-500" id="modal-subtitle">Tunjukkan kepada petugas perpustakaan</p>
        </div>

        <!-- QR Code Display -->
        <div class="barcode-qr-wrap">
            <div id="modal-qr-container">
                <!-- QR code will be rendered here -->
            </div>
            <div class="token-display" id="modal-token-display"></div>
        </div>

        <!-- Countdown -->
        <div class="countdown-wrap" id="modal-countdown-wrap">
            <i class="fas fa-clock" style="color:#92400e"></i>
            <div>
                <div style="font-size:0.7rem; color:#92400e; font-weight:600">MASA BERLAKU</div>
                <div class="countdown-time" id="modal-countdown">00:00:00</div>
            </div>
        </div>

        <!-- Book name -->
        <div class="bg-gray-50 rounded-xl p-3 mb-4">
            <p class="text-xs text-gray-500 mb-1"><i class="fas fa-book"></i> Buku:</p>
            <p class="font-semibold text-gray-800 text-sm" id="modal-book-name"></p>
        </div>

        <!-- Warning -->
        <p class="text-xs text-gray-400 mb-4">
            <i class="fas fa-exclamation-triangle text-amber-400"></i>
            Barcode ini hanya bisa di-scan <strong>satu kali</strong> dan akan hangus setelah masa berlaku habis.
        </p>

        <!-- Close -->
        <button type="button" onclick="closeBarcodeModal()" class="w-full py-3 bg-gray-100 text-gray-700 rounded-xl font-semibold hover:bg-gray-200 transition text-sm">
            Tutup
        </button>
    </div>
</div>

<script>
let barcodeCountdownInterval = null;
let barcodeCurrentSeconds    = 0;

function openLoanBarcodeModal(btn) {
    if (!btn) return;
    const type = btn.dataset.type || 'loan';
    const id = parseInt(btn.dataset.id || '0');
    const token = btn.dataset.token || '';
    const remaining = parseInt(btn.dataset.remaining || '0');
    const title = btn.dataset.title || '';
    showBarcodeModal(type, id, token, remaining, title);
}

function showBarcodeModal(type, loanId, token, remainingSeconds, bookTitle) {
    const isLoan   = type === 'loan';
    const overlay  = document.getElementById('barcode-modal-overlay');
    const icon     = document.getElementById('modal-icon');
    const title    = document.getElementById('modal-title');
    const subtitle = document.getElementById('modal-subtitle');
    const tokenEl  = document.getElementById('modal-token-display');
    const bookEl   = document.getElementById('modal-book-name');

    if (!overlay) return;

    icon.className = 'barcode-modal-icon ' + (isLoan ? 'loan' : 'return');
    icon.innerHTML = isLoan ? '<i class="fas fa-book-reader"></i>' : '<i class="fas fa-undo"></i>';
    title.textContent = isLoan ? 'Barcode Peminjaman' : 'Barcode Pengembalian';
    subtitle.textContent = isLoan
        ? 'Tunjukkan kepada petugas untuk mengambil buku'
        : 'Tunjukkan kepada petugas untuk mengembalikan buku';
    tokenEl.textContent = token;
    bookEl.textContent  = bookTitle;

    // QR Code display: coba SVG lokal terlebih dahulu, fallback ke qrserver
    const qrContainer = document.getElementById('modal-qr-container');
    const qrSize = 200;
    const localSvgUrl = `/loans/${loanId}/barcode-svg/${type}`;
    const fallbackUrl = `https://api.qrserver.com/v1/create-qr-code/?size=${qrSize}x${qrSize}&data=${encodeURIComponent(token)}&format=png&margin=2`;

    qrContainer.innerHTML = `<img src="${localSvgUrl}" alt="QR Code" style="border-radius:8px; width:${qrSize}px; height:${qrSize}px; margin:0 auto; display:block;" onerror="this.onerror=null; this.src='${fallbackUrl}';">`;

    // Start countdown
    barcodeCurrentSeconds = remainingSeconds;
    updateBarcodeModalCountdown();
    clearInterval(barcodeCountdownInterval);
    barcodeCountdownInterval = setInterval(() => {
        barcodeCurrentSeconds--;
        if (barcodeCurrentSeconds <= 0) {
            barcodeCurrentSeconds = 0;
            clearInterval(barcodeCountdownInterval);
            const wrap = document.getElementById('modal-countdown-wrap');
            if (wrap) wrap.classList.add('expired');
            const cd = document.getElementById('modal-countdown');
            if (cd) cd.textContent = 'HANGUS';
        }
        updateBarcodeModalCountdown();
    }, 1000);

    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function updateBarcodeModalCountdown() {
    if (barcodeCurrentSeconds <= 0) return;
    const h = Math.floor(barcodeCurrentSeconds / 3600);
    const m = Math.floor((barcodeCurrentSeconds % 3600) / 60);
    const s = barcodeCurrentSeconds % 60;
    const timeStr = [h, m, s].map(v => String(v).padStart(2, '0')).join(':');
    const cd = document.getElementById('modal-countdown');
    if (cd) cd.textContent = timeStr;

    const wrap = document.getElementById('modal-countdown-wrap');
    if (wrap && barcodeCurrentSeconds < 300) {
        wrap.style.background = 'linear-gradient(135deg, #fee2e2, #fecaca)';
        if (cd) cd.style.color = '#991b1b';
    }
}

function closeBarcodeModal(event) {
    if (event && event.target !== document.getElementById('barcode-modal-overlay')) return;
    clearInterval(barcodeCountdownInterval);
    const overlay = document.getElementById('barcode-modal-overlay');
    if (overlay) overlay.style.display = 'none';
    document.body.style.overflow = '';
    const wrap = document.getElementById('modal-countdown-wrap');
    if (wrap) {
        wrap.classList.remove('expired');
        wrap.style.background = '';
    }
    const cd = document.getElementById('modal-countdown');
    if (cd) cd.style.color = '';
}

// Close on Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeBarcodeModal();
});
</script>
