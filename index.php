<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
initialize_database();
$today_str = date('d/m/Y');
$current_fy = financial_year(date('Y-m-d'));
$current_month = date('m/Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CSS (SNA SPARSH) Transfer Entry Application</title>
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>

  <!-- Top Header -->
  <header class="header">
    <div>
      <h1>CSS (SNA SPARSH) Transfer Entry Management System</h1>
      <p>Windows & Web Transfer Entry Daily Payments, Scheme Master & Analytical Report Suite</p>
    </div>
    <div id="user-info-area" style="display: flex; align-items: center; gap: 12px;">
      <span id="logged-user-name" style="font-weight: 600; font-size: 0.95rem; background: rgba(255,255,255,0.2); padding: 6px 14px; border-radius: 20px;">User: amit</span>
      <button onclick="handleLogout()" class="btn btn-secondary" style="padding: 6px 14px; font-size: 0.85rem;">Logout</button>
    </div>
  </header>

  <div class="main-container">
    <!-- Tab Navigation -->
    <nav class="tabs-header">
      <button class="tab-btn active" data-tab="tab-upload">📤 Upload Daily File</button>
      <button class="tab-btn" data-tab="tab-view-data">📊 View Uploaded Data</button>
      <button class="tab-btn" data-tab="tab-generate">📄 Generate PDF Reports</button>
      <button class="tab-btn" data-tab="tab-view-pdf">📁 View PDFs</button>
      <button class="tab-btn" data-tab="tab-summary">📈 Summary Reports</button>
      <button class="tab-btn" data-tab="tab-detailed">📋 Transfer Entry Report</button>
      <button class="tab-btn" data-tab="tab-master">⚙️ Scheme Config Master</button>
      <button class="tab-btn" data-tab="tab-users" id="nav-tab-users" style="display: none;">👥 User Approvals & Roles</button>
    </nav>

    <!-- Tab 1: Upload Daily File -->
    <div id="tab-upload" class="tab-content active">
      <div class="card">
        <h2 class="card-title">Daily Payment File Upload</h2>
        <p class="card-subtitle">Upload your daily .xls, .xlsx, or .csv payment file to the SQLite database.</p>

        <form id="upload-form">
          <div class="form-grid">
            <div class="form-group">
              <label>Daily File (.csv, .xls, .xlsx)</label>
              <input type="file" id="upload-file" class="form-control" accept=".csv,.xls,.xlsx" required>
            </div>
            <div class="form-group">
              <label>Report Date (DD/MM/YYYY)</label>
              <input type="text" id="upload-date" class="form-control" value="<?= $today_str ?>" required>
            </div>
          </div>
          <button type="submit" class="btn btn-primary">Upload to Database</button>
        </form>

        <div id="upload-status" style="margin-top: 14px; font-weight: 600; color: var(--success);"></div>
      </div>

      <div class="card">
        <h3 class="card-title">Recent Uploads</h3>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Report Date</th>
                <th>Source File</th>
                <th align="right">Records</th>
                <th align="right">Total Amount</th>
                <th>Uploaded At</th>
              </tr>
            </thead>
            <tbody id="recent-uploads-body">
              <tr><td colspan="5" align="center">Loading recent uploads...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tab 2: View Uploaded Data -->
    <div id="tab-view-data" class="tab-content">
      <div class="card">
        <h2 class="card-title">View Uploaded Data</h2>
        <p class="card-subtitle">View and filter raw payment records stored in the database.</p>

        <div class="form-grid">
          <div class="form-group">
            <label>From Date (DD/MM/YYYY)</label>
            <input type="text" id="view-start-date" class="form-control" value="<?= $today_str ?>">
          </div>
          <div class="form-group">
            <label>To Date (DD/MM/YYYY)</label>
            <input type="text" id="view-end-date" class="form-control" value="<?= $today_str ?>">
          </div>
          <div class="form-group" style="justify-content: flex-end;">
            <button id="btn-filter-view-data" class="btn btn-primary">Filter Records</button>
          </div>
        </div>

        <div id="view-data-summary" style="margin-bottom: 12px; font-weight: 600; color: var(--primary);"></div>

        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Posting Date/Time</th>
                <th>State Government</th>
                <th>TR Code</th>
                <th align="right">Amount</th>
                <th>CG Account UDCH</th>
                <th>Source File</th>
              </tr>
            </thead>
            <tbody id="view-data-body">
              <tr><td colspan="6" align="center">Click 'Filter Records' to view payment data.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tab 3: Generate PDF Reports -->
    <div id="tab-generate" class="tab-content">
      <div class="card">
        <h2 class="card-title">Generate Transfer Entry PDFs</h2>
        <p class="card-subtitle">One PDF is created for each unreported daily entry. All TR codes are validated against Scheme Config Master before generation.</p>

        <div class="form-grid">
          <div class="form-group">
            <label>From Posting Date</label>
            <input type="text" id="gen-from-date" class="form-control" value="<?= $today_str ?>">
          </div>
          <div class="form-group">
            <label>To Posting Date</label>
            <input type="text" id="gen-to-date" class="form-control" value="<?= $today_str ?>">
          </div>
          <div class="form-group">
            <label>Accounting Month (MM/YYYY) <span style="color:red;">*</span></label>
            <input type="text" id="gen-acct-month" class="form-control" value="<?= $current_month ?>" required placeholder="MM/YYYY">
          </div>
          <div class="form-group">
            <label>Starting Sectional Number <span style="color:red;">*</span></label>
            <input type="number" id="gen-sec-num" class="form-control" value="1" min="1" required>
          </div>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 10px; align-items: center; flex-wrap: wrap;">
          <button id="btn-generate-pdfs" class="btn btn-primary">Generate PDFs</button>
          <a id="btn-download-merged-gen" href="#" target="_blank" class="btn btn-success" style="display: none; text-decoration: none;">📥 Open Single Merged PDF (Vouchers + Summary Annexure)</a>
        </div>

        <div id="gen-status" style="margin-top: 16px; font-weight: 600; color: var(--primary);"></div>
      </div>
    </div>

    <!-- Tab 4: View PDFs -->
    <div id="tab-view-pdf" class="tab-content">
      <div class="card">
        <h2 class="card-title">View & Download PDFs</h2>
        <p class="card-subtitle">View individual TE vouchers or download a single merged PDF containing all TE vouchers with Summary Report in the last page.</p>

        <div class="form-grid">
          <div class="form-group">
            <label>From Date</label>
            <input type="text" id="pdf-start-date" class="form-control" value="">
          </div>
          <div class="form-group">
            <label>To Date</label>
            <input type="text" id="pdf-end-date" class="form-control" value="">
          </div>
          <div class="form-group" style="justify-content: flex-end; gap: 10px; display: flex; align-items: flex-end;">
            <button id="btn-filter-pdf" class="btn btn-primary">Filter Reports</button>
            <button id="btn-download-merged-view" class="btn btn-success">📥 Download Single Merged PDF</button>
          </div>
        </div>

        <div class="table-responsive" style="margin-top: 16px;">
          <table class="table">
            <thead>
              <tr>
                <th>Sectional No.</th>
                <th>Posting Date</th>
                <th>TR Code</th>
                <th align="right">Amount</th>
                <th>Accounting Month</th>
                <th>Generated Date</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="pdf-list-body">
              <tr><td colspan="7" align="center">Click 'Filter Reports' to view generated PDFs.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tab 5: Summary Reports -->
    <div id="tab-summary" class="tab-content">
      <div class="card">
        <h2 class="card-title">Summary & Analytical Reports</h2>
        <p class="card-subtitle">Generate date-wise, accounting month-wise, or financial year-wise reports with central/state share calculations.</p>

        <div style="display: flex; gap: 16px; margin-bottom: 16px;">
          <label><input type="radio" name="summary-mode" value="date"> Date Range</label>
          <label><input type="radio" name="summary-mode" value="month" checked> Accounting Month</label>
          <label><input type="radio" name="summary-mode" value="fy"> Financial Year</label>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label>From Date</label>
            <input type="text" id="sum-from-date" class="form-control" value="<?= $today_str ?>">
          </div>
          <div class="form-group">
            <label>To Date</label>
            <input type="text" id="sum-to-date" class="form-control" value="<?= $today_str ?>">
          </div>
          <div class="form-group">
            <label>Accounting Month</label>
            <input type="text" id="sum-acct-month" class="form-control" value="<?= $current_month ?>">
          </div>
          <div class="form-group">
            <label>Financial Year</label>
            <input type="text" id="sum-fy" class="form-control" value="<?= $current_fy ?>">
          </div>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap;">
          <button id="btn-load-summary" class="btn btn-primary">Load Report</button>
          <button id="btn-excel-summary" class="btn btn-secondary">Export Excel (.xls)</button>
          <button id="btn-pdf-summary" class="btn btn-success">📥 Download Single Merged PDF Report</button>
        </div>

        <div id="summary-status" style="margin-top: 14px; font-weight: 600; color: var(--primary);"></div>

        <div class="table-responsive" style="margin-top: 16px;">
          <table class="table">
            <thead>
              <tr>
                <th align="center">S.No.</th>
                <th>Name of Ministry</th>
                <th align="center">TR No.</th>
                <th>TR Description</th>
                <th align="right">Total Amount (Rs.)</th>
                <th align="right">Central Share (Rs.)</th>
                <th align="right">State Share (Rs.)</th>
                <th align="center">Sub Head</th>
                <th align="center">Detail Head</th>
                <th align="center">Sectional No.</th>
                <th align="center">Date</th>
              </tr>
            </thead>
            <tbody id="summary-body">
              <tr><td colspan="11" align="center">Select filter and click 'Load Report'.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tab 6: Transfer Entry Detailed Report -->
    <div id="tab-detailed" class="tab-content">
      <div class="card">
        <h2 class="card-title">Transfer Entry Detailed Report Generator</h2>
        <p class="card-subtitle">Generate 18-column detailed Transfer Entry reports with debit/credit major, minor, sub & detail heads.</p>

        <div style="display: flex; gap: 16px; margin-bottom: 16px;">
          <label><input type="radio" name="detailed-mode" value="date" checked> Date Range</label>
          <label><input type="radio" name="detailed-mode" value="month"> Accounting Month</label>
          <label><input type="radio" name="detailed-mode" value="fy"> Financial Year</label>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label>From Date</label>
            <input type="text" id="det-from-date" class="form-control" value="<?= $today_str ?>">
          </div>
          <div class="form-group">
            <label>To Date</label>
            <input type="text" id="det-to-date" class="form-control" value="<?= $today_str ?>">
          </div>
          <div class="form-group">
            <label>Accounting Month</label>
            <input type="text" id="det-acct-month" class="form-control" value="<?= $current_month ?>">
          </div>
          <div class="form-group">
            <label>Financial Year</label>
            <input type="text" id="det-fy" class="form-control" value="<?= $current_fy ?>">
          </div>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 10px;">
          <button id="btn-load-detailed" class="btn btn-primary">Load Report</button>
          <button id="btn-excel-detailed" class="btn btn-secondary">Export Excel (.xls)</button>
        </div>

        <div id="detailed-status" style="margin-top: 14px; font-weight: 600; color: var(--primary);"></div>

        <div class="table-responsive" style="margin-top: 16px;">
          <table class="table">
            <thead>
              <tr>
                <th>Major (Dr)</th><th>Sub Major (Dr)</th><th>Minor (Dr)</th><th>Sub (Dr)</th><th>Detail (Dr)</th><th>Sub Detail (Dr)</th>
                <th align="right">Total Amount (Rs.)</th>
                <th>Major (Cr)</th><th>Sub Major (Cr)</th><th>Minor (Cr)</th><th>Sub (Cr)</th><th>Detail (Cr)</th><th>Sub Detail (Cr)</th>
                <th>Sectional No.</th><th>TR No.</th><th>TR Description</th><th>Ministry</th><th>Date</th>
              </tr>
            </thead>
            <tbody id="detailed-body">
              <tr><td colspan="18" align="center">Select filter and click 'Load Report'.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tab 7: Scheme Config Master -->
    <div id="tab-master" class="tab-content">
      <div class="card">
        <h2 class="card-title">Scheme Configuration Master Data</h2>
        <p class="card-subtitle">Manage CSS scheme mapping entries. Edits permitted for Admin users only.</p>

        <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
          <input type="text" id="search-master-input" class="form-control" placeholder="Search TR code, ministry..." style="max-width: 320px;">
          <button id="btn-search-master" class="btn btn-secondary">Search</button>
          <button id="btn-add-master" class="btn btn-primary" style="margin-left: auto;">Add New Scheme</button>
        </div>

        <div id="master-status" style="margin-bottom: 12px; font-weight: 600; color: var(--primary);"></div>

        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th align="center">ID</th>
                <th align="center">TR Code</th>
                <th>TR Description</th>
                <th>Controller / Ministry</th>
                <th align="center">Central %</th>
                <th align="center">State %</th>
                <th align="center">Sub Head</th>
                <th align="center">Detail Head</th>
                <th align="center">Action</th>
              </tr>
            </thead>
            <tbody id="master-body">
              <tr><td colspan="9" align="center">Loading scheme master records...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <div class="form-group" style="margin-bottom: 14px;">
    <label for="reg-role">Requested Role</label>
    <select id="reg-role" class="form-control">
      <option value="OPERATOR">Operator (Upload & Generate PDFs)</option>
      <option value="VIEWER">Viewer (Read-Only Access)</option>
    </select>
  </div>
  <!-- Tab 7: Scheme Config Master -->
 <div id="tab-users" class="tab-content">
  <div class="card">
    <h2 class="card-title">User Approvals & Role Management</h2>
    <p class="card-subtitle">Approve new registrations, assign roles (Admin, Operator, Viewer), or remove accounts.</p>

    <div class="table-responsive" style="margin-top: 16px;">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Current Role</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="users-table-body">
          <tr><td colspan="7" align="center">Loading users...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
  <!-- Alert / Error Modal -->
  <div id="alert-modal" class="modal-backdrop">
    <div class="modal">
      <div class="modal-header" id="alert-modal-title">Notice</div>
      <div class="modal-body" id="alert-modal-body"></div>
      <div class="modal-footer">
        <button onclick="closeAlertModal()" class="btn btn-primary">OK</button>
      </div>
    </div>
  </div>

  <!-- Master Add/Edit Modal -->
  <div id="master-modal" class="modal-backdrop">
    <div class="modal" style="max-width: 600px;">
      <div class="modal-header" id="master-modal-title">Add Scheme Master Entry</div>
      <div class="modal-body">
        <input type="hidden" id="master-id">
        <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
          <div class="form-group">
            <label>TR Code (e.g. TR10)</label>
            <input type="text" id="master-tr-code" class="form-control" required>
          </div>
          <div class="form-group">
            <label>TR Description</label>
            <input type="text" id="master-tr-desc" class="form-control">
          </div>
          <div class="form-group">
            <label>Controller / Ministry</label>
            <input type="text" id="master-controller" class="form-control">
          </div>
          <div class="form-group">
            <label>CSS Scheme Name</label>
            <input type="text" id="master-css" class="form-control">
          </div>
          <div class="form-group">
            <label>Central Share (%)</label>
            <input type="number" step="0.01" id="master-central" class="form-control" value="100">
          </div>
          <div class="form-group">
            <label>State Share (%)</label>
            <input type="number" step="0.01" id="master-state" class="form-control" value="0">
          </div>
          <div class="form-group">
            <label>Sub Head (e.g. 99)</label>
            <input type="number" id="master-sub" class="form-control">
          </div>
          <div class="form-group">
            <label>Detail Head (e.g. 26)</label>
            <input type="number" id="master-detail" class="form-control">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button onclick="closeMasterModal()" class="btn btn-secondary">Cancel</button>
        <button onclick="saveMasterRecord()" class="btn btn-primary">Save Record</button>
      </div>
    </div>
  </div>

  <!-- Application Login Modal -->
  <div id="login-modal" class="modal-backdrop active" style="z-index: 2000; background: rgba(15, 23, 42, 0.85);">
    <div class="modal" style="max-width: 420px; padding: 28px;">
      
      <div style="display: flex; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px;">
        <button id="auth-tab-login" class="btn" onclick="switchAuthTab('login')" style="flex: 1; background: transparent; color: var(--primary); font-weight: 700; border-bottom: 3px solid var(--primary); border-radius: 0;">Login</button>
        <button id="auth-tab-register" class="btn" onclick="switchAuthTab('register')" style="flex: 1; background: transparent; color: #64748b; font-weight: 600; border-bottom: 3px solid transparent; border-radius: 0;">Register User</button>
      </div>

      <form id="login-form">
        <div class="form-group" style="margin-bottom: 16px;">
          <label for="login-user-id">Username / Login ID</label>
          <input type="text" id="login-user-id" class="form-control" placeholder="Username (e.g. User)" required value="">
        </div>
        <div class="form-group" style="margin-bottom: 20px;">
          <label for="login-password">Password</label>
          <input type="password" id="login-password" class="form-control" placeholder="Password" required value="">
        </div>

        <div id="login-error-msg" style="display: none; color: var(--danger); font-size: 0.85rem; margin-bottom: 16px; text-align: center; font-weight: 600;"></div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1rem;">Sign In</button>
      </form>

      <form id="register-form" style="display: none;">
        <div class="form-group" style="margin-bottom: 14px;">
          <label for="reg-username">Username *</label>
          <input type="text" id="reg-username" class="form-control" placeholder="Choose a username" required>
        </div>
        <div class="form-group" style="margin-bottom: 14px;">
          <label for="reg-full-name">Full Name</label>
          <input type="text" id="reg-full-name" class="form-control" placeholder="e.g. John Doe">
        </div>
        <div class="form-group" style="margin-bottom: 14px;">
          <label for="reg-email">Email Address</label>
          <input type="email" id="reg-email" class="form-control" placeholder="john@example.com">
        </div>
        <div class="form-group" style="margin-bottom: 20px;">
          <label for="reg-password">Password *</label>
          <input type="password" id="reg-password" class="form-control" placeholder="Min 4 characters" required>
        </div>

        <div id="register-error-msg" style="display: none; color: var(--danger); font-size: 0.85rem; margin-bottom: 16px; text-align: center; font-weight: 600;"></div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1rem; background-color: #059669;">Create Account & Login</button>
      </form>

    </div>
  </div>

  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
