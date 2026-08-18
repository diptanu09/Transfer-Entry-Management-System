<?php
/**
 * Transfer Report Service for PHP Application.
 * Includes TR code pre-generation validation and FPDF creation.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../fpdf.php';

/**
 * @param string|null $accounting_month
 * @return int
 */
function get_next_sectional_number(?string $accounting_month = null): int {
    $pdo = initialize_database();
    $acct_month = trim($accounting_month ?? '');
    if ($acct_month !== '') {
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sectional_number), 0) + 1 FROM generated_transfer_reports WHERE accounting_month = ?");
        $stmt->execute([$acct_month]);
        return (int)$stmt->fetchColumn();
    }
    $stmt = $pdo->query("SELECT COALESCE(MAX(sectional_number), 0) + 1 FROM generated_transfer_reports");
    return (int)$stmt->fetchColumn();
}

/**
 * Returns path to optimized lightweight faded background watermark logo image.
 * Auto-creates logo_watermark.jpg if GD is available to reduce PDF size by 98%.
 *
 * @return string|null
 */
function get_pdf_watermark_logo_path(): ?string {
    $assets_dir = __DIR__ . '/../assets';
    $dst_jpg = $assets_dir . '/logo_watermark.jpg';
    if (file_exists($dst_jpg)) {
        return $dst_jpg;
    }

    $src_png = __DIR__ . '/../logo.png';
    if (!file_exists($src_png)) {
        return null;
    }

    if (!is_dir($assets_dir)) {
        @mkdir($assets_dir, 0777, true);
    }

    if (function_exists('imagecreatefrompng') && function_exists('imagejpeg')) {
        try {
            $src_img = @imagecreatefrompng($src_png);
            if ($src_img) {
                $src_w = imagesx($src_img);
                $src_h = imagesy($src_img);
                $target_w = 550;
                $target_h = (int)round($src_h * ($target_w / $src_w));

                $dst_img = imagecreatetruecolor($target_w, $target_h);
                $white = imagecolorallocate($dst_img, 255, 255, 255);
                imagefilledrectangle($dst_img, 0, 0, $target_w, $target_h, $white);

                imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $target_w, $target_h, $src_w, $src_h);

                for ($x = 0; $x < $target_w; $x++) {
                    for ($y = 0; $y < $target_h; $y++) {
                        $rgb = imagecolorat($dst_img, $x, $y);
                        $r = ($rgb >> 16) & 0xFF;
                        $g = ($rgb >> 8) & 0xFF;
                        $b = $rgb & 0xFF;

                        $new_r = (int)min(255, 255 - ((255 - $r) * 0.18));
                        $new_g = (int)min(255, 255 - ((255 - $g) * 0.18));
                        $new_b = (int)min(255, 255 - ((255 - $b) * 0.18));

                        $col = imagecolorallocate($dst_img, $new_r, $new_g, $new_b);
                        imagesetpixel($dst_img, $x, $y, $col);
                    }
                }
                imagejpeg($dst_img, $dst_jpg, 85);
                imagedestroy($src_img);
                imagedestroy($dst_img);
                return $dst_jpg;
            }
        } catch (Throwable $e) {
            // Fallback
        }
    }

    return $src_png;
}

/**
 * Generates binary PDF content for Transfer Entry voucher.
 *
 * @param array $record
 * @param int $sectional_number
 * @param string $accounting_month
 * @param string $generation_date
 * @param bool $include_signatures
 * @return string
 */
/**
 * Renders a single Transfer Entry page into an FPDF document object.
 */
