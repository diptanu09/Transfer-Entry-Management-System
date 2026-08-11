<?php
/**
 * Scheme Configuration Master Service for Transfer Entry Management System.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

/**
 * Helper function to retrieve all valid TR codes mapped from the master table.
 *
 * @return array
 */
function get_scheme_master_map(): array {
    $pdo = initialize_database();
    $stmt = $pdo->query("
        SELECT tr_code, sub_head, detail_head, controller, css, tr_desc, central_share, state_share
        FROM scheme_configuration_master
    ");

    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $m) {
        if (!empty($m['tr_code'])) {
            $raw_tr = trim($m['tr_code']);
            $map[strtoupper($raw_tr)] = $m;

            $extracted = extract_tr_code($raw_tr);
            if ($extracted) {
                $map[$extracted] = $m;
                $map[display_tr_code($extracted)] = $m;
            }

            $clean = strtoupper(preg_replace('/[\s\-_.]/', '', $raw_tr));
            if ($clean !== '') {
                $map[$clean] = $m;
            }
        }
    }
    return $map;
}

/**
 * @param string|null $search_query
 * @return array
 */
function get_master_records(?string $search_query = null): array {
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
    $records = $stmt->fetchAll();

    usort($records, function($a, $b) {
        return strnatcasecmp($a['tr_code'] ?? '', $b['tr_code'] ?? '');
    });

    return $records;
}

/**
 * @param array $data
 * @return int
 * @throws Exception
 */
function add_master_record(array $data): int {
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

/**
 * @param int|string $id
 * @param array $data
 * @return void
 * @throws Exception
 */
function update_master_record($id, array $data): void {
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

/**
 * @param int|string $id
 * @return void
 * @throws Exception
 */
function delete_master_record($id): void {
    $pdo = initialize_database();
    $stmt = $pdo->prepare("DELETE FROM scheme_configuration_master WHERE id = ?");
    $stmt->execute([(int)$id]);
    $pdo->commit();
}