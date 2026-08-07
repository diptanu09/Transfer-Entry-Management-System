<?php
/**
 * Transfer Report Service for PHP Application.
 * Includes TR code pre-generation validation and FPDF creation.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../fpdf.php';

function get_next_sectional_number() {
    $pdo = initialize_database();
    $stmt = $pdo->query("SELECT COALESCE(MAX(sectional_number), 0) + 1 FROM generated_transfer_reports");
    return (int)$stmt->fetchColumn();
}

function generate_transfer_entry_pdf_content($record, $sectional_number, $accounting_month, $generation_date, $include_signatures = false) {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(16, 16, 16);
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(false);

    $financial_year_val = financial_year($accounting_month);
    $amount_text = indian_number($record['amount'] ?? 0);
    $gen_date_fmt = format_date($generation_date);

    // Top Header
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(80, 5, 'AC-23', 0, 0, 'L');
    $pdf->Cell(98, 5, 'See Article 7.2', 0, 1, 'R');
    $pdf->Ln(2);

    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->Cell(0, 8, 'TRANSFER ENTRY', 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(0, 6, "Transfer entry in the accounts {$accounting_month} of {$financial_year_val}", 0, 1, 'C');
    $pdf->Cell(0, 6, "Sectional No. BK/TE/{$financial_year_val}/{$sectional_number}    Dated. {$gen_date_fmt}", 0, 1, 'C');
    $pdf->Ln(4);

    // Table Box
    $start_y = $pdf->GetY();
    $left = 16;
    $w = 178;
    $half_w = 89;
    $box_h = 170;

    $pdf->Rect($left, $start_y, $w, $box_h);
    $pdf->Line($left + $half_w, $start_y, $left + $half_w, $start_y + $box_h);
    $pdf->Line($left, $start_y + 10, $left + $w, $start_y + 10);

    // Debit Header
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetXY($left + 4, $start_y + 2);
    $pdf->Cell(40, 6, 'Debit', 0, 0, 'L');
    $pdf->Cell(40, 6, "Rs.{$amount_text}/-", 0, 0, 'R');

    // Credit Header
    $pdf->SetXY($left + $half_w + 4, $start_y + 2);
    $pdf->Cell(40, 6, 'Credit', 0, 0, 'L');
    $pdf->Cell(40, 6, "Rs.{$amount_text}/-", 0, 0, 'R');

    // Left Column Content (Debit)
    $pdf->SetXY($left + 4, $start_y + 14);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(80, 6, 'Major and Minor Heads', 0, 1, 'L');

    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetX($left + 6);
    $pdf->Cell(80, 6, '8675 - Deposit with RB', 0, 1, 'L');
    $pdf->SetX($left + 6);
    $pdf->Cell(80, 6, '106 - RBS (CAO)', 0, 1, 'L');
    $pdf->Ln(4);

    $pdf->SetX($left + 4);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(80, 6, 'Credit Note details', 0, 1, 'L');

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetX($left + 6);
    $pdf->Cell(80, 5, 'State: ' . ($record['state_government'] ?? '-'), 0, 1, 'L');

    $p_date_str = format_date($record['posting_date'] ?? '');
    $pdf->SetX($left + 6);
    $pdf->Cell(80, 5, "Posting: {$p_date_str} " . ($record['posting_time'] ?? ''), 0, 1, 'L');

    $pdf->SetX($left + 6);
    $pdf->MultiCell(80, 5, "CG Account UDCH: " . ($record['cg_account_udch_code'] ?? '-'), 0, 'L');

    // Right Column Content (Credit)
    $pdf->SetXY($left + $half_w + 4, $start_y + 14);
    $pdf->SetFont('Helvetica', 'B', 10);
    $ministry = controller_to_ministry($record['controller'] ?? '');
    $pdf->MultiCell(80, 5, $ministry, 0, 'L');
    $pdf->Ln(2);

    $pdf->SetX($left + $half_w + 4);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(80, 6, '1601 - Grants-in-aid', 0, 1, 'L');
    $pdf->SetX($left + $half_w + 4);
    $pdf->Cell(80, 6, '06 - Centrally Sponsored Schemes', 0, 1, 'L');
    $pdf->SetX($left + $half_w + 4);
    $pdf->Cell(80, 6, '101 - Central Share', 0, 1, 'L');

    $sub_h = format_head_code($record['sub_head'] ?? '00');
    $det_h = format_head_code($record['detail_head'] ?? '00');
    $pdf->SetX($left + $half_w + 4);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(80, 6, "(101-{$sub_h}-{$det_h})", 0, 1, 'L');
    $pdf->Ln(3);

    $tr_display = display_tr_code($record['sg_account_name'] ?? '');
    $tr_desc = $record['tr_desc'] ?? '';
    $pdf->SetX($left + $half_w + 4);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->MultiCell(80, 5, "{$tr_display} - {$tr_desc}", 0, 'L');

    if ($include_signatures) {
        $pdf->SetY($start_y + $box_h + 10);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(55, 6, 'Dealing Hand', 0, 0, 'L');
        $pdf->Cell(68, 6, 'A.A.O/Book', 0, 0, 'C');
        $pdf->Cell(55, 6, 'Senior Accounts Officer/Book', 0, 1, 'R');
    }

    return $pdf->Output('S');
}

function generate_transfer_reports($start_date_str, $end_date_str, $accounting_month, $starting_sec_num = null) {
    parse_accounting_month($accounting_month);
    $start_db = to_db_date($start_date_str);
    $end_db = to_db_date($end_date_str);

    if (!$start_db || !$end_db) {
        throw new Exception("Posting dates must use DD/MM/YYYY.");
    }
    if ($start_db > $end_db) {
        throw new Exception("From date cannot be after To date.");
    }

    $pdo = initialize_database();
    $gen_date = date('Y-m-d');

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
        SELECT d.*, m.tr_code as master_tr_code, m.controller, m.tr_desc, m.central_share, m.state_share, m.sub_head, m.detail_head
        FROM daily_payment_records d
        LEFT JOIN ranked_master m ON m.tr_code = d.sg_account_name AND m.row_rank = 1
        LEFT JOIN generated_transfer_reports g ON g.daily_payment_record_id = d.id
        WHERE d.posting_date BETWEEN ? AND ? AND g.id IS NULL
        ORDER BY d.posting_date ASC, d.posting_time ASC, d.id ASC
    ");
    $stmt->execute([$start_db, $end_db]);
    $records = $stmt->fetchAll();

    if (empty($records)) {
        return [
            'generated' => 0,
            'skipped' => 0,
            'warnings' => ["No unreported daily payment records found for the selected date range."],
            'missing_tr_codes' => []
        ];
    }

    $missing_tr_map = [];
    $valid_records = [];

    foreach ($records as $r) {
        if ($r['master_tr_code'] === null || $r['sub_head'] === null || $r['detail_head'] === null) {
            $raw_code = trim($r['sg_account_name'] ?? '');
            $state_name = $r['state_government'] ?? '';
            $p_date = !empty($r['posting_date']) ? format_date($r['posting_date']) : '';

            $label = $raw_code !== '' ? display_tr_code($raw_code) : "Blank/Unmapped TR Code";
            $amt = (float)($r['amount'] ?? 0.0);

            if (!isset($missing_tr_map[$label])) {
                $missing_tr_map[$label] = ['amount' => 0.0, 'count' => 0];
            }
            $missing_tr_map[$label]['amount'] += $amt;
            $missing_tr_map[$label]['count'] += 1;
        } else {
            $valid_records[] = $r;
        }
    }

    if (!empty($missing_tr_map)) {
        $lines = [];
        $total_missing_amt = 0.0;
        foreach ($missing_tr_map as $label => $info) {
            $amt = $info['amount'];
            $cnt = $info['count'];
            $total_missing_amt += $amt;
            $lines[] = "- {$label} - Rs. " . indian_number($amt) . "/- ({$cnt} records)";
        }
        $tr_details_str = implode("\n", $lines);
        $total_missing_fmt = indian_number($total_missing_amt);
        throw new Exception(
            "First please update these TR codes in Master Table before running the programme because these TR codes are missing:\n\n" .
            $tr_details_str . "\n\n" .
            "Total Missing Amount: Rs. {$total_missing_fmt}/-"
        );
    }

    $next_sec_num = ($starting_sec_num !== null) ? (int)$starting_sec_num : get_next_sectional_number();
    $generated_count = 0;

    $pdo->beginTransaction();
    try {
        foreach ($valid_records as $r) {
            $pdf_bytes = generate_transfer_entry_pdf_content($r, $next_sec_num, $accounting_month, $gen_date);
            $p_date_str = str_replace('/', '-', format_date($r['posting_date']));
            $filename = "TE_{$next_sec_num}_{$r['sg_account_name']}_{$p_date_str}.pdf";

            $ins_stmt = $pdo->prepare("
                INSERT INTO generated_transfer_reports (
                    daily_payment_record_id, sectional_number, accounting_month,
                    generation_date, pdf_file_name, pdf_content
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $ins_stmt->execute([
                $r['id'],
                $next_sec_num,
                $accounting_month,
                $gen_date,
                $filename,
                $pdf_bytes
            ]);

            $next_sec_num++;
            $generated_count++;
        }

        $pdo->commit();
        return [
            'generated' => $generated_count,
            'skipped' => 0,
            'warnings' => [],
            'missing_tr_codes' => []
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function get_generated_reports($start_date = null, $end_date = null) {
    $pdo = initialize_database();
    $where = [];
    $params = [];

    if ($start_date) {
        $start_db = to_db_date($start_date);
        if ($start_db) {
            $where[] = "d.posting_date >= ?";
            $params[] = $start_db;
        }
    }
    if ($end_date) {
        $end_db = to_db_date($end_date);
        if ($end_db) {
            $where[] = "d.posting_date <= ?";
            $params[] = $end_db;
        }
    }

    $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    $stmt = $pdo->prepare("
        SELECT g.id, g.sectional_number, g.accounting_month, g.generation_date,
               g.pdf_file_name, d.posting_date, d.posting_time,
               d.sg_account_name, d.amount
        FROM generated_transfer_reports g
        JOIN daily_payment_records d ON d.id = g.daily_payment_record_id
        $where_sql
        ORDER BY d.posting_date DESC, d.posting_time ASC, g.sectional_number ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function download_generated_pdf($report_id) {
    $pdo = initialize_database();
    $stmt = $pdo->prepare("SELECT pdf_file_name, pdf_content FROM generated_transfer_reports WHERE id = ?");
    $stmt->execute([(int)$report_id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new Exception("PDF report record not found.");
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $row['pdf_file_name'] . '"');
    header('Content-Length: ' . strlen($row['pdf_content']));
    echo $row['pdf_content'];
    exit;
}