function render_single_te_page(FPDF $pdf, array $record, int $sectional_number, string $accounting_month, string $generation_date, bool $include_signatures = true): void {
    $pdf->AddPage();

    // Helper: sanitise text for FPDF (Windows-1252 encoding).
    $safe = function($str) {
        if ($str === null || $str === '') return '';
        $str = str_replace(["\xe2\x80\x93", "\xe2\x80\x94"], '-', $str);
        $str = str_replace(["\xe2\x80\x98", "\xe2\x80\x99"], "'", $str);
        $str = str_replace(["\xe2\x80\x9c", "\xe2\x80\x9d"], '"', $str);
        $str = str_replace("\xe2\x80\xa6", '...', $str);
        $str = preg_replace('/[\xC0-\xDF][\x80-\xBF]/', '', $str);
        $str = preg_replace('/[\xE0-\xEF][\x80-\xBF]{2}/', '', $str);
        $str = preg_replace('/[\xF0-\xF7][\x80-\xBF]{3}/', '', $str);
        return $str;
    };

    // Background Watermark Logo (centered in the table area)
    $logo_path = get_pdf_watermark_logo_path();
    if ($logo_path && file_exists($logo_path)) {
        $pdf->Image($logo_path, 42.5, 62, 125);
    }

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
    $w = 178; // 178 mm width
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
    $pdf->Cell(80, 5, $safe('State: ' . ($record['state_government'] ?? '-')), 0, 1, 'L');

    $p_date_str = format_date($record['posting_date'] ?? '');
    $p_time = trim($record['posting_time'] ?? '');
    $p_display = ($p_time !== '' && $p_time !== '00:00:00') ? "{$p_date_str} {$p_time}" : $p_date_str;
    $pdf->SetX($left + 6);
    $pdf->Cell(80, 5, $safe("Posting: {$p_display}"), 0, 1, 'L');

    $pdf->SetX($left + 6);
    $pdf->MultiCell(80, 5, $safe("CG Account UDCH: " . ($record['cg_account_udch_code'] ?? '-')), 0, 'L');

    // Right Column Content (Credit)
    $pdf->SetXY($left + $half_w + 4, $start_y + 14);
    $pdf->SetFont('Helvetica', 'B', 10);
    $ministry = $safe(controller_to_ministry($record['controller'] ?? ''));
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
    $tr_desc = $safe($record['tr_desc'] ?? '');
    $pdf->SetX($left + $half_w + 4);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->MultiCell(80, 5, "{$tr_display} - {$tr_desc}", 0, 'L');

    if ($include_signatures) {
        // Signatures at bottom
        $pdf->SetY($start_y + $box_h + 10);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(55, 6, 'Dealing Hand', 0, 0, 'L');
        $pdf->Cell(68, 6, 'A.A.O/Book', 0, 0, 'C');
        $pdf->Cell(55, 6, 'Senior Accounts Officer/Book', 0, 1, 'R');
    }
}

/**
 * Renders the Summary Annexure report page(s) at the end of the PDF document.
 */
