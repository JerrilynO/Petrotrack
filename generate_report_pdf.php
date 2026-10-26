<?php
session_start();
include 'config/db_connect.php';

$required_role = 'admin';
include 'auth_check.php';

$logged_in_name = $_SESSION['name'] ?? 'User';
$logged_in_role = $_SESSION['role'] ?? '';

// --- FETCH STATS ---
$total_deliveries = $conn->query("SELECT COUNT(*) as t FROM deliveries")->fetch_assoc()['t'];
// Loss = driver-reported minus station-reported volume, on delivered trips only (same rule as reports.php)
$total_loss = max(0, $conn->query("SELECT SUM(driver_reported_vol - station_reported_vol) as l FROM deliveries WHERE delivery_status = 'delivered' AND driver_reported_vol IS NOT NULL AND station_reported_vol IS NOT NULL")->fetch_assoc()['l'] ?? 0);
$delivered_count = $conn->query("SELECT COUNT(*) as d FROM deliveries WHERE delivery_status = 'delivered'")->fetch_assoc()['d'];
$success_rate = $total_deliveries > 0 ? round(($delivered_count / $total_deliveries) * 100, 1) : 0;
$pending = $total_deliveries - $delivered_count;

// --- FETCH LAST 5 TRIPS ---
$trips_result = $conn->query("SELECT id, volume_loaded, COALESCE(station_reported_vol, volume_delivered) AS volume_delivered, GREATEST(0, COALESCE(driver_reported_vol, 0) - COALESCE(station_reported_vol, 0)) AS loss FROM deliveries WHERE delivery_status = 'delivered' ORDER BY id DESC LIMIT 5");
$trips = [];
while ($row = $trips_result->fetch_assoc()) {
    $trips[] = $row;
}

require_once 'tcpdf/TCPDF-main/tcpdf.php';

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetCreator('PetroTrack');
$pdf->SetAuthor($logged_in_name);
$pdf->SetTitle('PetroTrack Report');
$pdf->SetMargins(20, 20, 20);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

$date = date('d M Y');

// --- TRIP ROWS ---
$trip_rows = '';
foreach ($trips as $trip) {
    $loss = max(0, $trip['loss']);
    $loss_color = $loss > 50 ? '#e74c3c' : '#27ae60';
    $trip_rows .= "
        <tr>
            <td style='padding: 10px 8px; border-bottom: 1px solid #eeeeee; font-size: 13px;'>#{$trip['id']}</td>
            <td style='padding: 10px 8px; border-bottom: 1px solid #eeeeee; font-size: 13px;'>" . number_format($trip['volume_loaded']) . " L</td>
            <td style='padding: 10px 8px; border-bottom: 1px solid #eeeeee; font-size: 13px;'>" . number_format($trip['volume_delivered']) . " L</td>
            <td style='padding: 10px 8px; border-bottom: 1px solid #eeeeee; font-size: 13px; color: {$loss_color};'>" . number_format($loss) . " L</td>
        </tr>
    ";
}

if (empty($trip_rows)) {
    $trip_rows = "<tr><td colspan='4' style='padding: 15px; text-align: center; color: #999; font-size: 13px;'>No data available</td></tr>";
}

$html = "
<style>
    * { font-family: helvetica; color: #222; }
</style>

<!-- TOP LINE -->
<table style='width:100%; margin-bottom: 30px;'>
    <tr>
        <td style='width: 60%;'>
            <span style='font-size: 22px; font-weight: bold; color: #222;'>PetroTrack</span><br>
            <span style='font-size: 11px; color: #999;'>Performance Report</span>
        </td>
        <td style='text-align: right; font-size: 11px; color: #999;'>
            {$date}<br>
            " . htmlspecialchars($logged_in_name) . "<br>
            " . getRoleLabel($logged_in_role) . "
        </td>
    </tr>
</table>

<!-- DIVIDER -->
<hr style='border: 0; border-top: 1px solid #222; margin-bottom: 30px;'>

<!-- STATS ROW -->
<table style='width: 100%; margin-bottom: 35px;'>
    <tr>
        <td style='width: 33%; text-align: center; padding: 0 10px;'>
            <div style='font-size: 32px; font-weight: bold; color: #222;'>{$total_deliveries}</div>
            <div style='font-size: 11px; color: #999; margin-top: 4px;'>TOTAL DELIVERIES</div>
        </td>
        <td style='width: 33%; text-align: center; padding: 0 10px; border-left: 1px solid #eee; border-right: 1px solid #eee;'>
            <div style='font-size: 32px; font-weight: bold; color: #222;'>{$success_rate}%</div>
            <div style='font-size: 11px; color: #999; margin-top: 4px;'>SUCCESS RATE</div>
        </td>
        <td style='width: 33%; text-align: center; padding: 0 10px;'>
            <div style='font-size: 32px; font-weight: bold; color: #222;'>" . number_format($total_loss) . "L</div>
            <div style='font-size: 11px; color: #999; margin-top: 4px;'>TOTAL VOLUME LOSS</div>
        </td>
    </tr>
</table>

<!-- DIVIDER -->
<hr style='border: 0; border-top: 1px solid #eee; margin-bottom: 30px;'>

<!-- DELIVERY SUMMARY -->
<table style='width: 100%; margin-bottom: 35px;'>
    <tr>
        <td style='width: 50%; padding-right: 15px;'>
            <div style='font-size: 11px; color: #999; margin-bottom: 5px;'>COMPLETED</div>
            <div style='font-size: 18px; font-weight: bold;'>{$delivered_count}</div>
        </td>
        <td style='width: 50%; padding-left: 15px; border-left: 1px solid #eee;'>
            <div style='font-size: 11px; color: #999; margin-bottom: 5px;'>PENDING / IN TRANSIT</div>
            <div style='font-size: 18px; font-weight: bold;'>{$pending}</div>
        </td>
    </tr>
</table>

<!-- DIVIDER -->
<hr style='border: 0; border-top: 1px solid #eee; margin-bottom: 30px;'>

<!-- TRIP TABLE HEADING -->
<div style='font-size: 11px; color: #999; margin-bottom: 12px; letter-spacing: 1px;'>LAST 5 COMPLETED TRIPS</div>

<!-- TRIP TABLE -->
<table style='width: 100%;'>
    <tr style='border-bottom: 1px solid #222;'>
        <td style='font-size: 11px; font-weight: bold; color: #999; padding-bottom: 8px;'>TRIP ID</td>
        <td style='font-size: 11px; font-weight: bold; color: #999; padding-bottom: 8px;'>LOADED</td>
        <td style='font-size: 11px; font-weight: bold; color: #999; padding-bottom: 8px;'>DELIVERED</td>
        <td style='font-size: 11px; font-weight: bold; color: #999; padding-bottom: 8px;'>LOSS</td>
    </tr>
    {$trip_rows}
</table>

<!-- DIVIDER -->
<hr style='border: 0; border-top: 1px solid #eee; margin-top: 30px; margin-bottom: 15px;'>

<!-- FOOTER -->
<table style='width: 100%;'>
    <tr>
        <td style='font-size: 10px; color: #bbb;'>Confidential — PetroTrack Logistics</td>
        <td style='text-align: right; font-size: 10px; color: #bbb;'>Generated {$date}</td>
    </tr>
</table>
";

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('PetroTrack_Report_' . date('Y-m-d') . '.pdf', 'D');
exit;
?>