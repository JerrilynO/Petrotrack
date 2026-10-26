<?php
session_start();
$logged_in_name = $_SESSION['name'] ?? 'User';
$logged_in_role = $_SESSION['role'] ?? '';
$initials = strtoupper(substr($logged_in_name, 0, 1));
include 'config/db_connect.php';
$required_role = 'admin';
include 'auth_check.php';

if (!function_exists('getRoleLabel')) {
    function getRoleLabel($role) {
        switch($role) {
            case 'admin': return 'Logistics Manager';
            case 'driver': return 'Tanker Driver';
            case 'supervisor': return 'Station Supervisor';
            default: return 'Staff';
        }
    }
}

// ── PERIOD FILTER ─────────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'this_month';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';

switch ($period) {
    case 'today':
        $where_date = "AND DATE(d.created_at) = CURDATE()";
        $period_label = "Today";
        break;
    case 'this_week':
        $where_date = "AND YEARWEEK(d.created_at, 1) = YEARWEEK(CURDATE(), 1)";
        $period_label = "This Week";
        break;
    case 'last_month':
        $where_date = "AND YEAR(d.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(d.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
        $period_label = "Last Month";
        break;
    case 'custom':
        $df = $conn->real_escape_string($date_from);
        $dt = $conn->real_escape_string($date_to);
        $where_date = "AND DATE(d.created_at) BETWEEN '$df' AND '$dt'";
        $period_label = $date_from && $date_to ? date('d M Y', strtotime($date_from)).' – '.date('d M Y', strtotime($date_to)) : "Custom Range";
        break;
    case 'all_time':
        $where_date = "";
        $period_label = "All Time";
        break;
    default: // this_month
        $where_date = "AND YEAR(d.created_at) = YEAR(CURDATE()) AND MONTH(d.created_at) = MONTH(CURDATE())";
        $period_label = "This Month";
        $period = 'this_month';
        break;
}

// Simpler version for queries without join alias
$where_date_simple = str_replace('d.created_at', 'created_at', $where_date);

// ── STATS ─────────────────────────────────────────────────────────────────────
$total_res = $conn->query("SELECT COUNT(*) as total FROM deliveries WHERE 1=1 $where_date_simple");
$total_deliveries = $total_res->fetch_assoc()['total'];

$vol_res = $conn->query("SELECT SUM(volume_delivered) as vol FROM deliveries WHERE volume_delivered IS NOT NULL $where_date_simple");
$total_volume = $vol_res->fetch_assoc()['vol'] ?? 0;

// Only count loss on DELIVERED deliveries where both volumes were recorded
$loss_res = $conn->query("SELECT SUM(driver_reported_vol - station_reported_vol) as total_loss FROM deliveries WHERE delivery_status='delivered' AND driver_reported_vol IS NOT NULL AND station_reported_vol IS NOT NULL $where_date_simple");
$total_loss = max(0, $loss_res->fetch_assoc()['total_loss'] ?? 0);

$delivered_res = $conn->query("SELECT COUNT(*) as done FROM deliveries WHERE delivery_status='delivered' $where_date_simple");
$delivered_count = $delivered_res->fetch_assoc()['done'];

$flagged_res = $conn->query("SELECT COUNT(*) as cnt FROM deliveries WHERE delivery_status='flagged' $where_date_simple");
$flagged_count = $flagged_res->fetch_assoc()['cnt'];

$success_rate = $total_deliveries > 0 ? ($delivered_count / $total_deliveries) * 100 : 0;

// ── FLAGGED DELIVERIES (with discrepancy reason) ──────────────────────────────
$flagged_result = $conn->query(
    "SELECT d.id, d.volume_loaded, d.driver_reported_vol, d.station_reported_vol,
            d.discrepancy_reason, d.delivery_date, d.created_at,
            d.source, d.destination, d.product_type,
            v.plate_number, u.name as driver_name,
            (d.driver_reported_vol - d.station_reported_vol) as discrepancy
     FROM deliveries d
     JOIN vehicles v ON d.truck_id = v.id
     LEFT JOIN users u ON v.driver_id = u.id
     WHERE d.delivery_status = 'flagged' $where_date
     ORDER BY d.id DESC"
);

// ── SUSPENDED DRIVERS ─────────────────────────────────────────────────────────
$suspended_result = $conn->query("SELECT id, name, phone, status_reason FROM users WHERE status='suspended' AND role='driver'");

// ── ALL DELIVERIES HISTORY ────────────────────────────────────────────────────
$history_result = $conn->query(
    "SELECT d.id, d.source, d.destination, d.product_type,
            d.volume_loaded, d.driver_reported_vol, d.station_reported_vol,
            d.discrepancy_reason, d.delivery_status, d.delivery_date,
            v.plate_number, u.name as driver_name
     FROM deliveries d
     JOIN vehicles v ON d.truck_id = v.id
     LEFT JOIN users u ON v.driver_id = u.id
     WHERE 1=1 $where_date
     ORDER BY d.id DESC"
);
$all_history = $history_result->fetch_all(MYSQLI_ASSOC);

// ── CHART DATA (last 8 completed trips in period) ─────────────────────────────
$chart_result = $conn->query(
    "SELECT d.id, d.volume_loaded, d.driver_reported_vol, d.station_reported_vol,
            (d.driver_reported_vol - d.station_reported_vol) as loss
     FROM deliveries d
     WHERE d.delivery_status='delivered'
     AND d.driver_reported_vol IS NOT NULL AND d.station_reported_vol IS NOT NULL
     $where_date
     ORDER BY d.id DESC LIMIT 8"
);
$trips = $chart_result->fetch_all(MYSQLI_ASSOC);
$max_vol = 1;
foreach ($trips as $t) { if ($t['volume_loaded'] > $max_vol) $max_vol = $t['volume_loaded']; }

// ── UNREAD MESSAGES ───────────────────────────────────────────────────────────
$unread = $conn->prepare("SELECT COUNT(*) as c FROM messages WHERE receiver_id=? AND is_read=0");
$unread->bind_param("i", $_SESSION['user_id']); $unread->execute();
$uc = $unread->get_result()->fetch_assoc()['c']; $unread->close();

$products = ['PMS'=>'PMS (Petrol)','AGO'=>'AGO (Diesel)','DPK'=>'DPK (Kerosene)','ATK'=>'ATK (Aviation)'];

