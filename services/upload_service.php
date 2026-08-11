<?php
/**
 * Daily Payment file upload & import service
 * for Transfer Entry Management System.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/master_service.php';
require_once __DIR__ . '/xls_parser.php';


/**
 * Parse native binary .xlsx (OpenXML Zip container)
 * using built-in ZipArchive & SimpleXML.
 *
 * @param string $file_path
 * @return array
 */
function parse_xlsx_file(string $file_path): array
{
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();

    if ($zip->open($file_path) !== true) {
        return [];
    }

    /*
     * Read shared strings.
     */
    $shared_strings = [];

    $ss_xml = $zip->getFromName('xl/sharedStrings.xml');

    if ($ss_xml !== false) {
        $xml = @simplexml_load_string($ss_xml);

        if ($xml && isset($xml->si)) {
            foreach ($xml->si as $val) {

                if (isset($val->t)) {
                    $shared_strings[] = (string) $val->t;

                } elseif (isset($val->r)) {
                    $text = '';

                    foreach ($val->r as $r) {
                        if (isset($r->t)) {
                            $text .= (string) $r->t;
                        }
                    }

                    $shared_strings[] = $text;

                } else {
                    $shared_strings[] = (string) $val;
                }
            }
        }
    }


    /*
     * Locate worksheet.
     */
    $sheet_xml_content = false;
    $sheet_name = 'xl/worksheets/sheet1.xml';

    $sheet_xml_content = $zip->getFromName($sheet_name);

    if ($sheet_xml_content === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {

            $name = $zip->getNameIndex($i);

            if (
                strpos($name, 'xl/worksheets/sheet') === 0 &&
                substr($name, -4) === '.xml'
            ) {
                $sheet_xml_content = $zip->getFromName($name);
                break;
            }
        }
    }


    /*
     * Parse worksheet rows.
     */
    $rows = [];

    if ($sheet_xml_content !== false) {

        $xml = @simplexml_load_string($sheet_xml_content);

        if (
            $xml &&
            isset($xml->sheetData) &&
            isset($xml->sheetData->row)
        ) {

            foreach ($xml->sheetData->row as $row) {

                $row_cells = [];

                foreach ($row->c as $cell) {

                    $ref = (string) $cell['r'];

                    /*
                     * Extract column letters from cell reference.
                     * Example: A1 -> A, AB12 -> AB
                     */
                    $col_letter = strtoupper(
                        preg_replace('/[^A-Za-z]/', '', $ref)
                    );

                    $col_num = 0;

                    for ($l = 0; $l < strlen($col_letter); $l++) {
                        $col_num =
                            ($col_num * 26) +
                            (ord($col_letter[$l]) - 64);
                    }

                    $col_idx = max(0, $col_num - 1);

                    $type = (string) $cell['t'];
                    $cell_val = '';


                    /*
                     * Shared string.
                     */
                    if ($type === 's') {

                        $val_idx = isset($cell->v)
                            ? (int) $cell->v
                            : -1;

                        if (
                            $val_idx >= 0 &&
                            isset($shared_strings[$val_idx])
                        ) {
                            $cell_val = $shared_strings[$val_idx];
                        }


                    /*
                     * Inline string.
                     */
                    } elseif ($type === 'inlineStr') {

                        if (isset($cell->is->t)) {
                            $cell_val = (string) $cell->is->t;
                        }


                    /*
                     * Normal numeric/string value.
                     */
                    } elseif (isset($cell->v)) {

                        $cell_val = (string) $cell->v;
                    }


                    /*
                     * Preserve empty columns.
                     */
                    while (count($row_cells) < $col_idx) {
                        $row_cells[] = '';
                    }

                    $row_cells[$col_idx] = trim($cell_val);
                }


                /*
                 * Ignore completely empty rows.
                 */
                $has_data = false;

                foreach ($row_cells as $value) {
                    if ($value !== '') {
                        $has_data = true;
                        break;
                    }
                }

                if ($has_data) {
                    $rows[] = $row_cells;
                }
            }
        }
    }

    $zip->close();

    return $rows;
}


/**
 * Import daily payment file.
 *
 * @param string $file_path
 * @param string $file_name
 * @param string $report_date_input
 * @return int
 * @throws Exception
 */
