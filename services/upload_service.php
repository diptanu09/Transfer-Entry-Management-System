<?php
/**
 * Daily Payment file upload & import service for PHP application.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

function import_daily_payment_file($file_path, $file_name, $report_date_input) {
    $pdo = initialize_database();
    $report_date = to_db_date($report_date_input);
    if (!$report_date) {
        throw new Exception("Invalid Report Date format. Use DD/MM/YYYY.");
    }

    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $rows = [];

    if ($ext === 'csv' || $ext === 'txt') {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new Exception("Unable to open uploaded CSV file.");
        }
        while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
            $rows[] = $data;
        }
        fclose($handle);
    } else {
        // Simple XML/HTML spreadsheet or basic text parse fallback for excel exports
        $content = file_get_contents($file_path);
        if (strpos($content, '<tr') !== false) {
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $content, $tr_matches);
            foreach ($tr_matches[1] as $tr) {
                preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $tr, $td_matches);
                if (!empty($td_matches[1])) {
                    $rows[] = array_map('strip_tags', array_map('trim', $td_matches[1]));
                }
            }
        } else {
            // Fallback line-by-line tab/comma split
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $rows[] = str_getcsv($line, strpos($line, "\t") !== false ? "\t" : ",");
                }
            }
        }
    }

    if (empty($rows)) {
        throw new Exception("The uploaded file contains no data.");
    }

    // Identify header row
    $header_idx = -1;
    $col_map = [];
    foreach ($rows as $idx => $row) {
        $row_str = strtolower(implode(' ', $row));
        if (strpos($row_str, 'state') !== false || strpos($row_str, 'posting') !== false || strpos($row_str, 'amount') !== false || strpos($row_str, 'account') !== false) {
            $header_idx = $idx;
            foreach ($row as $c_idx => $c_name) {
                $c_clean = strtolower(trim($c_name));
                if (strpos($c_clean, 'posting date') !== false || strpos($c_clean, 'date') !== false) $col_map['posting_date'] = $c_idx;
                elseif (strpos($c_clean, 'posting time') !== false || strpos($c_clean, 'time') !== false) $col_map['posting_time'] = $c_idx;
                elseif (strpos($c_clean, 'state') !== false) $col_map['state'] = $c_idx;
                elseif (strpos($c_clean, 'sg account') !== false || strpos($c_clean, 'tr code') !== false || strpos($c_clean, 'scheme') !== false) $col_map['tr_code'] = $c_idx;
                elseif (strpos($c_clean, 'amount') !== false) $col_map['amount'] = $c_idx;
                elseif (strpos($c_clean, 'cg account') !== false || strpos($c_clean, 'udch') !== false) $col_map['cg_code'] = $c_idx;
            }
            break;
        }
    }

    // Fetch master table TR code mapping
    $master_stmt = $pdo->query("
        WITH ranked_master AS (
            SELECT tr_code, sub_head, detail_head,
                   ROW_NUMBER() OVER (
                       PARTITION BY tr_code
                       ORDER BY
                            CASE WHEN controller IS NOT NULL OR css IS NOT NULL THEN 0 ELSE 1 END,
                            source_row_number
                   ) AS row_rank
            FROM scheme_configuration_master
        )
        SELECT tr_code, sub_head, detail_head
        FROM ranked_master
        WHERE row_rank = 1
    ");
    $master_rows = $master_stmt->fetchAll();
    $valid_master_map = [];
    foreach ($master_rows as $m) {
        if (!empty($m['tr_code']) && $m['sub_head'] !== null && $m['detail_head'] !== null) {
            $valid_master_map[strtoupper(trim($m['tr_code']))] = true;
        }
    }

    $parsed_records = [];
    $missing_tr_map = [];

    $start_row = ($header_idx >= 0) ? $header_idx + 1 : 0;
    for ($i = $start_row; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (empty(array_filter($row))) continue;

        $p_date_raw = isset($col_map['posting_date']) ? ($row[$col_map['posting_date']] ?? '') : ($row[0] ?? '');
        $p_time = isset($col_map['posting_time']) ? ($row[$col_map['posting_time']] ?? '') : ($row[1] ?? '');
        $state = isset($col_map['state']) ? ($row[$col_map['state']] ?? '') : ($row[2] ?? '');
        $raw_tr = isset($col_map['tr_code']) ? ($row[$col_map['tr_code']] ?? '') : ($row[3] ?? '');
        $amt_raw = isset($col_map['amount']) ? ($row[$col_map['amount']] ?? '') : ($row[4] ?? '');
        $cg_code = isset($col_map['cg_code']) ? ($row[$col_map['cg_code']] ?? '') : ($row[5] ?? '');

        $p_date = to_db_date($p_date_raw) ?? $report_date;
        $amt = (float)str_replace(',', '', trim($amt_raw));

        // Extract TR code using regex match
        $extracted_tr = null;
        if (preg_match('/TR\s*\d+/i', $raw_tr, $matches)) {
            $extracted_tr = strtoupper(preg_replace('/\s+/', '', $matches[0]));
        }

        $is_valid = ($extracted_tr !== null && isset($valid_master_map[$extracted_tr]));

        if (!$is_valid) {
            if ($extracted_tr) {
                $label = display_tr_code($extracted_tr);
            } else {
                $info_parts = [];
                if (trim($state)) $info_parts[] = "State: " . trim($state);
                if ($p_date) $info_parts[] = "Date: " . format_date($p_date);
                $info_str = implode(', ', $info_parts);
                $label = $info_str ? "Blank/Unmapped TR Code ({$info_str})" : "Blank/Unmapped TR Code";
            }

            if (!isset($missing_tr_map[$label])) {
                $missing_tr_map[$label] = ['amount' => 0.0, 'count' => 0];
            }
            $missing_tr_map[$label]['amount'] += $amt;
            $missing_tr_map[$label]['count'] += 1;
        }

        $parsed_records[] = [
            'row_num' => $i + 1,
            'p_date' => $p_date,
            'p_time' => trim($p_time),
            'state' => trim($state),
            'tr_code' => $extracted_tr ?: trim($raw_tr),
            'amount' => $amt,
            'cg_code' => trim($cg_code)
        ];
    }

    if (!empty($missing_tr_map)) {
        $lines = [];
        $total_missing_amt = 0.0;
        foreach ($missing_tr_map as $label => $info) {
            $amt = $info['amount'];
            $cnt = $info['count'];
            $total_missing_amt += $amt;
            $rec_str = ($cnt === 1) ? "({$cnt} record)" : "({$cnt} records)";
            $lines[] = "- {$label} - Rs. " . indian_number($amt) . "/- {$rec_str}";
        }
        $tr_details_str = implode("\n", $lines);
        $total_missing_fmt = indian_number($total_missing_amt);
        throw new Exception(
            "First please update these TR codes in Master Table before uploading the file because these TR codes are missing:\n\n" .
            $tr_details_str . "\n\n" .
            "Total Missing Amount: Rs. {$total_missing_fmt}/-"
        );
    }

    $pdo->beginTransaction();
    $imported_count = 0;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO daily_payment_records (
                source_file_name, source_row_number, report_date, posting_date, posting_time,
                state_government, sg_account_name, amount, cg_account_udch_code
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($parsed_records as $rec) {
            if (!empty($rec['tr_code']) && $rec['amount'] > 0) {
                $stmt->execute([
                    $file_name,
                    $rec['row_num'],
                    $report_date,
                    $rec['p_date'],
                    $rec['p_time'],
                    $rec['state'],
                    $rec['tr_code'],
                    $rec['amount'],
                    $rec['cg_code']
                ]);
                $imported_count++;
            }
        }

        $pdo->commit();
        return $imported_count;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function get_recent_uploads() {
    $pdo = initialize_database();
    $stmt = $pdo->query("
        SELECT source_file_name, report_date, COUNT(*) as record_count, SUM(amount) as total_amount, MAX(created_at) as uploaded_at
        FROM daily_payment_records
        GROUP BY source_file_name, report_date
        ORDER BY uploaded_at DESC
        LIMIT 50
    ");
    return $stmt->fetchAll();
}

function get_uploaded_file_records($source_file_name, $report_date) {
    $pdo = initialize_database();
    $stmt = $pdo->prepare("
        SELECT posting_date, posting_time, state_government, sg_account_name, amount, cg_account_udch_code
        FROM daily_payment_records
        WHERE source_file_name = ? AND report_date = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$source_file_name, $report_date]);
    return $stmt->fetchAll();
}

function get_daily_payment_records($start_date = null, $end_date = null) {
    $pdo = initialize_database();
    $where = [];
    $params = [];

    if ($start_date) {
        $start_db = to_db_date($start_date);
        if ($start_db) {
            $where[] = "posting_date >= ?";
            $params[] = $start_db;
        }
    }
    if ($end_date) {
        $end_db = to_db_date($end_date);
        if ($end_db) {
            $where[] = "posting_date <= ?";
            $params[] = $end_db;
        }
    }

    $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    $stmt = $pdo->prepare("
        SELECT id, source_file_name, report_date, posting_date, posting_time,
               state_government, sg_account_name, amount, cg_account_udch_code
        FROM daily_payment_records
        $where_sql
        ORDER BY posting_date DESC, posting_time ASC, id ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}