// ── PDF GENERATION ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_pdf'])) {
    require_once 'tcpdf/TCPDF-main/tcpdf.php';

    $pdf_flagged   = $conn->query("SELECT d.id, d.volume_loaded, d.driver_reported_vol, d.station_reported_vol, d.discrepancy_reason, d.delivery_date, d.source, d.destination, d.product_type, v.plate_number, u.name as driver_name FROM deliveries d JOIN vehicles v ON d.truck_id=v.id LEFT JOIN users u ON v.driver_id=u.id WHERE d.delivery_status='flagged' ORDER BY d.id DESC LIMIT 15");
    $pdf_history   = $conn->query("SELECT d.id, d.source, d.destination, d.product_type, d.volume_loaded, d.driver_reported_vol, d.station_reported_vol, d.delivery_status, d.delivery_date, v.plate_number, u.name as driver_name FROM deliveries d JOIN vehicles v ON d.truck_id=v.id LEFT JOIN users u ON v.driver_id=u.id WHERE 1=1 $where_date ORDER BY d.id DESC LIMIT 25");
    $pdf_suspended = $conn->query("SELECT name, phone, status_reason FROM users WHERE status='suspended' AND role='driver'");

    $date     = date('d M Y');
    $time     = date('H:i');
    $avg_loss = $delivered_count > 0 ? number_format($total_loss / max($delivered_count, 1)) : '0';
    $prod_map = ['PMS'=>'PMS (Petrol)','AGO'=>'AGO (Diesel)','DPK'=>'DPK (Kerosene)','ATK'=>'ATK (Aviation)'];

    // Custom PDF class
    class PetroTrackPDF extends TCPDF {
        public $reportPeriod = '';
        public $generatedBy  = '';
        public $generatedOn  = '';

        public function Header() {
            $this->SetFont('helvetica', '', 8);
            $this->SetTextColor(148, 163, 184);
            $this->Cell(0, 6, 'PetroTrack Logistics  |  Performance Report  |  ' . $this->reportPeriod, 0, 0, 'L');
            $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 1, 'R');
            $this->SetDrawColor(226, 232, 240);
            $this->Line($this->getMargins()['left'], $this->GetY(), $this->getPageWidth() - $this->getMargins()['right'], $this->GetY());
            $this->Ln(5);
        }

        public function Footer() {
            $this->SetY(-14);
            $this->SetFont('helvetica', '', 7);
            $this->SetTextColor(148, 163, 184);
            $this->SetDrawColor(226, 232, 240);
            $this->Line($this->getMargins()['left'], $this->GetY(), $this->getPageWidth() - $this->getMargins()['right'], $this->GetY());
            $this->Ln(2);
            $this->Cell(0, 5, 'Confidential  |  PetroTrack Logistics  |  Generated by ' . $this->generatedBy . ' on ' . $this->generatedOn, 0, 0, 'C');
        }
    }

    $pdf = new PetroTrackPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->reportPeriod = $period_label;
    $pdf->generatedBy  = $logged_in_name;
    $pdf->generatedOn  = $date . ' at ' . $time;
    $pdf->SetCreator('PetroTrack');
    $pdf->SetAuthor($logged_in_name);
    $pdf->SetTitle('PetroTrack Performance Report');
    $pdf->SetMargins(20, 28, 20);
    $pdf->SetAutoPageBreak(true, 24);

    // ── PAGE 1: COVER ────────────────────────────────────────────────────────
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 11);

    $cover  = '<style>* { font-family: helvetica; }</style>';
    $cover .= '
<table style="width:100%; margin-bottom:4px;">
    <tr>
        <td>
            <div style="font-size:30px; font-weight:bold; color:#1e293b; letter-spacing:-1px;">PetroTrack</div>
            <div style="font-size:9px; color:#94a3b8; letter-spacing:2.5px; text-transform:uppercase; margin-top:3px;">Petroleum Logistics Management System</div>
        </td>
        <td style="text-align:right; vertical-align:bottom;">
            <div style="font-size:12px; color:#4f0470; font-weight:bold; text-transform:uppercase; letter-spacing:1px;">Performance Report</div>
        </td>
    </tr>
</table>
<table style="width:100%; margin-bottom:28px;"><tr><td style="height:3px; background:#1e293b;"></td></tr></table>

<table style="width:100%; background:#f8fafc; border-radius:8px; margin-bottom:28px;">
    <tr>
        <td style="padding:22px 24px; width:40%;">
            <div style="font-size:9px; color:#94a3b8; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:8px;">Reporting Period</div>
            <div style="font-size:20px; font-weight:bold; color:#1e293b;">' . htmlspecialchars($period_label) . '</div>
        </td>
        <td style="padding:22px 24px; width:30%; border-left:1px solid #e2e8f0;">
            <div style="font-size:9px; color:#94a3b8; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:8px;">Generated By</div>
            <div style="font-size:13px; font-weight:bold; color:#1e293b;">' . htmlspecialchars($logged_in_name) . '</div>
            <div style="font-size:10px; color:#64748b; margin-top:3px;">' . getRoleLabel($logged_in_role) . '</div>
        </td>
        <td style="padding:22px 24px; width:30%; border-left:1px solid #e2e8f0;">
            <div style="font-size:9px; color:#94a3b8; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:8px;">Date Generated</div>
            <div style="font-size:13px; font-weight:bold; color:#1e293b;">' . $date . '</div>
            <div style="font-size:10px; color:#64748b; margin-top:3px;">' . $time . '</div>
        </td>
    </tr>
</table>

<div style="font-size:9px; font-weight:bold; color:#94a3b8; letter-spacing:2px; text-transform:uppercase; margin-bottom:16px;">KEY METRICS</div>

