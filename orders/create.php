<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = T('order_create_title', 'Buat Order Request Baru');
requireRole(['engineer', 'supervisor', 'manager']);

$db = Database::getInstance();
$user = currentUser();

$costCodes = $db->fetchAll("SELECT id, code, name FROM cost_codes WHERE is_active = 1 ORDER BY code ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = cleanInput($_POST['title'] ?? '');
    $purpose = cleanInput($_POST['purpose'] ?? '');
    $costCodeId = (int)($_POST['cost_code_id'] ?? 0);
    $requestedDate = $_POST['requested_date'] ?? date('Y-m-d');
    $neededDate = !empty($_POST['needed_date']) ? $_POST['needed_date'] : null;
    $notes = cleanInput($_POST['notes'] ?? '');
    $action = $_POST['action'] ?? 'submit';

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
        setFlash('danger', T('order_validation_err', 'Judul dan minimal 1 item harus diisi'));
    } else {
        try {
            $status = $action === 'draft' ? 'draft' : 'pending_supervisor';
            $orderNo = generateOrderNo($db, 'PR');

            $db->beginTransaction();
            $db->query(
                "INSERT INTO orders (order_no, requested_by, cost_code_id, title, purpose, requested_date, needed_date, total_amount, status, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$orderNo, (int)$user['id'], $costCodeId > 0 ? $costCodeId : null, $title, $purpose, $requestedDate, $neededDate, $totalAmount, $status, $notes]
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

            setFlash('success', $status === 'draft' ? T('order_action_save_ok', 'Draft disimpan') : T('order_action_submit_ok', 'Order berhasil dikirim ke Supervisor'));
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
            <p class="text-[11px] font-bold uppercase tracking-[0.25em] text-secondary mb-1">ENG. DEPT • <?= T('nav_order_create', 'Buat Order Request') ?></p>
            <h1 class="font-display text-3xl font-black text-primary"><?= T('order_create_title', 'Buat Order Request Baru') ?></h1>
            <p class="text-sm text-secondary mt-1"><?= T('order_create_subtitle', 'Isi detail permintaan untuk diproses approval') ?></p>
        </div>
        <a href="<?= BASE_URL ?>orders/index.php" class="btn-ghost inline-flex self-start"><i class="fas fa-arrow-left mr-1.5"></i><?= T('order_btn_back', 'Kembali') ?></a>
    </div>

    <form method="post" class="space-y-6" id="orderForm">
        <div class="card-premium p-5 sm:p-7">
            <h3 class="font-black text-primary text-lg mb-5 flex items-center gap-2"><i class="fas fa-info-circle text-accent"></i> Informasi Order</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="form-group">
                    <label class="form-label"><?= T('order_field_title', 'Judul / Keperluan') ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="title" maxlength="255" required value="<?= cleanInput($_POST['title'] ?? '') ?>" class="form-input" placeholder="<?= T('order_field_title_ph', 'Contoh: Pembelian Sparepart Pompa Air') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= T('order_field_cost', 'Cost Code (Kode Akun Biaya') ?></label>
                    <select name="cost_code_id" class="form-input">
                        <option value="">-- Pilih Cost Code --</option>
                        <?php foreach ($costCodes as $cc):
                            $sel = ((int)($_POST['cost_code_id'] ?? 0) === (int)$cc['id']) ? 'selected' : '';
                            echo "<option value=\"{$cc['id']}\" {$sel}>{$cc['code']} — {$cc['name']}</option>";
                        endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= T('order_field_date', 'Tanggal Permintaan') ?></label>
                    <input type="date" name="requested_date" required value="<?= $_POST['requested_date'] ?? date('Y-m-d') ?>" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= T('order_field_needed', 'Tanggal Dibutuhkan') ?></label>
                    <input type="date" name="needed_date" value="<?= $_POST['needed_date'] ?? '' ?>" class="form-input">
                </div>
                <div class="form-group md:col-span-2">
                    <label class="form-label"><?= T('order_field_purpose', 'Tujuan / Keterangan') ?></label>
                    <textarea name="purpose" rows="2" class="form-input" placeholder="Keterangan singkat tujuan / keperluan order"><?= cleanInput($_POST['purpose'] ?? '') ?></textarea>
                </div>
                <div class="form-group md:col-span-2">
                    <label class="form-label"><?= T('order_field_notes', 'Catatan Tambahan') ?></label>
                    <textarea name="notes" rows="2" class="form-input" placeholder="Catatan tambahan untuk approval"><?= cleanInput($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="card-premium p-5 sm:p-7">
            <div class="flex items-center justify-between mb-5">
                <h3 class="font-black text-primary text-lg flex items-center gap-2"><i class="fas fa-list-check text-accent"></i> <?= T('order_items_title', 'Daftar Barang / Jasa') ?> <span class="text-red-500">*</span></h3>
                <button type="button" onclick="addItemRow()" class="btn-gold-outline text-sm"><i class="fas fa-plus mr-1"></i><?= T('order_item_add', '+ Tambah Item') ?></button>
            </div>
            <div class="overflow-x-auto -mx-5 sm:-mx-7 px-5 sm:px-7">
                <table class="w-full min-w-[900px] border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-slate-50/80">
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary w-10">No</th>
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary"><?= T('order_item_name', 'Nama Item') ?> *</th>
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary"><?= T('order_item_desc', 'Spesifikasi / Ket') ?></th>
                            <th class="text-right p-3 text-xs font-black uppercase tracking-wider text-secondary w-28"><?= T('order_item_qty', 'Qty') ?></th>
                            <th class="text-left p-3 text-xs font-black uppercase tracking-wider text-secondary w-28"><?= T('order_item_unit', 'Satuan') ?></th>
                            <th class="text-right p-3 text-xs font-black uppercase tracking-wider text-secondary w-44"><?= T('order_item_price', 'Harga Satuan (Rp)') ?></th>
                            <th class="text-right p-3 text-xs font-black uppercase tracking-wider text-secondary w-44"><?= T('order_item_subtotal', 'Subtotal') ?></th>
                            <th class="w-16"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-accent/40 bg-amber-50/40">
                            <td colspan="6" class="p-4 text-right font-black uppercase tracking-wider text-secondary text-sm"><?= T('order_field_total', 'Total Nilai') ?></td>
                            <td class="p-4 text-right font-black text-primary text-2xl" id="grandTotalRp">Rp 0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <a href="<?= BASE_URL ?>orders/index.php" class="btn-ghost text-center"><?= T('order_btn_back', 'Kembali') ?></a>
            <div class="flex flex-col sm:flex-row gap-3">
                <button type="submit" name="action" value="draft" class="btn-outline text-center"><i class="fas fa-save mr-1.5"></i><?= T('order_btn_save_draft', 'Simpan Draft') ?></button>
                <button type="submit" name="action" value="submit" class="btn-gold text-center"><i class="fas fa-paper-plane mr-1.5"></i><?= T('order_btn_submit', 'Kirim ke Supervisor') ?></button>
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
    recalc();
}
function recalc(){
    let tot = 0;
    document.querySelectorAll('#itemsBody tr.item-row').forEach(r=>{
        const q = parseFloat(r.querySelector('[data-qty]').value.replace(/\D/g,''))||0;
        const p = parseFloat(r.querySelector('[data-price]').value.replace(/\D/g,''))||0;
        tot += q*p;
    });
    document.getElementById('grandTotalRp').textContent = rp(tot);
}
function addItemRow(def={}){
    itemCounter++;
    const tbody = document.getElementById('itemsBody');
    const no = tbody.querySelectorAll('tr.item-row').length + 1;
    const tr = document.createElement('tr');
    tr.className = 'item-row border-b border-border/70 hover:bg-slate-50/50';
    tr.innerHTML = `
        <td class="p-3 font-bold text-secondary text-center num-no">${no}</td>
        <td class="p-2"><input name="item_name[]" class="form-input !py-2 !px-3 text-sm" placeholder="Nama barang / jasa" value="${def.name||''}" required></td>
        <td class="p-2"><input name="item_desc[]" class="form-input !py-2 !px-3 text-sm" placeholder="Spesifikasi / catatan" value="${def.desc||''}"></td>
        <td class="p-2"><input name="item_qty[]" data-qty type="text" inputmode="decimal" class="form-input !py-2 !px-3 text-sm text-right" placeholder="0" value="${def.qty||1}" oninput="calcRow(this.closest('tr'))"></td>
        <td class="p-2"><input name="item_unit[]" class="form-input !py-2 !px-3 text-sm" placeholder="pcs, kg, hr" value="${def.unit||''}"></td>
        <td class="p-2"><input name="item_price[]" data-price type="text" inputmode="numeric" class="form-input !py-2 !px-3 text-sm text-right" placeholder="0" value="${def.price||0}" oninput="this.value = rp(this.value.replace(/\D/g,'')); calcRow(this.closest('tr'))"></td>
        <td class="p-3 text-right font-bold text-primary" data-subtotal>Rp 0</td>
        <td class="p-2 text-center"><button type="button" class="w-9 h-9 rounded-xl bg-red-50 text-red-600 hover:bg-red-100 border border-red-100" onclick="removeItem(this)" title="<?= T('order_item_remove', 'Hapus') ?>"><i class="fas fa-trash text-xs"></i></button></td>
    `;
    tbody.appendChild(tr);
    renum();
    if(def.price||def.qty) calcRow(tr);
}
function renum(){ document.querySelectorAll('#itemsBody .num-no').forEach((e,i)=>e.textContent = i+1); }
function removeItem(btn){
    const tr = btn.closest('tr.item-row'); if(!tr) return;
    if(document.querySelectorAll('#itemsBody tr.item-row').length<=1){
        tr.querySelectorAll('input').forEach(inp=>{ if(inp.name!=='item_unit[]' && inp.name!=='item_desc[]') inp.value='';});
        tr.querySelectorAll('[data-subtotal]').forEach(el=>el.textContent='Rp 0');
        recalc();
    } else { tr.remove(); renum(); recalc(); }
}
addItemRow();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>