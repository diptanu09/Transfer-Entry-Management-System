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
  <title>CSS (SNA SPARSH) Transfer Entry Management System</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>

  <div class="app-layout">
    <!-- Collapsible Sidebar -->
    <aside id="sidebar" class="sidebar collapsed">
      <div class="sidebar-header">
        <div id="sidebar-brand-btn" class="sidebar-brand" style="cursor: pointer;" title="Click logo to toggle sidebar">
          <div class="brand-icon">
            <img src="logo.png" alt="SPARSH Logo" class="brand-logo-img">
          </div>
          <div class="brand-text">
            <span class="brand-title">SPARSH TE</span>
            <span class="brand-sub">Management Suite</span>
          </div>
        </div>
      </div>

      <nav class="sidebar-menu">
        <div class="sidebar-label">Daily Operations</div>
        
        <button class="tab-btn active" data-tab="tab-upload" data-title="Daily File Upload" data-subtitle="Upload daily .xls, .xlsx, or .csv payment data files" title="Upload Daily File">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
          <span>Upload Daily File</span>
        </button>

        <button class="tab-btn" data-tab="tab-view-data" data-title="View Uploaded Data" data-subtitle="View and filter raw payment records stored in database" title="View Uploaded Data">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
          <span>View Uploaded Data</span>
        </button>

        <button class="tab-btn" data-tab="tab-generate" data-title="Generate PDF Reports" data-subtitle="Validate TR codes and generate Transfer Entry PDF vouchers" title="Generate PDF Reports">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          <span>Generate PDFs</span>
        </button>

        <button class="tab-btn" data-tab="tab-view-pdf" data-title="View & Download PDFs" data-subtitle="Inspect individual vouchers or download single merged PDF" title="View PDFs">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
          <span>View PDFs</span>
        </button>

        <div class="sidebar-label">Analytics & Settings</div>

        <button class="tab-btn" data-tab="tab-summary" data-title="Summary & Analytical Reports" data-subtitle="Central/State share calculations by Date, Month or Financial Year" title="Summary Reports">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
          <span>Summary Reports</span>
        </button>

        <button class="tab-btn" data-tab="tab-detailed" data-title="Transfer Entry Detailed Report" data-subtitle="Generate 18-column detailed accounting breakdown" title="Transfer Entry Report">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          <span>TE Detailed Report</span>
        </button>

        <button class="tab-btn" data-tab="tab-batch-posting" data-title="Batch TE Posting to VLCS Schema" data-subtitle="Post TE Detailed Report records into VLCS database schema (vlcs.B2_TE_HDRS, vlcs.B2_TE_HDR, vlcs.B2_TE_DTLS)" title="Batch TE Posting">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
          <span>Batch TE Posting</span>
        </button>

        <button class="tab-btn" data-tab="tab-master" data-title="Scheme Config Master Data" data-subtitle="Manage CSS scheme mappings and share breakdown percentages" title="Scheme Config Master">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          <span>Scheme Config Master</span>
        </button>

        <button class="tab-btn" data-tab="tab-users" id="nav-tab-users" style="display: none;" data-title="User Approvals & Role Management" data-subtitle="Approve registrations and manage security roles" title="User Approvals">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
          <span>User Management</span>
        </button>
      </nav>

      <div class="sidebar-footer">
        <div class="sidebar-status-dot"></div>
        <span class="sidebar-status-text">SQLite Connected</span>
      </div>
    </aside>

    <div id="sidebar-overlay" class="sidebar-overlay"></div>

    <!-- Main Workspace Area -->
    <div class="main-wrapper">
      <!-- Topbar Header -->
      <header class="topbar">
        <div class="topbar-left">
          <button id="mobile-menu-btn" class="mobile-menu-btn">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
          <div class="page-breadcrumb">
            <h1 id="page-title-heading" class="page-title">Daily File Upload</h1>
            <span id="page-subtitle-text" class="page-sub">Upload daily .xls, .xlsx, or .csv payment data files</span>
          </div>
        </div>

        <div class="topbar-right">
          <div class="date-pill">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <span><?= $today_str ?></span>
          </div>

          <button id="theme-toggle-btn" class="theme-toggle-btn" title="Toggle Light/Dark Theme">
            <svg id="theme-icon-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <svg id="theme-icon-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="display:none;"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
          </button>

          <div id="user-info-area" class="user-badge" style="display: flex;">
            <div class="user-avatar">U</div>
            <span id="logged-user-name" class="user-name-text">User: Guest</span>
            <button onclick="handleLogout()" class="btn btn-secondary" style="padding: 4px 10px; font-size: 0.75rem; border-radius: 20px;">Logout</button>
          </div>
        </div>
      </header>

      <!-- Dashboard Workspace -->
      <div class="dashboard-container">

        <!-- KPI Metric Cards -->
        <div class="kpi-grid">
          <div class="kpi-card" onclick="switchTabById('tab-upload')" title="Go to Upload Daily File">
            <div class="kpi-icon kpi-icon-blue">
              <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <div class="kpi-details">
              <span id="kpi-uploaded-files" class="kpi-value">0</span>
              <span class="kpi-label">Uploaded Files</span>
            </div>
          </div>

          <div class="kpi-card" onclick="switchTabById('tab-view-data')" title="Go to View Uploaded Data">
            <div class="kpi-icon kpi-icon-green">
              <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="kpi-details">
              <span id="kpi-total-amount" class="kpi-value">₹0</span>
              <span class="kpi-label">Total Amount (₹)</span>
            </div>
          </div>

          <div class="kpi-card" onclick="switchTabById('tab-view-pdf')" title="Go to View PDFs">
            <div class="kpi-icon kpi-icon-warning">
              <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            </div>
            <div class="kpi-details">
              <span id="kpi-generated-vouchers" class="kpi-value">0</span>
              <span class="kpi-label">TE Vouchers</span>
            </div>
          </div>

          <div class="kpi-card" onclick="switchTabById('tab-master')" title="Go to Scheme Config Master">
            <div class="kpi-icon kpi-icon-purple">
              <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
            </div>
            <div class="kpi-details">
              <span id="kpi-active-schemes" class="kpi-value">0</span>
              <span class="kpi-label">Active Schemes</span>
            </div>
          </div>
        </div>

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
                <label>Accounting Month (MM/YYYY) <span style="color:var(--danger);">*</span></label>
                <input type="text" id="gen-acct-month" class="form-control" value="<?= $current_month ?>" required placeholder="MM/YYYY">
              </div>
              <div class="form-group">
                <label>Starting Sectional Number <span style="color:var(--danger);">*</span></label>
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
                    <th align="center">VLC TE Number</th>
                    <th>Posting Date</th>
                    <th>TR Code</th>
                    <th align="right">Amount</th>
                    <th>Accounting Month</th>
                    <th>Generated Date</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody id="pdf-list-body">
                  <tr><td colspan="8" align="center">Click 'Filter Reports' to view generated PDFs.</td></tr>
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
                    <th>Sectional No.</th><th align="center">VLC TE Number</th><th>TR No.</th><th>TR Description</th><th>Ministry</th><th>Date</th>
                  </tr>
                </thead>
                <tbody id="detailed-body">
                  <tr><td colspan="19" align="center">Select filter and click 'Load Report'.</td></tr>
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

        <!-- Tab 8: User Approvals & Roles -->
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

        <!-- Tab 9: Batch Posting of TE Data -->
        <div id="tab-batch-posting" class="tab-content">
          <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
              <div>
                <h2 class="card-title">Batch Posting of TE Detailed Report Data</h2>
                <p class="card-subtitle">Post transfer entry vouchers into Oracle database <strong>vlcs</strong> under schema <strong>vlcs</strong> (tables: <code>vlcs.B2_TE_HDRS</code>, <code>vlcs.B2_TE_HDR</code>, <code>vlcs.B2_TE_DTLS</code>).</p>
              </div>
              <div class="date-pill" style="background: rgba(99, 102, 241, 0.1); color: #6366f1; border-color: rgba(99, 102, 241, 0.2);">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span>Batch Code: B2_DDR_DEPARTMENT_TR_BATCH</span>
              </div>
            </div>

            <form id="batch-filter-form" onsubmit="event.preventDefault(); handleBatchPreview();">
              <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); margin-top: 16px;">
                <div class="form-group">
                  <label for="batch-filter-type">Filter Type</label>
                  <select id="batch-filter-type" class="form-control" onchange="toggleBatchFilterInputs()">
                    <option value="month" selected>By Accounting Month</option>
                    <option value="date">By Date Range</option>
                    <option value="fy">By Financial Year</option>
                  </select>
                </div>

                <div class="form-group" id="batch-group-month">
                  <label for="batch-acct-month">Accounting Month</label>
                  <input type="text" id="batch-acct-month" class="form-control" value="<?= $current_month ?>" placeholder="MM/YYYY (e.g. 04/2026)">
                </div>

                <div class="form-group" id="batch-group-from" style="display: none;">
                  <label for="batch-from-date">From Date</label>
                  <input type="text" id="batch-from-date" class="form-control" placeholder="DD/MM/YYYY">
                </div>

                <div class="form-group" id="batch-group-to" style="display: none;">
                  <label for="batch-to-date">To Date</label>
                  <input type="text" id="batch-to-date" class="form-control" placeholder="DD/MM/YYYY">
                </div>

                <div class="form-group" id="batch-group-fy" style="display: none;">
                  <label for="batch-fy-val">Financial Year</label>
                  <select id="batch-fy-val" class="form-control">
                    <option value="2025-26">2025-26</option>
                    <option value="2026-27" selected>2026-27</option>
                    <option value="2027-28">2027-28</option>
                  </select>
                </div>
              </div>

              <div style="display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap;">
                <button type="button" onclick="handleBatchPreview()" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  <span>Preview TE Data to Post</span>
                </button>

                <button type="button" onclick="executeBatchPostingAction()" class="btn btn-primary" style="background-color: #059669; border-color: #059669; display: inline-flex; align-items: center; gap: 6px;">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                  <span>Run Batch Posting (Post to VLCS)</span>
                </button>
              </div>
            </form>

            <div id="batch-status-banner" style="display: none; margin-top: 16px; padding: 12px 16px; border-radius: 8px; font-weight: 600;"></div>
          </div>

          <!-- KPI Metric Cards for Batch Posting -->
          <div class="kpi-grid" style="margin-top: 20px;">
            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-blue">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
              </div>
              <div class="kpi-details">
                <span id="kpi-batch-vouchers" class="kpi-value">0</span>
                <span class="kpi-label">TE Vouchers Ready</span>
              </div>
            </div>

            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-green">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              </div>
              <div class="kpi-details">
                <span id="kpi-batch-total-amt" class="kpi-value">₹0.00</span>
                <span class="kpi-label">Total Amount to Post</span>
              </div>
            </div>

            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-purple">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
              </div>
              <div class="kpi-details">
                <span class="kpi-value" style="font-size: 1.1rem; color: #8b5cf6;">DB: vlcs | Schema: vlcs</span>
                <span class="kpi-label">Target Oracle Database</span>
              </div>
            </div>

            <div class="kpi-card">
              <div class="kpi-icon kpi-icon-warning">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              </div>
              <div class="kpi-details">
                <span id="kpi-batch-last-run" class="kpi-value" style="font-size: 1rem;">Not Run Yet</span>
                <span class="kpi-label">Last Batch Run</span>
              </div>
            </div>
          </div>

          <!-- Unposted TE Preview Table Card -->
          <div class="card" style="margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 12px;">
              <div>
                <h3 class="card-title">TE Vouchers Preview (Target Tables: vlcs.B2_TE_HDRS, vlcs.B2_TE_HDR, vlcs.B2_TE_DTLS)</h3>
                <span id="batch-preview-desc" class="card-subtitle">Select filters and click "Preview TE Data to Post" to view unposted vouchers.</span>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>Sectional No. (Sanction No)</th>
                    <th>TR No & Description</th>
                    <th align="center">Posting Date</th>
                    <th align="center">Debit Head (8675-00-106-03)</th>
                    <th align="center">Credit Head (1601)</th>
                    <th align="right">Total Amount (₹)</th>
                  </tr>
                </thead>
                <tbody id="batch-preview-body">
                  <tr><td colspan="6" align="center">No preview data loaded yet. Click "Preview TE Data to Post".</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Historical Batch Posting Execution Logs -->
          <div class="card" style="margin-top: 20px;">
            <h3 class="card-title">Batch Execution Logs & Audit Trail (VLCS_B2_TE_BATCH_LOG)</h3>
            <p class="card-subtitle">History of previous batch postings executed for Transfer Entry Detailed Reports.</p>

            <div class="table-responsive" style="margin-top: 14px;">
              <table class="table">
                <thead>
                  <tr>
                    <th>Log ID</th>
                    <th>Batch Code</th>
                    <th>Filter / Month</th>
                    <th align="right">Vouchers Posted</th>
                    <th align="right">Total Amount (₹)</th>
                    <th align="center">Status</th>
                    <th>Run User</th>
                    <th align="center">Run Date</th>
                    <th>Log Message</th>
                  </tr>
                </thead>
                <tbody id="batch-logs-body">
                  <tr><td colspan="9" align="center">Loading batch history...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

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
  <div id="login-modal" class="modal-backdrop active" style="z-index: 2000;">
    <div class="modal" style="max-width: 420px; padding: 28px;">
      
      <div style="display: flex; border-bottom: 2px solid var(--border); margin-bottom: 20px;">
        <button id="auth-tab-login" class="btn" onclick="switchAuthTab('login')" style="flex: 1; background: transparent; color: var(--primary); font-weight: 700; border-bottom: 3px solid var(--primary); border-radius: 0;">Login</button>
        <button id="auth-tab-register" class="btn" onclick="switchAuthTab('register')" style="flex: 1; background: transparent; color: var(--text-muted); font-weight: 600; border-bottom: 3px solid transparent; border-radius: 0;">Register User</button>
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
        <div class="form-group" style="margin-bottom: 14px;">
          <label for="reg-password">Password *</label>
          <input type="password" id="reg-password" class="form-control" placeholder="Min 4 characters" required>
        </div>
        <div class="form-group" style="margin-bottom: 20px;">
          <label for="reg-role">Requested Role</label>
          <select id="reg-role" class="form-control">
            <option value="OPERATOR">Operator (Upload & Generate PDFs)</option>
            <option value="VIEWER">Viewer (Read-Only Access)</option>
          </select>
        </div>

        <div id="register-error-msg" style="display: none; color: var(--danger); font-size: 0.85rem; margin-bottom: 16px; text-align: center; font-weight: 600;"></div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 1rem; background-color: #059669;">Create Account & Login</button>
      </form>

    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
