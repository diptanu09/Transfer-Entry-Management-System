<?php
/**
 * RESTful JSON API Router for Transfer Entry Management System.
 * Supports RBAC (ADMIN, OPERATOR, VIEWER) & Admin Approval Workflow.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services/upload_service.php';
require_once __DIR__ . '/services/master_service.php';
require_once __DIR__ . '/services/transfer_service.php';
require_once __DIR__ . '/services/summary_service.php';
require_once __DIR__ . '/services/detailed_service.php';

session_start();

$action = $_REQUEST['action'] ?? '';

function send_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function send_error($message, $status = 400) {
    send_json(['success' => false, 'error' => $message], $status);
}

function require_role($allowed_roles) {
    $current_role = $_SESSION['role'] ?? 'VIEWER';
    if (!in_array($current_role, $allowed_roles)) {
        send_error("Access Denied: Your role ({$current_role}) does not have permission to perform this action.", 403);
    }
}

// -------------------------------------------------------------------
// 1. REGISTER ACTION (Requires Admin Approval before login)
// -------------------------------------------------------------------
if ($action === 'register') {
    $username  = strtolower(trim($_POST['username'] ?? ''));
    $password  = trim($_POST['password'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $req_role  = strtoupper(trim($_POST['requested_role'] ?? 'OPERATOR'));

    if (!in_array($req_role, ['OPERATOR', 'VIEWER'])) {
        $req_role = 'OPERATOR';
    }

    if (empty($username) || empty($password)) {
        send_error("Username and Password are required.", 400);
    }

    if (strlen($password) < 4) {
        send_error("Password must be at least 4 characters long.", 400);
    }

    $pdo = get_db_connection();

    // Check duplicate username
    $check_stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = ?");
    $check_stmt->execute([$username]);
    if ($check_stmt->fetch()) {
        send_error("Username '{$username}' is already registered.", 409);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    $ins_stmt = $pdo->prepare("
        INSERT INTO users (username, password_hash, full_name, email, role, is_approved, status)
        VALUES (?, ?, ?, ?, ?, 0, 'PENDING')
    ");
    $ins_stmt->execute([$username, $hash, $full_name, $email, $req_role]);
    $pdo->commit();

    send_json([
        'success' => true,
        'message' => 'Registration submitted successfully! Please wait for an Administrator to approve your account before logging in.'
    ]);
}

// -------------------------------------------------------------------
// 2. LOGIN ACTION (Verifies Approval Status & Role)
// -------------------------------------------------------------------
if ($action === 'login') {
    $user = strtolower(trim($_POST['user_id'] ?? $_POST['username'] ?? ''));
    $pwd  = trim($_POST['password'] ?? '');

    if (empty($user) || empty($pwd)) {
        send_error("Username and Password are required.", 400);
    }

    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT username, password_hash, full_name, role, is_approved, status FROM users WHERE LOWER(username) = ?");
    $stmt->execute([$user]);
    $row = $stmt->fetch();

    if (!$row) {
        send_error("Invalid Username or Password!", 401);
    }

    // Check approval status
    $is_approved = (int)($row['is_approved'] ?? 0);
    $status      = strtoupper($row['status'] ?? 'PENDING');

    if ($is_approved !== 1 || $status !== 'APPROVED') {
        send_error("Account Pending Approval: Your account has not been approved by an Administrator yet.", 403);
    }

    if (password_verify($pwd, $row['password_hash'])) {
        $_SESSION['user']      = $row['username'];
        $_SESSION['full_name'] = $row['full_name'] ?: $row['username'];
        $_SESSION['role']      = strtoupper($row['role'] ?? 'VIEWER');

        send_json([
            'success'   => true,
            'user'      => $row['username'],
            'full_name' => $_SESSION['full_name'],
            'role'      => $_SESSION['role']
        ]);
    } else {
        send_error("Invalid Username or Password!", 401);
    }
}

// -------------------------------------------------------------------
// 3. LOGOUT & CHECK AUTH
// -------------------------------------------------------------------
if ($action === 'logout') {
    session_unset();
    session_destroy();
    send_json(['success' => true]);
}

if ($action === 'check_auth') {
    send_json([
        'authenticated' => !empty($_SESSION['user']),
        'user'          => $_SESSION['user'] ?? null,
        'full_name'     => $_SESSION['full_name'] ?? null,
        'role'          => $_SESSION['role'] ?? 'VIEWER'
    ]);
}

// Protect remaining actions
if (empty($_SESSION['user'])) {
    send_error("Authentication required. Please log in.", 401);
}

try {
    switch ($action) {
        // =========================================================
        // USER MANAGEMENT ENDPOINTS (ADMIN ONLY)
        // =========================================================
        case 'get_users_list':
            require_role(['ADMIN']);
            $pdo = get_db_connection();
            $stmt = $pdo->query("SELECT id, username, full_name, email, role, is_approved, status, created_at FROM users ORDER BY is_approved ASC, id DESC");
            send_json(['success' => true, 'users' => $stmt->fetchAll()]);
            break;

        case 'approve_user':
            require_role(['ADMIN']);
            $user_id = (int)($_POST['user_id'] ?? 0);
            $role    = strtoupper(trim($_POST['role'] ?? 'OPERATOR'));

            if (!$user_id) send_error("User ID is required.");
            if (!in_array($role, ['ADMIN', 'OPERATOR', 'VIEWER'])) $role = 'OPERATOR';

            $pdo = get_db_connection();
            $stmt = $pdo->prepare("UPDATE users SET is_approved = 1, status = 'APPROVED', role = ? WHERE id = ?");
            $stmt->execute([$role, $user_id]);
            $pdo->commit();
            send_json(['success' => true, 'message' => 'User approved successfully.']);
            break;

        case 'update_user_role':
            require_role(['ADMIN']);
            $user_id = (int)($_POST['user_id'] ?? 0);
            $role    = strtoupper(trim($_POST['role'] ?? 'OPERATOR'));

            if (!$user_id) send_error("User ID is required.");
            if (!in_array($role, ['ADMIN', 'OPERATOR', 'VIEWER'])) send_error("Invalid role.");

            $pdo = get_db_connection();
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$role, $user_id]);
            $pdo->commit();
            send_json(['success' => true, 'message' => 'User role updated successfully.']);
            break;

        case 'reject_user':
            require_role(['ADMIN']);
            $user_id = (int)($_POST['user_id'] ?? 0);
            if (!$user_id) send_error("User ID is required.");

            $pdo = get_db_connection();
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $pdo->commit();
            send_json(['success' => true, 'message' => 'User account removed.']);
            break;

        // =========================================================
        // OPERATOR & ADMIN ACTIONS (File Upload, Generate PDF, Master Changes)
        // =========================================================
        case 'upload_file':
            require_role(['ADMIN', 'OPERATOR']);
            if (empty($_FILES['file'])) send_error("No file uploaded.");
            $report_date = $_POST['report_date'] ?? date('d/m/Y');
            $file = $_FILES['file'];
            $count = import_daily_payment_file($file['tmp_name'], $file['name'], $report_date);
            $records = get_uploaded_file_records($file['name'], to_db_date($report_date));
            $total_amount = array_reduce($records, function($sum, $item) { return $sum + ($item['amount'] ?? 0); }, 0);
            send_json([
                'success' => true,
                'count' => $count,
                'total_amount' => $total_amount,
                'source_file_name' => $file['name'],
                'report_date' => $report_date
            ]);
            break;

        case 'generate_pdfs':
            require_role(['ADMIN', 'OPERATOR']);
            $from_date = $_POST['from_date'] ?? '';
            $to_date = $_POST['to_date'] ?? '';
            $acct_month = $_POST['accounting_month'] ?? '';
            $sec_num = isset($_POST['sectional_number']) ? (int)$_POST['sectional_number'] : null;

            $res = generate_transfer_reports($from_date, $to_date, $acct_month, $sec_num);
            send_json([
                'success' => true,
                'generated' => $res['generated'],
                'skipped' => $res['skipped'],
                'warnings' => $res['warnings'],
                'next_sectional_number' => get_next_sectional_number()
            ]);
            break;

        case 'add_master_record':
        case 'update_master_record':
        case 'delete_master_record':
            require_role(['ADMIN', 'OPERATOR']);
            $pwd = $_POST['password'] ?? '';
            if ($action === 'add_master_record') {
                $id = add_master_record($_POST, $pwd);
                send_json(['success' => true, 'id' => $id]);
            } elseif ($action === 'update_master_record') {
                $id = $_POST['id'] ?? 0;
                update_master_record($id, $_POST, $pwd);
                send_json(['success' => true]);
            } else {
                $id = $_POST['id'] ?? 0;
                delete_master_record($id, $pwd);
                send_json(['success' => true]);
            }
            break;

        // =========================================================
        // READ-ONLY ACTIONS (Allowed for VIEWER, OPERATOR, ADMIN)
        // =========================================================
        case 'get_recent_uploads':
            send_json(['success' => true, 'uploads' => get_recent_uploads()]);
            break;

        case 'get_uploaded_file_records':
            $source_file = $_GET['source_file_name'] ?? '';
            $report_date = $_GET['report_date'] ?? '';
            send_json(['success' => true, 'records' => get_uploaded_file_records($source_file, to_db_date($report_date))]);
            break;

        case 'get_view_data':
            $start = $_GET['start_date'] ?? null;
            $end = $_GET['end_date'] ?? null;
            send_json(['success' => true, 'records' => get_daily_payment_records($start, $end)]);
            break;

        case 'get_next_sectional_number':
            send_json(['success' => true, 'next_sectional_number' => get_next_sectional_number()]);
            break;

        case 'get_pdf_list':
            $start = $_GET['start_date'] ?? null;
            $end = $_GET['end_date'] ?? null;
            send_json(['success' => true, 'reports' => get_generated_reports($start, $end)]);
            break;

        case 'download_pdf':
            $id = $_GET['id'] ?? 0;
            download_generated_pdf($id);
            break;

        case 'get_summary_report':
            $type = $_GET['filter_type'] ?? 'month';
            $from = $_GET['from_date'] ?? null;
            $to = $_GET['to_date'] ?? null;
            $month = $_GET['accounting_month'] ?? null;
            $fy = $_GET['financial_year_val'] ?? null;

            list($records, $desc) = get_summary_report_data($type, $from, $to, $month, $fy);
            send_json(['success' => true, 'records' => $records, 'description' => $desc]);
            break;

        case 'export_summary_excel':
            $type = $_GET['filter_type'] ?? 'month';
            $from = $_GET['from_date'] ?? null;
            $to = $_GET['to_date'] ?? null;
            $month = $_GET['accounting_month'] ?? null;
            $fy = $_GET['financial_year_val'] ?? null;

            list($records, $desc) = get_summary_report_data($type, $from, $to, $month, $fy);
            export_summary_excel($records, $desc);
            break;

        case 'get_detailed_report':
            $type = $_GET['filter_type'] ?? 'date';
            $from = $_GET['from_date'] ?? null;
            $to = $_GET['to_date'] ?? null;
            $month = $_GET['accounting_month'] ?? null;
            $fy = $_GET['financial_year_val'] ?? null;

            list($records, $desc) = get_transfer_entry_report_data($type, $from, $to, $month, $fy);
            send_json(['success' => true, 'records' => $records, 'description' => $desc]);
            break;

        case 'export_detailed_excel':
            $type = $_GET['filter_type'] ?? 'date';
            $from = $_GET['from_date'] ?? null;
            $to = $_GET['to_date'] ?? null;
            $month = $_GET['accounting_month'] ?? null;
            $fy = $_GET['financial_year_val'] ?? null;

            list($records, $desc) = get_transfer_entry_report_data($type, $from, $to, $month, $fy);
            export_detailed_excel($records, $desc);
            break;

        case 'get_master_records':
            $q = $_GET['search'] ?? null;
            send_json(['success' => true, 'records' => get_master_records($q)]);
            break;

        default:
            send_error("Invalid or missing action parameter.");
    }
} catch (Throwable $e) {
    send_error($e->getMessage());
}