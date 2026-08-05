<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('order_detail_title', 'Detail Order Request');
requireRole(['engineer', 'supervisor', 'manager', 'admin']);

$db = Database::getInstance();
$user = currentUser();
$role = (string)$user['role'];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { setFlash('danger', T('order_not_found', 'Order tidak ditemukan')); redirect('orders/index.php'); }

$order = $db->fetchOne(
    "SELECT o.*, cc.code as cc_code, cc.name as cc_name, u.name as req_name, u.email as req_email
     FROM orders o
     LEFT JOIN cost_codes cc ON cc.id = o.cost_code_id
     LEFT JOIN users u ON u.id = o.requested_by
     WHERE o.id = ?",
    [$id]
);
if (!$order) { setFlash('danger', T('order_not_found', 'Order tidak ditemukan')); redirect('orders/index.php'); }

if ($role === 'engineer' && (int)$order['requested_by'] !== (int)$user['id']) {
    setFlash('danger', T('order_unauthorized', 'Anda tidak memiliki akses ke order ini'));
    redirect('orders/index.php');
}

$items = $db->fetchAll("SELECT * FROM order_items WHERE order_id = ? ORDER BY sort_order ASC, id ASC", [$id]);
$approvals = $db->fetchAll(
    "SELECT a.*, u.name as user_name FROM order_approvals a LEFT JOIN users u ON u.id = a.user_id WHERE a.order_id = ? ORDER BY a.id ASC",
    [$id]
);
$supervisors = $db->fetchAll("SELECT id, name, email FROM users WHERE role = 'supervisor' AND status = 'active' ORDER BY name");
$managers = $db->fetchAll("SELECT id, name, email FROM users WHERE role = 'manager' AND status = 'active' ORDER BY name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $rejectReason = cleanInput($_POST['reject_reason'] ?? '');
    $notes = cleanInput($_POST['notes'] ?? '');

    try {
        $db->beginTransaction();
        if ($action === 'approve_spv' && $role === 'supervisor' && $order['status'] === 'pending_supervisor') {
            $db->query(
                "UPDATE orders SET status = 'pending_manager', supervisor_id = ?, supervisor_approved_at = NOW(), notes = COALESCE(NULLIF(?, ''), notes) WHERE id = ?",
                [(int)$user['id'], $notes, $id]
            );
            addOrderApproval($db, $id, (int)$user['id'], $role, 'approve', $notes ?: 'Disetujui Supervisor → lanjut ke Manager');
            $db->commit();
            setFlash('success', T('order_action_spv_approve_ok', 'Berhasil disetujui Supervisor, menunggu Manager'));
        } elseif ($action === 'approve_mgr' && $role === 'manager' && $order['status'] === 'pending_manager') {
            $db->query(
                "UPDATE orders SET status = 'approved', manager_id = ?, manager_approved_at = NOW(), notes = COALESCE(NULLIF(?, ''), notes) WHERE id = ?",
                [(int)$user['id'], $notes, $id]
            );
            addOrderApproval($db, $id, (int)$user['id'], $role, 'approve', $notes ?: 'Final Approval Manager: Order Disetujui');
            $db->commit();
            setFlash('success', T('order_action_approve_ok', 'Order berhasil disetujui'));
        } elseif ($action === 'reject_spv' && $role === 'supervisor' && $order['status'] === 'pending_supervisor') {
            if (!$rejectReason) { setFlash('danger', 'Alasan penolakan wajib diisi'); $db->rollBack(); redirect('orders/detail.php?id=' . $id); }
            $db->query(
                "UPDATE orders SET status = 'rejected', supervisor_id = ?, rejected_by = ?, rejected_reason = ?, rejected_at = NOW() WHERE id = ?",
                [(int)$user['id'], (int)$user['id'], $rejectReason, $id]
            );
            addOrderApproval($db, $id, (int)$user['id'], $role, 'reject', 'DITOLAK SPV: ' . $rejectReason);
            $db->commit();
            setFlash('success', T('order_action_reject_ok', 'Order ditolak'));
        } elseif ($action === 'reject_mgr' && $role === 'manager' && $order['status'] === 'pending_manager') {
            if (!$rejectReason) { setFlash('danger', 'Alasan penolakan wajib diisi'); $db->rollBack(); redirect('orders/detail.php?id=' . $id); }
            $db->query(
                "UPDATE orders SET status = 'rejected', manager_id = ?, rejected_by = ?, rejected_reason = ?, rejected_at = NOW() WHERE id = ?",
                [(int)$user['id'], (int)$user['id'], $rejectReason, $id]
            );
            addOrderApproval($db, $id, (int)$user['id'], $role, 'reject', 'DITOLAK MGR: ' . $rejectReason);
            $db->commit();
            setFlash('success', T('order_action_reject_ok', 'Order ditolak'));
        } elseif ($action === 'resubmit' && ((int)$order['requested_by'] === (int)$user['id']) && in_array($order['status'], ['rejected','draft'], true)) {
            $db->query("UPDATE orders SET status = 'pending_supervisor', rejected_by = NULL, rejected_reason = NULL, rejected_at = NULL WHERE id = ?", [$id]);
            addOrderApproval($db, $id, (int)$user['id'], $role, 'submit', 'Re-submit ke Supervisor');
            $db->commit();
            setFlash('success', T('order_action_submit_ok', 'Order berhasil dikirim ke Supervisor'));
        } elseif ($action === 'complete' && $isManagerOrSpv = in_array($role, ['manager','supervisor','admin'], true)) {
            $db->query("UPDATE orders SET status = 'completed', completed_at = NOW() WHERE id = ?", [$id]);
            addOrderApproval($db, $id, (int)$user['id'], $role, 'complete', $notes ?: 'Order ditandai SELESAI');
            $db->commit();
            setFlash('success', T('order_action_complete_ok', 'Order ditandai selesai'));
        } else {
            try { $db->rollBack(); } catch (Throwable $_) {}
            setFlash('danger', T('order_action_invalid', 'Aksi tidak valid'));
        }
    } catch (Throwable $e) {
        try { $db->rollBack(); } catch (Throwable $_) {}
        setFlash('danger', 'Error: ' . $e->getMessage());
    }
    redirect('orders/detail.php?id=' . $id);
}

