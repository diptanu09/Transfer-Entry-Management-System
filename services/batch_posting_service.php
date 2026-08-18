<?php
/**
 * TE Detailed Report Batch Posting Service for VLCS Schema (vlcs.B2_TE_HDRS, vlcs.B2_TE_HDR, vlcs.B2_TE_DTLS)
 * Modeled after Oracle Forms batch runner B20BAT001 & te.docx schema definitions.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/detailed_service.php';

/**
 * Preview unposted TE Detailed Report vouchers before running batch.
 *
 * @param string $filter_type
 * @param string|null $from_date
 * @param string|null $to_date
 * @param string|null $accounting_month
 * @param string|null $financial_year_val
 * @return array
 * @throws Exception
 */
function preview_te_batch_posting(string $filter_type, ?string $from_date = null, ?string $to_date = null, ?string $accounting_month = null, ?string $financial_year_val = null): array {
    list($records, $filter_desc) = get_transfer_entry_report_data($filter_type, $from_date, $to_date, $accounting_month, $financial_year_val, true);
    
    if (empty($records)) {
        $notice = "No TE Detailed Report vouchers found matching filter criteria.";
        try {
            $pdo = initialize_database();
            $stmt_chk = $pdo->query("SELECT COUNT(*) FROM daily_payment_records");
            $daily_count = (int)$stmt_chk->fetchColumn();
            if ($daily_count > 0) {
                $stmt_gen = $pdo->query("SELECT COUNT(*) FROM generated_transfer_reports");
                $gen_count = (int)$stmt_gen->fetchColumn();
                if ($gen_count === 0) {
                    $notice = "Daily payment records exist, but no Transfer Reports (PDF vouchers) have been generated yet. Please generate vouchers in the 'Generate PDFs' tab first.";
                }
            }
        } catch (Throwable $eNotice) {}

        return [
            'vouchers_count' => 0,
            'total_amount'   => 0.0,
            'filter_desc'    => $filter_desc,
            'vouchers'       => [],
            'raw_records'    => [],
            'notice'         => $notice
        ];
    }

    // Group records by Sectional Number (Voucher)
    $vouchers = [];
    $total_amount = 0.0;

    foreach ($records as $r) {
        $sec_no = $r['sectional_no'] ?? 'UNASSIGNED';
        if (!isset($vouchers[$sec_no])) {
            $vouchers[$sec_no] = [
                'sectional_no'  => $sec_no,
                'tr_no'         => $r['tr_no'] ?? '',
                'tr_desc'       => $r['tr_desc'] ?? '',
                'ministry_name' => $r['ministry_name'] ?? '',
                'posting_date'  => $r['posting_date'] ?? '',
                'total_amount'  => 0.0,
                'entries_count' => 0,
                'debit_head'    => "{$r['major_head_dr']}-{$r['sub_major_dr']}-{$r['minor_head_dr']}-{$r['sub_head_dr']}-{$r['detail_head_dr']}-{$r['sub_detail_dr']}",
                'credit_heads'  => []
            ];
        }
        $amt = (float)($r['total_amount'] ?? 0.0);
        $vouchers[$sec_no]['total_amount'] += $amt;
        $vouchers[$sec_no]['entries_count']++;
        $vouchers[$sec_no]['credit_heads'][] = [
            'major_head'  => $r['major_head_cr'],
            'sub_major'   => $r['sub_major_cr'],
            'minor_head'  => $r['minor_head_cr'],
            'sub_head'    => $r['sub_head_cr'],
            'detail_head' => $r['detail_head_cr'],
            'sub_detail'  => $r['sub_detail_cr'],
            'amount'      => $amt
        ];
        $total_amount += $amt;
    }

    return [
        'vouchers_count' => count($vouchers),
        'total_amount'   => $total_amount,
        'filter_desc'    => $filter_desc,
        'vouchers'       => array_values($vouchers),
        'raw_records'    => $records
    ];
}

/**
 * Helper to prepare an INSERT statement filtered to columns that actually exist in the database table.
 * Prevents Oracle ORA-00904 (invalid identifier) when optional columns are missing in schema tables.
 *
 * @param Oci8PdoWrapper|SafePdoWrapper|PDO $pdo
 * @param string $tbl_name
 * @param array $col_defs Map of uppercase column name => ['is_date' => bool]
 * @param bool $is_oracle
 * @return array [$stmt, $active_keys]
 */