<table style="width:100%; border-collapse:separate; margin-bottom:28px;">
    <tr>
        <td style="width:22%; padding:24px 18px; background:#f8fafc; text-align:center; border-top:4px solid #4f0470; border-radius:6px;">
            <div style="font-size:36px; font-weight:bold; color:#4f0470;">' . number_format($total_deliveries) . '</div>
            <div style="font-size:9px; color:#64748b; text-transform:uppercase; letter-spacing:0.8px; margin-top:8px;">Total Deliveries</div>
        </td>
        <td style="width:4%;"></td>
        <td style="width:22%; padding:24px 18px; background:#f0fdf4; text-align:center; border-top:4px solid #10b981; border-radius:6px;">
            <div style="font-size:36px; font-weight:bold; color:#065f46;">' . number_format($delivered_count) . '</div>
            <div style="font-size:9px; color:#64748b; text-transform:uppercase; letter-spacing:0.8px; margin-top:8px;">Completed</div>
        </td>
        <td style="width:4%;"></td>
        <td style="width:22%; padding:24px 18px; background:#fef2f2; text-align:center; border-top:4px solid #ef4444; border-radius:6px;">
            <div style="font-size:36px; font-weight:bold; color:#991b1b;">' . number_format($total_loss) . ' L</div>
            <div style="font-size:9px; color:#64748b; text-transform:uppercase; letter-spacing:0.8px; margin-top:8px;">Volume Lost</div>
        </td>
        <td style="width:4%;"></td>
        <td style="width:22%; padding:24px 18px; background:#fffbeb; text-align:center; border-top:4px solid #f59e0b; border-radius:6px;">
            <div style="font-size:36px; font-weight:bold; color:#92400e;">' . number_format($flagged_count) . '</div>
            <div style="font-size:9px; color:#64748b; text-transform:uppercase; letter-spacing:0.8px; margin-top:8px;">Flagged</div>
        </td>
    </tr>
</table>

<table style="width:100%; background:#f8fafc; border-radius:8px;">
    <tr>
        <td style="padding:18px 22px; width:33%; border-right:1px solid #e2e8f0;">
            <div style="font-size:9px; color:#94a3b8; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;">Success Rate</div>
            <div style="font-size:20px; font-weight:bold; color:#1e293b;">' . number_format($success_rate, 1) . '%</div>
        </td>
        <td style="padding:18px 22px; width:33%; border-right:1px solid #e2e8f0;">
            <div style="font-size:9px; color:#94a3b8; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;">Total Volume Delivered</div>
            <div style="font-size:20px; font-weight:bold; color:#1e293b;">' . number_format($total_volume) . ' L</div>
        </td>
        <td style="padding:18px 22px; width:33%;">
            <div style="font-size:9px; color:#94a3b8; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;">Avg Loss Per Trip</div>
            <div style="font-size:20px; font-weight:bold; color:#1e293b;">' . $avg_loss . ' L</div>
        </td>
    </tr>
</table>';

    $pdf->writeHTML($cover, true, false, true, false, '');

    // ── PAGE 2: FLAGGED + SUSPENDED ──────────────────────────────────────────
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->AddPage();

    $flagged_rows = '';
    $f_count = 0;
    if ($pdf_flagged && $pdf_flagged->num_rows > 0) {
        while ($f = $pdf_flagged->fetch_assoc()) {
            $f_count++;
            $disc   = max(0, ($f['driver_reported_vol'] ?? 0) - ($f['station_reported_vol'] ?? 0));
            $bg     = $f_count % 2 == 0 ? '#fff5f5' : '#ffffff';
            $reason = htmlspecialchars($f['discrepancy_reason'] ?? 'No reason recorded');
            $flagged_rows .= '
            <tr style="background:' . $bg . ';">
                <td style="padding:11px 10px; font-size:10px; border-bottom:1px solid #fee2e2;">
                    <strong>#' . $f['id'] . '</strong><br>
                    <span style="color:#94a3b8; font-size:9px;">' . ($f['delivery_date'] ? date('d M Y', strtotime($f['delivery_date'])) : '—') . '</span>
                </td>
                <td style="padding:11px 10px; font-size:10px; border-bottom:1px solid #fee2e2;">
                    ' . htmlspecialchars($f['driver_name'] ?? 'Unknown') . '<br>
                    <span style="color:#94a3b8; font-size:9px;">' . htmlspecialchars($f['plate_number']) . '</span>
                </td>
                <td style="padding:11px 10px; font-size:10px; border-bottom:1px solid #fee2e2;">
                    ' . htmlspecialchars($f['source'] ?? '—') . '<br>
                    <span style="color:#94a3b8;">to ' . htmlspecialchars($f['destination'] ?? '—') . '</span>
                </td>
                <td style="padding:11px 10px; font-size:10px; border-bottom:1px solid #fee2e2; color:#1d4ed8; font-weight:bold;">' . ($f['driver_reported_vol'] ? number_format($f['driver_reported_vol']) . ' L' : '—') . '</td>
                <td style="padding:11px 10px; font-size:10px; border-bottom:1px solid #fee2e2; color:#059669; font-weight:bold;">' . ($f['station_reported_vol'] ? number_format($f['station_reported_vol']) . ' L' : '—') . '</td>
                <td style="padding:11px 10px; font-size:12px; border-bottom:1px solid #fee2e2; color:#dc2626; font-weight:bold;">-' . number_format($disc) . ' L</td>
            </tr>
            <tr style="background:' . $bg . ';">
                <td colspan="6" style="padding:4px 10px 14px 10px; font-size:9px; border-bottom:2px solid #fecaca; color:#991b1b;">
                    <strong>Supervisor Note:</strong> ' . $reason . '
                </td>
            </tr>';
        }
    } else {
        $flagged_rows = '<tr><td colspan="6" style="text-align:center; padding:22px; color:#94a3b8; font-size:11px;">No flagged deliveries in this period.</td></tr>';
    }

    $page2  = '<style>* { font-family: helvetica; }</style>';
    $page2 .= '<div style="font-size:9px; font-weight:bold; color:#94a3b8; letter-spacing:2px; text-transform:uppercase; margin-bottom:16px;">FLAGGED DELIVERIES — DISCREPANCY BREAKDOWN</div>';
    $page2 .= '
<table style="width:100%; border-collapse:collapse; margin-bottom:30px;">
    <thead>
        <tr style="background:#1e293b;">
            <th style="padding:12px 10px; font-size:9px; color:white; text-align:left; font-weight:bold; letter-spacing:0.5px;">ORDER / DATE</th>
            <th style="padding:12px 10px; font-size:9px; color:white; text-align:left; font-weight:bold; letter-spacing:0.5px;">DRIVER / TRUCK</th>
            <th style="padding:12px 10px; font-size:9px; color:white; text-align:left; font-weight:bold; letter-spacing:0.5px;">ROUTE</th>
            <th style="padding:12px 10px; font-size:9px; color:white; text-align:left; font-weight:bold; letter-spacing:0.5px;">DRIVER VOL</th>
            <th style="padding:12px 10px; font-size:9px; color:white; text-align:left; font-weight:bold; letter-spacing:0.5px;">STATION DIP</th>
            <th style="padding:12px 10px; font-size:9px; color:white; text-align:left; font-weight:bold; letter-spacing:0.5px;">LOSS</th>
        </tr>
    </thead>
    <tbody>' . $flagged_rows . '</tbody>
