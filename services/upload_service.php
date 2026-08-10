<?php
/**
 * Daily Payment file upload & import service for Transfer Entry Management System.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/master_service.php';

/**
 * Parse native binary .xlsx (OpenXML Zip container) using built-in ZipArchive & SimpleXML.
 *
 * @param string $file_path
 * @return array
 */
function parse_xlsx_file(string $file_path): array {
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($file_path) !== TRUE) {
        return [];
    }

    $shared_strings = [];
    $ss_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss_xml !== false) {
        $xml = @simplexml_load_string($ss_xml);
        if ($xml && isset($xml->si)) {
            foreach ($xml->si as $val) {
                if (isset($val->t)) {
                    $shared_strings[] = (string)$val->t;
                } elseif (isset($val->r)) {
                    $text = '';
                    foreach ($val->r as $r) {
                        $text .= (string)$r->t;
                    }
                    $shared_strings[] = $text;
                } else {
                    $shared_strings[] = (string)$val;
                }
            }
        }
    }

    $sheet_xml_content = false;
    $sheet_name = 'xl/worksheets/sheet1.xml';
    $sheet_xml_content = $zip->getFromName($sheet_name);
    if ($sheet_xml_content === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'xl/worksheets/sheet') === 0 && substr($name, -4) === '.xml') {
                $sheet_xml_content = $zip->getFromName($name);
                break;
            }
        }
    }

    $rows = [];
    if ($sheet_xml_content !== false) {
        $xml = @simplexml_load_string($sheet_xml_content);
        if ($xml && isset($xml->sheetData) && isset($xml->sheetData->row)) {
            foreach ($xml->sheetData->row as $row) {
                $row_cells = [];
                foreach ($row->c as $cell) {
                    $ref = (string)$cell['r'];
                    $col_letter = strtoupper(preg_replace('/[^A-Za-z]/', '', $ref));
                    $col_num = 0;
                    for ($l = 0; $l < strlen($col_letter); $l++) {
                        $col_num = $col_num * 26 + (ord($col_letter[$l]) - 64);
                    }
                    $col_idx = max(0, $col_num - 1);

                    $type = (string)$cell['t'];
                    $cell_val = '';

                    if ($type === 's') {
                        $val_idx = (int)$cell->v;
                        $cell_val = $shared_strings[$val_idx] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $cell_val = (string)($cell->is->t ?? '');
                    } elseif (isset($cell->v)) {
                        $cell_val = (string)$cell->v;
                    }

                    while (count($row_cells) < $col_idx) {
                        $row_cells[] = '';
                    }
                    $row_cells[$col_idx] = trim($cell_val);
                }
                if (!empty(array_filter($row_cells, function($v) { return $v !== ''; }))) {
                    $rows[] = $row_cells;
                }
            }
        }
    }

    $zip->close();
    return $rows;
}

/**
 * @param string $file_path
 * @param string $file_name
 * @param string $report_date_input
 * @return int
 * @throws Exception
 */