$order = $db->fetchOne(
    "SELECT o.*, cc.code as cc_code, cc.name as cc_name, u.name as req_name, u.email as req_email,
            spv.name as spv_name, mgr.name as mgr_name, rej.name as rej_name
     FROM orders o
     LEFT JOIN cost_codes cc ON cc.id = o.cost_code_id
     LEFT JOIN users u ON u.id = o.requested_by
     LEFT JOIN users spv ON spv.id = o.supervisor_id
     LEFT JOIN users mgr ON mgr.id = o.manager_id
     LEFT JOIN users rej ON rej.id = o.rejected_by
     WHERE o.id = ?",
    [$id]
);
$items = $db->fetchAll("SELECT * FROM order_items WHERE order_id = ? ORDER BY sort_order ASC, id ASC", [$id]);
$approvals = $db->fetchAll(
    "SELECT a.*, u.name as user_name FROM order_approvals a LEFT JOIN users u ON u.id = a.user_id WHERE a.order_id = ? ORDER BY a.id ASC",
    [$id]
);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
$canApproveSpv = ($role === 'supervisor' && $order['status'] === 'pending_supervisor');
$canApproveMgr = ($role === 'manager' && $order['status'] === 'pending_manager');
$canRejectSpv = $canApproveSpv;
$canRejectMgr = $canApproveMgr;
$canResubmit = ((int)$order['requested_by'] === (int)$user['id'] && in_array($order['status'], ['rejected','draft'], true));
$canComplete = in_array($role, ['manager','supervisor','admin'], true) && $order['status'] === 'approved';
?>
<div class="page-shell page-shell--7xl">
    <div class="page-header flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6 animate-fade-in">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.25em] text-secondary mb-1">ENG. DEPT • <?= T('order_detail_title', 'Detail Order Request') ?></p>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-display text-3xl font-black text-primary"><?= cleanInput($order['order_no']) ?></h1>
                <span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-black uppercase border <?= getOrderStatusBadgeClass($order['status']) ?>"><?= getOrderStatusText($order['status']) ?></span>
            </div>
            <p class="text-sm text-secondary mt-1 font-semibold"><?= cleanInput($order['title']) ?></p>
        </div>
        <a href="<?= BASE_URL ?>orders/index.php" class="btn-ghost inline-flex self-start"><i class="fas fa-arrow-left mr-1.5"></i><?= T('order_btn_back', 'Kembali') ?></a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="card-premium p-5 sm:p-7">
                <h3 class="font-black text-primary text-lg mb-5 flex items-center gap-2"><i class="fas fa-info-circle text-accent"></i> <?= T('order_info_title', 'Informasi Order') ?></h3>
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-secondary mb-1"><?= T('order_field_title', 'Judul / Keperluan') ?></dt>
                        <dd class="text-primary font-bold text-base"><?= cleanInput($order['title']) ?></dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-secondary mb-1"><?= T('order_field_cost', 'Cost Code') ?></dt>
                        <dd class="text-primary">
                            <?php if (!empty($order['cc_code'])): ?>
                                <span class="font-mono font-black text-accent"><?= cleanInput($order['cc_code']) ?></span>
                                <span class="text-secondary text-sm block mt-0.5"><?= cleanInput($order['cc_name']) ?></span>
                            <?php else: ?>
                                <span class="text-slate-400 text-sm">-</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-secondary mb-1"><?= T('order_field_requestor', 'Pemohon') ?></dt>
                        <dd class="text-primary font-semibold"><?= cleanInput($order['req_name']) ?><span class="text-secondary text-xs block"><?= cleanInput($order['req_email']) ?></span></dd>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-secondary mb-1"><?= T('order_field_date', 'Tgl Permintaan') ?></dt>
                            <dd class="text-primary font-semibold"><?= formatDate($order['requested_date']) ?></dd>
                        </div>
                        <div>
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-secondary mb-1"><?= T('order_field_needed', 'Tgl Dibutuhkan') ?></dt>
                            <dd class="text-primary font-semibold"><?= !empty($order['needed_date']) ? formatDate($order['needed_date']) : '-' ?></dd>
                        </div>
                    </div>
                    <?php if (!empty($order['purpose'])): ?>
                        <div class="md:col-span-2">
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-secondary mb-1"><?= T('order_field_purpose', 'Tujuan / Keterangan') ?></dt>
                            <dd class="text-primary text-sm"><?= nl2br(cleanInput($order['purpose'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($order['rejected_reason'])): ?>
                        <div class="md:col-span-2 p-4 rounded-2xl bg-red-50 border border-red-200">
                            <dt class="text-[11px] font-black uppercase tracking-wider text-red-700 mb-1 flex items-center gap-1"><i class="fas fa-circle-exclamation"></i> <?= T('order_field_reject_reason', 'Alasan Penolakan') ?></dt>
                            <dd class="text-red-800 font-semibold text-sm"><?= cleanInput($order['rejected_reason']) ?> <span class="text-red-600 text-xs block mt-1">Oleh: <?= cleanInput($order['rej_name'] ?? '-') ?> • <?= !empty($order['rejected_at']) ? formatDateTime($order['rejected_at']) : '' ?></span></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($order['notes'])): ?>
                        <div class="md:col-span-2">
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-secondary mb-1"><?= T('order_field_notes', 'Catatan Tambahan') ?></dt>
                            <dd class="text-primary text-sm"><?= nl2br(cleanInput($order['notes'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-secondary mb-1">Approval Supervisor</dt>
                        <dd class="text-primary text-sm"><?= !empty($order['spv_name']) ? '<span class="text-green-700 font-bold"><i class="fas fa-circle-check mr-1"></i>' . cleanInput($order['spv_name']) . '</span>' . (!empty($order['supervisor_approved_at']) ? '<span class="block text-xs text-secondary">' . formatDateTime($order['supervisor_approved_at']) . '</span>' : '') : '<span class="text-slate-400">Belum ada approval</span>' ?></dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-secondary mb-1">Approval Manager</dt>
                        <dd class="text-primary text-sm"><?= !empty($order['mgr_name']) ? '<span class="text-green-700 font-bold"><i class="fas fa-circle-check mr-1"></i>' . cleanInput($order['mgr_name']) . '</span>' . (!empty($order['manager_approved_at']) ? '<span class="block text-xs text-secondary">' . formatDateTime($order['manager_approved_at']) . '</span>' : '') : '<span class="text-slate-400">Belum ada approval</span>' ?></dd>
                    </div>
                </dl>
            </div>

            <div class="card-premium p-0 overflow-hidden">
                <div class="px-5 sm:px-7 py-5 border-b border-border bg-slate-50/60">
                    <h3 class="font-black text-primary text-lg flex items-center gap-2"><i class="fas fa-list-check text-accent"></i> <?= T('order_items_title', 'Daftar Barang / Jasa') ?> <span class="text-xs text-secondary font-semibold normal-case">(<?= count($items) ?> item)</span></h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[800px]">
                        <thead>
                            <tr class="bg-slate-50/70 border-b border-border">
                                <th class="text-left p-4 text-[11px] font-black uppercase tracking-wider text-secondary w-12">No</th>
                                <th class="text-left p-4 text-[11px] font-black uppercase tracking-wider text-secondary"><?= T('order_item_name', 'Nama Item') ?></th>
                                <th class="text-left p-4 text-[11px] font-black uppercase tracking-wider text-secondary"><?= T('order_item_desc', 'Spesifikasi') ?></th>
                                <th class="text-right p-4 text-[11px] font-black uppercase tracking-wider text-secondary w-20"><?= T('order_item_qty', 'Qty') ?></th>
                                <th class="text-left p-4 text-[11px] font-black uppercase tracking-wider text-secondary w-24"><?= T('order_item_unit', 'Satuan') ?></th>
                                <th class="text-right p-4 text-[11px] font-black uppercase tracking-wider text-secondary w-40"><?= T('order_item_price', 'Harga') ?></th>
                                <th class="text-right p-4 text-[11px] font-black uppercase tracking-wider text-secondary w-44"><?= T('order_item_subtotal', 'Subtotal') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1; foreach ($items as $it): ?>
                                <tr class="border-b border-border/60 hover:bg-slate-50/50">
                                    <td class="p-4 text-center font-bold text-secondary"><?= $no++ ?></td>
                                    <td class="p-4 font-bold text-primary"><?= cleanInput($it['item_name']) ?></td>
                                    <td class="p-4 text-sm text-secondary"><?= !empty($it['description']) ? cleanInput($it['description']) : '-' ?></td>
                                    <td class="p-4 text-right font-semibold text-primary"><?= formatNumber((float)$it['qty'], (fmod((float)$it['qty'], 1) !== 0.0 ? 2 : 0)) ?></td>
                                    <td class="p-4 text-secondary font-semibold"><?= cleanInput($it['unit']) ?: '-' ?></td>
                                    <td class="p-4 text-right font-semibold text-secondary whitespace-nowrap">Rp <?= formatNumber((float)$it['unit_price'], 2) ?></td>
                                    <td class="p-4 text-right font-black text-primary whitespace-nowrap">Rp <?= formatNumber((float)$it['subtotal'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-accent/50 bg-amber-50/50">
                                <td colspan="6" class="p-5 text-right font-black uppercase tracking-wider text-secondary"><?= T('order_field_total', 'Total Nilai') ?></td>
                                <td class="p-5 text-right font-black text-primary text-2xl whitespace-nowrap">Rp <?= formatNumber((float)$order['total_amount'], 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card-premium p-5 sm:p-6">
                <h3 class="font-black text-primary text-lg mb-4 flex items-center gap-2"><i class="fas fa-gavel text-accent"></i> <?= T('order_action', 'Aksi Approval') ?></h3>

                <?php if ($canApproveSpv || $canApproveMgr || $canRejectSpv || $canRejectMgr || $canResubmit || $canComplete): ?>
                    <form method="post" class="space-y-4">
                        <?php if ($canApproveSpv): ?>
                            <div>
                                <label class="form-label !mb-1">Catatan untuk Manager (opsional)</label>
                                <textarea name="notes" rows="2" class="form-input" placeholder="Catatan diteruskan ke Manager..."></textarea>
                            </div>
                            <button type="submit" name="action" value="approve_spv" class="btn-gold w-full"><i class="fas fa-circle-check mr-1.5"></i><?= T('order_btn_approve_spv', 'Setujui & Kirim ke Manager') ?></button>
                        <?php endif; ?>

                        <?php if ($canApproveMgr): ?>
                            <div>
                                <label class="form-label !mb-1">Catatan Final Approval (opsional)</label>
                                <textarea name="notes" rows="2" class="form-input" placeholder="Catatan persetujuan akhir..."></textarea>
                            </div>
                            <button type="submit" name="action" value="approve_mgr" class="btn-gold w-full"><i class="fas fa-shield-check mr-1.5"></i><?= T('order_btn_approve_mgr', 'Setujui (Final Approval)') ?></button>
                        <?php endif; ?>

                        <?php if ($canRejectSpv || $canRejectMgr): ?>
                            <div>
                                <label class="form-label !mb-1 text-red-700"><?= T('order_field_reject_reason', 'Alasan Penolakan') ?> <span class="text-red-500">*</span></label>
                                <textarea name="reject_reason" required rows="3" class="form-input border-red-200 focus:border-red-400" placeholder="Wajib diisi untuk menolak order..."></textarea>
                            </div>
                            <button type="submit" name="action" value="<?= $canRejectMgr ? 'reject_mgr' : 'reject_spv' ?>" class="btn-danger w-full"><i class="fas fa-circle-xmark mr-1.5"></i><?= T('order_btn_reject', 'Tolak') ?></button>
                        <?php endif; ?>

                        <?php if ($canComplete): ?>
                            <div>
                                <label class="form-label !mb-1">Catatan Selesai (opsional)</label>
                                <textarea name="notes" rows="2" class="form-input" placeholder="Dokumen / barang sudah diterima..."></textarea>
                            </div>
                            <button type="submit" name="action" value="complete" class="btn-success w-full"><i class="fas fa-flag-checkered mr-1.5"></i><?= T('order_btn_complete', 'Tandai Selesai') ?></button>
                        <?php endif; ?>

                        <?php if ($canResubmit): ?>
                            <button type="submit" name="action" value="resubmit" class="btn-gold w-full"><i class="fas fa-rotate-right mr-1.5"></i><?= T('order_btn_resubmit', 'Ajukan Kembali ke Supervisor') ?></button>
                        <?php endif; ?>
                    </form>
                <?php else: ?>
                    <div class="p-5 rounded-2xl bg-slate-50 border border-dashed border-slate-200 text-center">
                        <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-white border border-slate-200 flex items-center justify-center">
                            <i class="fas fa-hourglass-half text-2xl text-slate-400"></i>
                        </div>
                        <p class="text-sm text-secondary font-semibold mb-1">Tidak ada aksi</p>
                        <p class="text-xs text-slate-400">Menunggu proses berikutnya dari pihak approval</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card-premium p-5 sm:p-6">
                <h3 class="font-black text-primary text-lg mb-4 flex items-center gap-2"><i class="fas fa-timeline text-accent"></i> <?= T('order_approval_log', 'Jejak Approval') ?></h3>
                <?php if (count($approvals) === 0): ?>
                    <p class="text-sm text-slate-400 text-center py-6">Belum ada riwayat</p>
                <?php else: ?>
                    <ol class="relative border-l border-amber-200/70 ml-3 space-y-5">
                        <?php
                        $colors = [
                            'submit' => ['bg-blue-100 text-blue-700', 'text-blue-700'],
                            'approve' => ['bg-green-100 text-green-700', 'text-green-700'],
                            'reject' => ['bg-red-100 text-red-700', 'text-red-700'],
                            'update' => ['bg-gray-100 text-gray-700', 'text-gray-700'],
                            'complete' => ['bg-emerald-100 text-emerald-700', 'text-emerald-700'],
                        ];
                        $iconMap = ['submit'=>'fa-paper-plane','approve'=>'fa-circle-check','reject'=>'fa-circle-xmark','update'=>'fa-pen','complete'=>'fa-flag-checkered'];
                        foreach ($approvals as $a):
                            [$badgeClass, $txtCol] = $colors[$a['action']] ?? $colors['update'];
                            $icon = $iconMap[$a['action']] ?? 'fa-info';
                        ?>
                            <li class="ml-5">
                                <div class="absolute -left-[13px] w-6 h-6 rounded-full <?= $badgeClass ?> border-2 border-white flex items-center justify-center shadow-sm">
                                    <i class="fas <?= $icon ?> text-[10px]"></i>
                                </div>
                                <p class="font-bold text-sm <?= $txtCol ?> flex items-center gap-1.5">
                                    <span class="uppercase tracking-wide"><?= $a['action'] ?></span>
                                    <span class="text-xs text-secondary font-semibold normal-case">• <?= $a['role'] ?></span>
                                </p>
                                <p class="text-xs text-secondary mt-0.5"><?= cleanInput($a['user_name'] ?? '-') ?> • <?= formatDateTime($a['created_at']) ?></p>
                                <?php if (!empty($a['notes'])): ?>
                                    <p class="text-sm text-primary mt-1.5 p-2.5 rounded-xl bg-slate-50 border border-slate-100"><?= nl2br(cleanInput($a['notes'])) ?></p>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>