</table>';

    $page2 .= '<div style="font-size:9px; font-weight:bold; color:#94a3b8; letter-spacing:2px; text-transform:uppercase; margin-bottom:16px;">SUSPENDED DRIVERS</div>';
    $page2 .= '
<table style="width:100%; border-collapse:collapse;">
    <thead>
        <tr style="background:#1e293b;">
            <th style="padding:12px 14px; font-size:9px; color:white; text-align:left; font-weight:bold; width:28%;">DRIVER</th>
            <th style="padding:12px 14px; font-size:9px; color:white; text-align:left; font-weight:bold; width:20%;">PHONE</th>
            <th style="padding:12px 14px; font-size:9px; color:white; text-align:left; font-weight:bold; width:52%;">SUSPENSION REASON</th>
        </tr>
    </thead>
    <tbody>';

    if ($pdf_suspended && $pdf_suspended->num_rows > 0) {
        $s_count = 0;
        while ($sd = $pdf_suspended->fetch_assoc()) {
            $s_count++;
            $bg = $s_count % 2 == 0 ? '#f8fafc' : '#ffffff';
            $page2 .= '
            <tr style="background:' . $bg . ';">
                <td style="padding:12px 14px; font-size:10px; border-bottom:1px solid #f1f5f9;"><strong>' . htmlspecialchars($sd['name']) . '</strong></td>
                <td style="padding:12px 14px; font-size:10px; border-bottom:1px solid #f1f5f9;">' . htmlspecialchars($sd['phone'] ?? '—') . '</td>
                <td style="padding:12px 14px; font-size:10px; border-bottom:1px solid #f1f5f9; color:#991b1b;">' . htmlspecialchars($sd['status_reason'] ?? 'Under investigation') . '</td>
            </tr>';
        }
    } else {
        $page2 .= '<tr><td colspan="3" style="text-align:center; padding:22px; color:#94a3b8; font-size:11px;">No suspended drivers.</td></tr>';
    }
    $page2 .= '</tbody></table>';
    $pdf->writeHTML($page2, true, false, true, false, '');

    // ── PAGE 3: DELIVERY HISTORY ─────────────────────────────────────────────
    $pdf->AddPage();

    $history_rows  = '';
    $h_count = 0;
    $status_colors = ['delivered'=>'#065f46','flagged'=>'#991b1b','cancelled'=>'#475569','pending'=>'#92400e','in_transit'=>'#1d4ed8','awaiting_verification'=>'#92400e'];

    if ($pdf_history && $pdf_history->num_rows > 0) {
        while ($h = $pdf_history->fetch_assoc()) {
            $h_count++;
            $var     = ($h['driver_reported_vol'] && $h['station_reported_vol']) ? ($h['driver_reported_vol'] - $h['station_reported_vol']) : null;
            $var_str = $var !== null
                ? (abs($var) <= 100
                    ? '<span style="color:#059669; font-weight:bold;">+/-' . number_format(abs($var)) . 'L</span>'
                    : '<span style="color:#dc2626; font-weight:bold;">-' . number_format(abs($var)) . 'L</span>')
                : '<span style="color:#94a3b8;">—</span>';
            $bg = $h['delivery_status'] == 'flagged' ? '#fff5f5' : ($h_count % 2 == 0 ? '#f8fafc' : '#ffffff');
            $sc = $status_colors[$h['delivery_status']] ?? '#475569';
            $history_rows .= '
            <tr style="background:' . $bg . ';">
                <td style="padding:10px 9px; font-size:10px; border-bottom:1px solid #f1f5f9;">
                    <strong>#' . $h['id'] . '</strong><br>
                    <span style="color:#94a3b8; font-size:8px;">' . ($h['delivery_date'] ? date('d M Y', strtotime($h['delivery_date'])) : '—') . '</span>
                </td>
                <td style="padding:10px 9px; font-size:10px; border-bottom:1px solid #f1f5f9;">
                    ' . htmlspecialchars($h['driver_name'] ?? '—') . '<br>
                    <span style="color:#94a3b8; font-size:8px;">' . htmlspecialchars($h['plate_number']) . '</span>
                </td>
                <td style="padding:10px 9px; font-size:10px; border-bottom:1px solid #f1f5f9;">' . htmlspecialchars(substr($h['destination'] ?? '—', 0, 20)) . '</td>
                <td style="padding:10px 9px; font-size:10px; border-bottom:1px solid #f1f5f9;">' . ($prod_map[$h['product_type']] ?? $h['product_type']) . '</td>
                <td style="padding:10px 9px; font-size:10px; border-bottom:1px solid #f1f5f9;">' . number_format($h['volume_loaded']) . ' L</td>
                <td style="padding:10px 9px; font-size:10px; border-bottom:1px solid #f1f5f9; color:#1d4ed8;">' . ($h['driver_reported_vol'] ? number_format($h['driver_reported_vol']) . 'L' : '—') . '</td>
                <td style="padding:10px 9px; font-size:10px; border-bottom:1px solid #f1f5f9; color:#059669;">' . ($h['station_reported_vol'] ? number_format($h['station_reported_vol']) . 'L' : '—') . '</td>
                <td style="padding:10px 9px; font-size:10px; border-bottom:1px solid #f1f5f9;">' . $var_str . '</td>
                <td style="padding:10px 9px; font-size:9px; border-bottom:1px solid #f1f5f9; color:' . $sc . '; font-weight:bold; text-transform:uppercase;">' . str_replace('_', ' ', $h['delivery_status']) . '</td>
            </tr>';
        }
    } else {
        $history_rows = '<tr><td colspan="9" style="text-align:center; padding:22px; color:#94a3b8; font-size:11px;">No deliveries in this period.</td></tr>';
    }

    $page3  = '<style>* { font-family: helvetica; }</style>';
    $page3 .= '<div style="font-size:9px; font-weight:bold; color:#94a3b8; letter-spacing:2px; text-transform:uppercase; margin-bottom:16px;">DELIVERY HISTORY — ' . htmlspecialchars($period_label) . ' (Latest 25)</div>';
    $page3 .= '
