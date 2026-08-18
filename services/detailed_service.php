<?php
/**
 * 18-Column Detailed Transfer Entry Report Service for PHP Application.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

/**
 * @param string $filter_type
 * @param string|null $from_date
 * @param string|null $to_date
 * @param string|null $accounting_month
 * @param string|null $financial_year_val
 * @return array
 * @throws Exception
 */
function get_transfer_entry_report_data(string $filter_type, ?string $from_date = null, ?string $to_date = null, ?string $accounting_month = null, ?string $financial_year_val = null, bool $only_unposted = false): array {
    $pdo = initialize_database();
    $where = [];
    $params = [];
    $filter_desc = "";

    if ($only_unposted) {
        $where[] = "(g.vlc_te_number IS NULL OR g.vlc_te_number = '')";
    }

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
        list($m_num, $y_num) = parse_accounting_month($accounting_month);
        $m_padded = sprintf('%02d/%04d', $m_num, $y_num);
        $m_unpadded = sprintf('%d/%04d', $m_num, $y_num);
        $where[] = "(g.accounting_month = ? OR g.accounting_month = ?)";
        $params[] = $m_padded;
        $params[] = $m_unpadded;
        $fy_str = financial_year($m_padded);
        $filter_desc = "Accounting Month: {$m_padded} ({$fy_str})";
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
            SELECT tr_code, controller, css, tr_desc, sub_head, detail_head,
                   ROW_NUMBER() OVER (
                       PARTITION BY tr_code
                       ORDER BY
                            CASE WHEN controller IS NOT NULL OR css IS NOT NULL THEN 0 ELSE 1 END,
                            id
                   ) AS row_rank
            FROM scheme_configuration_master
        )
        SELECT g.id AS report_id, g.sectional_number, g.accounting_month, g.generation_date, g.vlc_te_number,
               d.posting_date, d.sg_account_name, d.amount, d.state_government, d.cg_account_udch_code,
               m.controller, m.tr_desc, m.sub_head, m.detail_head
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
        $amt = (float)($r['amount'] ?? 0.0);
        $sec_num = (int)($r['sectional_number'] ?? 0);
        $gen_date = $r['generation_date'] ?: $r['posting_date'];
        $fy_str = financial_year($gen_date);

        $records[] = [
            'report_id' => $r['report_id'] ?? null,
            'sectional_number_raw' => $sec_num,
            'vlc_te_number' => $r['vlc_te_number'] ?? '',
            'vlcs_te_number' => $r['vlc_te_number'] ?? '',
            'major_head_dr' => '8675',
            'sub_major_dr' => '00',
            'minor_head_dr' => '106',
            'sub_head_dr' => '03',
            'detail_head_dr' => '00',
            'sub_detail_dr' => '00',
            'total_amount' => $amt,
            'major_head_cr' => '1601',
            'sub_major_cr' => '06',
            'minor_head_cr' => '101',
            'sub_head_cr' => format_head_code($r['sub_head']),
            'detail_head_cr' => format_head_code($r['detail_head']),
            'sub_detail_cr' => '00',
            'sectional_no' => "BK/TE/{$fy_str}/{$sec_num}",
            'tr_no' => display_tr_code($r['sg_account_name']),
            'tr_desc' => $r['tr_desc'] ?? '-',
            'ministry_name' => controller_to_ministry($r['controller']),
            'posting_date' => format_date($r['posting_date'])
        ];
    }

    return [$records, $filter_desc];
}

/**
 * @param array $records
 * @param string $filter_desc
 * @return void
 */
function export_detailed_excel(array $records, string $filter_desc): void {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"Transfer_Entry_Report_" . date('Ymd') . ".xls\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<table border='1'>";
    echo "<tr><th colspan='19' style='font-size:16px; font-weight:bold; text-align:center;'>TRANSFER ENTRY DETAILED REPORT</th></tr>";
    echo "<tr><th colspan='19' style='font-size:11px; text-align:center;'>Filter: {$filter_desc}</th></tr>";
    echo "<tr style='background-color:#eaeaea; font-weight:bold;'>";
    echo "<th>Major Head (Dr)</th><th>Sub Major (Dr)</th><th>Minor Head (Dr)</th><th>Sub Head (Dr)</th><th>Detail Head (Dr)</th><th>Sub Detail (Dr)</th>";
    echo "<th>Total Amount (Rs.)</th>";
    echo "<th>Major Head (Cr)</th><th>Sub Major (Cr)</th><th>Minor Head (Cr)</th><th>Sub Head (Cr)</th><th>Detail Head (Cr)</th><th>Sub Detail (Cr)</th>";
    echo "<th>Sectional No.</th><th>VLC TE Number</th><th>TR No.</th><th>TR Description</th><th>Name of Ministry</th><th>Posting Date</th>";
    echo "</tr>";

    $tot_amt = 0;
    foreach ($records as $r) {
        $tot_amt += $r['total_amount'];
        $vlc_te = htmlspecialchars($r['vlcs_te_number'] ?? $r['vlc_te_number'] ?? '');

        echo "<tr>";
        echo "<td align='center'>{$r['major_head_dr']}</td>";
        echo "<td align='center'>{$r['sub_major_dr']}</td>";
        echo "<td align='center'>{$r['minor_head_dr']}</td>";
        echo "<td align='center'>{$r['sub_head_dr']}</td>";
        echo "<td align='center'>{$r['detail_head_dr']}</td>";
        echo "<td align='center'>{$r['sub_detail_dr']}</td>";
        echo "<td align='right'>" . number_format($r['total_amount'], 2) . "</td>";
        echo "<td align='center'>{$r['major_head_cr']}</td>";
        echo "<td align='center'>{$r['sub_major_cr']}</td>";
        echo "<td align='center'>{$r['minor_head_cr']}</td>";
        echo "<td align='center'>{$r['sub_head_cr']}</td>";
        echo "<td align='center'>{$r['detail_head_cr']}</td>";
        echo "<td align='center'>{$r['sub_detail_cr']}</td>";
        echo "<td align='center'>" . htmlspecialchars($r['sectional_no']) . "</td>";
        echo "<td align='center'>" . ($vlc_te ? "TE-{$vlc_te}" : "-") . "</td>";
        echo "<td align='center'>" . htmlspecialchars($r['tr_no']) . "</td>";
        echo "<td>" . htmlspecialchars($r['tr_desc']) . "</td>";
        echo "<td>" . htmlspecialchars($r['ministry_name']) . "</td>";
        echo "<td align='center'>{$r['posting_date']}</td>";
        echo "</tr>";
    }

    echo "<tr style='background-color:#f5f5f5; font-weight:bold;'>";
    echo "<td colspan='6'>Total</td>";
    echo "<td align='right'>" . number_format($tot_amt, 2) . "</td>";
    echo "<td colspan='6'></td>";
    echo "<td>" . count($records) . " records</td>";
    echo "<td colspan='5'></td>";
    echo "</tr>";
    echo "</table>";
    exit;
}