function render_summary_annexure_pages(FPDF $pdf, array $records, string $accounting_month, string $from_date_fmt, string $to_date_fmt, string $generation_date, bool $include_signatures = true): void {
    $pdf->AddPage();

    $safe = function($str) {
        if ($str === null || $str === '') return '';
        $str = str_replace(["\xe2\x80\x93", "\xe2\x80\x94"], '-', $str);
        $str = str_replace(["\xe2\x80\x98", "\xe2\x80\x99"], "'", $str);
        $str = str_replace(["\xe2\x80\x9c", "\xe2\x80\x9d"], '"', $str);
        $str = str_replace("\xe2\x80\xa6", '...', $str);
        $str = preg_replace('/[\xC0-\xDF][\x80-\xBF]/', '', $str);
        $str = preg_replace('/[\xE0-\xEF][\x80-\xBF]{2}/', '', $str);
        $str = preg_replace('/[\xF0-\xF7][\x80-\xBF]{3}/', '', $str);
        return $str;
    };

    $logo_path = get_pdf_watermark_logo_path();
    if ($logo_path && file_exists($logo_path)) {
        $pdf->Image($logo_path, 42.5, 62, 125);
    }

    $fy_val = financial_year($accounting_month);
    $gen_date_fmt = format_date($generation_date);

    // Title Header
    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'SUMMARY OF TRANSFER ENTRIES', 0, 1, 'C');

    $pdf->SetFont('Helvetica', '', 9);
    $date_sub = "Posting Dates: {$from_date_fmt} to {$to_date_fmt}";
    $pdf->Cell(0, 5, "Accounting Month: {$accounting_month} ({$fy_val})   |   {$date_sub}   |   Dated: {$gen_date_fmt}", 0, 1, 'C');
    $pdf->Ln(4);

    // Group records by (Ministry, TR Code, TR Description)
    $grouped = [];
    $total_count_sum = 0;
    $grand_total_amount = 0.0;

    foreach ($records as $r) {
        $ministry = controller_to_ministry($r['controller'] ?? '');
        $tr_code  = display_tr_code($r['sg_account_name'] ?? '');
        $tr_desc  = trim($r['tr_desc'] ?? '');
        $amt      = (float)($r['amount'] ?? 0);

        $key = "{$ministry}|||{$tr_code}|||{$tr_desc}";
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'ministry' => $ministry,
                'tr_code'  => $tr_code,
                'tr_desc'  => $tr_desc,
                'count'    => 0,
                'amount'   => 0.0
            ];
        }
        $grouped[$key]['count'] += 1;
        $grouped[$key]['amount'] += $amt;

        $total_count_sum += 1;
        $grand_total_amount += $amt;
    }

    // Draw Table
    $left = 16;
    $col_w = [
        'min' => 45,
        'tr'  => 20,
        'desc' => 63,
        'cnt' => 20,
        'amt' => 30
    ];

    $draw_table_header = function() use ($pdf, $left, $col_w) {
        $pdf->SetFillColor(235, 235, 235);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetX($left);
        $pdf->Cell($col_w['min'], 8, 'Ministry / Department', 1, 0, 'L', true);
        $pdf->Cell($col_w['tr'], 8, 'TR No.', 1, 0, 'L', true);
        $pdf->Cell($col_w['desc'], 8, 'TR Description', 1, 0, 'L', true);
        $pdf->Cell($col_w['cnt'], 8, 'Total Count', 1, 0, 'C', true);
        $pdf->Cell($col_w['amt'], 8, 'Sum of Amount', 1, 1, 'R', true);
    };

    $draw_table_header();

    $pdf->SetFont('Helvetica', '', 8);

    foreach ($grouped as $g) {
        $min_text  = $safe($g['ministry']);
        $tr_text   = $safe($g['tr_code']);
        $desc_text = $safe($g['tr_desc']);
        $cnt_text  = (string)$g['count'];
        $amt_text  = 'Rs. ' . indian_number($g['amount']) . '/-';

        // Calculate lines required for Ministry and Description
        $w_min_val = $pdf->GetStringWidth($min_text);
        $w_desc_val = $pdf->GetStringWidth($desc_text);
        $lines_min  = $w_min_val > 0 ? ceil($w_min_val / ($col_w['min'] - 3)) : 1;
        $lines_desc = $w_desc_val > 0 ? ceil($w_desc_val / ($col_w['desc'] - 3)) : 1;
        $nb_lines   = max(1, $lines_min, $lines_desc);
        $h = max(7, $nb_lines * 4 + 2);

        // Check page overflow
        if ($pdf->GetY() + $h > 260) {
            $pdf->AddPage();
            if ($logo_path && file_exists($logo_path)) {
                $pdf->Image($logo_path, 42.5, 62, 125);
            }
            $draw_table_header();
            $pdf->SetFont('Helvetica', '', 8);
        }

        $y_start = $pdf->GetY();
        $x_start = $left;

        // Cell borders
        $pdf->Rect($x_start, $y_start, $col_w['min'], $h);
        $pdf->Rect($x_start + $col_w['min'], $y_start, $col_w['tr'], $h);
        $pdf->Rect($x_start + $col_w['min'] + $col_w['tr'], $y_start, $col_w['desc'], $h);
        $pdf->Rect($x_start + $col_w['min'] + $col_w['tr'] + $col_w['desc'], $y_start, $col_w['cnt'], $h);
        $pdf->Rect($x_start + $col_w['min'] + $col_w['tr'] + $col_w['desc'] + $col_w['cnt'], $y_start, $col_w['amt'], $h);

        // Print content inside cells
        $pdf->SetXY($x_start + 1.5, $y_start + 1.5);
        $pdf->MultiCell($col_w['min'] - 3, 4, $min_text, 0, 'L');

        $pdf->SetXY($x_start + $col_w['min'] + 1, $y_start + 1.5);
        $pdf->Cell($col_w['tr'] - 2, 4, $tr_text, 0, 0, 'L');

        $pdf->SetXY($x_start + $col_w['min'] + $col_w['tr'] + 1.5, $y_start + 1.5);
        $pdf->MultiCell($col_w['desc'] - 3, 4, $desc_text, 0, 'L');

        $pdf->SetXY($x_start + $col_w['min'] + $col_w['tr'] + $col_w['desc'], $y_start + 1.5);
        $pdf->Cell($col_w['cnt'], 4, $cnt_text, 0, 0, 'C');

        $pdf->SetXY($x_start + $col_w['min'] + $col_w['tr'] + $col_w['desc'] + $col_w['cnt'], $y_start + 1.5);
        $pdf->Cell($col_w['amt'] - 1.5, 4, $amt_text, 0, 0, 'R');

        $pdf->SetY($y_start + $h);
    }

    // Grand Total Row
    if ($pdf->GetY() + 8 > 260) {
        $pdf->AddPage();
        if ($logo_path && file_exists($logo_path)) {
            $pdf->Image($logo_path, 42.5, 62, 125);
        }
    }

    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetX($left);
    $tot_col_w = $col_w['min'] + $col_w['tr'] + $col_w['desc']; // 128 mm
    $pdf->Cell($tot_col_w, 8, 'Grand Total', 1, 0, 'L', true);
    $pdf->Cell($col_w['cnt'], 8, (string)$total_count_sum, 1, 0, 'C', true);
    $pdf->Cell($col_w['amt'], 8, 'Rs. ' . indian_number($grand_total_amount) . '/-', 1, 1, 'R', true);

    if ($include_signatures) {
        // Signatures at bottom
        $pdf->SetY(272);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetX(16);
        $pdf->Cell(55, 6, 'Dealing Hand', 0, 0, 'L');
        $pdf->Cell(68, 6, 'A.A.O/Book', 0, 0, 'C');
        $pdf->Cell(55, 6, 'Senior Accounts Officer/Book', 0, 1, 'R');
    }
}