function prepare_dynamic_insert($pdo, string $tbl_name, array $col_defs, bool $is_oracle): array {
    $existing_cols = [];
    try {
        if ($is_oracle) {
            $stmt_c = $pdo->prepare("SELECT column_name FROM user_tab_columns WHERE UPPER(table_name) = UPPER(?)");
            $stmt_c->execute([$tbl_name]);
            $rows = $stmt_c->fetchAll() ?: [];
            foreach ($rows as $r) {
                $c_name = strtoupper($r['column_name'] ?? '');
                if ($c_name) $existing_cols[$c_name] = true;
            }
        } else {
            $stmt_c = $pdo->prepare("PRAGMA table_info({$tbl_name})");
            $stmt_c->execute();
            $rows = $stmt_c->fetchAll() ?: [];
            foreach ($rows as $r) {
                $c_name = strtoupper($r['name'] ?? '');
                if ($c_name) $existing_cols[$c_name] = true;
            }
        }
    } catch (Throwable $eCols) {}

    $active_columns = [];
    $value_placeholders = [];
    $active_keys = [];

    foreach ($col_defs as $col => $cfg) {
        $col_upper = strtoupper($col);
        if (!empty($existing_cols) && !isset($existing_cols[$col_upper])) {
            continue; // Skip column if it does not exist in target database table
        }
        $active_columns[] = $col_upper;
        $active_keys[] = $col;

        $is_date = !empty($cfg['is_date']);
        if ($is_oracle && $is_date) {
            $value_placeholders[] = "TO_DATE(?, 'YYYY-MM-DD')";
        } else {
            $value_placeholders[] = "?";
        }
    }

    $col_str = implode(', ', $active_columns);
    $val_str = implode(', ', $value_placeholders);
    $sql = "INSERT INTO {$tbl_name} ({$col_str}) VALUES ({$val_str})";

    $stmt = $pdo->prepare($sql);
    return [$stmt, $active_keys];
}

/**
 * Execute batch posting of TE Detailed Report data into VLCS schema.
 * Modeled after Oracle Forms batch runner B20BAT001.
 *
 * @param string $filter_type
 * @param string|null $from_date
 * @param string|null $to_date
 * @param string|null $accounting_month
 * @param string|null $financial_year_val
 * @param string $username
 * @return array
 * @throws Exception
 */
