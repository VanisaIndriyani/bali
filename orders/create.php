<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = '① REQUEST ORDER — Logistik Flow';
requireRole(['supervisor', 'manager']);

$db = Database::getInstance();
$user = currentUser();
$curRole = (string)($user['role'] ?? '');
if ($curRole !== 'supervisor' && $curRole !== 'manager') {
    header('Location: ' . BASE_URL . 'index.php', true, 302);
    exit();
}

$costCodes = $db->fetchAll("SELECT id, code, name FROM cost_codes WHERE is_active = 1 ORDER BY code ASC");
$engineerList = $db->fetchAll("SELECT id, name, email, role FROM users WHERE status = 1 AND role IN ('engineer','supervisor','manager') ORDER BY FIELD(role,'engineer','supervisor','manager'), name ASC");

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
    $requestedBy = (int)($_POST['requested_by'] ?? 0);
    if ($requestedBy <= 0) $requestedBy = (int)$user['id'];
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
            $status = $action === 'draft' ? 'draft' : 'pending_supervisor';
            $orderNo = generateOrderNo($db, 'PR');

            $db->beginTransaction();
            $db->query(
                "INSERT INTO orders (order_no, req_number, requested_by, cost_code_id, title, purpose, requested_date, needed_date, total_amount, admin_price_notes, status, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$orderNo, ($reqNumber!=='' ? $reqNumber : null), $requestedBy, $costCodeId > 0 ? $costCodeId : null, $title, $purpose, $requestedDate, $neededDate, $totalAmount, ($adminPriceNotes!==''?$adminPriceNotes:null), $status, $notes]
            );
            $orderId = (int)$db->lastInsertId();

            $insItem = $db->getConnection()->prepare(
                "INSERT INTO order_items (order_id, item_name, description, qty, unit, unit_price, subtotal, sort_order) VALUES (?,?,?,?,?,?,?,?)"
            );
            foreach ($items as $it) {
                $insItem->execute([$orderId, $it['name'], $it['desc'], $it['qty'], $it['unit'], $it['price'], $it['subtotal'], $it['sort']]);
            }

            addOrderApproval($db, $orderId, (int)$user['id'], $user['role'], $action === 'draft' ? 'update' : 'submit', $status === 'pending_supervisor' ? 'Submit ke Supervisor' : 'Draft');
            $db->commit();

            setFlash('success', $status === 'draft' ? 'Draft disimpan' : 'Order berhasil dikirim ke Supervisor');
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
            <p class="text-[11px] font-bold uppercase tracking-[0.25em] text-secondary mb-1">
                <span class="inline-flex items-center gap-2">
                    <span class="w-6 h-6 rounded-full bg-gradient-to-br from-amber-500 to-orange-600 text-white inline-flex items-center justify-center text-[10px] font-black shadow-md shadow-amber-500/40">①</span>
                    <span>REQUEST ORDER → ORDER BY → PICK ACCOUNT → ITEM (BISA TAMBAH) → REQ NUMBER → APPROVAL</span>
                </span>
            </p>
            <h1 class="font-display text-3xl font-black text-primary flex items-center gap-3">
                <i class="fas fa-boxes-stacked text-amber-700"></i>
                LOGISTIC <span class="text-amber-700">·</span> Request Order Baru
            </h1>
            <p class="text-sm text-secondary mt-1">Flow sesuai catatan: ① Request Order → ② Order by / Pilih Eng → ③ Pilih Account → ④ Item Order QTY (bisa ditambah) → ⑤ REQ NUMBER (Admin isi nanti + harga)</p>
        </div>
        <a href="<?= BASE_URL ?>orders/index.php" class="btn-ghost inline-flex self-start"><i class="fas fa-clipboard-list mr-1.5"></i>⑧ DAFTAR ORDER / PR</a>
    </div>

    <form method="post" class="space-y-6" id="orderForm">
        <!-- ============ FLOW 1 & 2 + 3 = INFORMASI ORDER ============ -->
        <div class="card-premium p-5 sm:p-7 shadow-lg shadow-amber-500/5 ring-1 ring-amber-100">
            <h3 class="font-black text-primary text-lg mb-5 flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-gradient-to-br from-amber-500 to-orange-600 text-white text-xs font-black shadow-sm">①</span>
                <span>INFORMASI REQUEST ORDER</span>
                <span class="ml-auto text-[10px] font-bold uppercase tracking-widest text-amber-700 bg-amber-50 px-2.5 py-1 rounded-full ring-1 ring-amber-200">Order By + Account</span>
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="form-group">
                    <label class="form-label flex items-center gap-1.5">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-amber-100 text-amber-900 text-[10px] font-black">②</span>
                        Order by / Pilih Eng (dari data master users) <span class="text-red-500">*</span>
                    </label>
                    <select name="requested_by" required class="form-input">
                        <option value="">-- Pilih Nama Engineer / Staff --</option>
                        <?php
                        $selectedReqBy = (int)($_POST['requested_by'] ?? ($user['id'] ?? 0));
                        foreach ($engineerList as $eng):
                            $sel = ($selectedReqBy === (int)$eng['id']) ? 'selected' : '';
                            $roleTag = match($eng['role'] ?? '') { 'engineer' => 'Eng', 'supervisor' => 'Spv', 'manager' => 'Mgr', default => 'User' };
                            echo sprintf('<option value="%d" %s>[%s] %s — %s</option>', (int)$eng['id'], $sel, strtoupper($roleTag), htmlspecialchars((string)$eng['name']), htmlspecialchars((string)$eng['email']));
                        endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label flex items-center gap-1.5">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-amber-100 text-amber-900 text-[10px] font-black">③</span>
                        Pilih Account (dari data master cost_codes)
                    </label>
                    <select name="cost_code_id" class="form-input">
                        <option value="">-- Pilih Cost Code (Account Biaya) --</option>
                        <?php foreach ($costCodes as $cc):
                            $sel = ((int)($_POST['cost_code_id'] ?? 0) === (int)$cc['id']) ? 'selected' : '';
                            echo "<option value=\"{$cc['id']}\" {$sel}>{$cc['code']} — {$cc['name']}</option>";
                        endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label flex items-center gap-1.5">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-sky-100 text-sky-900 text-[10px] font-black">⑤</span>
                        REQ NUMBER (Diisi Admin nanti beserta harga)
                    </label>
                    <input type="text" name="req_number" maxlength="60" value="<?= cleanInput($_POST['req_number'] ?? '') ?>" class="form-input font-mono tracking-wide" placeholder="Contoh: REQ-LOG/008/AUG/2026">
                </div>
                <div class="form-group">
                    <label class="form-label">Judul / Keperluan <span class="text-red-500">*</span></label>
                    <input type="text" name="title" maxlength="255" required value="<?= cleanInput($_POST['title'] ?? '') ?>" class="form-input" placeholder="Contoh: Pembelian Sparepart Pompa Air">
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal Permintaan</label>
                    <input type="date" name="requested_date" required value="<?= $_POST['requested_date'] ?? date('Y-m-d') ?>" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal Dibutuhkan</label>
                    <input type="date" name="needed_date" value="<?= $_POST['needed_date'] ?? '' ?>" class="form-input">
                </div>
                <div class="form-group md:col-span-2">
                    <label class="form-label flex items-center gap-1.5">
                        <i class="fas fa-coins text-amber-700 text-xs"></i>
                        KETERANGAN HARGA (Input Admin & Isi Harga Satuan / Total)
                    </label>
                    <textarea name="admin_price_notes" rows="1.5" class="form-input" placeholder="Catatan khusus admin tentang harga satuan / nego supplier / dll (opsional)"><?= cleanInput($_POST['admin_price_notes'] ?? '') ?></textarea>
                </div>
                <div class="form-group md:col-span-2">
                    <label class="form-label">Tujuan / Keterangan</label>
                    <textarea name="purpose" rows="2" class="form-input" placeholder="Keterangan singkat tujuan / keperluan order"><?= cleanInput($_POST['purpose'] ?? '') ?></textarea>
                </div>
                <div class="form-group md:col-span-2">
                    <label class="form-label">Catatan Tambahan</label>
                    <textarea name="notes" rows="2" class="form-input" placeholder="Catatan tambahan untuk approval"><?= cleanInput($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- ============ FLOW 4 = ITEM ORDER QTY BISA DITAMBAH ============ -->
        <div class="card-premium p-5 sm:p-7">
            <div class="flex items-center justify-between mb-5">
                <h3 class="font-black text-primary text-lg flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 text-white text-xs font-black shadow-sm">④</span>
                    <span>ITEM ORDER QTY <span class="text-emerald-700 text-sm font-bold">(BISA DITAMBAH BANYAK BARIS!)</span></span>
                    <span class="text-red-500">*</span>
                </h3>
                <button type="button" onclick="addItemRow()" class="btn-gold-outline text-sm"><i class="fas fa-plus mr-1"></i>+ Tambah Item</button>
            </div>
            <div class="overflow-x-auto -mx-5 sm:-mx-7 px-5 sm:px-7">
                <table class="w-full min-w-[900px] border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-slate-50/80">
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary w-10">No</th>
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary">Nama Item *</th>
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary">Spesifikasi / Ket</th>
                            <th class="text-right p-3 text-xs font-black uppercase tracking-wider text-secondary w-28">Qty</th>
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary w-28">Satuan</th>
                            <th class="text-right p-3 text-xs font-black uppercase tracking-wider text-secondary w-44">Harga Satuan (Rp)</th>
                            <th class="text-right p-3 text-xs font-black uppercase tracking-wider text-secondary w-44">Subtotal</th>
                            <th class="w-16"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-accent/40 bg-amber-50/40">
                            <td colspan="6" class="p-4 text-right font-black uppercase tracking-wider text-secondary text-sm">Total Nilai</td>
                            <td class="p-4 text-right font-black text-primary text-2xl" id="grandTotalRp">Rp 0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <a href="<?= BASE_URL ?>orders/index.php" class="btn-ghost text-center">Kembali ke ⑧ Daftar Order</a>
            <div class="flex flex-col sm:flex-row gap-3">
                <button type="submit" name="action" value="draft" class="btn-outline text-center"><i class="fas fa-save mr-1.5"></i>Simpan Draft</button>
                <button type="submit" name="action" value="submit" class="btn-gold text-center"><i class="fas fa-paper-plane mr-1.5"></i>Kirim ke Supervisor → Approval 1</button>
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
    r.querySelector('[data-subtotal]').textContent = rp(sub);
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
        <td class="p-3 align-middle"><input type="text" name="item_name[]" value="${name.replace(/"/g,'&quot;')}" required placeholder="Nama barang / jasa" class="form-input !py-2 !text-sm"></td>
        <td class="p-3 align-middle"><input type="text" name="item_desc[]" value="${desc.replace(/"/g,'&quot;')}" placeholder="Spesifikasi, merk, dll" class="form-input !py-2 !text-sm"></td>
        <td class="p-3 align-middle"><input type="number" step="0.01" min="0" name="item_qty[]" data-qty value="${qty}" oninput="calcRow(this.closest('tr'))" class="form-input !py-2 !text-sm !text-right"></td>
        <td class="p-3 align-middle"><input type="text" name="item_unit[]" value="${unit.replace(/"/g,'&quot;')}" placeholder="pcs / kg / m / liter" class="form-input !py-2 !text-sm"></td>
        <td class="p-3 align-middle"><input type="text" name="item_price[]" data-price value="${price}" oninput="calcRow(this.closest('tr'))" class="form-input !py-2 !text-sm !text-right font-mono" placeholder="0"></td>
        <td class="p-3 align-middle text-right font-bold text-primary" data-subtotal data-v="0">Rp 0</td>
        <td class="p-3 align-middle text-center">
            <button type="button" onclick="this.closest('tr').remove(); recalcNo(); calcGrand();" class="w-8 h-8 rounded-lg text-red-600 hover:bg-red-50 hover:text-red-700 border border-red-200 inline-flex items-center justify-center" title="Hapus baris"><i class="fas fa-trash text-xs"></i></button>
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
// auto add 3 rows on first load sesuai gambar kertas ("contoh 1... 2... 3...")
document.addEventListener('DOMContentLoaded', () => {
    addItemRow({name:'', desc:'', qty:1, unit:'pcs', price:0});
    addItemRow({name:'', desc:'', qty:1, unit:'pcs', price:0});
    addItemRow({name:'', desc:'', qty:1, unit:'pcs', price:0});
    calcGrand();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php';
