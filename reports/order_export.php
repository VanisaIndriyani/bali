<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance();
$user = currentUser();
$userRole = (string)($user['role'] ?? 'engineer');

/* ---------- 1. PARAMETER TANGGAL ---------- */
$dateRaw = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateRaw)) {
    $dateRaw = date('Y-m-d');
}
$reportDate = $dateRaw;
$reportDateObj = DateTime::createFromFormat('Y-m-d', $reportDate);
$reportDateLabel = $reportDateObj ? strtoupper($reportDateObj->format('j F Y')) : strtoupper($reportDate);

/* ---------- 2. HELPER ---------- */
function repFmtIndo($v, $dec = 2) { $v=(float)$v; return number_format($v, $dec, ',', '.'); }
function repFmtRupiah($v) { $v=(float)$v; if ($v==0) return '0'; return number_format($v, 0, ',', '.'); }
function repOrderStatusLabel($st) {
    $st = strtolower((string)$st);
    $map = [
        'draft' => 'Draft',
        'pending_supervisor' => 'Pending Supervisor',
        'pending_manager' => 'Pending Manager',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'completed' => 'Completed'
    ];
    return $map[$st] ?? ucfirst($st);
}
function repCsvEscape($v) {
    $v = (string)$v;
    $v = str_replace(["\r\n","\r"], "\n", $v);
    $v = preg_replace('/\s+/u', ' ', $v);
    $v = trim($v);
    if (strpos($v, ',') !== false || strpos($v, ';') !== false || strpos($v, "\n") !== false
        || strpos($v, '"') !== false || strpos($v, '=') === 0 || strpos($v, '@') === 0) {
        return '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}

/* ---------- 3. DATA ORDERS ---------- */
$orderRows = [];
try {
    $orders = $db->fetchAll(
        "SELECT o.id, o.order_no, o.requested_date, o.needed_date, o.title, o.purpose, o.total_amount,
                o.status, o.notes,
                u.name as requester_name, cc.code as cost_code, cc.name as cost_name
         FROM orders o
         LEFT JOIN users u ON u.id = o.requested_by
         LEFT JOIN cost_codes cc ON cc.id = o.cost_code_id
         WHERE o.requested_date = ?
         ORDER BY o.created_at ASC, o.id ASC",
        [$reportDate]
    );

    foreach ($orders as $o) {
        $oid = (int)($o['id'] ?? 0);
        $items = [];
        try {
            $items = $db->fetchAll(
                "SELECT item_name, description, qty, unit, unit_price, subtotal
                 FROM order_items WHERE order_id = ? ORDER BY sort_order ASC, id ASC",
                [$oid]
            );
        } catch (Exception $e) { $items = []; }

        if (count($items) === 0) {
            $orderRows[] = [
                'order_no' => (string)($o['order_no'] ?? ''),
                'order_date' => (string)($o['requested_date'] ?? ''),
                'needed_date' => (string)($o['needed_date'] ?? ''),
                'title' => (string)($o['title'] ?? ''),
                'purpose' => (string)($o['purpose'] ?? ''),
                'requester' => (string)($o['requester_name'] ?? ''),
                'cost_code' => trim(((string)($o['cost_code'] ?? '')) . ' ' . ((string)($o['cost_name'] ?? ''))),
                'status' => repOrderStatusLabel($o['status'] ?? ''),
                'status_raw' => strtolower((string)($o['status'] ?? '')),
                'item_name' => '-',
                'description' => '',
                'qty' => '',
                'unit' => '',
                'unit_price' => 0,
                'subtotal' => 0,
                'grand_total' => (float)($o['total_amount'] ?? 0),
                'notes' => (string)($o['notes'] ?? ''),
            ];
        } else {
            $first = true;
            foreach ($items as $it) {
                $orderRows[] = [
                    'order_no' => $first ? (string)($o['order_no'] ?? '') : '',
                    'order_date' => $first ? (string)($o['requested_date'] ?? '') : '',
                    'needed_date' => $first ? (string)($o['needed_date'] ?? '') : '',
                    'title' => $first ? (string)($o['title'] ?? '') : '',
                    'purpose' => $first ? (string)($o['purpose'] ?? '') : '',
                    'requester' => $first ? (string)($o['requester_name'] ?? '') : '',
                    'cost_code' => $first ? trim(((string)($o['cost_code'] ?? '')) . ' ' . ((string)($o['cost_name'] ?? ''))) : '',
                    'status' => $first ? repOrderStatusLabel($o['status'] ?? '') : '',
                    'status_raw' => $first ? strtolower((string)($o['status'] ?? '')) : '',
                    'item_name' => (string)($it['item_name'] ?? '-'),
                    'description' => (string)($it['description'] ?? ''),
                    'qty' => (float)($it['qty'] ?? 0),
                    'unit' => (string)($it['unit'] ?? ''),
                    'unit_price' => (float)($it['unit_price'] ?? 0),
                    'subtotal' => (float)($it['subtotal'] ?? 0),
                    'grand_total' => $first ? (float)($o['total_amount'] ?? 0) : 0,
                    'notes' => $first ? (string)($o['notes'] ?? '') : '',
                ];
                $first = false;
            }
        }
    }
} catch (Exception $e) { /* orders/items table not exists = kosong */ }

/* ---------- 4. RENDER MODE ---------- */
$format = isset($_GET['format']) ? strtolower(cleanInput($_GET['format'])) : 'print';
$fileName = 'Order_Logistic_' . $reportDate;

if ($format === 'excel') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";

    $sep = ';';
    $out = '';

    $out .= 'ORDER / PURCHASE REQUEST REPORT' . $sep . $sep . $sep . $sep . $sep . $sep . $sep . $sep . $sep . $sep . $sep . $sep . "\n";
    $out .= 'DATE' . $sep . $reportDateLabel . $sep . $sep . $sep . $sep . $sep . $sep . $sep . $sep . $sep . $sep . $sep . $sep . "\n";
    $out .= "\n";

    if (count($orderRows) === 0) {
        $out .= 'No order / purchase request found for selected date.' . "\n";
    } else {
        $out .= 'ORDER NO' . $sep
              . 'ORDER DATE' . $sep
              . 'NEEDED DATE' . $sep
              . 'TITLE' . $sep
              . 'REQUESTED BY' . $sep
              . 'COST CODE' . $sep
              . 'STATUS' . $sep
              . 'ITEM NAME' . $sep
              . 'DESCRIPTION' . $sep
              . 'QTY' . $sep
              . 'UNIT' . $sep
              . 'UNIT PRICE' . $sep
              . 'SUBTOTAL' . $sep
              . 'GRAND TOTAL' . $sep
              . 'NOTES' . "\n";

        foreach ($orderRows as $r) {
            $out .= repCsvEscape($r['order_no']) . $sep
                  . repCsvEscape($r['order_date']) . $sep
                  . repCsvEscape($r['needed_date']) . $sep
                  . repCsvEscape($r['title']) . $sep
                  . repCsvEscape($r['requester']) . $sep
                  . repCsvEscape($r['cost_code']) . $sep
                  . repCsvEscape($r['status']) . $sep
                  . repCsvEscape($r['item_name']) . $sep
                  . repCsvEscape($r['description']) . $sep
                  . repCsvEscape(repFmtIndo($r['qty'], 2)) . $sep
                  . repCsvEscape($r['unit']) . $sep
                  . repCsvEscape(repFmtRupiah($r['unit_price'])) . $sep
                  . repCsvEscape(repFmtRupiah($r['subtotal'])) . $sep
                  . repCsvEscape($r['grand_total'] > 0 ? repFmtRupiah($r['grand_total']) : '') . $sep
                  . repCsvEscape($r['notes']) . "\n";
        }
    }

    echo $out;
    exit;
}

/* ====================================================== */
/* MODE PRINT / HTML DEFAULT                               */
/* ====================================================== */
function repStCls($st) {
    $s = strtolower((string)$st);
    if (strpos($s,'approve') !== false || strpos($s,'complete') !== false) return 'st-green';
    if (strpos($s,'reject') !== false) return 'st-red';
    if (strpos($s,'pending') !== false) return 'st-amber';
    if (strpos($s,'draft') !== false) return 'st-slate';
    return 'st-slate';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?= $fileName ?></title>
<style>
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #0f172a; font-size: 11px; background: #fff; }
    .wrap { max-width: 1250px; margin: 0 auto; padding: 12mm 12mm; }
    .hd { margin-bottom: 12px; border-bottom: 1px solid #cbd5e1; padding-bottom: 8px; }
    .hd h1 { margin: 0 0 3px 0; font-size: 17px; font-weight: 800; letter-spacing: 0.2px; }
    .hd .sub { color: #475569; font-size: 11px; font-weight: 600; }
    table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; vertical-align: top; font-size: 10.5px; }
    th { background: #f1f5f9; font-weight: 700; color: #334155; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.2px; white-space: nowrap; }
    td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    td.ord-no { font-weight: 800; color: #1e293b; white-space: nowrap; }
    td.status { font-weight: 700; white-space: nowrap; }
    .st-green { color: #047857; }
    .st-red { color: #b91c1c; }
    .st-amber { color: #b45309; }
    .st-slate { color: #475569; }
    tr.new-order td { border-top: 2px solid #94a3b8; }
    .empty { padding: 20px; text-align: center; color: #94a3b8; font-style: italic; border: 1px dashed #cbd5e1; border-radius: 8px; margin-top: 14px; }
    .ft { margin-top: 14px; display: flex; justify-content: space-between; color: #64748b; font-size: 10px; border-top: 1px solid #e2e8f0; padding-top: 6px; }
    @media print {
        @page { margin: 12mm 10mm; size: A4 landscape; }
        body { font-size: 10px; }
        .wrap { padding: 0; max-width: 100%; }
        th, td { padding: 3px 5px; font-size: 9.5px; }
    }
</style>
</head>
<body>
<div class="wrap">
    <div class="hd">
        <h1>ORDER / PURCHASE REQUEST REPORT</h1>
        <div class="sub">DATE: <?= $reportDateLabel ?></div>
    </div>

    <?php if (count($orderRows) === 0): ?>
        <div class="empty">No order / purchase request found for selected date.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th style="width:100px">Order No</th>
                <th style="width:80px">Date</th>
                <th style="width:80px">Needed</th>
                <th>Title</th>
                <th style="width:100px">Requester</th>
                <th style="width:90px">Status</th>
                <th>Item</th>
                <th class="num" style="width:55px">Qty</th>
                <th style="width:50px">Unit</th>
                <th class="num" style="width:90px">Unit Price</th>
                <th class="num" style="width:95px">Subtotal</th>
                <th class="num" style="width:100px">Grand Total</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $lastNo = '';
            foreach ($orderRows as $r):
                $newOrder = ($r['order_no'] !== '' && $r['order_no'] !== $lastNo);
                if ($r['order_no'] !== '') $lastNo = $r['order_no'];
                $stCls = $newOrder ? repStCls($r['status_raw']) : '';
            ?>
            <tr class="<?= $newOrder ? 'new-order' : '' ?>">
                <td class="ord-no"><?= htmlspecialchars($r['order_no']) ?></td>
                <td><?= htmlspecialchars($r['order_date']) ?></td>
                <td><?= htmlspecialchars($r['needed_date']) ?></td>
                <td><?= htmlspecialchars($r['title']) ?></td>
                <td><?= htmlspecialchars($r['requester']) ?></td>
                <td class="status <?= $stCls ?>"><?= htmlspecialchars($r['status']) ?></td>
                <td><?= htmlspecialchars($r['item_name']) ?><?php if (strlen($r['description']) > 0): ?><br><span style="color:#64748b;font-size:9.5px"><?= htmlspecialchars($r['description']) ?></span><?php endif; ?></td>
                <td class="num"><?= $r['qty'] !== '' && (float)$r['qty'] > 0 ? repFmtIndo($r['qty'], (fmod((float)$r['qty'], 1) === 0 ? 0 : 2)) : '' ?></td>
                <td><?= htmlspecialchars($r['unit']) ?></td>
                <td class="num"><?= (float)$r['unit_price'] > 0 ? 'Rp ' . repFmtRupiah($r['unit_price']) : '' ?></td>
                <td class="num"><?= (float)$r['subtotal'] > 0 ? 'Rp ' . repFmtRupiah($r['subtotal']) : '' ?></td>
                <td class="num" style="font-weight:700"><?= (float)$r['grand_total'] > 0 ? 'Rp ' . repFmtRupiah($r['grand_total']) : '' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="ft">
        <span>Generated: <?= date('Y-m-d H:i:s') ?></span>
        <span>Page 1 / 1</span>
    </div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function(){ window.print(); }, 400); });</script>
</body>
</html>