function post_te_detailed_report_batch(string $filter_type, ?string $from_date = null, ?string $to_date = null, ?string $accounting_month = null, ?string $financial_year_val = null, string $username = 'DIR'): array {
    $main_db = get_db_connection();
    $pdo = get_vlcs_db_connection();
    
    // Fetch TE Detailed Report data (only unposted records where VLC TE Number is blank)
    list($records, $filter_desc) = get_transfer_entry_report_data($filter_type, $from_date, $to_date, $accounting_month, $financial_year_val, true);

    if (empty($records)) {
        throw new Exception("No unposted TE Detailed Report records found matching filter criteria to post.");
    }

    // Group records by Sectional Number (Voucher)
    $vouchers = [];
    foreach ($records as $r) {
        $sec_no = $r['sectional_no'] ?? 'UNASSIGNED';
        if (!isset($vouchers[$sec_no])) {
            $vouchers[$sec_no] = [
                'sectional_no'         => $sec_no,
                'sectional_number_raw' => $r['sectional_number_raw'] ?? 0,
                'tr_no'                => $r['tr_no'] ?? '',
                'tr_desc'              => $r['tr_desc'] ?? '',
                'ministry_name'        => $r['ministry_name'] ?? '',
                'posting_date'         => $r['posting_date'] ?? '',
                'total_amount'         => 0.0,
                'report_ids'           => [],
                'entries'              => []
            ];
        }
        $amt = (float)($r['total_amount'] ?? 0.0);
        $vouchers[$sec_no]['total_amount'] += $amt;
        if (!empty($r['report_id'])) {
            $vouchers[$sec_no]['report_ids'][] = $r['report_id'];
        }
        $vouchers[$sec_no]['entries'][] = $r;
    }

    $posted_vouchers = 0;
    $posted_total_amt = 0.0;

    $pdo->beginTransaction();

    try {
        // Table names depending on Oracle vs SQLite connection
        $is_oracle = ($pdo instanceof Oci8PdoWrapper);
        $tbl_hdrs = $is_oracle ? 'B2_TE_HDRS' : 'b2_te_hdrs';
        $tbl_hdr  = $is_oracle ? 'B2_TE_HDR'  : 'b2_te_hdr';
        $tbl_dtls = $is_oracle ? 'B2_TE_DTLS' : 'b2_te_dtls';

        $is_oracle_main = ($main_db instanceof Oci8PdoWrapper);
        $tbl_log  = $is_oracle_main ? 'VLCS_B2_TE_BATCH_LOG' : 'vlcs_b2_te_batch_log';

        // Prepare statements depending on Oracle vs SQLite schema definitions
        $stmt_max = $pdo->prepare("SELECT COALESCE(MAX(te_no), 0) AS max_no FROM {$tbl_hdrs}");
        
        $hdrs_defs = [
            'TE_NO'               => ['is_date' => false],
            'GRANT_CODE'          => ['is_date' => false],
            'PARAMETER_CODE'      => ['is_date' => false],
            'SOURCE_CODE'         => ['is_date' => false],
            'TE_DATE'             => ['is_date' => true],
            'SST_TAG'             => ['is_date' => false],
            'BELATED_TAG'         => ['is_date' => false],
            'DR_CR_DD_DC_TAG'     => ['is_date' => false],
            'DAA_TAG'             => ['is_date' => false],
            'CONTINGENCY_TAG'     => ['is_date' => false],
            'FIN_YEAR_CODE'       => ['is_date' => false],
            'ACCOUNTING_MONTH'    => ['is_date' => false],
            'MONTH_OF_ACCOUNT'    => ['is_date' => true],
            'CREATE_USER'         => ['is_date' => false],
            'CREATE_DATE'         => ['is_date' => true],
            'MODIFY_USER'         => ['is_date' => false],
            'MODIFY_DATE'         => ['is_date' => true],
            'TE_APPROVE_TAG'      => ['is_date' => false],
            'TE_DESCR'            => ['is_date' => false],
            'SL_NO'               => ['is_date' => false],
            'SOURCE_MINISTRY_TAG' => ['is_date' => false],
            'TE_SANCTION_NO'      => ['is_date' => false],
            'TAG'                 => ['is_date' => false],
            'CAT_SCHEME_CODE'     => ['is_date' => false],
        ];

        $hdr_defs = [
            'TE_NO'                => ['is_date' => false],
            'MAJOR_HEAD_CODE'      => ['is_date' => false],
            'SUB_MAJOR_HEAD_CODE'  => ['is_date' => false],
            'MINOR_HEAD_CODE'      => ['is_date' => false],
            'SUB_HEAD_CODE'        => ['is_date' => false],
            'SUB_SUB_HEAD_CODE'    => ['is_date' => false],
            'DETAIL_HEAD_CODE'     => ['is_date' => false],
            'SUB_DETAIL_HEAD_CODE' => ['is_date' => false],
            'INITIAL_AMOUNT'       => ['is_date' => false],
            'CREATE_USER'          => ['is_date' => false],
            'CREATE_DATE'          => ['is_date' => true],
            'MODIFY_USER'          => ['is_date' => false],
            'MODIFY_DATE'          => ['is_date' => true],
            'DR_CR_DD_DC_TAG'      => ['is_date' => false],
            'DAA_TAG'              => ['is_date' => false],
            'CAT_SCHEME_CODE'      => ['is_date' => false],
            'GRANT_CODE'           => ['is_date' => false],
            'PARAMETER_CODE'       => ['is_date' => false],
        ];

        $dtls_defs = [
            'TE_NO'                => ['is_date' => false],
            'SRL_NO'               => ['is_date' => false],
            'GRANT_CODE'           => ['is_date' => false],
            'PARAMETER_CODE'       => ['is_date' => false],
            'MAJOR_HEAD_CODE'      => ['is_date' => false],
            'SUB_MAJOR_HEAD_CODE'  => ['is_date' => false],
            'MINOR_HEAD_CODE'      => ['is_date' => false],
            'SUB_HEAD_CODE'        => ['is_date' => false],
            'SUB_SUB_HEAD_CODE'    => ['is_date' => false],
            'DETAIL_HEAD_CODE'     => ['is_date' => false],
            'SUB_DETAIL_HEAD_CODE' => ['is_date' => false],
            'TO_AMOUNT'            => ['is_date' => false],
            'DAA_TAG'              => ['is_date' => false],
            'CONTINGENCY_TAG'      => ['is_date' => false],
            'DR_CR_DD_DC_TAG'      => ['is_date' => false],
            'REMARKS'              => ['is_date' => false],
            'REASONS'              => ['is_date' => false],
            'CREATE_USER'          => ['is_date' => false],
            'CREATE_DATE'          => ['is_date' => true],
            'MODIFY_USER'          => ['is_date' => false],
            'MODIFY_DATE'          => ['is_date' => true],
            'SL_NO'                => ['is_date' => false],
            'CAT_SCHEME_CODE'      => ['is_date' => false],
        ];

        $log_defs = [
            'ID'               => ['is_date' => false],
            'BATCH_CODE'       => ['is_date' => false],
            'FILTER_TYPE'      => ['is_date' => false],
            'ACCOUNTING_MONTH' => ['is_date' => false],
            'FIN_YEAR'         => ['is_date' => false],
            'RECORDS_POSTED'   => ['is_date' => false],
            'TOTAL_AMOUNT'     => ['is_date' => false],
            'STATUS'           => ['is_date' => false],
            'MESSAGE'          => ['is_date' => false],
            'RUN_USER'         => ['is_date' => false],
            'RUN_DATE'         => ['is_date' => true],
        ];

        list($stmt_ins_hdrs, $hdrs_active_keys) = prepare_dynamic_insert($pdo, $tbl_hdrs, $hdrs_defs, $is_oracle);
        list($stmt_ins_hdr,  $hdr_active_keys)  = prepare_dynamic_insert($pdo, $tbl_hdr,  $hdr_defs,  $is_oracle);
        list($stmt_ins_dtls, $dtls_active_keys) = prepare_dynamic_insert($pdo, $tbl_dtls, $dtls_defs, $is_oracle);
        list($stmt_log,      $log_active_keys)  = prepare_dynamic_insert($main_db, $tbl_log, $log_defs, $is_oracle_main);

        $today_db = date('Y-m-d');
        $create_user = strtoupper(trim($username ?: 'DIR'));

        // Query max existing SRL_NO in B2_TE_DTLS table to ensure continuous incremental sequence
        $stmt_max_dtl = $pdo->prepare("SELECT COALESCE(MAX(srl_no), 0) AS max_srl FROM {$tbl_dtls}");
        $stmt_max_dtl->execute();
        $max_dtl_row = $stmt_max_dtl->fetch();
        $srl_no = (int)($max_dtl_row['max_srl'] ?? 0) + 1;

        foreach ($vouchers as $sec_no => $v) {
            // Obtain next TE_NO
            $stmt_max->execute();
            $max_row = $stmt_max->fetch();
            $te_no = (int)($max_row['max_no'] ?? 0) + 1;

            $post_date_db = to_db_date($v['posting_date']) ?: $today_db;
            
            // Calculate Accounting Month code (1 for April, 2 for May, 3 for June... 12 for March)
            if (!empty($accounting_month)) {
                $acct_m_code = acct_month_code($accounting_month);
            } else {
                $acct_m_code = acct_month_code($post_date_db);
            }

            // Calculate FIN_YEAR_CODE (e.g. 28 for FY 2026-27, 29 for FY 2027-28)
            if (!empty($financial_year_val)) {
                $fy_code = fin_year_code($financial_year_val);
            } elseif (!empty($accounting_month)) {
                $fy_code = fin_year_code($accounting_month);
            } else {
                $fy_code = fin_year_code($post_date_db);
            }

            // 1. Insert into B2_TE_HDRS
            $hdrs_row_map = [
                'TE_NO'               => $te_no,
                'GRANT_CODE'          => null, // Blank as requested
                'PARAMETER_CODE'      => 27,
                'SOURCE_CODE'         => 55,
                'TE_DATE'             => $post_date_db,
                'SST_TAG'             => 'N',
                'BELATED_TAG'         => 'N',
                'DR_CR_DD_DC_TAG'     => null,
                'DAA_TAG'             => null,
                'CONTINGENCY_TAG'     => 'N',
                'FIN_YEAR_CODE'       => $fy_code,
                'ACCOUNTING_MONTH'    => $acct_m_code,
                'MONTH_OF_ACCOUNT'    => $post_date_db,
                'CREATE_USER'         => $create_user,
                'CREATE_DATE'         => $today_db,
                'MODIFY_USER'         => $create_user,
                'MODIFY_DATE'         => $today_db,
                'TE_APPROVE_TAG'      => 'Y',
                'TE_DESCR'            => substr($v['tr_desc'] ?: "TE Voucher {$sec_no}", 0, 60),
                'SL_NO'               => 28,
                'SOURCE_MINISTRY_TAG' => 'S',
                'TE_SANCTION_NO'      => substr($sec_no, 0, 18),
                'TAG'                 => 'A',
                'CAT_SCHEME_CODE'     => 8
            ];
            $hdrs_params = [];
            foreach ($hdrs_active_keys as $k) {
                $hdrs_params[] = $hdrs_row_map[$k] ?? null;
            }
            $stmt_ins_hdrs->execute($hdrs_params);

            // 2. Insert into B2_TE_HDR (Debit entry for Major Head 8675)
            $hdr_row_map = [
                'TE_NO'                => $te_no,
                'MAJOR_HEAD_CODE'      => 8675,
                'SUB_MAJOR_HEAD_CODE'  => 0,
                'MINOR_HEAD_CODE'      => 106,
                'SUB_HEAD_CODE'        => 3,
                'SUB_SUB_HEAD_CODE'    => 0,
                'DETAIL_HEAD_CODE'     => 0,
                'SUB_DETAIL_HEAD_CODE' => 0,
                'INITIAL_AMOUNT'       => $v['total_amount'],
                'CREATE_USER'          => $create_user,
                'CREATE_DATE'          => $today_db,
                'MODIFY_USER'          => $create_user,
                'MODIFY_DATE'          => $today_db,
                'DR_CR_DD_DC_TAG'      => 'DR',
                'DAA_TAG'              => 'N',
                'CAT_SCHEME_CODE'      => 8,
                'GRANT_CODE'           => 43,
                'PARAMETER_CODE'       => 27
            ];
            $hdr_params = [];
            foreach ($hdr_active_keys as $k) {
                $hdr_params[] = $hdr_row_map[$k] ?? null;
            }
            $stmt_ins_hdr->execute($hdr_params);

            // 3. Insert into B2_TE_DTLS (Credit entries for Major Head 1601)
            foreach ($v['entries'] as $entry) {
                $sub_head_num = (int)preg_replace('/[^\d]/', '', $entry['sub_head_cr']);
                $detail_head_num = (int)preg_replace('/[^\d]/', '', $entry['detail_head_cr']);
                $entry_amt = (float)$entry['total_amount'];

                $dtls_row_map = [
                    'TE_NO'                => $te_no,
                    'SRL_NO'               => $srl_no,
                    'GRANT_CODE'           => 43,
                    'PARAMETER_CODE'       => 27,
                    'MAJOR_HEAD_CODE'      => 1601,
                    'SUB_MAJOR_HEAD_CODE'  => 6,
                    'MINOR_HEAD_CODE'      => 101,
                    'SUB_HEAD_CODE'        => $sub_head_num,
                    'SUB_SUB_HEAD_CODE'    => 0,
                    'DETAIL_HEAD_CODE'     => $detail_head_num,
                    'SUB_DETAIL_HEAD_CODE' => 0,
                    'TO_AMOUNT'            => $entry_amt,
                    'DAA_TAG'              => 'Y',
                    'CONTINGENCY_TAG'      => 'N',
                    'DR_CR_DD_DC_TAG'      => 'CR',
                    'REMARKS'              => null,
                    'REASONS'              => null,
                    'CREATE_USER'          => $create_user,
                    'CREATE_DATE'          => $today_db,
                    'MODIFY_USER'          => $create_user,
                    'MODIFY_DATE'          => $today_db,
                    'SL_NO'                => 28,
                    'CAT_SCHEME_CODE'      => 8
                ];
                $dtls_params = [];
                foreach ($dtls_active_keys as $k) {
                    $dtls_params[] = $dtls_row_map[$k] ?? null;
                }
                $stmt_ins_dtls->execute($dtls_params);

                $srl_no++;
            }

            // Auto-update VLC TE Number in generated_transfer_reports for the posted voucher
            try {
                if (!empty($v['report_ids'])) {
                    $unique_ids = array_unique($v['report_ids']);
                    $placeholders = implode(',', array_fill(0, count($unique_ids), '?'));
                    $upd_sql = "UPDATE generated_transfer_reports SET vlc_te_number = ?, is_posted = 1 WHERE id IN ({$placeholders})";
                    $upd_params = array_merge([(string)$te_no], $unique_ids);
                    $stmt_upd_te = $main_db->prepare($upd_sql);
                    $stmt_upd_te->execute($upd_params);
                } elseif (!empty($v['sectional_number_raw'])) {
                    $stmt_upd_te = $main_db->prepare("UPDATE generated_transfer_reports SET vlc_te_number = ?, is_posted = 1 WHERE sectional_number = ? AND (vlc_te_number IS NULL OR vlc_te_number = '')");
                    $stmt_upd_te->execute([(string)$te_no, $v['sectional_number_raw']]);
                }
            } catch (Throwable $eUpdTe) {}

            $posted_vouchers++;
            $posted_total_amt += $v['total_amount'];
        }

        // Log batch posting execution into TransferEntry schema (main DB connection)
        $log_msg = "Successfully posted {$posted_vouchers} TE vouchers totaling ₹" . number_format($posted_total_amt, 2) . " into schema VLCS.";
        
        $next_log_id = 1;
        try {
            $stmt_log_max = $main_db->query("SELECT COALESCE(MAX(id), 0) AS max_log_id FROM {$tbl_log}");
            $max_log_row = $stmt_log_max->fetch();
            $next_log_id = (int)($max_log_row['max_log_id'] ?? 0) + 1;
        } catch (Throwable $eMaxLog) {
            $next_log_id = 1;
        }

        $log_row_map = [
            'ID'               => $next_log_id,
            'BATCH_CODE'       => 'B2_DDR_DEPARTMENT_TR_BATCH',
            'FILTER_TYPE'      => $filter_type,
            'ACCOUNTING_MONTH' => $accounting_month ?: 'ALL',
            'FIN_YEAR'         => $financial_year_val ?: date('Y') . '-' . (date('y') + 1),
            'RECORDS_POSTED'   => $posted_vouchers,
            'TOTAL_AMOUNT'     => $posted_total_amt,
            'STATUS'           => 'SUCCESS',
            'MESSAGE'          => $log_msg,
            'RUN_USER'         => $create_user,
            'RUN_DATE'         => $today_db
        ];
        $log_params = [];
        foreach ($log_active_keys as $k) {
            $log_params[] = $log_row_map[$k] ?? null;
        }
        $stmt_log->execute($log_params);

        $pdo->commit();

        return [
            'success'        => true,
            'message'        => $log_msg,
            'posted_vouchers' => $posted_vouchers,
            'total_amount'   => $posted_total_amt,
            'filter_desc'    => $filter_desc
        ];

    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new Exception("Batch Posting Failed: " . $e->getMessage());
    }
}

/**
 * Fetch execution logs of past TE batch posting runs from TransferEntry schema.
 *
 * @return array
 */
function get_batch_posting_history(): array {
    $main_db = get_db_connection();
    $is_oracle = ($main_db instanceof Oci8PdoWrapper);
    $tbl_log = $is_oracle ? 'VLCS_B2_TE_BATCH_LOG' : 'vlcs_b2_te_batch_log';

    try {
        $stmt = $main_db->query("SELECT * FROM {$tbl_log} ORDER BY id DESC");
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
