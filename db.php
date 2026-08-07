<?php
/**
 * Database connection and schema initializer for PHP application.
 */

function get_db_path() {
    $local_dir = __DIR__ . '/data';
    if (!is_dir($local_dir)) {
        mkdir($local_dir, 0777, true);
    }
    return $local_dir . '/daily_reports.db';
}

function get_db_connection() {
    $db_path = get_db_path();
    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function initialize_database() {
    $pdo = get_db_connection();
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_payment_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_file_name TEXT NOT NULL,
            source_row_number INTEGER NOT NULL,
            report_date TEXT NOT NULL,
            posting_date TEXT NOT NULL,
            posting_time TEXT NOT NULL,
            state_government TEXT NOT NULL,
            sg_account_name TEXT NOT NULL,
            amount REAL NOT NULL,
            cg_account_udch_code TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scheme_configuration_master (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_file_name TEXT NOT NULL,
            source_row_number INTEGER NOT NULL,
            controller TEXT,
            css TEXT,
            tr_code TEXT,
            tr_desc TEXT,
            central_share REAL,
            state_share REAL,
            sub_head INTEGER,
            detail_head INTEGER,
            imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS generated_transfer_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            daily_payment_record_id INTEGER NOT NULL UNIQUE,
            sectional_number INTEGER NOT NULL UNIQUE,
            accounting_month TEXT NOT NULL,
            generation_date TEXT NOT NULL,
            pdf_file_name TEXT NOT NULL,
            pdf_content BLOB NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (daily_payment_record_id) REFERENCES daily_payment_records (id)
        );
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_daily_payment_date ON daily_payment_records (posting_date);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_scheme_master_tr_code ON scheme_configuration_master (tr_code);");
    
    return $pdo;
}
