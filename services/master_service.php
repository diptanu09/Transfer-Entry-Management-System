<?php
/**
 * Scheme Configuration Master Service for Transfer Entry Management System.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

const MASTER_PASSWORD = "12345";

function verify_password($password) {
    return ($password === MASTER_PASSWORD);
}

/**
 * Helper function to retrieve all valid TR codes mapped from the master table.
 */
function get_scheme_master_map() {
    $pdo = initialize_database();
    $stmt = $pdo->query("
        WITH ranked_master AS (
            SELECT tr_code, sub_head, detail_head, controller, css, tr_desc, central_share, state_share,
                   ROW_NUMBER() OVER (
                       PARTITION BY tr_code
                       ORDER BY
                            CASE WHEN controller IS NOT NULL OR css IS NOT NULL THEN 0 ELSE 1 END,
                            source_row_number
                   ) AS row_rank
            FROM scheme_configuration_master
        )
        SELECT tr_code, sub_head, detail_head, controller, css, tr_desc, central_share, state_share
        FROM ranked_master
        WHERE row_rank = 1
    ");
    
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $m) {
        if (!empty($m['tr_code']) && $m['sub_head'] !== null && $m['detail_head'] !== null) {
            $map[strtoupper(trim($m['tr_code']))] = $m;
        }
    }
    return $map;
}

function get_master_records($search_query = null) {
    $pdo = initialize_database();
    $where = [];
    $params = [];

    if ($search_query && trim($search_query) !== '') {
        $q = '%' . trim($search_query) . '%';
        $where[] = "(LOWER(tr_code) LIKE LOWER(?) OR LOWER(tr_desc) LIKE LOWER(?) OR LOWER(controller) LIKE LOWER(?) OR LOWER(css) LIKE LOWER(?))";
        $params = [$q, $q, $q, $q];
    }

    $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    $stmt = $pdo->prepare("
        SELECT id, controller, css, tr_code, tr_desc, central_share, state_share, sub_head, detail_head, imported_at
        FROM scheme_configuration_master
        $where_sql
        ORDER BY tr_code ASC, id ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function add_master_record($data, $password) {
    if (!verify_password($password)) {
        throw new Exception("Incorrect password! Master record changes require password '12345'.");
    }

    $tr_code = strtoupper(trim($data['tr_code'] ?? ''));
    if (empty($tr_code)) {
        throw new Exception("TR Code is required.");
    }

    $pdo = initialize_database();
    $next_row_stmt = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM scheme_configuration_master");
    $next_row_num = $next_row_stmt->fetchColumn();

    $stmt = $pdo->prepare("
        INSERT INTO scheme_configuration_master (
            source_file_name, source_row_number, controller, css,
            tr_code, tr_desc, central_share, state_share, sub_head, detail_head
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        "MANUAL_ENTRY",
        $next_row_num,
        trim($data['controller'] ?? '') ?: null,
        trim($data['css'] ?? '') ?: null,
        $tr_code,
        trim($data['tr_desc'] ?? '') ?: null,
        ($data['central_share'] !== '' && $data['central_share'] !== null) ? (float)$data['central_share'] : 100.0,
        ($data['state_share'] !== '' && $data['state_share'] !== null) ? (float)$data['state_share'] : 0.0,
        ($data['sub_head'] !== '' && $data['sub_head'] !== null) ? (int)$data['sub_head'] : null,
        ($data['detail_head'] !== '' && $data['detail_head'] !== null) ? (int)$data['detail_head'] : null,
    ]);

    $pdo->commit();
    return $pdo->lastInsertId();
}

function update_master_record($id, $data, $password) {
    if (!verify_password($password)) {
        throw new Exception("Incorrect password! Master record changes require password '12345'.");
    }

    $tr_code = strtoupper(trim($data['tr_code'] ?? ''));
    if (empty($tr_code)) {
        throw new Exception("TR Code is required.");
    }

    $pdo = initialize_database();
    $stmt = $pdo->prepare("
        UPDATE scheme_configuration_master
        SET controller = ?,
            css = ?,
            tr_code = ?,
            tr_desc = ?,
            central_share = ?,
            state_share = ?,
            sub_head = ?,
            detail_head = ?
        WHERE id = ?
    ");

    $stmt->execute([
        trim($data['controller'] ?? '') ?: null,
        trim($data['css'] ?? '') ?: null,
        $tr_code,
        trim($data['tr_desc'] ?? '') ?: null,
        ($data['central_share'] !== '' && $data['central_share'] !== null) ? (float)$data['central_share'] : 100.0,
        ($data['state_share'] !== '' && $data['state_share'] !== null) ? (float)$data['state_share'] : 0.0,
        ($data['sub_head'] !== '' && $data['sub_head'] !== null) ? (int)$data['sub_head'] : null,
        ($data['detail_head'] !== '' && $data['detail_head'] !== null) ? (int)$data['detail_head'] : null,
        (int)$id
    ]);
    $pdo->commit();
}

function delete_master_record($id, $password) {
    if (!verify_password($password)) {
        throw new Exception("Incorrect password! Master record changes require password '12345'.");
    }

    $pdo = initialize_database();
    $stmt = $pdo->prepare("DELETE FROM scheme_configuration_master WHERE id = ?");
    $stmt->execute([(int)$id]);
    $pdo->commit();
}