<table style="width:100%; border-collapse:collapse;">
    <thead>
        <tr style="background:#1e293b;">
            <th style="padding:11px 9px; font-size:8px; color:white; text-align:left; font-weight:bold;">ORDER</th>
            <th style="padding:11px 9px; font-size:8px; color:white; text-align:left; font-weight:bold;">DRIVER / TRUCK</th>
            <th style="padding:11px 9px; font-size:8px; color:white; text-align:left; font-weight:bold;">DESTINATION</th>
            <th style="padding:11px 9px; font-size:8px; color:white; text-align:left; font-weight:bold;">PRODUCT</th>
            <th style="padding:11px 9px; font-size:8px; color:white; text-align:left; font-weight:bold;">LOADED</th>
            <th style="padding:11px 9px; font-size:8px; color:white; text-align:left; font-weight:bold;">DRIVER VOL</th>
            <th style="padding:11px 9px; font-size:8px; color:white; text-align:left; font-weight:bold;">STATION VOL</th>
            <th style="padding:11px 9px; font-size:8px; color:white; text-align:left; font-weight:bold;">VARIANCE</th>
            <th style="padding:11px 9px; font-size:8px; color:white; text-align:left; font-weight:bold;">STATUS</th>
        </tr>
    </thead>
    <tbody>' . $history_rows . '</tbody>
</table>';

    $pdf->writeHTML($page3, true, false, true, false, '');
    $pdf->Output('PetroTrack_Report_' . date('Y-m-d') . '.pdf', 'D');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reports - PetroTrack</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:#0986B8;--primary-dark:#3b0357;--primary-light:#7c2d9e;--secondary:#0f172a;--muted:#64748b;--border:#e2e8f0;--danger:#ef4444;--success:#10b981;--warning:#f59e0b;--info:#3b82f6;--radius:12px;--radius-lg:20px;--shadow:0 4px 6px -1px rgb(0 0 0/.1),0 2px 4px -2px rgb(0 0 0/.1);--shadow-lg:0 20px 25px -5px rgb(0 0 0/.1),0 8px 10px -6px rgb(0 0 0/.1);--sidebar-width:260px;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#f8fafc 0%,#f3e8ff 50%,#f8fafc 100%);color:#1e293b;display:flex;min-height:100vh;}

/* SIDEBAR */
.sidebar{width:var(--sidebar-width);background:linear-gradient(135deg,#fff 0%,#faf5ff 100%);height:100vh;position:fixed;left:0;top:0;border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;overflow-y:auto;}
.sidebar-header{padding:25px 20px;border-bottom:1px solid var(--border);}
.brand-logo{font-size:1.25rem;font-weight:800;color:var(--secondary);text-decoration:none;display:flex;align-items:center;gap:8px;}
.nav-links{list-style:none;padding:20px 0;flex:1;}
.nav-links li a{display:flex;align-items:center;padding:12px 25px;color:var(--muted);text-decoration:none;font-weight:500;transition:.3s;gap:12px;font-size:.95rem;}
.nav-links li a.active,.nav-links li a:hover{color:var(--primary);background:#faf5ff;border-right:4px solid var(--primary);}
.user-profile{padding:20px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;}
.avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:16px;flex-shrink:0;}
.btn-logout{display:flex;align-items:center;justify-content:center;width:calc(100% - 40px);margin:0 20px 20px;background:var(--secondary);color:white;border:none;padding:12px;border-radius:50px;font-size:.85rem;font-weight:600;cursor:pointer;text-decoration:none;transition:.2s;}
.btn-logout:hover{background:var(--primary);}

/* MAIN */
.main-content{margin-left:var(--sidebar-width);flex:1;padding:30px;width:calc(100% - var(--sidebar-width));}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:15px;}
.page-header h2{font-size:26px;font-weight:700;color:var(--secondary);}
.page-header p{font-size:13px;color:var(--muted);margin-top:3px;}

/* PERIOD FILTER BAR */
.filter-bar{background:white;border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px 22px;margin-bottom:24px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.filter-bar label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);white-space:nowrap;}
.period-btns{display:flex;gap:6px;flex-wrap:wrap;}
.period-btn{padding:7px 16px;border-radius:50px;border:1px solid var(--border);background:white;font-size:12px;font-weight:600;color:var(--muted);cursor:pointer;text-decoration:none;transition:.2s;}
.period-btn:hover{border-color:var(--primary);color:var(--primary);}
.period-btn.active{background:var(--primary);color:white;border-color:var(--primary);}
.custom-range{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.custom-range input{padding:7px 12px;border:1px solid var(--border);border-radius:8px;font-size:12px;font-family:'Inter',sans-serif;outline:none;}
.custom-range input:focus{border-color:var(--primary);}
.btn-apply{padding:7px 16px;background:var(--primary);color:white;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:white;border-radius:var(--radius-lg);padding:22px;box-shadow:var(--shadow);border-top:4px solid transparent;transition:.2s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);}
.stat-card.purple{border-color:var(--primary);}
.stat-card.green{border-color:var(--success);}
.stat-card.red{border-color:var(--danger);}
.stat-card.orange{border-color:var(--warning);}
.stat-title{font-size:.75rem;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;}
.stat-value{font-size:1.9rem;font-weight:800;color:var(--secondary);line-height:1;}
.stat-sub{font-size:.72rem;color:var(--muted);margin-top:6px;}

/* CHARTS */
.reports-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px;}
.chart-card{background:white;padding:24px;border-radius:var(--radius-lg);box-shadow:var(--shadow);border:1px solid rgba(79,4,112,.08);}
.chart-card h3{font-size:1rem;font-weight:700;color:var(--secondary);margin-bottom:6px;display:flex;align-items:center;gap:8px;}
.chart-sub{font-size:.78rem;color:var(--muted);margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--border);}

