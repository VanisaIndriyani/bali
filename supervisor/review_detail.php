<?php
$pageTitle = 'Review Detail Daily Log';
require_once __DIR__ . '/../config/config.php';
requireRole(['supervisor', 'manager']); // Manager Access All

$db = Database::getInstance();
$user = currentUser();

$logId = (int)($_GET['id'] ?? 0);
$log = $db->fetchOne(
    "SELECT dl.*, u.name as engineer_name, u.email as engineer_email, u.position as engineer_position, u.phone as engineer_phone
     FROM daily_logs dl
     LEFT JOIN users u ON dl.engineer_id = u.id
     WHERE dl.id = ?",
    [$logId]
);

if (!$log) {
    setFlash('error', 'Daily Log tidak ditemukan');
    redirect('supervisor/review.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $signature = $_POST['signature_data'] ?? '';
    $revisionNotes = trim($_POST['revision_notes'] ?? '');

    if ($action === 'approve') {
        if (empty($signature)) {
            setFlash('error', 'Tanda tangan digital wajib diisi! Silakan tanda tangan di area yang tersedia.');
            redirect('supervisor/review_detail.php?id=' . $logId);
        } else {
            $sigFilename = null;
            if (!empty($signature) && strpos($signature, 'data:image') === 0) {
                $sigFilename = uniqid('sig_') . '.png';
                $sigPath = UPLOAD_PATH . $sigFilename;
                $sigData = explode(',', $signature)[1];
                file_put_contents($sigPath, base64_decode($sigData));
            }

            $db->update('daily_logs', [
                'status' => 'approved',
                'supervisor_id' => $user['id'],
                'supervisor_signature' => $sigFilename,
                'approved_at' => date('Y-m-d H:i:s'),
                'revision_notes' => null,
            ], 'id = :id', ['id' => $logId]);

            setFlash('success', 'Daily Log berhasil di-Approve');
            redirect('supervisor/review.php');
        }
    } elseif ($action === 'reject') {
        if (empty($revisionNotes)) {
            setFlash('error', 'Catatan revisi wajib diisi saat reject! Silakan isi kolom catatan di bawah.');
            redirect('supervisor/review_detail.php?id=' . $logId);
        } else {
            $db->update('daily_logs', [
                'status' => 'rejected',
                'supervisor_id' => $user['id'],
                'revision_notes' => $revisionNotes,
                'supervisor_signature' => null,
                'approved_at' => null,
            ], 'id = :id', ['id' => $logId]);

            setFlash('success', 'Daily Log ditolak dengan catatan revisi');
            redirect('supervisor/review.php');
        }
    }
}

$log = $db->fetchOne(
    "SELECT dl.*, u.name as engineer_name, u.email as engineer_email, u.position as engineer_position, u.phone as engineer_phone,
     s.name as supervisor_name, s.signature_image as supervisor_sig
     FROM daily_logs dl
     LEFT JOIN users u ON dl.engineer_id = u.id
     LEFT JOIN users s ON dl.supervisor_id = s.id
     WHERE dl.id = ?",
    [$logId]
);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

// --- HELPER BADGE SHIFT VISUAL (Pagi/Siang/Malam) untuk ditampilkan di header dan info engineer ---
$__shiftVal = (!empty($log['shift']) && in_array($log['shift'], ['pagi','siang','malam'], true)) ? (string)$log['shift'] : '';
$__shiftBadge = '';
if ($__shiftVal === 'pagi') {
    $__shiftBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gradient-to-r from-yellow-500 to-amber-600 text-white text-xs font-bold tracking-wide uppercase shadow-sm shadow-amber-500/20"><i class="fas fa-sun-plant-wilt"></i> Shift Pagi</span>';
} elseif ($__shiftVal === 'siang') {
    $__shiftBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gradient-to-r from-sky-500 to-indigo-600 text-white text-xs font-bold tracking-wide uppercase shadow-sm shadow-sky-500/20"><i class="fas fa-sun"></i> Shift Siang</span>';
} elseif ($__shiftVal === 'malam') {
    $__shiftBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gradient-to-r from-indigo-600 to-slate-900 text-white text-xs font-bold tracking-wide uppercase shadow-sm shadow-indigo-500/20"><i class="fas fa-moon-stars"></i> Shift Malam</span>';
} else {
    $__shiftBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-slate-100 border border-slate-200 text-slate-600 text-xs font-semibold tracking-wide uppercase"><i class="fas fa-circle-question"></i> Belum ada shift</span>';
}
?>

<div class="page-shell page-shell--5xl">
    <div class="mb-8 animate-fade-in">
        <a href="<?= BASE_URL ?>supervisor/review.php" class="inline-flex items-center gap-1.5 text-sm text-secondary hover:text-primary mb-4 transition-colors">
            <i class="fas fa-chevron-left text-xs"></i> Kembali ke Daftar Review
        </a>
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <h1 class="font-display text-2xl lg:text-3xl font-bold text-primary mb-1 flex items-center gap-3 flex-wrap">
                    <i class="fas fa-magnifying-glass mr-2 text-accent"></i>Detail Daily Log
                    <?= $__shiftBadge ?>
                </h1>
                <p class="text-secondary">
                    <i class="fas fa-calendar-day text-accent mr-1"></i>
                    Tanggal <span class="font-semibold text-primary"><?= formatDate($log['log_date']) ?></span>
                </p>
            </div>
            <span class="self-start px-4 py-2 rounded-full text-sm font-bold <?= getStatusBadgeClass($log['status']) ?>">
                <i class="fas fa-<?= $log['status'] === 'approved' ? 'circle-check' : ($log['status'] === 'rejected' ? 'circle-xmark' : 'clock') ?> mr-1.5"></i>
                <?= getStatusText($log['status']) ?>
            </span>
        </div>
    </div>

    <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden mb-6 animate-slide-up">
        <div class="px-5 lg:px-6 py-4 border-b border-border bg-gradient-to-r from-muted/50 to-surface">
            <h3 class="font-bold text-primary flex items-center gap-2">
                <i class="fas fa-user-circle text-accent"></i>Informasi Engineer
            </h3>
        </div>
        <div class="p-5 lg:p-6">
            <div class="flex flex-col sm:flex-row gap-4 sm:items-center">
                <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-gradient-to-br from-primary to-secondary flex items-center justify-center text-white text-xl sm:text-2xl font-bold flex-shrink-0 shadow-lg">
                    <?= strtoupper(mb_substr((string)($log['engineer_name'] ?? 'U'), 0, 1) ?: 'U') ?>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 sm:gap-4 flex-1 min-w-0">
                    <div><p class="text-xs text-secondary uppercase tracking-wider mb-0.5">Nama</p><p class="font-semibold text-primary"><?= cleanInput($log['engineer_name']) ?></p></div>
                    <div><p class="text-xs text-secondary uppercase tracking-wider mb-0.5">Jabatan</p><p class="font-semibold text-primary"><?= cleanInput($log['engineer_position'] ?? '-') ?></p></div>
                    <div><p class="text-xs text-secondary uppercase tracking-wider mb-0.5">Email</p><p class="font-semibold text-primary text-sm"><?= cleanInput($log['engineer_email']) ?></p></div>
                    <div><p class="text-xs text-secondary uppercase tracking-wider mb-0.5">Phone</p><p class="font-semibold text-primary"><?= cleanInput($log['engineer_phone'] ?? '-') ?></p></div>
                    <div>
                        <p class="text-xs text-secondary uppercase tracking-wider mb-0.5">Shift Bertugas</p>
                        <div class="mt-0.5"><?= $__shiftBadge ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden mb-6 animate-slide-up" style="animation-delay: 50ms">
        <div class="px-5 lg:px-6 py-4 border-b border-border bg-muted/30">
            <h3 class="font-bold text-primary flex items-center gap-2">
                <i class="fas fa-gauge-high text-accent"></i>Data Konsumsi
            </h3>
        </div>
        <div class="p-5 lg:p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="p-5 rounded-card bg-amber-50 border border-amber-100">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center"><i class="fas fa-bolt text-amber-600"></i></div>
                    <div><p class="text-xs text-amber-700 uppercase tracking-wider font-semibold">Listrik</p><p class="text-[10px] text-amber-600">Total Consume</p></div>
                </div>
                <div class="text-3xl font-bold text-amber-700"><?= formatNumber($log['total_electricity']) ?> <span class="text-sm font-semibold">kWh</span></div>
            </div>
            <div class="p-5 rounded-card bg-blue-50 border border-blue-100">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center"><i class="fas fa-droplet text-blue-600"></i></div>
                    <div><p class="text-xs text-blue-700 uppercase tracking-wider font-semibold">Air</p><p class="text-[10px] text-blue-600">Total Consume</p></div>
                </div>
                <div class="text-3xl font-bold text-blue-700"><?= formatNumber($log['total_water']) ?> <span class="text-sm font-semibold">m3</span></div>
            </div>
            <div class="p-5 rounded-card bg-orange-50 border border-orange-100">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-10 h-10 rounded-lg bg-orange-100 flex items-center justify-center"><i class="fas fa-fire text-orange-600"></i></div>
                    <div><p class="text-xs text-orange-700 uppercase tracking-wider font-semibold">Gas</p><p class="text-[10px] text-orange-600">Total Consume</p></div>
                </div>
                <div class="text-3xl font-bold text-orange-700"><?= formatNumber($log['total_gas']) ?> <span class="text-sm font-semibold">kg</span></div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 100ms">
            <div class="px-5 lg:px-6 py-4 border-b border-border bg-muted/30">
                <h3 class="font-bold text-primary flex items-center gap-2"><i class="fas fa-camera text-accent"></i>Dokumentasi Foto</h3>
            </div>
            <div class="p-5 lg:p-6">
                <?php if ($log['photo_path']): ?>
                    <a href="<?= UPLOAD_URL . $log['photo_path'] ?>" target="_blank">
                        <img src="<?= UPLOAD_URL . $log['photo_path'] ?>" alt="Foto Dokumentasi" class="w-full h-64 rounded-card object-cover hover:scale-[1.02] transition-transform duration-300 cursor-pointer shadow-md">
                    </a>
                <?php else: ?>
                    <div class="w-full h-64 rounded-card bg-muted/50 border-2 border-dashed border-border flex flex-col items-center justify-center text-secondary">
                        <i class="fas fa-image text-4xl mb-2 opacity-40"></i>
                        <p class="text-sm">Tidak ada foto</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="space-y-6">
            <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 120ms">
                <div class="px-5 lg:px-6 py-4 border-b border-border bg-muted/30">
                    <h3 class="font-bold text-primary flex items-center gap-2"><i class="fas fa-list-check text-green-600"></i>Aktivitas Pekerjaan</h3>
                </div>
                <div class="p-5 lg:p-6"><div class="text-primary leading-relaxed whitespace-pre-wrap"><?= nl2br(cleanInput($log['work_activities'])) ?></div></div>
            </div>
            <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 140ms">
                <div class="px-5 lg:px-6 py-4 border-b border-border bg-muted/30">
                    <h3 class="font-bold text-primary flex items-center gap-2"><i class="fas fa-triangle-exclamation text-yellow-600"></i>Kendala</h3>
                </div>
                <div class="p-5 lg:p-6"><div class="text-primary leading-relaxed whitespace-pre-wrap"><?= $log['obstacles'] ? nl2br(cleanInput($log['obstacles'])) : '<span class="text-secondary italic">Tidak ada kendala</span>' ?></div></div>
            </div>
            <div class="bg-surface rounded-premium border border-border shadow-sm overflow-hidden animate-slide-up" style="animation-delay: 160ms">
                <div class="px-5 lg:px-6 py-4 border-b border-border bg-muted/30">
                    <h3 class="font-bold text-primary flex items-center gap-2"><i class="fas fa-lightbulb text-accent"></i>Solusi</h3>
                </div>
                <div class="p-5 lg:p-6"><div class="text-primary leading-relaxed whitespace-pre-wrap"><?= $log['solutions'] ? nl2br(cleanInput($log['solutions'])) : '<span class="text-secondary italic">Tidak ada solusi yang dicatat</span>' ?></div></div>
            </div>
        </div>
    </div>

    <?php if ($log['revision_notes']): ?>
        <div class="mb-6 p-5 rounded-premium bg-red-50 border border-red-200 animate-slide-up">
            <h3 class="font-bold text-red-700 mb-2 flex items-center gap-2"><i class="fas fa-note-sticky"></i>Catatan Revisi Sebelumnya</h3>
            <p class="text-red-800 whitespace-pre-wrap"><?= nl2br(cleanInput($log['revision_notes'])) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($log['supervisor_signature']): ?>
        <div class="mb-6 p-5 bg-green-50 border border-green-200 rounded-premium animate-slide-up">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h3 class="font-bold text-green-700 flex items-center gap-2 mb-1"><i class="fas fa-signature"></i>Disetujui oleh</h3>
                    <p class="text-green-800 font-semibold"><?= cleanInput($log['supervisor_name'] ?? $user['name']) ?></p>
                    <p class="text-xs text-green-600 mt-0.5">Pada: <?= formatDateTime($log['approved_at']) ?></p>
                </div>
                <img src="<?= UPLOAD_URL . $log['supervisor_signature'] ?>" alt="Signature" class="h-24 bg-white rounded-lg border border-green-200 p-2 shadow-sm">
            </div>
        </div>
    <?php endif; ?>

    <?php if ($log['status'] === 'pending'): ?>
    <div class="bg-surface rounded-premium border-2 border-primary shadow-xl overflow-hidden animate-slide-up" style="animation-delay: 200ms">
        <div class="px-5 lg:px-6 py-4 bg-gradient-to-r from-primary via-gray-900 to-primary text-white">
            <h3 class="font-bold text-lg flex items-center gap-2"><i class="fas fa-file-signature"></i>Approval Digital</h3>
            <p class="text-xs text-white/70 mt-0.5">Berikan tanda tangan dan persetujuan Anda</p>
        </div>
        <div class="p-5 lg:p-6 space-y-6">
            <form method="POST" id="reviewForm">
                <div id="approveSection" class="hidden">
                    <label class="block text-sm font-semibold text-primary mb-2">
                        <i class="fas fa-pen mr-1.5 text-accent"></i>Tanda Tangan Digital <span class="text-red-500">*</span>
                    </label>
                    <div class="rounded-card border-2 border-dashed border-border bg-muted/30 overflow-hidden touch-none">
                        <canvas id="signaturePad" width="800" height="220" class="w-full h-48 sm:h-56 cursor-crosshair bg-white block"></canvas>
                    </div>
                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mt-3">
                        <button type="button" onclick="clearSignature()" class="inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                            <i class="fas fa-eraser"></i>Hapus Tanda Tangan
                        </button>
                        <div class="flex items-center gap-2 text-xs text-secondary">
                            <i class="fas fa-info-circle text-accent"></i>
                            Tanda tangan Anda sebagai bukti persetujuan
                        </div>
                    </div>
                </div>

                <div id="rejectSection" class="hidden">
                    <label class="block text-sm font-semibold text-primary mb-2">
                        <i class="fas fa-note-sticky mr-1.5 text-red-600"></i>Catatan Revisi <span class="text-red-500">*</span>
                    </label>
                    <textarea id="rejectNotes" name="revision_notes" rows="4"
                        class="w-full px-4 py-3 rounded-card border border-border bg-muted/50 text-primary placeholder-secondary/60 focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-100 focus:bg-surface transition-all resize-none"
                        placeholder="Jelaskan alasan penolakan dan hal yang perlu diperbaiki..."></textarea>
                    <p class="text-[11px] text-secondary mt-1.5"><i class="fas fa-info-circle mr-1"></i>Catatan ini akan dikirim ke engineer untuk perbaikan</p>
                </div>

                <div class="flex flex-col sm:flex-row sm:justify-end gap-3 pt-4 border-t border-border">
                    <button type="button" onclick="setAction('reject')"
                        class="px-6 py-3.5 rounded-card bg-red-50 text-red-700 font-semibold border border-red-200 hover:bg-red-100 transition-all duration-300 flex items-center justify-center gap-2 group">
                        <i class="fas fa-circle-xmark group-hover:scale-110 transition-transform"></i>
                        Reject Daily Log
                    </button>
                    <button type="button" onclick="setAction('approve')"
                        class="px-6 py-3.5 rounded-card bg-gradient-to-r from-green-600 via-green-700 to-green-600 text-white font-semibold shadow-lg hover:shadow-2xl hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2 group animate-pulse-glow">
                        <i class="fas fa-circle-check group-hover:scale-110 transition-transform"></i>
                        Approve Daily Log
                    </button>
                </div>

                <input type="hidden" name="action" id="actionField">
                <input type="hidden" name="signature_data" id="signatureDataField">
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
let signaturePad, sigCtx, isDrawing = false, hasSignature = false;

document.addEventListener('DOMContentLoaded', function() {
    signaturePad = document.getElementById('signaturePad');
    if (signaturePad) {
        sigCtx = signaturePad.getContext('2d');
        sigCtx.strokeStyle = '#111';
        sigCtx.lineWidth = 2.5;
        sigCtx.lineCap = 'round';
        sigCtx.lineJoin = 'round';

        function getPos(e) {
            const rect = signaturePad.getBoundingClientRect();
            const scaleX = signaturePad.width / rect.width;
            const scaleY = signaturePad.height / rect.height;
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            return { x: (clientX - rect.left) * scaleX, y: (clientY - rect.top) * scaleY };
        }

        function startDraw(e) {
            e.preventDefault();
            isDrawing = true;
            hasSignature = true;
            const pos = getPos(e);
            sigCtx.beginPath();
            sigCtx.moveTo(pos.x, pos.y);
        }
        function draw(e) {
            if (!isDrawing) return;
            e.preventDefault();
            const pos = getPos(e);
            sigCtx.lineTo(pos.x, pos.y);
            sigCtx.stroke();
        }
        function endDraw() { isDrawing = false; }

        signaturePad.addEventListener('mousedown', startDraw);
        signaturePad.addEventListener('mousemove', draw);
        window.addEventListener('mouseup', endDraw);
        signaturePad.addEventListener('touchstart', startDraw, { passive: false });
        signaturePad.addEventListener('touchmove', draw, { passive: false });
        signaturePad.addEventListener('touchend', endDraw);
    }
});

function clearSignature() {
    if (sigCtx) {
        sigCtx.clearRect(0, 0, signaturePad.width, signaturePad.height);
        hasSignature = false;
    }
}

function setAction(action) {
    document.getElementById('approveSection').classList.toggle('hidden', action !== 'approve');
    document.getElementById('rejectSection').classList.toggle('hidden', action !== 'reject');

    setTimeout(() => {
        const form = document.getElementById('reviewForm');
        const actionField = document.getElementById('actionField');
        const sigField = document.getElementById('signatureDataField');

        if (action === 'approve') {
            if (!hasSignature) {
                alert('Tanda tangan digital wajib diisi! Silakan tanda tangan di area yang tersedia.');
                return;
            }
            sigField.value = signaturePad.toDataURL('image/png');
            actionField.value = 'approve';
            form.submit();
        } else {
            const notes = document.getElementById('rejectNotes').value.trim();
            if (!notes) {
                alert('Catatan revisi wajib diisi untuk reject!');
                document.getElementById('rejectNotes').focus();
                return;
            }
            if (!confirm('Anda yakin ingin menolak Daily Log ini?')) return;
            actionField.value = 'reject';
            form.submit();
        }
    }, action === 'approve' ? 50 : 50);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
