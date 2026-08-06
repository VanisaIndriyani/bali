<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('order_create_title', 'Buat Order Request Baru');
requireRole(['supervisor', 'manager']);

$db = Database::getInstance();
$user = currentUser();
$curRole = (string)($user['role'] ?? '');
if ($curRole !== 'supervisor' && $curRole !== 'manager') {
    header('Location: ' . BASE_URL . 'index.php', true, 302);
    exit();
}

$costCodes = $db->fetchAll("SELECT id, code, name FROM cost_codes WHERE is_active = 1 ORDER BY code ASC");

try {
    $chk = $db->fetchOne("SHOW COLUMNS FROM orders LIKE 'req_number'");
    if (!$chk) {
        $db->query("ALTER TABLE orders ADD COLUMN req_number VARCHAR(60) NULL AFTER order_no");
        $db->query("ALTER TABLE orders ADD COLUMN admin_price_notes TEXT NULL AFTER total_amount");
        $db->query("ALTER TABLE orders ADD INDEX idx_req_number (req_number)");
    }
} catch (Throwable $_) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = cleanInput($_POST['title'] ?? '');
    $purpose = cleanInput($_POST['purpose'] ?? '');
    $costCodeId = (int)($_POST['cost_code_id'] ?? 0);
    $requestedDate = $_POST['requested_date'] ?? date('Y-m-d');
    $neededDate = !empty($_POST['needed_date']) ? $_POST['needed_date'] : null;
    $notes = cleanInput($_POST['notes'] ?? '');
    $action = $_POST['action'] ?? 'submit';
    $userId = (int)$user['id'];
    $userRole = (string)($user['role'] ?? '');
    $requestedBy = $userId;
    $reqNumber = trim((string)($_POST['req_number'] ?? ''));
    $adminPriceNotes = cleanInput($_POST['admin_price_notes'] ?? '');

    $items = [];
    $totalAmount = 0;
    if (!empty($_POST['item_name']) && is_array($_POST['item_name'])) {
        $itemNames = $_POST['item_name'];
        $itemDescs = $_POST['item_desc'] ?? [];
        $itemQtys = $_POST['item_qty'] ?? [];
        $itemUnits = $_POST['item_unit'] ?? [];
        $itemPrices = $_POST['item_price'] ?? [];
        foreach ($itemNames as $i => $name) {
            $name = trim((string)$name);
            if ($name === '') continue;
            $qty = (float)($itemQtys[$i] ?? 0);
            $price = (float)str_replace([',', '.'], ['', '.'], (string)($itemPrices[$i] ?? 0));
            $subtotal = $qty * $price;
            $totalAmount += $subtotal;
            $items[] = [
                'name' => $name,
                'desc' => (string)($itemDescs[$i] ?? ''),
                'qty' => $qty,
                'unit' => (string)($itemUnits[$i] ?? ''),
                'price' => $price,
                'subtotal' => $subtotal,
                'sort' => $i,
            ];
        }
    }

    if ($title === '' || count($items) === 0) {
        setFlash('danger', 'Judul dan minimal 1 item harus diisi');
    } else {
        try {
            $status = 'pending_supervisor';
            $extraSets = [];
            $extraMsg = 'Submit ke Supervisor';
            if ($action === 'draft') {
                $status = 'draft';
                $extraMsg = 'Draft';
            } else {
                if ($userRole === 'supervisor') {
                    $status = 'pending_manager';
                    $extraSets[] = "supervisor_id = ".(int)$userId.", supervisor_approved_at = NOW()";
                    $extraMsg = 'Supervisor submit → Approve 1 otomatis, lanjut ke Manager (Approval 2)';
                } elseif ($userRole === 'manager') {
                    $status = 'approved';
                    $extraSets[] = "supervisor_id = ".(int)$userId.", supervisor_approved_at = NOW()";
                    $extraSets[] = "manager_id = ".(int)$userId.", manager_approved_at = NOW()";
                    $extraMsg = 'Manager submit → Approve 1 + Approve 2 otomatis LULUS (Final Approved)';
                } elseif ($userRole === 'admin') {
                    $status = 'approved';
                    $extraSets[] = "manager_id = ".(int)$userId.", manager_approved_at = NOW()";
                    $extraMsg = 'Admin submit → Approve Final otomatis';
                }
            }
            $status = $db->escape($status);
            $orderNo = generateOrderNo($db, 'PR');

            $db->beginTransaction();
            $db->query(
                "INSERT INTO orders (order_no, req_number, requested_by, cost_code_id, title, purpose, requested_date, needed_date, total_amount, admin_price_notes, status, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$orderNo, ($reqNumber!=='' ? $reqNumber : null), $requestedBy, $costCodeId > 0 ? $costCodeId : null, $title, $purpose, $requestedDate, $neededDate, $totalAmount, ($adminPriceNotes!==''?$adminPriceNotes:null), $status, $notes]
            );
            $orderId = (int)$db->lastInsertId();
            if (!empty($extraSets)) {
                $db->query("UPDATE orders SET ".implode(', ', $extraSets)." WHERE id = ".$orderId);
            }

            $insItem = $db->getConnection()->prepare(
                "INSERT INTO order_items (order_id, item_name, description, qty, unit, unit_price, subtotal, sort_order) VALUES (?,?,?,?,?,?,?,?)"
            );
            foreach ($items as $it) {
                $insItem->execute([$orderId, $it['name'], $it['desc'], $it['qty'], $it['unit'], $it['price'], $it['subtotal'], $it['sort']]);
            }

            addOrderApproval($db, $orderId, (int)$user['id'], $userRole, $action === 'draft' ? 'update' : 'submit', $extraMsg);
            $db->commit();

            $okMsg = 'Order berhasil diproses';
            if ($status === 'draft') $okMsg = 'Draft disimpan';
            elseif ($status === 'pending_manager') $okMsg = 'Order berhasil dikirim ke Manager (Approval 2)';
            elseif ($status === 'approved') $okMsg = 'Order berhasil disetujui otomatis';
            else $okMsg = 'Order berhasil dikirim ke Supervisor';
            setFlash('success', $okMsg);
            redirect('orders/detail.php?id=' . $orderId);
        } catch (Throwable $e) {
            try { $db->rollBack(); } catch (Throwable $_) {}
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="page-shell page-shell--7xl">
    <div class="page-header flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-6 animate-fade-in">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.3em] text-secondary mb-2">ENG. DEPT • BUAT ORDER REQUEST</p>
            <h1 class="font-display text-3xl font-black text-primary">
                <?= T('order_create_header', 'Buat Order Request Baru') ?>
            </h1>
            <p class="text-sm text-secondary mt-1.5"><?= T('order_create_sub', 'Isi detail permintaan untuk diproses approval') ?></p>
        </div>
        <a href="<?= BASE_URL ?>orders/index.php" class="btn-ghost inline-flex self-start"><i class="fas fa-arrow-left mr-1.5"></i><?= T('btn_back', 'Kembali') ?></a>
    </div>

    <form method="post" class="space-y-6" id="orderForm">
        <div class="card-premium p-5 sm:p-7">
            <h3 class="font-black text-primary text-lg mb-5 flex items-center gap-2">
                <i class="fas fa-circle-info text-amber-600"></i>
                <?= T('order_card_info', 'Informasi Order') ?>
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="form-group">
                    <label class="form-label"><?= T('order_field_title', 'Judul / Keperluan') ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="title" maxlength="255" required value="<?= cleanInput($_POST['title'] ?? '') ?>" class="form-input" placeholder="<?= T('order_ph_title', 'Contoh: Pembelian Sparepart Pompa Air') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= T('order_field_costcode', 'Cost Code (Kode Akun Biaya)') ?></label>
                    <select name="cost_code_id" class="form-input">
                        <option value=""><?= T('order_ph_costcode', '-- Pilih Cost Code --') ?></option>
                        <?php foreach ($costCodes as $cc):
                            $sel = ((int)($_POST['cost_code_id'] ?? 0) === (int)$cc['id']) ? 'selected' : '';
                            echo "<option value=\"{$cc['id']}\" {$sel}>{$cc['code']} — {$cc['name']}</option>";
                        endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= T('order_field_reqdate', 'Tanggal Permintaan') ?></label>
                    <input type="date" name="requested_date" required value="<?= $_POST['requested_date'] ?? date('Y-m-d') ?>" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= T('order_field_needdate', 'Tanggal Dibutuhkan') ?></label>
                    <input type="date" name="needed_date" value="<?= $_POST['needed_date'] ?? '' ?>" class="form-input">
                </div>
                <div class="form-group md:col-span-2">
                    <label class="form-label"><?= T('order_field_purpose', 'Tujuan / Keterangan') ?></label>
                    <textarea name="purpose" rows="2" class="form-input" placeholder="<?= T('order_ph_purpose', 'Keterangan singkat tujuan / keperluan order') ?>"><?= cleanInput($_POST['purpose'] ?? '') ?></textarea>
                </div>
                <div class="form-group md:col-span-2">
                    <label class="form-label"><?= T('order_field_notes', 'Catatan Tambahan') ?></label>
                    <textarea name="notes" rows="2" class="form-input" placeholder="<?= T('order_ph_notes', 'Catatan tambahan untuk approval') ?>"><?= cleanInput($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="card-premium p-5 sm:p-7">
            <div class="flex items-center justify-between mb-5">
                <h3 class="font-black text-primary text-lg flex items-center gap-2">
                    <i class="fas fa-cart-shopping text-emerald-700"></i>
                    <?= T('order_card_items', 'Item Order') ?>
                    <span class="text-red-500">*</span>
                </h3>
                <button type="button" onclick="addItemRow()" class="btn-gold-outline text-sm"><i class="fas fa-plus mr-1"></i><?= T('order_btn_add_item', '+ Tambah Item') ?></button>
            </div>
            <div class="overflow-x-auto -mx-5 sm:-mx-7 px-5 sm:px-7">
                <table class="w-full min-w-[900px] border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-slate-50/80">
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary w-10">No</th>
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary"><?= T('order_th_name', 'Nama Item') ?> *</th>
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary"><?= T('order_th_spec', 'Spesifikasi / Ket') ?></th>
                            <th class="text-right p-3 text-xs font-black uppercase tracking-wider text-secondary w-28"><?= T('order_th_qty', 'Qty') ?></th>
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary w-28"><?= T('order_th_unit', 'Satuan') ?></th>
                            <th class="text-right p-3 text-xs font-black uppercase tracking-wider text-secondary w-44"><?= T('order_th_price', 'Harga Satuan (Rp)') ?></th>
                            <th class="text-right p-3 text-xs font-black uppercase tracking-wider text-secondary w-44"><?= T('order_th_subtotal', 'Subtotal') ?></th>
                            <th class="w-16"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-accent/40 bg-amber-50/40">
                            <td colspan="6" class="p-4 text-right font-black uppercase tracking-wider text-secondary text-sm"><?= T('order_total', 'Total Nilai') ?></td>
                            <td class="p-4 text-right font-black text-primary text-2xl" id="grandTotalRp">Rp 0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <a href="<?= BASE_URL ?>orders/index.php" class="btn-ghost text-center"><i class="fas fa-arrow-left mr-1.5"></i><?= T('order_btn_back_list', 'Kembali ke Daftar Order') ?></a>
            <div class="flex flex-col sm:flex-row gap-3">
                <button type="submit" name="action" value="draft" class="btn-outline text-center"><i class="fas fa-save mr-1.5"></i><?= T('order_btn_draft', 'Simpan Draft') ?></button>
                <button type="submit" name="action" value="submit" class="btn-gold text-center"><i class="fas fa-paper-plane mr-1.5"></i><?= T('order_btn_submit', 'Kirim untuk Approval') ?></button>
            </div>
        </div>
    </form>
</div>

<script>
let itemCounter = 0;
function rp(v){ v=(v||0).toString().replace(/\D/g,''); return 'Rp ' + (parseInt(v||0,10)||0).toLocaleString('id-ID'); }
function calcRow(r){
    const q = parseFloat(r.querySelector('[data-qty]').value.replace(/\D/g,''))||0;
    const p = parseFloat(r.querySelector('[data-price]').value.replace(/\D/g,''))||0;
    const sub = q*p;
    const td = r.querySelector('[data-subtotal]');
    td.textContent = rp(sub);
    td.dataset.v = sub;
    calcGrand();
}
function calcGrand(){
    let t = 0;
    document.querySelectorAll('#itemsBody tr').forEach(r => {
        t += parseFloat((r.querySelector('[data-subtotal]').dataset.v || '0')) || 0;
    });
    const el = document.getElementById('grandTotalRp');
    if (el) el.textContent = rp(t);
}
function addItemRow(prefill = {}){
    const tbody = document.getElementById('itemsBody');
    if (!tbody) return;
    itemCounter++;
    const no = tbody.children.length + 1;
    const name = (prefill.name || '').toString();
    const desc = (prefill.desc || '').toString();
    const qty  = prefill.qty  ?? 1;
    const unit = (prefill.unit || '').toString();
    const price= prefill.price ?? 0;
    const tr = document.createElement('tr');
    tr.className = 'border-b border-border/60 hover:bg-slate-50/50 transition';
    tr.innerHTML = `
        <td class="p-3 font-black text-secondary text-sm align-middle">${no}</td>
        <td class="p-3 align-middle"><input type="text" name="item_name[]" value="${name.replace(/"/g,'&quot;')}" required placeholder="<?= T('order_ph_itemname', 'Nama barang / jasa') ?>" class="form-input !py-2 !text-sm"></td>
        <td class="p-3 align-middle"><input type="text" name="item_desc[]" value="${desc.replace(/"/g,'&quot;')}" placeholder="<?= T('order_ph_itemdesc', 'Spesifikasi, merk, dll') ?>" class="form-input !py-2 !text-sm"></td>
        <td class="p-3 align-middle"><input type="number" step="0.01" min="0" name="item_qty[]" data-qty value="${qty}" oninput="calcRow(this.closest('tr'))" class="form-input !py-2 !text-sm !text-right"></td>
        <td class="p-3 align-middle"><input type="text" name="item_unit[]" value="${unit.replace(/"/g,'&quot;')}" placeholder="<?= T('order_ph_itemunit', 'pcs / kg / m / liter') ?>" class="form-input !py-2 !text-sm"></td>
        <td class="p-3 align-middle"><input type="text" name="item_price[]" data-price value="${price}" oninput="calcRow(this.closest('tr'))" class="form-input !py-2 !text-sm !text-right font-mono" placeholder="0"></td>
        <td class="p-3 align-middle text-right font-bold text-primary" data-subtotal data-v="0">Rp 0</td>
        <td class="p-3 align-middle text-center">
            <button type="button" onclick="this.closest('tr').remove(); recalcNo(); calcGrand();" class="w-8 h-8 rounded-lg text-red-600 hover:bg-red-50 hover:text-red-700 border border-red-200 inline-flex items-center justify-center" title="<?= T('order_btn_hapus_baris', 'Hapus baris') ?>"><i class="fas fa-trash text-xs"></i></button>
        </td>
    `;
    tbody.appendChild(tr);
    calcRow(tr);
}
function recalcNo(){
    document.querySelectorAll('#itemsBody tr').forEach((r, i) => {
        const td = r.querySelector('td:first-child');
        if (td) td.textContent = (i + 1).toString();
    });
}
document.addEventListener('DOMContentLoaded', () => {
    addItemRow({name:'', desc:'', qty:1, unit:'pcs', price:0});
    addItemRow({name:'', desc:'', qty:1, unit:'pcs', price:0});
    addItemRow({name:'', desc:'', qty:1, unit:'pcs', price:0});
    calcGrand();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php';