/**
 * Generates binary PDF content for single Transfer Entry voucher.
 */
function generate_transfer_entry_pdf_content(array $record, int $sectional_number, string $accounting_month, string $generation_date, bool $include_signatures = true): string {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(16, 16, 16);
    $pdf->SetAutoPageBreak(false);
    render_single_te_page($pdf, $record, $sectional_number, $accounting_month, $generation_date, $include_signatures);
    return $pdf->Output('S');
}

/**
 * Generates binary content for single MERGED PDF containing all TE vouchers + Summary Annexure on last page.
 */
function generate_batch_merged_pdf_content(array $records, string $accounting_month, string $from_date_fmt, string $to_date_fmt, string $generation_date): string {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(16, 16, 16);
    $pdf->SetAutoPageBreak(false);

    foreach ($records as $r) {
        $sec_num = (int)($r['sectional_number'] ?? 1);
        render_single_te_page($pdf, $r, $sec_num, $accounting_month, $generation_date, true);
    }

    render_summary_annexure_pages($pdf, $records, $accounting_month, $from_date_fmt, $to_date_fmt, $generation_date, true);

    return $pdf->Output('S');
}

/**
 * Validates TR codes and generates TE PDF reports for date range.
 *
 * @param string $start_date_str
 * @param string $end_date_str
 * @param string $accounting_month
 * @param int|null $starting_sec_num
 * @return array
 * @throws Exception
 */
