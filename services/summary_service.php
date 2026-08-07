<?php
/**
 * Summary & Analytical Report Service for PHP Application.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../fpdf.php';

function get_summary_report_data($filter_type, $from_date = null, $to_date = null, $accounting_month = null, $financial_year_val = null) {
    $pdo = initialize_database();
    $where = [];
    $params = [];
    $filter_desc = "";

    if (in_array(strtolower($filter_type), ['date', 'date_range'])) {
        if (!$from_date || !$to_date) throw new Exception("Both From Date and To Date are required.");
        $f_db = to_db_date($from_date);
        $t_db = to_db_date($to_date);
        if (!$f_db || !$t_db) throw new Exception("Dates must be in DD/MM/YYYY format.");
        if ($f_db > $t_db) throw new Exception("From Date cannot be after To Date.");
        $where[] = "d.posting_date BETWEEN ? AND ?";
        $params[] = $f_db;
        $params[] = $t_db;
        $filter_desc = "Dates: " . format_date($f_db) . " to " . format_date($t_db);
    } elseif (in_array(strtolower($filter_type), ['month', 'accounting_month'])) {
        if (!$accounting_month) throw new Exception("Accounting Month is required.");
        parse_accounting_month($accounting_month);
        $where[] = "g.accounting_month = ?";
        $params[] = trim($accounting_month);
        $fy_str = financial_year($accounting_month);
        $filter_desc = "Accounting Month: " . trim($accounting_month) . " ({$fy_str})";
    } elseif (in_array(strtolower($filter_type), ['fy', 'financial_year'])) {
        if (!$financial_year_val) throw new Exception("Financial Year is required.");
        preg_match('/^(20\d{2})/', trim($financial_year_val), $m);
        if (!$m) throw new Exception("Invalid Financial Year format. Use YYYY-YY (e.g. 2026-27).");
        $start_year = (int)$m[1];
        $start_db = "{$start_year}-04-01";
        $end_db = ($start_year + 1) . "-03-31";
        $where[] = "d.posting_date BETWEEN ? AND ?";
        $params[] = $start_db;
        $params[] = $end_db;
        $filter_desc = "Financial Year: " . trim($financial_year_val) . " (" . format_date($start_db) . " to " . format_date($end_db) . ")";
    } else {
        throw new Exception("Invalid filter type.");
    }

    $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        WITH ranked_master AS (
            SELECT *,
                   ROW_NUMBER() OVER (
                       PARTITION BY tr_code
                       ORDER BY
                            CASE WHEN controller IS NOT NULL OR css IS NOT NULL THEN 0 ELSE 1 END,
                            source_row_number
                   ) AS row_rank
            FROM scheme_configuration_master
        )
        SELECT g.sectional_number, g.accounting_month, g.generation_date,
               d.posting_date, d.sg_account_name, d.amount, d.state_government, d.cg_account_udch_code,
               m.controller, m.tr_desc, m.central_share, m.state_share, m.sub_head, m.detail_head
        FROM generated_transfer_reports g
        JOIN daily_payment_records d ON d.id = g.daily_payment_record_id
        LEFT JOIN ranked_master m ON m.tr_code = d.sg_account_name AND m.row_rank = 1
        $where_sql
        ORDER BY g.sectional_number ASC, d.posting_date ASC, d.id ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $records = [];
    foreach ($rows as $r) {
        $total_amt = (float)($r['amount'] ?? 0.0);
        $c_pct = ($r['central_share'] !== null) ? (float)$r['central_share'] : 100.0;
        $s_pct = ($r['state_share'] !== null) ? (float)$r['state_share'] : 0.0;

        $c_amt = round(($total_amt * $c_pct) / 100.0, 2);
        $s_amt = round(($total_amt * $s_pct) / 100.0, 2);

        $sec_num = (int)($r['sectional_number'] ?? 0);
        $gen_date = $r['generation_date'] ?: $r['posting_date'];
        $fy_str = financial_year($gen_date);

        $records[] = [
            'sectional_number' => "BK/TE/{$fy_str}/{$sec_num}",
            'sec_raw' => $sec_num,
            'posting_date' => format_date($r['posting_date']),
            'ministry_name' => controller_to_ministry($r['controller']),
            'tr_no' => display_tr_code($r['sg_account_name']),
            'tr_desc' => $r['tr_desc'] ?? '-',
            'total_amount' => $total_amt,
            'central_share_pct' => $c_pct,
            'central_share_amount' => $c_amt,
            'state_share_pct' => $s_pct,
            'state_share_amount' => $s_amt,
            'sub_head' => format_head_code($r['sub_head']),
            'detail_head' => format_head_code($r['detail_head'])
        ];
    }

    return [$records, $filter_desc];
}

function export_summary_excel($records, $filter_desc) {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"Summary_Report_" . date('Ymd') . ".xls\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<table border='1'>";
    echo "<tr><th colspan='11' style='font-size:16px; font-weight:bold; text-align:center;'>CENTRAL AND STATE SHARE SUMMARY REPORT</th></tr>";
    echo "<tr><th colspan='11' style='font-size:11px; text-align:center;'>Filter: {$filter_desc}</th></tr>";
    echo "<tr style='background-color:#eaeaea; font-weight:bold;'>";
    echo "<th>S.No.</th><th>Name of Ministry</th><th>TR No.</th><th>TR Description</th><th>Total Amount (Rs.)</th><th>Central Share (Rs.)</th><th>State Share (Rs.)</th><th>Sub Head</th><th>Detail Head</th><th>Sectional No.</th><th>Date</th>";
    echo "</tr>";

    $tot_amt = 0; $tot_c = 0; $tot_s = 0;
    foreach ($records as $idx => $r) {
        $sno = $idx + 1;
        $tot_amt += $r['total_amount'];
        $tot_c += $r['central_share_amount'];
        $tot_s += $r['state_share_amount'];

        echo "<tr>";
        echo "<td align='center'>{$sno}</td>";
        echo "<td>" . htmlspecialchars($r['ministry_name']) . "</td>";
        echo "<td align='center'>" . htmlspecialchars($r['tr_no']) . "</td>";
        echo "<td>" . htmlspecialchars($r['tr_desc']) . "</td>";
        echo "<td align='right'>" . number_format($r['total_amount'], 2) . "</td>";
        echo "<td align='right'>" . number_format($r['central_share_amount'], 2) . "</td>";
        echo "<td align='right'>" . number_format($r['state_share_amount'], 2) . "</td>";
        echo "<td align='center'>{$r['sub_head']}</td>";
        echo "<td align='center'>{$r['detail_head']}</td>";
        echo "<td align='center'>" . htmlspecialchars($r['sectional_number']) . "</td>";
        echo "<td align='center'>{$r['posting_date']}</td>";
        echo "</tr>";
    }

    echo "<tr style='background-color:#f5f5f5; font-weight:bold;'>";
    echo "<td>Total</td><td></td><td></td><td>" . count($records) . " records</td>";
    echo "<td align='right'>" . number_format($tot_amt, 2) . "</td>";
    echo "<td align='right'>" . number_format($tot_c, 2) . "</td>";
    echo "<td align='right'>" . number_format($tot_s, 2) . "</td>";
    echo "<td colspan='4'></td>";
    echo "</tr>";
    echo "</table>";
    exit;
}