function import_daily_payment_file(
    string $file_path,
    string $file_name,
    string $report_date_input
): int {

    $pdo = initialize_database();

    $report_date = to_db_date($report_date_input);

    if (!$report_date) {
        throw new Exception(
            'Invalid Report Date format. Use DD/MM/YYYY.'
        );
    }


    /*
     * Determine file extension.
     */
    $ext = strtolower(
        pathinfo($file_name, PATHINFO_EXTENSION)
    );

    $rows = [];


    /*
     * XLS.
     */
    if ($ext === 'xls') {

        $rows = SimpleXlsReader::parseFile($file_path);

        /*
         * Fallback to XLSX parser if needed.
         */
        if (empty($rows)) {
            $rows = parse_xlsx_file($file_path);
        }


    /*
     * XLSX.
     */
    } elseif ($ext === 'xlsx') {

        $rows = parse_xlsx_file($file_path);

        /*
         * Fallback to legacy XLS parser.
         */
        if (empty($rows)) {
            $rows = SimpleXlsReader::parseFile($file_path);
        }
    }


    /*
     * CSV / TXT.
     */
    if (empty($rows)) {

        if ($ext === 'csv' || $ext === 'txt') {

            $handle = @fopen($file_path, 'r');

            if ($handle) {

                while (
                    ($data = fgetcsv($handle, 10000, ',')) !== false
                ) {
                    $rows[] = $data;
                }

                fclose($handle);
            }


        /*
         * Try HTML or plain text.
         */
        } else {

            $content = @file_get_contents($file_path);

            if ($content === false) {
                $content = '';
            }


            /*
             * HTML table.
             */
            if (stripos($content, '<tr') !== false) {

                preg_match_all(
                    '/<tr[^>]*>(.*?)<\/tr>/is',
                    $content,
                    $tr_matches
                );

                if (!empty($tr_matches[1])) {

                    foreach ($tr_matches[1] as $tr) {

                        preg_match_all(
                            '/<td[^>]*>(.*?)<\/td>/is',
                            $tr,
                            $td_matches
                        );

                        if (!empty($td_matches[1])) {

                            $clean_cells = [];

                            foreach ($td_matches[1] as $cell) {
                                $clean_cells[] = trim(
                                    strip_tags($cell)
                                );
                            }

                            $rows[] = $clean_cells;
                        }
                    }
                }


            /*
             * Plain text / CSV / TSV.
             */
            } else {

                $lines = preg_split(
                    "/\r\n|\n|\r/",
                    $content
                );

                foreach ($lines as $line) {

                    $line = trim($line);

                    if ($line !== '') {

                        $delimiter =
                            strpos($line, "\t") !== false
                                ? "\t"
                                : ',';

                        $rows[] = str_getcsv(
                            $line,
                            $delimiter
                        );
                    }
                }
            }
        }
    }


    /*
     * No data.
     */
    if (empty($rows)) {
        throw new Exception(
            'The uploaded file contains no data.'
        );
    }


    /*
     * Identify header row.
     */
    $header_idx = -1;
    $col_map = [];

    foreach ($rows as $idx => $row) {

        if (!is_array($row)) {
            continue;
        }

        $row_str = strtolower(
            implode(' ', $row)
        );


        /*
         * Look for likely header indicators.
         */
        if (
            strpos($row_str, 'state') !== false ||
            strpos($row_str, 'posting') !== false ||
            strpos($row_str, 'amount') !== false ||
            strpos($row_str, 'account') !== false ||
            strpos($row_str, 'scheme') !== false ||
            preg_match('/\btr\b/i', $row_str)
        ) {

            $header_idx = $idx;

            foreach ($row as $c_idx => $c_name) {

                $c_clean = strtolower(
                    trim((string) $c_name)
                );


                /*
                 * Posting date.
                 */
                if (
                    strpos($c_clean, 'posting date') !== false ||
                    strpos($c_clean, 'posting/accounting') !== false ||
                    strpos($c_clean, 'date') !== false
                ) {
                    $col_map['posting_date'] = $c_idx;
                }


                /*
                 * Posting time.
                 */
                if (
                    strpos($c_clean, 'posting time') !== false ||
                    strpos($c_clean, 'time') !== false
                ) {
                    $col_map['posting_time'] = $c_idx;
                }


                /*
                 * State / Government.
                 */
                if (
                    strpos($c_clean, 'state') !== false ||
                    strpos($c_clean, 'govt') !== false
                ) {
                    $col_map['state'] = $c_idx;
                }


                /*
                 * SG / TR account.
                 */
                if (
                    strpos($c_clean, 'sg account') !== false ||
                    strpos($c_clean, 'tr code') !== false ||
                    preg_match('/\btr\b/i', $c_clean) ||
                    strpos($c_clean, 'scheme') !== false ||
                    strpos($c_clean, 'account name') !== false ||
                    strpos($c_clean, 'particular') !== false
                ) {
                    $col_map['tr_code'] = $c_idx;
                }


                /*
                 * Amount.
                 */
                if (
                    strpos($c_clean, 'amount') !== false ||
                    strpos($c_clean, 'amt') !== false ||
                    preg_match('/\brs\b/i', $c_clean)
                ) {
                    $col_map['amount'] = $c_idx;
                }


                /*
                 * CG account / UDCH / Head.
                 */
                if (
                    strpos($c_clean, 'cg account') !== false ||
                    strpos($c_clean, 'udch') !== false ||
                    strpos($c_clean, 'head') !== false
                ) {
                    $col_map['cg_code'] = $c_idx;
                }
            }

            break;
        }
    }


    /*
     * Retrieve master scheme TR code mappings.
     */
    $valid_master_map = get_scheme_master_map();

    if (!is_array($valid_master_map)) {
        $valid_master_map = [];
    }


    $parsed_records = [];
    $missing_tr_map = [];


    /*
     * Start after header.
     */
    $start_row =
        ($header_idx >= 0)
            ? $header_idx + 1
            : 0;


    /*
     * Process rows.
     */
    for (
        $i = $start_row;
        $i < count($rows);
        $i++
    ) {

        $row = $rows[$i];

        if (!is_array($row)) {
            continue;
        }


        /*
         * Ignore empty rows.
         */
        $row_has_data = false;

        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                $row_has_data = true;
                break;
            }
        }

        if (!$row_has_data) {
            continue;
        }


        /*
         * Posting date.
         */
        if (isset($col_map['posting_date'])) {
            $p_date_raw =
                isset($row[$col_map['posting_date']])
                    ? $row[$col_map['posting_date']]
                    : '';
        } else {
            $p_date_raw =
                isset($row[0])
                    ? $row[0]
                    : '';
        }


        /*
         * Posting time.
         */
        if (isset($col_map['posting_time'])) {
            $p_time_raw =
                isset($row[$col_map['posting_time']])
                    ? $row[$col_map['posting_time']]
                    : '';
        } else {
            $p_time_raw = '';
        }


        /*
         * Extract posting time accurately.
         */
        $p_time = to_db_time(
            (string) $p_time_raw
        );

        if ($p_time === '00:00:00') {
            $p_time = to_db_time(
                (string) $p_date_raw
            );
        }

        if ($p_time === '00:00:00') {

            foreach ($row as $cell) {

                $chk_time = to_db_time(
                    (string) $cell
                );

                if ($chk_time !== '00:00:00') {
                    $p_time = $chk_time;
                    break;
                }
            }
        }


        /*
         * State.
         */
        if (isset($col_map['state'])) {
            $state_raw =
                isset($row[$col_map['state']])
                    ? $row[$col_map['state']]
                    : '';
        } else {
            $state_raw =
                isset($row[2])
                    ? $row[2]
                    : '';
        }

        $state_clean = mb_substr(
            trim((string) $state_raw),
            0,
            100
        );

        $state =
            ($state_clean !== '')
                ? $state_clean
                : 'N/A';


        /*
         * TR / SG account.
         */
        if (isset($col_map['tr_code'])) {
            $raw_tr =
                isset($row[$col_map['tr_code']])
                    ? $row[$col_map['tr_code']]
                    : '';
        } else {
            $raw_tr =
                isset($row[3])
                    ? $row[3]
                    : '';
        }


        /*
         * Amount.
         */
        if (isset($col_map['amount'])) {
            $amt_raw =
                isset($row[$col_map['amount']])
                    ? $row[$col_map['amount']]
                    : '';
        } else {
            $amt_raw =
                isset($row[4])
                    ? $row[4]
                    : '';
        }


        /*
         * CG code.
         */
        if (isset($col_map['cg_code'])) {
            $cg_raw =
                isset($row[$col_map['cg_code']])
                    ? $row[$col_map['cg_code']]
                    : '';
        } else {
            $cg_raw =
                isset($row[5])
                    ? $row[5]
                    : '';
        }

        $cg_clean = mb_substr(
            trim((string) $cg_raw),
            0,
            100
        );

        $cg_code =
            ($cg_clean !== '')
                ? $cg_clean
                : 'N/A';


        /*
         * Posting date.
         */
        $p_date = to_db_date(
            $p_date_raw
        );

        if (!$p_date) {
            $p_date = $report_date;
        }


        /*
         * Amount.
         */
        $amt_string = trim(
            (string) $amt_raw
        );

        $amt_string = str_replace(
            ',',
            '',
            $amt_string
        );

        $amt = (float) $amt_string;


        /*
         * Extract TR code using helper.
         */
        $extracted_tr = extract_tr_code(
            (string) $raw_tr
        );


        /*
         * Try normalized TR code.
         */
        if (
            !$extracted_tr &&
            trim((string) $raw_tr) !== ''
        ) {

            $clean_raw = strtoupper(
                preg_replace(
                    '/[\s\-\_.]/',
                    '',
                    (string) $raw_tr
                )
            );

            if (
                $clean_raw !== '' &&
                isset($valid_master_map[$clean_raw])
            ) {
                $extracted_tr = $clean_raw;

            } else {

                $trimmed_raw = strtoupper(
                    trim((string) $raw_tr)
                );

                if (
                    $trimmed_raw !== '' &&
                    isset($valid_master_map[$trimmed_raw])
                ) {
                    $extracted_tr = $trimmed_raw;
                }
            }
        }


        /*
         * If TR code was not found in designated column,
         * scan all cells in the row.
         */
        if (
            !$extracted_tr ||
            !isset($valid_master_map[$extracted_tr])
        ) {

            foreach ($row as $cell) {

                $cell_string = (string) $cell;

                /*
                 * Try helper first.
                 */
                $cell_tr = extract_tr_code(
                    $cell_string
                );

                if (
                    $cell_tr &&
                    isset($valid_master_map[$cell_tr])
                ) {
                    $extracted_tr = $cell_tr;
                    break;
                }


                /*
                 * Try normalized value.
                 */
                $cell_clean = strtoupper(
                    preg_replace(
                        '/[\s\-\_.]/',
                        '',
                        $cell_string
                    )
                );

                if (
                    $cell_clean !== '' &&
                    isset($valid_master_map[$cell_clean])
                ) {
                    $extracted_tr = $cell_clean;
                    break;
                }
            }
        }


        /*
         * Check whether TR code exists in master.
         */
        $is_valid =
            (
                $extracted_tr !== null &&
                $extracted_tr !== '' &&
                isset($valid_master_map[$extracted_tr])
            );


        /*
         * Track missing TR codes.
         */
        if (!$is_valid) {

            if ($extracted_tr) {

                $label = display_tr_code(
                    $extracted_tr
                );

            } else {

                $info_parts = [];

                if (trim($state) !== '') {
                    $info_parts[] =
                        'State: ' . trim($state);
                }

                if ($p_date) {
                    $info_parts[] =
                        'Date: ' . format_date($p_date);
                }

                $info_str = implode(
                    ', ',
                    $info_parts
                );

                if ($info_str) {
                    $label =
                        'Blank/Unmapped TR Code (' .
                        $info_str .
                        ')';
                } else {
                    $label =
                        'Blank/Unmapped TR Code';
                }
            }


            if (!isset($missing_tr_map[$label])) {

                $missing_tr_map[$label] = [
                    'amount' => 0.0,
                    'count' => 0
                ];
            }

            $missing_tr_map[$label]['amount'] += $amt;
            $missing_tr_map[$label]['count']++;
        }


        /*
         * Store parsed record.
         */
        $parsed_records[] = [
            'row_num' => $i + 1,
            'p_date' => $p_date,
            'p_time' => $p_time,
            'state' => $state,
            'tr_code' =>
                $extracted_tr
                    ? $extracted_tr
                    : trim((string) $raw_tr),
            'amount' => $amt,
            'cg_code' => $cg_code
        ];
    }


    /*
     * Stop import when TR codes are missing.
     */
    if (!empty($missing_tr_map)) {

        $lines = [];
        $total_missing_amt = 0.0;

        foreach ($missing_tr_map as $label => $info) {

            $amt = (float) $info['amount'];
            $cnt = (int) $info['count'];

            $total_missing_amt += $amt;

            $rec_str =
                ($cnt === 1)
                    ? '(' . $cnt . ' record)'
                    : '(' . $cnt . ' records)';

            $lines[] =
                '- ' .
                $label .
                ' - Rs. ' .
                indian_number($amt) .
                '/- ' .
                $rec_str;
        }

        $tr_details_str = implode(
            "\n",
            $lines
        );

        $total_missing_fmt =
            indian_number($total_missing_amt);

        throw new Exception(
            "First please update these TR codes in Master Table before uploading the file because these TR codes are missing:\n\n" .
            $tr_details_str .
            "\n\n" .
            "Total Missing Amount: Rs. " .
            $total_missing_fmt .
            "/-"
        );
    }


    /*
     * Start database transaction.
     */
    $pdo->beginTransaction();

    $imported_count = 0;

    try {

        $stmt = $pdo->prepare(
            "
            INSERT INTO daily_payment_records (
                source_file_name,
                source_row_number,
                report_date,
                posting_date,
                posting_time,
                state_government,
                sg_account_name,
                amount,
                cg_account_udch_code
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            "
        );


        /*
         * Insert records.
         */
        foreach ($parsed_records as $rec) {

            if (
                !empty($rec['tr_code']) &&
                $rec['amount'] > 0
            ) {

                $stmt->execute([
                    mb_substr(
                        $file_name,
                        0,
                        255
                    ),

                    $rec['row_num'],

                    $report_date,

                    $rec['p_date'],

                    $rec['p_time'],

                    $rec['state'],

                    mb_substr(
                        trim(
                            isset($rec['tr_code'])
                                ? $rec['tr_code']
                                : ''
                        ),
                        0,
                        100
                    ),

                    $rec['amount'],

                    $rec['cg_code']
                ]);

                $imported_count++;
            }
        }


        /*
         * Commit.
         */
        $pdo->commit();

        return $imported_count;


    } catch (Throwable $e) {

        /*
         * Roll back transaction.
         */
        if (
            isset($pdo) &&
            $pdo instanceof PDO &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


/**
 * Get recent uploaded files.
 *
 * @return array
 */
function get_recent_uploads(): array
{
    $pdo = initialize_database();

    $stmt = $pdo->query(
        "
        SELECT
            source_file_name,
            report_date,
            COUNT(*) AS record_count,
            SUM(amount) AS total_amount,
            MAX(created_at) AS uploaded_at
        FROM daily_payment_records
        GROUP BY source_file_name, report_date
        ORDER BY MAX(created_at) DESC
        "
    );

    return $stmt->fetchAll();
}


/**
 * Get records for a particular uploaded file.
 *
 * @param string $source_file_name
 * @param string $report_date
 * @return array
 */
function get_uploaded_file_records(
    string $source_file_name,
    string $report_date
): array {

    $pdo = initialize_database();

    $stmt = $pdo->prepare(
        "
        SELECT
            posting_date,
            posting_time,
            state_government,
            sg_account_name,
            amount,
            cg_account_udch_code
        FROM daily_payment_records
        WHERE source_file_name = ?
          AND report_date = ?
        ORDER BY id ASC
        "
    );

    $stmt->execute([
        $source_file_name,
        $report_date
    ]);

    return $stmt->fetchAll();
}


/**
 * Get daily payment records.
 *
 * @param string|null $start_date
 * @param string|null $end_date
 * @return array
 */
function get_daily_payment_records(
    ?string $start_date = null,
    ?string $end_date = null
): array {

    $pdo = initialize_database();

    $where = [];
    $params = [];


    /*
     * Start date.
     */
    if ($start_date) {

        $start_db = to_db_date(
            $start_date
        );

        if ($start_db) {

            $where[] =
                'posting_date >= ?';

            $params[] = $start_db;
        }
    }


    /*
     * End date.
     */
    if ($end_date) {

        $end_db = to_db_date(
            $end_date
        );

        if ($end_db) {

            $where[] =
                'posting_date <= ?';

            $params[] = $end_db;
        }
    }


    /*
     * WHERE clause.
     */
    $where_sql =
        !empty($where)
            ? 'WHERE ' . implode(' AND ', $where)
            : '';


    /*
     * Query records.
     */
    $stmt = $pdo->prepare(
        "
        SELECT
            id,
            source_file_name,
            report_date,
            posting_date,
            posting_time,
            state_government,
            sg_account_name,
            amount,
            cg_account_udch_code
        FROM daily_payment_records
        $where_sql
        ORDER BY
            posting_date DESC,
            posting_time ASC,
            id ASC
        "
    );

    $stmt->execute($params);

    return $stmt->fetchAll();
}