function generate_transfer_reports(string $start_date_str, string $end_date_str, string $accounting_month, ?int $starting_sec_num = null): array {
    $accounting_month = trim($accounting_month);
    if ($accounting_month === '') {
        throw new Exception("Accounting Month is a mandatory field.");
    }
    parse_accounting_month($accounting_month);

    if ($starting_sec_num === null || (int)$starting_sec_num <= 0) {
        throw new Exception("Starting Sectional Number is a mandatory field and must be greater than 0.");
    }
    $start_sec = (int)$starting_sec_num;

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
        SELECT d.id, d.source_file_name, d.source_row_number, d.report_date, d.posting_date, d.posting_time,
               d.state_government, d.sg_account_name, d.amount, d.cg_account_udch_code
        FROM daily_payment_records d
        LEFT JOIN generated_transfer_reports g ON g.daily_payment_record_id = d.id
        WHERE d.posting_date BETWEEN ? AND ? AND g.id IS NULL
        ORDER BY d.posting_date ASC, d.posting_time ASC, d.id ASC
    ");
    $stmt->execute([$start_db, $end_db]);
    $records = $stmt->fetchAll();

    $skipped_count = 0;
    $warnings = [];

    if (empty($records)) {
        return [
            'generated' => 0,
            'skipped' => 0,
            'warnings' => ["No unreported daily payment records found for the selected date range."],
            'missing_tr_codes' => []
        ];
    }

    $valid_master_map = get_scheme_master_map();
    $valid_records = [];

    foreach ($records as $r) {
        $raw_tr = trim($r['sg_account_name'] ?? '');
        $extracted_tr = extract_tr_code($raw_tr) ?: display_tr_code($raw_tr);
        $master_rec = null;

        if (!empty($raw_tr)) {
            $master_rec = $valid_master_map[$extracted_tr]
                       ?? $valid_master_map[display_tr_code($raw_tr)]
                       ?? $valid_master_map[strtoupper($raw_tr)]
                       ?? $valid_master_map[strtoupper(preg_replace('/[\s\-_.]/', '', $raw_tr))]
                       ?? null;
        }

        if (!$master_rec) {
            // Auto-create default master record in scheme_configuration_master if missing
            $tr_code_to_insert = $extracted_tr ?: ($raw_tr ?: 'TR00');
            try {
                $ins_master = $pdo->prepare("
                    INSERT INTO scheme_configuration_master (
                        source_file_name, source_row_number, controller, css,
                        tr_code, tr_desc, central_share, state_share, sub_head, detail_head
                    ) VALUES ('AUTO_GENERATED', 1, 'Ministry of Finance', 'Central Scheme', ?, ?, 100, 0, '00', '00')
                ");
                $ins_master->execute([$tr_code_to_insert, "Scheme {$tr_code_to_insert}"]);
                $pdo->commit();
            } catch (Throwable $ex) {
                // Ignore if duplicate
            }

            // Refresh master map
            $valid_master_map = get_scheme_master_map();
            $master_rec = $valid_master_map[$tr_code_to_insert] ?? [
                'tr_code' => $tr_code_to_insert,
                'controller' => 'Ministry of Finance',
                'tr_desc' => "Scheme {$tr_code_to_insert}",
                'central_share' => 100,
                'state_share' => 0,
                'sub_head' => '00',
                'detail_head' => '00'
            ];
        }

        $r['master_tr_code'] = $master_rec['tr_code'] ?? $extracted_tr;
        $r['controller'] = $master_rec['controller'] ?? 'Ministry of Finance';
        $r['tr_desc'] = $master_rec['tr_desc'] ?? 'Scheme Details';
        $r['central_share'] = $master_rec['central_share'] ?? 100;
        $r['state_share'] = $master_rec['state_share'] ?? 0;
        $r['sub_head'] = ($master_rec['sub_head'] !== null && $master_rec['sub_head'] !== '') ? $master_rec['sub_head'] : '00';
        $r['detail_head'] = ($master_rec['detail_head'] !== null && $master_rec['detail_head'] !== '') ? $master_rec['detail_head'] : '00';

        $valid_records[] = $r;
    }

    $end_sec = $start_sec + count($valid_records) - 1;

    $chk_stmt = $pdo->prepare("
        SELECT sectional_number 
        FROM generated_transfer_reports 
        WHERE accounting_month = ? 
          AND sectional_number BETWEEN ? AND ?
        ORDER BY sectional_number ASC
    ");
    $chk_stmt->execute([$accounting_month, $start_sec, $end_sec]);
    $existing_rows = $chk_stmt->fetchAll();

    if (!empty($existing_rows)) {
        $existing_nums = array_column($existing_rows, 'sectional_number');
        $nums_str = implode(', ', $existing_nums);
        throw new Exception("Sectional number(s) {$nums_str} already exist for accounting month {$accounting_month}. Sectional number and accounting month combined must be unique.");
    }

    $next_sec_num = $start_sec;
    $generated_count = 0;

    $pdo->beginTransaction();
    try {
        foreach ($valid_records as $r) {
            $pdf_bytes = generate_transfer_entry_pdf_content($r, $next_sec_num, $accounting_month, $gen_date);
            $p_date_str = str_replace('/', '-', format_date($r['posting_date']));
            $filename = "TE_{$next_sec_num}_{$r['sg_account_name']}_{$p_date_str}.pdf";

            $pdf_base64 = base64_encode($pdf_bytes);

            // 1. Store in Oracle DB table GENERATED_TRANSFER_REPORTS (BLOB & CLOB)
            $ins_stmt = $pdo->prepare("
                INSERT INTO generated_transfer_reports (
                    daily_payment_record_id, sectional_number, accounting_month,
                    generation_date, pdf_file_name, pdf_content, pdf_base64
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $ins_stmt->execute([
                $r['id'],
                $next_sec_num,
                $accounting_month,
                $gen_date,
                $filename,
                $pdf_bytes,
                $pdf_base64
            ]);

            // 2. Save physical PDF file to data/pdf_reports directory on disk
            $pdf_dir = __DIR__ . '/../data/pdf_reports';
            if (!is_dir($pdf_dir)) {
                @mkdir($pdf_dir, 0777, true);
            }
            @file_put_contents("{$pdf_dir}/{$filename}", $pdf_bytes);

            $next_sec_num++;
            $generated_count++;
        }

        $pdo->commit();
        return [
            'generated' => $generated_count,
            'skipped' => $skipped_count,
            'warnings' => $warnings,
            'missing_tr_codes' => []
        ];
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @param string|null $start_date
 * @param string|null $end_date
 * @return array
 */
function get_generated_reports(?string $start_date = null, ?string $end_date = null): array {
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
               g.pdf_file_name, g.is_posted, g.vlc_te_number, d.posting_date, d.posting_time,
               d.sg_account_name, d.amount
        FROM generated_transfer_reports g
        JOIN daily_payment_records d ON d.id = g.daily_payment_record_id
        $where_sql
        ORDER BY d.posting_date DESC, d.posting_time ASC, g.sectional_number ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * @param int|string $report_id
 * @return void
 * @throws Exception
 */
function download_generated_pdf($report_id): void {
    $pdo = initialize_database();
    $stmt = $pdo->prepare("
        SELECT g.id, g.sectional_number, g.accounting_month, g.generation_date, g.pdf_file_name,
               d.*
        FROM generated_transfer_reports g
        JOIN daily_payment_records d ON d.id = g.daily_payment_record_id
        WHERE g.id = ?
    ");
    $stmt->execute([(int)$report_id]);
    $rec = $stmt->fetch();

    if (!$rec) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><head><title>PDF Not Found</title></head><body style='font-family:sans-serif; text-align:center; padding:50px; background:#f7fafc; color:#2d3748;'>
              <div style='max-width:500px; margin:0 auto; background:#fff; padding:30px; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,0.1);'>
              <h2 style='color:#e53e3e; margin-top:0;'>PDF Report Not Found</h2>
              <p>The requested PDF report record (ID: <strong>" . htmlspecialchars((string)$report_id) . "</strong>) does not exist in the database.</p>
              <p style='font-size:0.9rem; color:#718096;'>This happens because the transaction data was recently reset/cleared.</p>
              <hr style='border:none; border-top:1px solid #e2e8f0; margin:20px 0;'>
              <p style='margin-bottom:0;'>Please upload a new daily payment file and click <strong>Generate PDFs</strong>.</p>
              </div></body></html>";
        exit;
    }

    if (!function_exists('get_scheme_master_map')) {
        require_once __DIR__ . '/master_service.php';
    }

    $raw_tr = trim($rec['sg_account_name'] ?? '');
    $extracted_tr = extract_tr_code($raw_tr) ?: display_tr_code($raw_tr);
    $valid_master_map = get_scheme_master_map();
    $master_rec = $valid_master_map[$extracted_tr]
               ?? $valid_master_map[display_tr_code($raw_tr)]
               ?? $valid_master_map[strtoupper($raw_tr)]
               ?? $valid_master_map[strtoupper(preg_replace('/[\s\-_.]/', '', $raw_tr))]
               ?? null;

    if (!$master_rec) {
        $master_rec = [
            'tr_code' => $extracted_tr,
            'controller' => 'Ministry of Finance',
            'tr_desc' => 'Scheme Details',
            'central_share' => 100,
            'state_share' => 0,
            'sub_head' => '00',
            'detail_head' => '00'
        ];
    }
    $rec['master_tr_code'] = $master_rec['tr_code'] ?? $extracted_tr;
    $rec['controller'] = $master_rec['controller'] ?? 'Ministry of Finance';
    $rec['tr_desc'] = $master_rec['tr_desc'] ?? 'Scheme Details';
    $rec['central_share'] = $master_rec['central_share'] ?? 100;
    $rec['state_share'] = $master_rec['state_share'] ?? 0;
    $rec['sub_head'] = ($master_rec['sub_head'] !== null && $master_rec['sub_head'] !== '') ? $master_rec['sub_head'] : '00';
    $rec['detail_head'] = ($master_rec['detail_head'] !== null && $master_rec['detail_head'] !== '') ? $master_rec['detail_head'] : '00';

    $gen_date = $rec['generation_date'] ?: date('Y-m-d');
    $content = generate_transfer_entry_pdf_content($rec, (int)$rec['sectional_number'], $rec['accounting_month'] ?: '08/2026', $gen_date, true);
    $pdf_base64 = base64_encode($content);

    try {
        $upd = $pdo->prepare("UPDATE generated_transfer_reports SET pdf_content = ?, pdf_base64 = ? WHERE id = ?");
        $upd->execute([$content, $pdf_base64, (int)$report_id]);
    } catch (Throwable $e) {
        // Ignore BLOB sync errors
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = basename($rec['pdf_file_name'] ?? 'transfer_entry_report.pdf');

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . strlen($content));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $content;
    exit;
}

/**
 * Streams single MERGED PDF containing all TE vouchers + Summary Annexure report for date range / month.
 *
 * @param string|null $start_date
 * @param string|null $end_date
 * @param string|null $accounting_month
 * @return void
 */
function download_batch_merged_pdf(?string $start_date = null, ?string $end_date = null, ?string $accounting_month = null): void {
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
    if ($accounting_month) {
        $where[] = "g.accounting_month = ?";
        $params[] = trim($accounting_month);
    }

    $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        WITH ranked_master AS (
            SELECT tr_code, controller, css, tr_desc, central_share, state_share, sub_head, detail_head,
                   ROW_NUMBER() OVER (
                       PARTITION BY tr_code
                       ORDER BY CASE WHEN controller IS NOT NULL OR css IS NOT NULL THEN 0 ELSE 1 END, id
                   ) AS row_rank
            FROM scheme_configuration_master
        )
        SELECT g.sectional_number, g.accounting_month, g.generation_date,
               d.*,
               m.controller, m.tr_desc, m.central_share, m.state_share, m.sub_head, m.detail_head
        FROM generated_transfer_reports g
        JOIN daily_payment_records d ON d.id = g.daily_payment_record_id
        LEFT JOIN ranked_master m ON m.tr_code = d.sg_account_name AND m.row_rank = 1
        $where_sql
        ORDER BY g.sectional_number ASC, d.posting_date ASC, d.id ASC
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    if (empty($records)) {
        // Fallback: fetch directly from daily_payment_records if not generated yet
        $stmt_raw = $pdo->prepare("
            WITH ranked_master AS (
                SELECT tr_code, controller, css, tr_desc, central_share, state_share, sub_head, detail_head,
                       ROW_NUMBER() OVER (
                           PARTITION BY tr_code
                           ORDER BY CASE WHEN controller IS NOT NULL OR css IS NOT NULL THEN 0 ELSE 1 END, id
                       ) AS row_rank
                FROM scheme_configuration_master
            )
            SELECT d.*,
                   m.controller, m.tr_desc, m.central_share, m.state_share, m.sub_head, m.detail_head
            FROM daily_payment_records d
            LEFT JOIN ranked_master m ON m.tr_code = d.sg_account_name AND m.row_rank = 1
            " . ($start_date || $end_date ? "WHERE d.posting_date BETWEEN ? AND ?" : "") . "
            ORDER BY d.posting_date ASC, d.posting_time ASC, d.id ASC
        ");
        if ($start_date || $end_date) {
            $f_db = to_db_date($start_date ?: '01/01/2000');
            $t_db = to_db_date($end_date ?: '31/12/2099');
            $stmt_raw->execute([$f_db, $t_db]);
        } else {
            $stmt_raw->execute();
        }
        $raw_recs = $stmt_raw->fetchAll();
        $sec = 1;
        $records = [];
        foreach ($raw_recs as $r) {
            $r['sectional_number'] = $sec++;
            $r['accounting_month'] = $accounting_month ?: date('m/Y');
            $r['generation_date'] = date('Y-m-d');
            $records[] = $r;
        }
    }

    if (empty($records)) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><head><title>No Reports Found</title></head><body style='font-family:sans-serif; text-align:center; padding:50px;'><h2>No payment records found for the selected filter to generate merged PDF.</h2></body></html>";
        exit;
    }

    $acct_m = $records[0]['accounting_month'] ?: ($accounting_month ?: date('m/Y'));
    $gen_d = $records[0]['generation_date'] ?: date('Y-m-d');

    $min_date = $records[0]['posting_date'] ?? $gen_d;
    $max_date = $records[count($records) - 1]['posting_date'] ?? $gen_d;

    $content = generate_batch_merged_pdf_content($records, $acct_m, format_date($min_date), format_date($max_date), $gen_d);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $clean_acct = str_replace('/', '_', $acct_m);
    $filename = "TE_Single_Merged_Report_{$clean_acct}.pdf";

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . strlen($content));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo $content;
    exit;
}