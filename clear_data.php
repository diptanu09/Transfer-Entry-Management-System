<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

try {
    $pdo = get_db_connection();

    // 1. Delete generated reports
    try {
        $pdo->exec("TRUNCATE TABLE GENERATED_TRANSFER_REPORTS");
    } catch (Throwable $e1) {
        $pdo->exec("DELETE FROM GENERATED_TRANSFER_REPORTS");
    }

    // 2. Delete daily payment records
    try {
        $pdo->exec("TRUNCATE TABLE DAILY_PAYMENT_RECORDS");
    } catch (Throwable $e2) {
        $pdo->exec("DELETE FROM DAILY_PAYMENT_RECORDS");
    }

    // 3. Reset sequences if Oracle 11g user_sequences exist
    try {
        $stmtSeq = $pdo->prepare("SELECT sequence_name FROM user_sequences");
        $stmtSeq->execute();
        $seqs = $stmtSeq->fetchAll();

        foreach ($seqs as $s) {
            $sName = strtoupper($s['sequence_name'] ?? '');
            if (strpos($sName, 'DAILY') !== false || strpos($sName, 'GENERATED') !== false || strpos($sName, 'REPORT') !== false) {
                try {
                    $pdo->exec("DROP SEQUENCE {$sName}");
                    $pdo->exec("CREATE SEQUENCE {$sName} START WITH 1 INCREMENT BY 1 NOCACHE");
                } catch (Throwable $exSeq) {
                    // Ignore drop error
                }
            }
        }
    } catch (Throwable $exOracleSeq) {
        // Ignore if user_sequences table doesn't exist (e.g. SQLite mode)
    }

    // Reset IDENTITY columns if 12c+ IDENTITY columns are used
    try {
        $pdo->exec("ALTER TABLE GENERATED_TRANSFER_REPORTS MODIFY (id GENERATED AS IDENTITY (START WITH 1))");
    } catch (Throwable $exId1) {}
    
    try {
        $pdo->exec("ALTER TABLE DAILY_PAYMENT_RECORDS MODIFY (id GENERATED AS IDENTITY (START WITH 1))");
    } catch (Throwable $exId2) {}

    // Reset SQLite auto-increment sequences
    try {
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('daily_payment_records', 'generated_transfer_reports')");
    } catch (Throwable $exSqlite) {}

    // 4. Remove physical PDF files from data/pdf_reports directory
    $pdf_dir = __DIR__ . '/data/pdf_reports';
    $deleted_files = 0;
    if (is_dir($pdf_dir)) {
        $files = glob($pdf_dir . '/*');
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
                $deleted_files++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'All transaction data and generated PDF files have been cleared successfully. IDs have been reset. Master table (SCHEME_CONFIGURATION_MASTER) and USERS remain untouched.',
        'deleted_pdf_files' => $deleted_files
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
