<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-type" content="text/html;charset=utf-8" />
<style>
    body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color:#000; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #000; padding: 6px 10px; vertical-align: top; }
    th { background: #d9d9d9; font-weight: bold; text-align: center; }
    h1 { font-size: 22px; text-align: center; margin: 4px 0; letter-spacing: 2px; font-weight:900; }
    h2 { font-size: 14px; margin: 18px 0 8px; font-weight:900; }
    .dt { font-weight: bold; font-size: 14px; margin: 6px 0; }
    .cen { text-align:center; }
    .num { text-align:right; font-variant-numeric: tabular-nums; }
    .bold { font-weight: bold; }
    .mid { vertical-align: middle; }
    .sign td { border: none; text-align:center; padding-top:18px; }
    .sign .ln { border-top:1px solid #000; padding-top:6px; font-weight:bold; }
    ul { margin: 0; padding-left: 14px; }
    li { margin:0; padding:0; line-height:1.3; }
</style>
</head>
<body>
<h1>DAILY ENGINEERING SUMMARY REPORT</h1>
<p class="dt">DATE: <?=htmlspecialchars($reportDateLabel)?></p>

<h2>1. KEY PERFORMANCE INDICATORS (KPIs)</h2>
<table>
    <thead><tr>
        <th>METRIC</th><th>LAST YEAR (LY)</th><th>TODAY</th><th>ITR</th><th>M&amp;U</th><th>GITB RANK</th>
    </tr></thead>
    <tbody>
    <?php foreach ($kpiData as $r) { ?>
        <tr>
            <td class="bold"><?=htmlspecialchars($r[0])?></td>
            <td class="cen"><?=htmlspecialchars($r[1])?></td>
            <td class="cen"><?=htmlspecialchars($r[2])?></td>
            <td class="cen"><?=htmlspecialchars($r[3])?></td>
            <td class="cen"><?=htmlspecialchars($r[4])?></td>
            <td class="cen"><?=htmlspecialchars($r[5])?></td>
        </tr>
    <?php } ?>
    </tbody>
</table>

<h2>2. UTILITY USAGE SUMMARY</h2>
<table>
    <thead><tr>
        <th>UTILITY</th><th>PERIOD</th><th>USAGE</th><th>COST (Rp.)</th>
    </tr></thead>
    <tbody>
        <tr>
            <td rowspan="2" class="bold cen mid">ELECTRICITY</td>
            <td class="cen">(LY)</td>
            <td class="num"><?=repFmtIndo($elecLY, 0)?> kWh</td>
            <td class="num"><?=repFmtRupiah($elecLY * $TARIF_LISTRIK)?></td>
        </tr>
        <tr>
            <td class="cen">(TODAY)</td>
            <td class="num"><?=repFmtIndo($elecToday, 0)?> kWh</td>
            <td class="num"><?=repFmtRupiah($elecToday * $TARIF_LISTRIK)?></td>
        </tr>
        <tr>
            <td rowspan="2" class="bold cen mid">WATER</td>
            <td class="cen">(LY)</td>
            <td class="num"><?=repFmtIndo($waterLY, 0)?> m3</td>
            <td class="num"><?=repFmtRupiah($waterLY * $TARIF_AIR)?></td>
        </tr>
        <tr>
            <td class="cen">(TODAY)</td>
            <td class="num"><?=repFmtIndo($waterToday, 0)?> m3</td>
            <td class="num"><?=repFmtRupiah($waterToday * $TARIF_AIR)?></td>
        </tr>
        <tr>
            <td rowspan="2" class="bold cen mid">GAS</td>
            <td class="cen">(LY)</td>
            <td class="num"><?=repFmtIndo($gasLY, 0)?> kg</td>
            <td class="num"><?=repFmtRupiah($gasLY * $TARIF_GAS)?></td>
        </tr>
        <tr>
            <td class="cen">(TODAY)</td>
            <td class="num"><?=repFmtIndo($gasToday, 0)?> kg</td>
            <td class="num"><?=repFmtRupiah($gasToday * $TARIF_GAS)?></td>
        </tr>
        <tr>
            <td rowspan="2" class="bold cen mid">FUEL</td>
            <td class="cen">(LY)</td>
            <td class="num"><?=repFmtIndo($fuelLY, 0)?> Liter</td>
            <td class="num"><?=repFmtRupiah($fuelLY * $TARIF_FUEL)?></td>
        </tr>
        <tr>
            <td class="cen">(TODAY)</td>
            <td class="num"><?=repFmtIndo($fuelToday, 0)?> Liter</td>
            <td class="num"><?=repFmtRupiah($fuelToday * $TARIF_FUEL)?></td>
        </tr>
    </tbody>
</table>

<h2>3. ENGINEERING ACTIVITIES</h2>
<table>
    <thead><tr>
        <th>DEPARTMENT</th><th>ACTIVITY DETAIL</th><th>STATUS</th>
    </tr></thead>
    <tbody>
    <?php foreach ($divisions as $d) {
        $list = $actByDiv[$d] ?? [];
        if (count($list) === 0) $list = [['name' => '-', 'status' => '-']];
        $rows = count($list);
        foreach ($list as $idx => $item) {
            $stLabel = repStatusLabel($item['status']);
    ?>
        <tr>
            <?php if ($idx === 0) { ?>
                <td rowspan="<?=$rows?>" class="bold cen mid"><?=htmlspecialchars($d)?></td>
            <?php } ?>
            <td><ul><li><?=htmlspecialchars($item['name'])?></li></ul></td>
            <td class="cen mid">&#10003; <?=htmlspecialchars($stLabel)?></td>
        </tr>
    <?php } } ?>
    </tbody>
</table>

<br><br>
<table class="sign" style="width:100%; border:none;">
    <tr>
        <td style="border:none; width:33%; font-weight:600;">Prepared By:</td>
        <td style="border:none; width:33%; font-weight:600;">Reviewed By:</td>
        <td style="border:none; width:33%; font-weight:600;">Approved By:</td>
    </tr>
    <tr>
        <td style="border:none; height:48px;"></td>
        <td style="border:none; height:48px;"></td>
        <td style="border:none; height:48px;"></td>
    </tr>
    <tr>
        <td class="ln"><?=htmlspecialchars($userName)?></td>
        <td class="ln">Supervisor / Manager</td>
        <td class="ln">Chief Engineer / EAM</td>
    </tr>
</table>
</body>
</html>