/* BAR CHART */
.bar-chart{display:flex;flex-direction:column;gap:14px;}
.bar-group{display:flex;align-items:center;gap:12px;}
.bar-label{width:70px;font-size:.75rem;font-weight:600;color:var(--muted);flex-shrink:0;}
.bar-track{flex:1;background:#f1f5f9;height:26px;border-radius:8px;overflow:hidden;position:relative;}
.bar-loaded{height:100%;background:linear-gradient(90deg,#dbeafe,#bfdbfe);border-radius:8px;position:absolute;top:0;left:0;}
.bar-delivered{height:100%;background:linear-gradient(90deg,var(--success),#059669);border-radius:8px;position:absolute;top:0;left:0;display:flex;align-items:center;padding-left:8px;}
.bar-delivered span{font-size:.65rem;font-weight:700;color:white;white-space:nowrap;}
.bar-meta{font-size:.7rem;color:var(--muted);width:90px;text-align:right;flex-shrink:0;line-height:1.4;}

/* DONUT */
.donut-container{display:flex;flex-direction:column;align-items:center;padding:10px;}
.donut-chart{width:150px;height:150px;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative;}
.donut-chart::after{content:'';width:105px;height:105px;background:white;border-radius:50%;position:absolute;}
.donut-text{position:absolute;text-align:center;z-index:10;}
.donut-text span{display:block;font-size:1.4rem;font-weight:800;color:var(--secondary);}
.donut-text small{font-size:.65rem;color:var(--muted);}
.legend{margin-top:16px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;}
.legend-item{display:flex;align-items:center;gap:5px;font-size:.75rem;color:var(--muted);}
.dot{width:9px;height:9px;border-radius:50%;}
.breakdown-row{display:flex;justify-content:space-between;font-size:.8rem;padding:7px 0;border-bottom:1px solid #f1f5f9;}
.breakdown-row:last-child{border-bottom:none;}

/* SECTION TITLE */
.section-title{font-size:1rem;font-weight:700;color:var(--secondary);margin-bottom:14px;display:flex;align-items:center;gap:8px;margin-top:28px;}

/* TABLE */
.data-table{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow);border:1px solid rgba(79,4,112,.08);overflow-x:auto;margin-bottom:24px;}
.data-table table{width:100%;border-collapse:collapse;min-width:750px;}
.data-table th{padding:12px 16px;text-align:left;background:#f8fafc;color:var(--primary);font-weight:600;font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);}
.data-table td{padding:13px 16px;border-bottom:1px solid #f1f5f9;font-size:.83rem;vertical-align:top;}
.data-table tr:last-child td{border-bottom:none;}
.data-table tr:hover td{background:#faf5ff;}
.flagged-row td{background:#fff5f5;}
.flagged-row:hover td{background:#fee2e2;}

/* STATUS PILLS */
.pill{padding:3px 10px;border-radius:50px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px;display:inline-block;}
.pill-delivered{background:#d1fae5;color:#065f46;}
.pill-flagged{background:#fee2e2;color:#991b1b;}
.pill-cancelled{background:#f1f5f9;color:#475569;}
.pill-pending{background:#fed7aa;color:#92400e;}
.pill-in_transit{background:#dbeafe;color:#1e40af;}
.pill-awaiting{background:#fef3c7;color:#92400e;}

/* LOSS REASON BOX */
.reason-box{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;margin-top:6px;font-size:.78rem;color:#991b1b;line-height:1.5;}
.reason-box strong{display:block;font-size:.7rem;text-transform:uppercase;letter-spacing:.3px;margin-bottom:3px;color:#b91c1c;}
.variance-ok{color:var(--success);font-weight:700;}
.variance-bad{color:var(--danger);font-weight:700;}

/* BADGE */
.badge-danger{background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:50px;font-size:.68rem;font-weight:700;}
.btn-reinstate{background:linear-gradient(135deg,var(--success),#059669);color:white;padding:5px 12px;border-radius:6px;font-size:.7rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:.2s;}
.btn-reinstate:hover{transform:translateY(-1px);box-shadow:0 2px 8px rgba(16,185,129,.3);}
.empty-state{text-align:center;padding:36px;color:var(--muted);}
.empty-state i{font-size:1.8rem;display:block;opacity:.3;margin-bottom:8px;}
.btn-export{background:linear-gradient(135deg,var(--primary),var(--primary-light));border:none;padding:10px 22px;border-radius:var(--radius);cursor:pointer;font-weight:600;color:white;transition:.3s;font-size:.875rem;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 6px -1px rgba(79,4,112,.3);text-decoration:none;}
.btn-export:hover{transform:translateY(-2px);box-shadow:0 8px 15px -5px rgba(79,4,112,.4);}
@media(max-width:900px){.reports-grid{grid-template-columns:1fr;}.sidebar{transform:translateX(-100%);}.main-content{margin-left:0;width:100%;padding:20px;}}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-header">
        <a href="admin_dashboard.php" class="brand-logo"><span>🛢️</span> PetroTrack</a>
    </div>
    <ul class="nav-links">
        <li><a href="admin_dashboard.php"></i> Dashboard</a></li>
        <li><a href="vehicle_management.php"></i> Vehicle Management</a></li>
        <li><a href="delivery_management.php"></i> Delivery Orders</a></li>
        <li><a href="user_management.php"></i> User Management</a></li>
        <li><a href="station_management.php"></i> Station Management</a></li>
        <li><a href="tracking_map.php"></i> Simulated Tracking</a></li>
        <li><a href="messages.php"></i> Messages
            <?php if($uc>0) echo '<span style="background:#ef4444;color:white;font-size:10px;padding:2px 7px;border-radius:10px;margin-left:8px;">'.$uc.'</span>'; ?>
        </a></li>
        <li><a href="reports.php" class="active"></i> Reports</a></li>
        <li><a href="profile.php"></i> My Profile</a></li>
    </ul>
    <div class="user-profile">
        <div class="avatar"><?php echo $initials; ?></div>
        <div>
            <div style="font-weight:700;font-size:14px;"><?php echo htmlspecialchars($logged_in_name); ?></div>
            <div style="font-size:11px;color:var(--muted);"><?php echo getRoleLabel($logged_in_role); ?></div>
        </div>
    </div>
    <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
</aside>

<!-- MAIN -->
<main class="main-content">

    <div class="page-header">
        <div>
            <h2><i  style="color:var(--primary);"></i> Performance Reports</h2>
            <p>Showing data for: <strong><?php echo $period_label; ?></strong></p>
        </div>
        <form method="POST" style="margin:0;">
            <button type="submit" name="generate_pdf" class="btn-export">
                <i ></i> Export PDF
            </button>
        </form>
    </div>

    <!-- PERIOD FILTER -->
    <div class="filter-bar">
        <label><i class="fas fa-calendar-alt"></i> &nbsp;Period:</label>
        <div class="period-btns">
            <?php foreach(['today'=>'Today','this_week'=>'This Week','this_month'=>'This Month','last_month'=>'Last Month','all_time'=>'All Time'] as $k=>$v): ?>
            <a href="?period=<?php echo $k; ?>" class="period-btn <?php echo $period==$k?'active':''; ?>"><?php echo $v; ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" action="reports.php" class="custom-range">
            <input type="hidden" name="period" value="custom">
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" required>
            <span style="color:var(--muted);font-size:12px;">to</span>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" required>
            <button type="submit" class="btn-apply">Apply</button>
        </form>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card purple">
            <div class="stat-title"><i ></i> Total Deliveries</div>
            <div class="stat-value"><?php echo number_format($total_deliveries); ?></div>
            <div class="stat-sub"><?php echo $period_label; ?></div>
        </div>
        <div class="stat-card green">
            <div class="stat-title"><i ></i> Completed</div>
            <div class="stat-value"><?php echo number_format($delivered_count); ?></div>
            <div class="stat-sub"><?php echo number_format($success_rate,1); ?>% success rate</div>
        </div>
        <div class="stat-card red">
            <div class="stat-title"><i ></i> Total Volume Loss</div>
            <div class="stat-value"><?php echo number_format($total_loss); ?> L</div>
            <div class="stat-sub">Across verified deliveries only</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-title"><i ></i> Flagged Deliveries</div>
            <div class="stat-value"><?php echo number_format($flagged_count); ?></div>
            <div class="stat-sub">Discrepancy detected</div>
        </div>
    </div>

    <!-- CHARTS -->
    <div class="reports-grid">
        <!-- BAR CHART -->
        <div class="chart-card">
            <h3><i class="fas fa-chart-bar" style="color:var(--primary);"></i> Volume Loaded vs Delivered</h3>
            <div class="chart-sub">Last 8 verified deliveries in period. Blue = loaded, green = station confirmed received.</div>
            <?php if(!empty($trips)): ?>
            <div class="bar-chart">
                <?php foreach($trips as $t):
                    $loaded    = $t['volume_loaded'];
                    $delivered = $t['station_reported_vol'] ?? $t['driver_reported_vol'] ?? 0;
                    $loss      = max(0, ($t['driver_reported_vol'] ?? 0) - ($t['station_reported_vol'] ?? 0));
                    $del_pct   = $max_vol > 0 ? round(($delivered / $max_vol) * 100) : 0;
                    $load_pct  = $max_vol > 0 ? round(($loaded / $max_vol) * 100) : 100;
                ?>
                <div class="bar-group">
                    <div class="bar-label">Trip #<?php echo $t['id']; ?></div>
                    <div class="bar-track" style="position:relative;">
                        <div class="bar-loaded" style="width:<?php echo $load_pct; ?>%;"></div>
                        <div class="bar-delivered" style="width:<?php echo $del_pct; ?>%;">
                            <span><?php echo number_format($delivered); ?>L</span>
                        </div>
                    </div>
                    <div class="bar-meta">
                        <?php if($loss > 0): ?>
                        <span class="variance-bad">-<?php echo number_format($loss); ?>L</span>
                        <?php else: ?>
                        <span class="variance-ok">✓ No loss</span>
                        <?php endif; ?>
                        <br><span><?php echo number_format($loaded); ?>L loaded</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:16px;margin-top:16px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:6px;font-size:.72rem;"><div style="width:12px;height:12px;border-radius:3px;background:#bfdbfe;"></div> Volume Loaded</div>
                <div style="display:flex;align-items:center;gap:6px;font-size:.72rem;"><div style="width:12px;height:12px;border-radius:3px;background:var(--success);"></div> Station Confirmed</div>
            </div>
            <?php else: ?>
            <div class="empty-state"><i></i><p>No verified deliveries in this period.</p></div>
            <?php endif; ?>
        </div>

        <!-- DONUT -->
        <div class="chart-card">
            <h3><i style="color:var(--primary);"></i> Fleet Efficiency</h3>
            <div class="chart-sub">Delivery outcome breakdown for <?php echo $period_label; ?>.</div>
            <div class="donut-container">
                <?php
                    $d_prog  = max($total_deliveries - $delivered_count - $flagged_count, 0);
                    $d_end   = $total_deliveries > 0 ? ($delivered_count / $total_deliveries) * 100 : 0;
                    $p_end   = $total_deliveries > 0 ? (($delivered_count + $d_prog) / $total_deliveries) * 100 : 0;
                    $donut_bg = $total_deliveries > 0
                        ? 'conic-gradient(var(--success) 0% ' . number_format($d_end,1,'.','') . '%, var(--warning) ' . number_format($d_end,1,'.','') . '% ' . number_format($p_end,1,'.','') . '%, var(--danger) ' . number_format($p_end,1,'.','') . '% 100%)'
                        : '#e2e8f0';
                ?>
                <div class="donut-chart" style="background:<?php echo $donut_bg; ?>;">
                    <div class="donut-text">
                        <span><?php echo number_format($success_rate,0); ?>%</span>
                        <small>Success</small>
                    </div>
                </div>
                <div class="legend">
                    <div class="legend-item"><div class="dot" style="background:var(--success);"></div> Delivered (<?php echo $delivered_count; ?>)</div>
                    <div class="legend-item"><div class="dot" style="background:var(--warning);"></div> In Progress (<?php echo $total_deliveries-$delivered_count-$flagged_count; ?>)</div>
                    <div class="legend-item"><div class="dot" style="background:var(--danger);"></div> Flagged (<?php echo $flagged_count; ?>)</div>
                </div>
            </div>
            <div style="background:#f8fafc;border-radius:10px;padding:14px;font-size:.8rem;color:var(--muted);margin-top:4px;">
                <div class="breakdown-row"><span>Total Deliveries</span><strong style="color:var(--secondary);"><?php echo number_format($total_deliveries); ?></strong></div>
                <div class="breakdown-row"><span>Volume Delivered</span><strong style="color:var(--success);"><?php echo number_format($total_volume); ?> L</strong></div>
                <div class="breakdown-row"><span>Volume Lost</span><strong style="color:var(--danger);"><?php echo number_format($total_loss); ?> L</strong></div>
                <div class="breakdown-row"><span>Avg Loss per Trip</span><strong style="color:var(--danger);"><?php echo $delivered_count>0 ? number_format($total_loss/max($delivered_count,1)) : 0; ?> L</strong></div>
            </div>
        </div>
    </div>

    <!-- FLAGGED DELIVERIES WITH REASONS -->
    <div class="section-title"><i style="color:var(--danger);"></i> Flagged Deliveries — Loss Breakdown</div>
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Order</th><th>Driver / Truck</th><th>Route</th><th>Product</th>
                    <th>Loaded</th><th>Driver Said</th><th>Station Dip</th>
                    <th>Loss &amp; Reason</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if($flagged_result && $flagged_result->num_rows > 0):
                while($f = $flagged_result->fetch_assoc()): ?>
            <tr class="flagged-row">
                <td><strong>#<?php echo $f['id']; ?></strong><br><small style="color:var(--muted);"><?php echo $f['delivery_date']?date('d M Y',strtotime($f['delivery_date'])):'—'; ?></small></td>
                <td><?php echo htmlspecialchars($f['driver_name']??'Unknown'); ?><br><small style="color:var(--muted);"><?php echo htmlspecialchars($f['plate_number']); ?></small></td>
                <td style="font-size:.78rem;"><?php echo htmlspecialchars($f['source']??'—'); ?><br><span style="color:var(--muted);">→</span> <?php echo htmlspecialchars($f['destination']??'—'); ?></td>
                <td><?php echo $products[$f['product_type']]??$f['product_type']; ?></td>
                <td><?php echo number_format($f['volume_loaded']); ?> L</td>
                <td style="color:var(--info);font-weight:600;"><?php echo $f['driver_reported_vol'] ? number_format($f['driver_reported_vol']).' L' : '—'; ?></td>
                <td style="color:var(--success);font-weight:600;"><?php echo $f['station_reported_vol'] ? number_format($f['station_reported_vol']).' L' : '—'; ?></td>
                <td>
                    <?php
                    $disc = max(0, ($f['driver_reported_vol']??0) - ($f['station_reported_vol']??0));
                    ?>
                    <span class="badge-danger">-<?php echo number_format($disc); ?> L</span>
                    <?php if(!empty($f['discrepancy_reason'])): ?>
                    <div class="reason-box">
                        <strong>Supervisor note:</strong>
                        <?php echo htmlspecialchars($f['discrepancy_reason']); ?>
                    </div>
                    <?php else: ?>
                    <div style="font-size:.75rem;color:var(--muted);margin-top:4px;">No reason recorded</div>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="delivery_management.php?action=reinstate&id=<?php echo $f['id']; ?>"
                       onclick="return confirm('Reinstate driver and mark delivery resolved?')"
                       class="btn-reinstate"><i class="fas fa-check-circle"></i> Reinstate</a>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="9"><div class="empty-state"><i class="fas fa-check-circle"></i><p>No flagged deliveries in this period.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- SUSPENDED DRIVERS -->
    <div class="section-title"><i style="color:var(--danger);"></i> Suspended Drivers</div>
    <div class="data-table">
        <table>
            <thead><tr><th>Driver</th><th>Phone</th><th>Suspension Reason</th></tr></thead>
            <tbody>
            <?php if($suspended_result && $suspended_result->num_rows > 0):
                while($sd = $suspended_result->fetch_assoc()): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($sd['name']); ?></strong></td>
                <td><?php echo htmlspecialchars($sd['phone']??'—'); ?></td>
                <td>
                    <div class="reason-box" style="background:#fef2f2;border-color:#fecaca;">
                        <strong>Reason:</strong>
                        <?php echo htmlspecialchars($sd['status_reason']??'Under investigation'); ?>
                    </div>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="3"><div class="empty-state"><i class="fas fa-user-check"></i><p>No suspended drivers.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- FULL DELIVERY HISTORY -->
    <div class="section-title"><i class="fas fa-history" style="color:var(--primary);"></i> All Deliveries — <?php echo $period_label; ?></div>
    <div class="data-table">
        <table>
            <thead>
                <tr>
                    <th>Order</th><th>Driver / Truck</th><th>Route</th><th>Product</th>
                    <th>Loaded</th><th>Driver Vol</th><th>Station Vol</th><th>Variance</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if(!empty($all_history)): foreach($all_history as $h):
                $var = ($h['driver_reported_vol'] && $h['station_reported_vol'])
                    ? ($h['driver_reported_vol'] - $h['station_reported_vol']) : null;
                $pillClass = [
                    'delivered'             => 'pill-delivered',
                    'flagged'               => 'pill-flagged',
                    'cancelled'             => 'pill-cancelled',
                    'pending'               => 'pill-pending',
                    'in_transit'            => 'pill-in_transit',
                    'awaiting_verification' => 'pill-awaiting',
                ][$h['delivery_status']] ?? '';
            ?>
            <tr class="<?php echo $h['delivery_status']=='flagged'?'flagged-row':''; ?>">
                <td><strong>#<?php echo $h['id']; ?></strong><br><small style="color:var(--muted);"><?php echo $h['delivery_date']?date('d M Y',strtotime($h['delivery_date'])):'—'; ?></small></td>
                <td><?php echo htmlspecialchars($h['driver_name']??'—'); ?><br><small style="color:var(--muted);"><?php echo htmlspecialchars($h['plate_number']); ?></small></td>
                <td style="font-size:.78rem;"><?php echo htmlspecialchars($h['source']??'—'); ?><br><span style="color:var(--muted);">→</span> <?php echo htmlspecialchars($h['destination']??'—'); ?></td>
                <td><?php echo $products[$h['product_type']]??$h['product_type']; ?></td>
                <td><?php echo number_format($h['volume_loaded']); ?> L</td>
                <td style="color:var(--info);"><?php echo $h['driver_reported_vol'] ? number_format($h['driver_reported_vol']).' L' : '—'; ?></td>
                <td style="color:var(--success);"><?php echo $h['station_reported_vol'] ? number_format($h['station_reported_vol']).' L' : '—'; ?></td>
                <td>
                    <?php if($var !== null): ?>
                        <?php if(abs($var) <= 100): ?>
                            <span class="variance-ok">✓ <?php echo number_format(abs($var)); ?>L</span>
                        <?php else: ?>
                            <span class="variance-bad">⚠ -<?php echo number_format(abs($var)); ?>L</span>
                            <?php if(!empty($h['discrepancy_reason'])): ?>
                            <div class="reason-box"><strong>Reason:</strong><?php echo htmlspecialchars($h['discrepancy_reason']); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: echo '<span style="color:var(--muted);">—</span>'; endif; ?>
                </td>
                <td><span class="pill <?php echo $pillClass; ?>"><?php echo ucfirst(str_replace('_',' ',$h['delivery_status'])); ?></span></td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="9"><div class="empty-state"><i class="fas fa-inbox"></i><p>No deliveries in this period.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>
</body>
</html>