function import_daily_payment_file(string $file_path, string $file_name, string $report_date_input): int {
    $pdo = initialize_database();
    $report_date = to_db_date($report_date_input);
    if (!$report_date) {
        throw new Exception("Invalid Report Date format. Use DD/MM/YYYY.");
    }

    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $rows = [];

    if ($ext === 'xlsx' || $ext === 'xls') {
        $rows = parse_xlsx_file($file_path);
    }

    if (empty($rows)) {
        if ($ext === 'csv' || $ext === 'txt') {
            $handle = @fopen($file_path, 'r');
            if ($handle) {
                while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
                    $rows[] = $data;
                }
                fclose($handle);
            }
        } else {
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
                $lines = explode("\n", $content);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $rows[] = str_getcsv($line, strpos($line, "\t") !== false ? "\t" : ",");
                    }
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
        if (strpos($row_str, 'state') !== false || strpos($row_str, 'posting') !== false || strpos($row_str, 'amount') !== false || strpos($row_str, 'account') !== false || strpos($row_str, 'tr') !== false || strpos($row_str, 'scheme') !== false) {
            $header_idx = $idx;
            foreach ($row as $c_idx => $c_name) {
                $c_clean = strtolower(trim($c_name));
                if (strpos($c_clean, 'posting date') !== false || strpos($c_clean, 'date') !== false) $col_map['posting_date'] = $c_idx;
                elseif (strpos($c_clean, 'posting time') !== false || strpos($c_clean, 'time') !== false) $col_map['posting_time'] = $c_idx;
                elseif (strpos($c_clean, 'state') !== false || strpos($c_clean, 'govt') !== false) $col_map['state'] = $c_idx;
                elseif (strpos($c_clean, 'sg account') !== false || strpos($c_clean, 'tr code') !== false || strpos($c_clean, 'tr') !== false || strpos($c_clean, 'scheme') !== false || strpos($c_clean, 'account name') !== false || strpos($c_clean, 'particular') !== false) $col_map['tr_code'] = $c_idx;
                elseif (strpos($c_clean, 'amount') !== false || strpos($c_clean, 'amt') !== false || strpos($c_clean, 'rs') !== false) $col_map['amount'] = $c_idx;
                elseif (strpos($c_clean, 'cg account') !== false || strpos($c_clean, 'udch') !== false || strpos($c_clean, 'head') !== false) $col_map['cg_code'] = $c_idx;
            }
            break;
        }
    }

    // Retrieve master scheme TR code mappings
    $valid_master_map = get_scheme_master_map();

    $parsed_records = [];
    $missing_tr_map = [];

    $start_row = ($header_idx >= 0) ? $header_idx + 1 : 0;
    for ($i = $start_row; $i < count($rows); $i++) {
        $row = $rows[$i];
        if (empty(array_filter($row))) continue;

        $p_date_raw = isset($col_map['posting_date']) ? ($row[$col_map['posting_date']] ?? '') : ($row[0] ?? '');
        $p_time_raw = isset($col_map['posting_time']) ? ($row[$col_map['posting_time']] ?? '') : '';
        if (empty($p_time_raw) && isset($row[1]) && preg_match('/\d{1,2}:\d{2}/', (string)$row[1])) {
            $p_time_raw = (string)$row[1];
        }
        $p_time_clean = mb_substr(trim((string)$p_time_raw), 0, 20);
        $p_time = ($p_time_clean !== '') ? $p_time_clean : '00:00:00';

        $state_raw = isset($col_map['state']) ? ($row[$col_map['state']] ?? '') : ($row[2] ?? '');
        $state_clean = mb_substr(trim((string)$state_raw), 0, 100);
        $state = ($state_clean !== '') ? $state_clean : 'N/A';

        $raw_tr = isset($col_map['tr_code']) ? ($row[$col_map['tr_code']] ?? '') : ($row[3] ?? '');
        $amt_raw = isset($col_map['amount']) ? ($row[$col_map['amount']] ?? '') : ($row[4] ?? '');

        $cg_raw = isset($col_map['cg_code']) ? ($row[$col_map['cg_code']] ?? '') : ($row[5] ?? '');
        $cg_clean = mb_substr(trim((string)$cg_raw), 0, 100);
        $cg_code = ($cg_clean !== '') ? $cg_clean : 'N/A';

        $p_date = to_db_date($p_date_raw) ?? $report_date;
        $amt = (float)str_replace(',', '', trim($amt_raw));

        // Extract TR code using helper
        $extracted_tr = extract_tr_code($raw_tr);
        if (!$extracted_tr && $raw_tr !== '') {
            $clean_raw = strtoupper(preg_replace('/[\s\-_.]/', '', $raw_tr));
            if (isset($valid_master_map[$clean_raw])) {
                $extracted_tr = $clean_raw;
            } elseif (isset($valid_master_map[strtoupper(trim($raw_tr))])) {
                $extracted_tr = strtoupper(trim($raw_tr));
            }
        }

        // If not found from designated tr_code column, scan all cells in the row
        if (!$extracted_tr || !isset($valid_master_map[$extracted_tr])) {
            foreach ($row as $cell) {
                $cell_tr = extract_tr_code((string)$cell);
                if ($cell_tr && isset($valid_master_map[$cell_tr])) {
                    $extracted_tr = $cell_tr;
                    break;
                }
                $cell_clean = strtoupper(preg_replace('/[\s\-_.]/', '', (string)$cell));
                if ($cell_clean !== '' && isset($valid_master_map[$cell_clean])) {
                    $extracted_tr = $cell_clean;
                    break;
                }
            }
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
            'p_time' => $p_time,
            'state' => $state,
            'tr_code' => $extracted_tr ?: trim($raw_tr),
            'amount' => $amt,
            'cg_code' => $cg_code
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
                    mb_substr($file_name, 0, 255),
                    $rec['row_num'],
                    $report_date,
                    $rec['p_date'],
                    $rec['p_time'],
                    $rec['state'],
                    mb_substr(trim($rec['tr_code'] ?? ''), 0, 100),
                    $rec['amount'],
                    $rec['cg_code']
                ]);
                $imported_count++;
            }
        }

        $pdo->commit();
        return $imported_count;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return array
 */
function get_recent_uploads(): array {
    $pdo = initialize_database();
    $stmt = $pdo->query("
        SELECT source_file_name, report_date, COUNT(*) as record_count, SUM(amount) as total_amount, MAX(created_at) as uploaded_at
        FROM daily_payment_records
        GROUP BY source_file_name, report_date
        ORDER BY MAX(created_at) DESC
    ");
    return $stmt->fetchAll();
}

/**
 * @param string $source_file_name
 * @param string $report_date
 * @return array
 */
function get_uploaded_file_records(string $source_file_name, string $report_date): array {
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

/**
 * @param string|null $start_date
 * @param string|null $end_date
 * @return array
 */
function get_daily_payment_records(?string $start_date = null, ?string $end_date = null): array {
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