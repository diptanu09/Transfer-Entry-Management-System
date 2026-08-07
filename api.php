<?php
/**
 * RESTful JSON API Router for PHP Application.
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

// -------------------------------------------------------------------
// 1. User Registration Action
// -------------------------------------------------------------------
if ($action === 'register') {
    $username  = strtolower(trim($_POST['username'] ?? ''));
    $password  = trim($_POST['password'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));

    if (empty($username) || empty($password)) {
        send_error("Username and Password are required.", 400);
    }

    if (strlen($password) < 4) {
        send_error("Password must be at least 4 characters long.", 400);
    }

    $pdo = get_db_connection();

    // Check if username already exists
    $check_stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = ?");
    $check_stmt->execute([$username]);
    if ($check_stmt->fetch()) {
        send_error("Username '{$username}' is already registered. Please choose another.", 409);
    }

    // Encrypt password using BCRYPT
    $hash = password_hash($password, PASSWORD_BCRYPT);

    $ins_stmt = $pdo->prepare("
        INSERT INTO users (username, password_hash, full_name, email, role)
        VALUES (?, ?, ?, ?, 'USER')
    ");
    $ins_stmt->execute([$username, $hash, $full_name, $email]);


    $_SESSION['user'] = $username;
    $_SESSION['full_name'] = $full_name ?: $username;

    send_json([
        'success' => true,
        'message' => 'Registration successful!',
        'user'    => $username,
        'full_name' => $_SESSION['full_name']
    ]);
}

// -------------------------------------------------------------------
// 2. Secure User Login Action (Database + password_verify)
// -------------------------------------------------------------------
if ($action === 'login') {
    $user = strtolower(trim($_POST['user_id'] ?? $_POST['username'] ?? ''));
    $pwd  = trim($_POST['password'] ?? '');

    if (empty($user) || empty($pwd)) {
        send_error("Username and Password are required.", 400);
    }

    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT username, password_hash, full_name, role FROM users WHERE LOWER(username) = ?");
    $stmt->execute([$user]);
    $row = $stmt->fetch();

    if (!$row) {
        send_error("User '{$user}' not found in database!", 401);
    }

    $stored_hash = $row['password_hash'] ?? '';

    // Check if the stored hash was truncated by Oracle
    if (strlen($stored_hash) < 60) {
        send_error("Stored password hash is corrupted/truncated (" . strlen($stored_hash) . " chars). Please alter USERS table and re-register.", 500);
    }

    if (password_verify($pwd, $stored_hash)) {
        $_SESSION['user']      = $row['username'];
        $_SESSION['full_name'] = $row['full_name'] ?: $row['username'];
        $_SESSION['role']      = $row['role'] ?? 'USER';

        send_json([
            'success'   => true,
            'user'      => $row['username'],
            'full_name' => $_SESSION['full_name'],
            'role'      => $_SESSION['role']
        ]);
    } else {
        send_error("Incorrect password for user '{$user}'.", 401);
    }
}

// -------------------------------------------------------------------
// 3. User Logout Action
// -------------------------------------------------------------------
if ($action === 'logout') {
    session_unset();
    session_destroy();
    send_json(['success' => true]);
}

// -------------------------------------------------------------------
// 4. Session Check Action
// -------------------------------------------------------------------
if ($action === 'check_auth') {
    send_json([
        'authenticated' => !empty($_SESSION['user']),
        'user'          => $_SESSION['user'] ?? null,
        'full_name'     => $_SESSION['full_name'] ?? null
    ]);
}

// Protect all remaining application endpoints
if (empty($_SESSION['user'])) {
    send_error("Authentication required. Please log in.", 401);
}

try {
    switch ($action) {
        case 'upload_file':
            if (empty($_FILES['file'])) {
                send_error("No file uploaded.");
            }
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

        case 'generate_pdfs':
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

        case 'add_master_record':
            $pwd = $_POST['password'] ?? '';
            $id = add_master_record($_POST, $pwd);
            send_json(['success' => true, 'id' => $id]);
            break;

        case 'update_master_record':
            $pwd = $_POST['password'] ?? '';
            $id = $_POST['id'] ?? 0;
            update_master_record($id, $_POST, $pwd);
            send_json(['success' => true]);
            break;

        case 'delete_master_record':
            $pwd = $_POST['password'] ?? '';
            $id = $_POST['id'] ?? 0;
            delete_master_record($id, $pwd);
            send_json(['success' => true]);
            break;

        default:
            send_error("Invalid or missing action parameter.");
    }
} catch (Throwable $e) {
    send_error($e->getMessage());
}