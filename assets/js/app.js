/**
 * Main Web Application Logic for Transfer Entry Management System
 */

let currentUserRole = 'VIEWER';

document.addEventListener('DOMContentLoaded', () => {
  initSidebarAndTheme();
  initAuth();
  initTabs();
  initUploadPage();
  initViewDataPage();
  initGeneratePdfPage();
  initViewPdfPage();
  initSummaryPage();
  initDetailedPage();
  initMasterPage();
  loadDashboardKpis();
});

// Switch Auth Tab (Login <-> Register)
function switchAuthTab(tab) {
  const loginForm = document.getElementById('login-form');
  const registerForm = document.getElementById('register-form');
  const tabLogin = document.getElementById('auth-tab-login');
  const tabRegister = document.getElementById('auth-tab-register');

  if (!loginForm || !registerForm) return;

  if (tab === 'register') {
    loginForm.style.display = 'none';
    registerForm.style.display = 'block';
    if (tabLogin) {
      tabLogin.style.color = '#64748b';
      tabLogin.style.borderBottomColor = 'transparent';
    }
    if (tabRegister) {
      tabRegister.style.color = 'var(--primary)';
      tabRegister.style.borderBottomColor = 'var(--primary)';
    }
  } else {
    loginForm.style.display = 'block';
    registerForm.style.display = 'none';
    if (tabRegister) {
      tabRegister.style.color = '#64748b';
      tabRegister.style.borderBottomColor = 'transparent';
    }
    if (tabLogin) {
      tabLogin.style.color = 'var(--primary)';
      tabLogin.style.borderBottomColor = 'var(--primary)';
    }
  }
}

// Authentication System
async function initAuth() {
  const loginForm = document.getElementById('login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', handleLogin);
  }

  const registerForm = document.getElementById('register-form');
  if (registerForm) {
    registerForm.addEventListener('submit', handleRegister);
  }

  await checkAuthStatus();
}

async function checkAuthStatus() {
  const modal = document.getElementById('login-modal');
  const userArea = document.getElementById('user-info-area');
  const userName = document.getElementById('logged-user-name');
  const usersTabNav = document.getElementById('nav-tab-users');

  try {
    const res = await fetch('api.php?action=check_auth');
    const data = await res.json();

    if (data.authenticated) {
      currentUserRole = data.role || 'VIEWER';

      if (modal) modal.classList.remove('active');
      if (userArea) userArea.style.display = 'flex';
      if (userName) userName.innerText = `User: ${data.full_name || data.user} (${currentUserRole})`;

      // Show Admin User Management tab only if ADMIN
      if (usersTabNav) {
        usersTabNav.style.display = (currentUserRole === 'ADMIN') ? 'flex' : 'none';
      }

      applyRoleUiRestrictions();
      loadDashboardKpis();
    } else {
      if (modal) modal.classList.add('active');
      if (userArea) userArea.style.display = 'none';
    }
  } catch (err) {
    if (modal) modal.classList.add('active');
  }
}

function applyRoleUiRestrictions() {
  // Viewer Role Restrictions: Disable upload & generate buttons
  const isViewer = (currentUserRole === 'VIEWER');
  const isAdmin = (currentUserRole === 'ADMIN');

  const uploadBtn = document.querySelector('#upload-form button[type="submit"]');
  const generateBtn = document.getElementById('btn-generate-pdfs');
  const addMasterBtn = document.getElementById('btn-add-master');

  if (uploadBtn) uploadBtn.disabled = isViewer;
  if (generateBtn) generateBtn.disabled = isViewer;
  if (addMasterBtn) addMasterBtn.style.display = isAdmin ? 'inline-block' : 'none';

  loadMasterRecords();
}

async function handleLogin(e) {
  e.preventDefault();
  const userIdInput = document.getElementById('login-user-id');
  const pwdInput = document.getElementById('login-password');
  const errBox = document.getElementById('login-error-msg');

  if (!userIdInput || !pwdInput) return;

  const userId = userIdInput.value.trim();
  const pwd = pwdInput.value.trim();

  if (errBox) errBox.style.display = 'none';

  const formData = new FormData();
  formData.append('user_id', userId);
  formData.append('password', pwd);

  try {
    const res = await fetch('api.php?action=login', { method: 'POST', body: formData });
    const data = await res.json();

    if (!data.success) {
      if (errBox) {
        errBox.innerText = data.error || "Invalid Login ID or Password!";
        errBox.style.display = 'block';
      }
      return;
    }

    await checkAuthStatus();
    loadRecentUploads();
  } catch (err) {
    if (errBox) {
      errBox.innerText = err.message || "Server communication error.";
      errBox.style.display = 'block';
    }
  }
}

async function handleRegister(e) {
  e.preventDefault();
  const usernameInput = document.getElementById('reg-username');
  const fullNameInput = document.getElementById('reg-full-name');
  const emailInput = document.getElementById('reg-email');
  const pwdInput = document.getElementById('reg-password');
  const roleInput = document.getElementById('reg-role');
  const errBox = document.getElementById('register-error-msg');

  if (errBox) errBox.style.display = 'none';

  const username = usernameInput ? usernameInput.value.trim() : '';
  const fullName = fullNameInput ? fullNameInput.value.trim() : '';
  const email = emailInput ? emailInput.value.trim() : '';
  const password = pwdInput ? pwdInput.value : '';
  const requestedRole = roleInput ? roleInput.value : 'OPERATOR';

  const formData = new FormData();
  formData.append('username', username);
  formData.append('full_name', fullName);
  formData.append('email', email);
  formData.append('password', password);
  formData.append('requested_role', requestedRole);

  try {
    const res = await fetch('api.php?action=register', { method: 'POST', body: formData });
    const data = await res.json();

    if (!data.success) {
      if (errBox) {
        errBox.innerText = data.error || "Registration failed.";
        errBox.style.display = 'block';
      }
      return;
    }

    alert(data.message || "Registration submitted! Please wait for Admin approval.");
    switchAuthTab('login');
  } catch (err) {
    if (errBox) {
      errBox.innerText = err.message || "Server communication error.";
      errBox.style.display = 'block';
    }
  }
}

async function handleLogout() {
  try {
    await fetch('api.php?action=logout');
    await checkAuthStatus();
  } catch (e) {
    location.reload();
  }
}

// User Management (Admin Only)
async function loadUsersList() {
  const tableBody = document.getElementById('users-table-body');
  if (!tableBody) return;

  tableBody.innerHTML = `<tr><td colspan="7" align="center">Loading users...</td></tr>`;

  try {
    const res = await fetch('api.php?action=get_users_list');
    const data = await res.json();

    if (!data.success) {
      tableBody.innerHTML = `<tr><td colspan="7" align="center">${data.error}</td></tr>`;
      return;
    }

    tableBody.innerHTML = (data.users || []).map(u => {
      const isApproved = (u.is_approved == 1);
      const statusBadge = isApproved
        ? `<span style="color: var(--success); font-weight:700;">APPROVED</span>`
        : `<span style="color: var(--danger); font-weight:700;">PENDING</span>`;

      return `
        <tr>
          <td>${u.id}</td>
          <td><strong>${escapeHtml(u.username)}</strong></td>
          <td>${escapeHtml(u.full_name || '-')}</td>
          <td>${escapeHtml(u.email || '-')}</td>
          <td>
            <select onchange="updateUserRole(${u.id}, this.value)" class="form-control" style="padding: 2px 6px; font-size: 0.85rem;">
              <option value="ADMIN" ${u.role === 'ADMIN' ? 'selected' : ''}>ADMIN</option>
              <option value="OPERATOR" ${u.role === 'OPERATOR' ? 'selected' : ''}>OPERATOR</option>
              <option value="VIEWER" ${u.role === 'VIEWER' ? 'selected' : ''}>VIEWER</option>
            </select>
          </td>
          <td>${statusBadge}</td>
          <td>
            ${!isApproved ? `<button onclick="approveUser(${u.id}, '${u.role}')" class="btn btn-primary" style="padding: 4px 10px; font-size:0.8rem; margin-right:6px;">Approve</button>` : ''}
            <button onclick="rejectUser(${u.id}, '${escapeHtml(u.username)}')" class="btn btn-secondary" style="padding: 4px 10px; font-size:0.8rem; background:#ef4444; color:#fff;">Remove</button>
          </td>
        </tr>
      `;
    }).join('') || `<tr><td colspan="7" align="center">No registered users found.</td></tr>`;
  } catch (err) {
    tableBody.innerHTML = `<tr><td colspan="7" align="center">Error loading user list.</td></tr>`;
  }
}

async function approveUser(userId, role) {
  const formData = new FormData();
  formData.append('user_id', userId);
  formData.append('role', role);

  try {
    const res = await fetch('api.php?action=approve_user', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      loadUsersList();
    } else {
      alert(data.error || "Failed to approve user.");
    }
  } catch (err) {
    alert("Error approving user.");
  }
}

async function updateUserRole(userId, newRole) {
  const formData = new FormData();
  formData.append('user_id', userId);
  formData.append('role', newRole);

  try {
    const res = await fetch('api.php?action=update_user_role', { method: 'POST', body: formData });
    const data = await res.json();
    if (!data.success) {
      alert(data.error || "Failed to update role.");
      loadUsersList();
    }
  } catch (err) {
    alert("Error updating role.");
  }
}

async function rejectUser(userId, username) {
  if (!confirm(`Are you sure you want to remove user '${username}'?`)) return;

  const formData = new FormData();
  formData.append('user_id', userId);

  try {
    const res = await fetch('api.php?action=reject_user', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.success) {
      loadUsersList();
    } else {
      alert(data.error || "Failed to remove user.");
    }
  } catch (err) {
    alert("Error removing user.");
  }
}

// Sidebar & Theme System
function initSidebarAndTheme() {
  const sidebar = document.getElementById('sidebar');
  const sidebarBrandBtn = document.getElementById('sidebar-brand-btn');
  const mobileMenuBtn = document.getElementById('mobile-menu-btn');
  const sidebarOverlay = document.getElementById('sidebar-overlay');
  const themeToggleBtn = document.getElementById('theme-toggle-btn');
  const sunIcon = document.getElementById('theme-icon-sun');
  const moonIcon = document.getElementById('theme-icon-moon');

  // Sidebar collapse persistence (default to collapsed '1')
  const savedState = localStorage.getItem('sidebar_collapsed');
  const isCollapsed = (savedState === null) ? true : (savedState === '1');
  if (sidebar) {
    if (isCollapsed) {
      sidebar.classList.add('collapsed');
    } else {
      sidebar.classList.remove('collapsed');
    }
  }

  if (sidebarBrandBtn && sidebar) {
    sidebarBrandBtn.addEventListener('click', () => {
      sidebar.classList.toggle('collapsed');
      localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
    });
  }

  // Mobile menu drawer
  if (mobileMenuBtn && sidebar && sidebarOverlay) {
    mobileMenuBtn.addEventListener('click', () => {
      sidebar.classList.add('mobile-open');
      sidebarOverlay.classList.add('active');
    });

    sidebarOverlay.addEventListener('click', () => {
      sidebar.classList.remove('mobile-open');
      sidebarOverlay.classList.remove('active');
    });
  }

  // Light/Dark Theme Switcher
  const savedTheme = localStorage.getItem('theme') || 'light';
  applyTheme(savedTheme);

  if (themeToggleBtn) {
    themeToggleBtn.addEventListener('click', () => {
      const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
      const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
      applyTheme(newTheme);
    });
  }

  function applyTheme(theme) {
    if (theme === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
      if (sunIcon) sunIcon.style.display = 'none';
      if (moonIcon) moonIcon.style.display = 'block';
    } else {
      document.documentElement.removeAttribute('data-theme');
      if (sunIcon) sunIcon.style.display = 'block';
      if (moonIcon) moonIcon.style.display = 'none';
    }
    localStorage.setItem('theme', theme);
  }
}

// Tab Navigation
function initTabs() {
  const tabBtns = document.querySelectorAll('.tab-btn');
  const tabContents = document.querySelectorAll('.tab-content');
  const pageTitle = document.getElementById('page-title-heading');
  const pageSubtitle = document.getElementById('page-subtitle-text');
  const sidebar = document.getElementById('sidebar');
  const sidebarOverlay = document.getElementById('sidebar-overlay');

  tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      tabBtns.forEach(b => b.classList.remove('active'));
      tabContents.forEach(c => c.classList.remove('active'));

      btn.classList.add('active');
      const targetId = btn.dataset.tab;
      const targetContent = document.getElementById(targetId);
      if (targetContent) targetContent.classList.add('active');

      // Update Topbar Heading
      if (pageTitle && btn.dataset.title) pageTitle.innerText = btn.dataset.title;
      if (pageSubtitle && btn.dataset.subtitle) pageSubtitle.innerText = btn.dataset.subtitle;

      // Close mobile drawer if open
      if (sidebar) sidebar.classList.remove('mobile-open');
      if (sidebarOverlay) sidebarOverlay.classList.remove('active');

      if (targetId === 'tab-upload') loadRecentUploads();
      if (targetId === 'tab-view-data') loadViewData();
      if (targetId === 'tab-generate') loadNextSectionalNumber();
      if (targetId === 'tab-view-pdf') loadPdfList();
      if (targetId === 'tab-master') loadMasterRecords();
      if (targetId === 'tab-users') loadUsersList();

      loadDashboardKpis();
    });
  });
}

// Tab Switch Helper
function switchTabById(tabId) {
  const targetBtn = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
  if (targetBtn) {
    targetBtn.click();
  }
}

// KPI Dashboard Aggregation
async function loadDashboardKpis() {
  const kpiRecords = document.getElementById('kpi-total-records');
  const kpiAmount = document.getElementById('kpi-total-amount');
  const kpiVouchers = document.getElementById('kpi-generated-vouchers');
  const kpiSchemes = document.getElementById('kpi-active-schemes');

  try {
    const [uploadsRes, masterRes, pdfsRes] = await Promise.all([
      fetch('api.php?action=get_recent_uploads').then(r => r.json()),
      fetch('api.php?action=get_master_records').then(r => r.json()),
      fetch('api.php?action=get_pdf_list').then(r => r.json())
    ]);

    if (uploadsRes.success && uploadsRes.uploads) {
      let totalRecs = 0;
      let totalAmt = 0;
      uploadsRes.uploads.forEach(item => {
        totalRecs += parseInt(item.record_count || item.total_records || 0, 10);
        totalAmt += parseFloat(item.total_amount || 0);
      });
      if (kpiRecords) kpiRecords.innerText = totalRecs.toLocaleString('en-IN');
      if (kpiAmount) kpiAmount.innerText = '₹' + totalAmt.toLocaleString('en-IN', { maximumFractionDigits: 2 });
    }

    if (masterRes.success && masterRes.records) {
      if (kpiSchemes) kpiSchemes.innerText = masterRes.records.length.toLocaleString('en-IN');
    }

    if (pdfsRes.success && pdfsRes.reports) {
      if (kpiVouchers) kpiVouchers.innerText = pdfsRes.reports.length.toLocaleString('en-IN');
    }
  } catch (err) {
    // Silent fail if unauthenticated or network error
  }
}

// Utility Helpers
function showAlertModal(title, message, isError = false) {
  const modal = document.getElementById('alert-modal');
  const titleEl = document.getElementById('alert-modal-title');
  const bodyEl = document.getElementById('alert-modal-body');

  if (titleEl) titleEl.innerText = title;
  if (bodyEl) {
    if (isError) {
      bodyEl.innerHTML = `<div class="alert-error-box">${escapeHtml(message)}</div>`;
    } else {
      bodyEl.innerText = message;
    }
  }
  if (modal) modal.classList.add('active');
}

function closeAlertModal() {
  const modal = document.getElementById('alert-modal');
  if (modal) modal.classList.remove('active');
}

function escapeHtml(text) {
  return String(text ?? '')
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function formatMoney(num) {
  return new Intl.NumberFormat('en-IN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(num || 0);
}

// Upload Page Logic
function initUploadPage() {
  const form = document.getElementById('upload-form');
  if (!form) return;

  loadRecentUploads();

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fileInput = document.getElementById('upload-file');
    const dateInput = document.getElementById('upload-date');
    const statusLabel = document.getElementById('upload-status');

    if (!fileInput || !fileInput.files.length) {
      alert("Choose a payment file first.");
      return;
    }

    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('report_date', dateInput ? dateInput.value : '');

    if (statusLabel) statusLabel.innerText = "Uploading to database... Please wait.";

    try {
      const res = await fetch('api.php?action=upload_file', { method: 'POST', body: formData });
      const data = await res.json();

      if (!data.success) {
        if (statusLabel) statusLabel.innerText = "Upload failed.";
        showAlertModal("Upload Failed", data.error, true);
        return;
      }

      if (statusLabel) {
        statusLabel.innerText = `Uploaded ${data.count} records successfully. Total amount: Rs. ${formatMoney(data.total_amount)}`;
      }
      loadRecentUploads();
    } catch (err) {
      if (statusLabel) statusLabel.innerText = "Upload failed.";
      showAlertModal("Error", err.message, true);
    }
  });
}

async function loadRecentUploads() {
  const tableBody = document.getElementById('recent-uploads-body');
  if (!tableBody) return;

  try {
    const res = await fetch('api.php?action=get_recent_uploads');
    const data = await res.json();

    if (data.success) {
      tableBody.innerHTML = (data.uploads || []).map(u => `
        <tr>
          <td>${escapeHtml(u.report_date)}</td>
          <td><strong>${escapeHtml(u.source_file_name)}</strong></td>
          <td align="right">${u.record_count}</td>
          <td align="right">Rs. ${formatMoney(u.total_amount)}</td>
          <td>${escapeHtml(u.uploaded_at)}</td>
        </tr>
      `).join('') || `<tr><td colspan="5" align="center">No uploads yet.</td></tr>`;
    }
  } catch (err) {
    console.error(err);
  }
}

// View Data Page Logic
function initViewDataPage() {
  const btn = document.getElementById('btn-filter-view-data');
  if (btn) btn.addEventListener('click', loadViewData);
}

async function loadViewData() {
  const startDateInput = document.getElementById('view-start-date');
  const endDateInput = document.getElementById('view-end-date');
  const body = document.getElementById('view-data-body');
  const summary = document.getElementById('view-data-summary');

  if (!body) return;

  const startDate = startDateInput ? startDateInput.value : '';
  const endDate = endDateInput ? endDateInput.value : '';

  body.innerHTML = `<tr><td colspan="6" align="center">Loading records...</td></tr>`;

  try {
    const res = await fetch(`api.php?action=get_view_data&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`);
    const data = await res.json();

    if (!data.success) {
      showAlertModal("Error", data.error, true);
      return;
    }

    const records = data.records || [];
    let totalAmt = 0;
    records.forEach(r => totalAmt += parseFloat(r.amount || 0));

    if (summary) {
      summary.innerText = `${records.length} record(s) loaded | Total amount: Rs. ${formatMoney(totalAmt)}`;
    }

    body.innerHTML = records.map(r => `
      <tr>
        <td>${escapeHtml(r.posting_date)} ${escapeHtml(r.posting_time)}</td>
        <td>${escapeHtml(r.state_government)}</td>
        <td><strong>${escapeHtml(r.sg_account_name)}</strong></td>
        <td align="right">Rs. ${formatMoney(r.amount)}</td>
        <td>${escapeHtml(r.cg_account_udch_code)}</td>
        <td>${escapeHtml(r.source_file_name)}</td>
      </tr>
    `).join('') || `<tr><td colspan="6" align="center">No payment records found.</td></tr>`;
  } catch (err) {
    showAlertModal("Error", err.message, true);
  }
}

// Generate PDF Page Logic
function initGeneratePdfPage() {
  loadNextSectionalNumber();
  const btn = document.getElementById('btn-generate-pdfs');
  if (btn) btn.addEventListener('click', generatePdfs);

  const acctMonthInput = document.getElementById('gen-acct-month');
  if (acctMonthInput) {
    acctMonthInput.addEventListener('input', () => loadNextSectionalNumber());
    acctMonthInput.addEventListener('change', () => loadNextSectionalNumber());
  }
}

async function loadNextSectionalNumber(acctMonthOverride) {
  const input = document.getElementById('gen-sec-num');
  const monthInput = document.getElementById('gen-acct-month');
  if (!input) return;
  const month = acctMonthOverride !== undefined ? acctMonthOverride : (monthInput ? monthInput.value.trim() : '');
  try {
    const res = await fetch(`api.php?action=get_next_sectional_number&accounting_month=${encodeURIComponent(month)}`);
    const data = await res.json();
    if (data.success) input.value = data.next_sectional_number;
  } catch (e) { }
}

async function generatePdfs() {
  const fromDateInput = document.getElementById('gen-from-date');
  const toDateInput = document.getElementById('gen-to-date');
  const acctMonthInput = document.getElementById('gen-acct-month');
  const secNumInput = document.getElementById('gen-sec-num');
  const statusLabel = document.getElementById('gen-status');

  const fromDate = fromDateInput ? fromDateInput.value.trim() : '';
  const toDate = toDateInput ? toDateInput.value.trim() : '';
  const acctMonth = acctMonthInput ? acctMonthInput.value.trim() : '';
  const secNum = secNumInput ? secNumInput.value.trim() : '';

  if (!acctMonth) {
    showAlertModal("Validation Error", "Accounting Month is a mandatory field.", true);
    return;
  }
  if (!secNum || parseInt(secNum, 10) <= 0) {
    showAlertModal("Validation Error", "Starting Sectional Number is a mandatory field and must be greater than 0.", true);
    return;
  }

  if (statusLabel) statusLabel.innerText = "Validating TR codes & generating reports... Please wait.";

  const formData = new FormData();
  formData.append('from_date', fromDate);
  formData.append('to_date', toDate);
  formData.append('accounting_month', acctMonth);
  formData.append('sectional_number', secNum);

  try {
    const res = await fetch('api.php?action=generate_pdfs', { method: 'POST', body: formData });
    let data;
    try {
      data = await res.json();
    } catch (parseErr) {
      throw new Error(`Server returned status ${res.status} with non-JSON response.`);
    }

    if (!data.success) {
      if (statusLabel) statusLabel.innerText = "PDF generation failed.";
      showAlertModal("PDF Generation Failed", data.error || "An error occurred during PDF generation.", true);
      return;
    }

    if (statusLabel) statusLabel.innerText = `Generated ${data.generated} PDF report(s) successfully!`;
    loadNextSectionalNumber(acctMonth);

    const btnMergedGen = document.getElementById('btn-download-merged-gen');
    if (btnMergedGen) {
      btnMergedGen.href = `api.php?action=download_merged_pdf&from_date=${encodeURIComponent(fromDate)}&to_date=${encodeURIComponent(toDate)}&accounting_month=${encodeURIComponent(acctMonth)}`;
      btnMergedGen.style.display = 'inline-block';
    }

    alert(`Generated ${data.generated} PDF report(s) successfully!\nClick 'Open Single Merged PDF' to view all vouchers with Summary Annexure.`);
  } catch (err) {
    if (statusLabel) statusLabel.innerText = "PDF generation failed.";
    showAlertModal("Error", err.message || "An unknown error occurred.", true);
  }
}

// View PDF Page Logic
function initViewPdfPage() {
  const btn = document.getElementById('btn-filter-pdf');
  if (btn) btn.addEventListener('click', loadPdfList);

  const btnMergedView = document.getElementById('btn-download-merged-view');
  if (btnMergedView) {
    btnMergedView.addEventListener('click', () => {
      const startDateInput = document.getElementById('pdf-start-date');
      const endDateInput = document.getElementById('pdf-end-date');
      const startDate = startDateInput ? startDateInput.value : '';
      const endDate = endDateInput ? endDateInput.value : '';
      window.open(`api.php?action=download_merged_pdf&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`, '_blank');
    });
  }
}

async function loadPdfList() {
  const body = document.getElementById('pdf-list-body');
  if (!body) return;

  const startDateInput = document.getElementById('pdf-start-date');
  const endDateInput = document.getElementById('pdf-end-date');

  const startDate = startDateInput ? startDateInput.value : '';
  const endDate = endDateInput ? endDateInput.value : '';

  try {
    const res = await fetch(`api.php?action=get_pdf_list&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`);
    const data = await res.json();

    if (data.success) {
      body.innerHTML = (data.reports || []).map(r => `
        <tr>
          <td>BK/TE/${escapeHtml(r.accounting_month)}/${r.sectional_number}</td>
          <td>${escapeHtml(r.posting_date)} ${escapeHtml(r.posting_time)}</td>
          <td><strong>${escapeHtml(r.sg_account_name)}</strong></td>
          <td align="right">Rs. ${formatMoney(r.amount)}</td>
          <td>${escapeHtml(r.accounting_month)}</td>
          <td>${escapeHtml(r.generation_date)}</td>
          <td><a href="api.php?action=download_pdf&id=${r.id}&file=${encodeURIComponent(r.pdf_file_name || 'report.pdf')}" target="_blank" class="btn btn-secondary" style="padding: 4px 10px; font-size: 0.8rem;">Open PDF</a></td>
        </tr>
      `).join('') || `<tr><td colspan="7" align="center">No generated PDFs found.</td></tr>`;
    }
  } catch (e) { }
}

// Summary Page Logic
function initSummaryPage() {
  const btnLoad = document.getElementById('btn-load-summary');
  const btnExcel = document.getElementById('btn-excel-summary');
  const btnPdf = document.getElementById('btn-pdf-summary');

  if (btnLoad) btnLoad.addEventListener('click', loadSummaryReport);
  if (btnExcel) btnExcel.addEventListener('click', exportSummaryExcel);
  if (btnPdf) {
    btnPdf.addEventListener('click', () => {
      const from = document.getElementById('sum-from-date') ? document.getElementById('sum-from-date').value : '';
      const to = document.getElementById('sum-to-date') ? document.getElementById('sum-to-date').value : '';
      const acctMonth = document.getElementById('sum-acct-month') ? document.getElementById('sum-acct-month').value : '';
      window.open(`api.php?action=download_merged_pdf&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}&accounting_month=${encodeURIComponent(acctMonth)}`, '_blank');
    });
  }
}

async function loadSummaryReport() {
  const modeRadio = document.querySelector('input[name="summary-mode"]:checked');
  const mode = modeRadio ? modeRadio.value : 'month';

  const from = document.getElementById('sum-from-date') ? document.getElementById('sum-from-date').value : '';
  const to = document.getElementById('sum-to-date') ? document.getElementById('sum-to-date').value : '';
  const month = document.getElementById('sum-acct-month') ? document.getElementById('sum-acct-month').value : '';
  const fy = document.getElementById('sum-fy') ? document.getElementById('sum-fy').value : '';

  const body = document.getElementById('summary-body');
  const status = document.getElementById('summary-status');

  if (!body) return;

  body.innerHTML = `<tr><td colspan="11" align="center">Loading summary report...</td></tr>`;

  try {
    const url = `api.php?action=get_summary_report&filter_type=${mode}&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}&accounting_month=${encodeURIComponent(month)}&financial_year_val=${encodeURIComponent(fy)}`;
    const res = await fetch(url);
    const data = await res.json();

    if (!data.success) {
      showAlertModal("Error", data.error, true);
      return;
    }

    const records = data.records || [];
    if (status) status.innerText = `Loaded ${records.length} record(s). ${data.description || ''}`;

    let totAmt = 0, totC = 0, totS = 0;
    let rowsHtml = records.map((r, i) => {
      totAmt += parseFloat(r.total_amount || 0);
      totC += parseFloat(r.central_share_amount || 0);
      totS += parseFloat(r.state_share_amount || 0);
      return `
        <tr>
          <td align="center">${i + 1}</td>
          <td>${escapeHtml(r.ministry_name)}</td>
          <td align="center"><strong>${escapeHtml(r.tr_no)}</strong></td>
          <td>${escapeHtml(r.tr_desc)}</td>
          <td align="right">Rs. ${formatMoney(r.total_amount)}</td>
          <td align="right">Rs. ${formatMoney(r.central_share_amount)}</td>
          <td align="right">Rs. ${formatMoney(r.state_share_amount)}</td>
          <td align="center">${escapeHtml(r.sub_head)}</td>
          <td align="center">${escapeHtml(r.detail_head)}</td>
          <td align="center">${escapeHtml(r.sectional_number)}</td>
          <td align="center">${escapeHtml(r.posting_date)}</td>
        </tr>
      `;
    }).join('');

    if (records.length) {
      rowsHtml += `
        <tr class="total-row">
          <td align="center">Total</td>
          <td colspan="3"><strong>${records.length} records</strong></td>
          <td align="right">Rs. ${formatMoney(totAmt)}</td>
          <td align="right">Rs. ${formatMoney(totC)}</td>
          <td align="right">Rs. ${formatMoney(totS)}</td>
          <td colspan="4"></td>
        </tr>
      `;
    }

    body.innerHTML = rowsHtml || `<tr><td colspan="11" align="center">No data found for filter.</td></tr>`;
  } catch (err) {
    showAlertModal("Error", err.message, true);
  }
}

function exportSummaryExcel() {
  const modeRadio = document.querySelector('input[name="summary-mode"]:checked');
  const mode = modeRadio ? modeRadio.value : 'month';

  const from = document.getElementById('sum-from-date') ? document.getElementById('sum-from-date').value : '';
  const to = document.getElementById('sum-to-date') ? document.getElementById('sum-to-date').value : '';
  const month = document.getElementById('sum-acct-month') ? document.getElementById('sum-acct-month').value : '';
  const fy = document.getElementById('sum-fy') ? document.getElementById('sum-fy').value : '';

  window.location.href = `api.php?action=export_summary_excel&filter_type=${mode}&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}&accounting_month=${encodeURIComponent(month)}&financial_year_val=${encodeURIComponent(fy)}`;
}

// Detailed Page Logic
function initDetailedPage() {
  const btnLoad = document.getElementById('btn-load-detailed');
  const btnExcel = document.getElementById('btn-excel-detailed');

  if (btnLoad) btnLoad.addEventListener('click', loadDetailedReport);
  if (btnExcel) btnExcel.addEventListener('click', exportDetailedExcel);
}

async function loadDetailedReport() {
  const modeRadio = document.querySelector('input[name="detailed-mode"]:checked');
  const mode = modeRadio ? modeRadio.value : 'date';

  const from = document.getElementById('det-from-date') ? document.getElementById('det-from-date').value : '';
  const to = document.getElementById('det-to-date') ? document.getElementById('det-to-date').value : '';
  const month = document.getElementById('det-acct-month') ? document.getElementById('det-acct-month').value : '';
  const fy = document.getElementById('det-fy') ? document.getElementById('det-fy').value : '';

  const body = document.getElementById('detailed-body');
  const status = document.getElementById('detailed-status');

  if (!body) return;

  body.innerHTML = `<tr><td colspan="18" align="center">Loading detailed report...</td></tr>`;

  try {
    const url = `api.php?action=get_detailed_report&filter_type=${mode}&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}&accounting_month=${encodeURIComponent(month)}&financial_year_val=${encodeURIComponent(fy)}`;
    const res = await fetch(url);
    const data = await res.json();

    if (!data.success) {
      showAlertModal("Error", data.error, true);
      return;
    }

    const records = data.records || [];
    if (status) status.innerText = `Loaded ${records.length} record(s). ${data.description || ''}`;

    let totAmt = 0;
    let rowsHtml = records.map(r => {
      totAmt += parseFloat(r.total_amount || 0);
      return `
        <tr>
          <td align="center">${escapeHtml(r.major_head_dr)}</td>
          <td align="center">${escapeHtml(r.sub_major_dr)}</td>
          <td align="center">${escapeHtml(r.minor_head_dr)}</td>
          <td align="center">${escapeHtml(r.sub_head_dr)}</td>
          <td align="center">${escapeHtml(r.detail_head_dr)}</td>
          <td align="center">${escapeHtml(r.sub_detail_dr)}</td>
          <td align="right">Rs. ${formatMoney(r.total_amount)}</td>
          <td align="center">${escapeHtml(r.major_head_cr)}</td>
          <td align="center">${escapeHtml(r.sub_major_cr)}</td>
          <td align="center">${escapeHtml(r.minor_head_cr)}</td>
          <td align="center">${escapeHtml(r.sub_head_cr)}</td>
          <td align="center">${escapeHtml(r.detail_head_cr)}</td>
          <td align="center">${escapeHtml(r.sub_detail_cr)}</td>
          <td align="center">${escapeHtml(r.sectional_no)}</td>
          <td align="center"><strong>${escapeHtml(r.tr_no)}</strong></td>
          <td>${escapeHtml(r.tr_desc)}</td>
          <td>${escapeHtml(r.ministry_name)}</td>
          <td align="center">${escapeHtml(r.posting_date)}</td>
        </tr>
      `;
    }).join('');

    if (records.length) {
      rowsHtml += `
        <tr class="total-row">
          <td colspan="6">Total</td>
          <td align="right">Rs. ${formatMoney(totAmt)}</td>
          <td colspan="6"></td>
          <td><strong>${records.length} records</strong></td>
          <td colspan="4"></td>
        </tr>
      `;
    }

    body.innerHTML = rowsHtml || `<tr><td colspan="18" align="center">No data found for filter.</td></tr>`;
  } catch (err) {
    showAlertModal("Error", err.message, true);
  }
}

function exportDetailedExcel() {
  const modeRadio = document.querySelector('input[name="detailed-mode"]:checked');
  const mode = modeRadio ? modeRadio.value : 'date';

  const from = document.getElementById('det-from-date') ? document.getElementById('det-from-date').value : '';
  const to = document.getElementById('det-to-date') ? document.getElementById('det-to-date').value : '';
  const month = document.getElementById('det-acct-month') ? document.getElementById('det-acct-month').value : '';
  const fy = document.getElementById('det-fy') ? document.getElementById('det-fy').value : '';

  window.location.href = `api.php?action=export_detailed_excel&filter_type=${mode}&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}&accounting_month=${encodeURIComponent(month)}&financial_year_val=${encodeURIComponent(fy)}`;
}

// Master Management Page Logic
function initMasterPage() {
  const searchBtn = document.getElementById('btn-search-master');
  const addBtn = document.getElementById('btn-add-master');

  if (searchBtn) searchBtn.addEventListener('click', loadMasterRecords);
  if (addBtn) addBtn.addEventListener('click', () => openMasterModal(null));

  loadMasterRecords();
}

let masterRecords = [];

async function loadMasterRecords() {
  const searchInput = document.getElementById('search-master-input');
  const q = searchInput ? searchInput.value : '';

  const body = document.getElementById('master-body');
  const status = document.getElementById('master-status');

  if (!body) return;

  try {
    const res = await fetch(`api.php?action=get_master_records&search=${encodeURIComponent(q)}`);
    const data = await res.json();

    if (data.success) {
      masterRecords = data.records || [];
      masterRecords.sort((a, b) => (a.tr_code || '').localeCompare((b.tr_code || ''), undefined, { numeric: true, sensitivity: 'base' }));

      if (status) {
        if (currentUserRole === 'ADMIN') {
          status.innerText = `Showing ${masterRecords.length} Scheme Configuration Master record(s).`;
        } else {
          status.innerText = `Showing ${masterRecords.length} Scheme Configuration Master record(s). (Read-only mode for ${currentUserRole}s)`;
        }
      }

      body.innerHTML = masterRecords.map(r => `
        <tr>
          <td align="center">${r.id}</td>
          <td align="center"><strong>${escapeHtml(r.tr_code || '-')}</strong></td>
          <td>${escapeHtml(r.tr_desc || '-')}</td>
          <td>${escapeHtml(r.controller || '-')}</td>
          <td align="center">${r.central_share !== null ? r.central_share + '%' : '-'}</td>
          <td align="center">${r.state_share !== null ? r.state_share + '%' : '-'}</td>
          <td align="center">${r.sub_head !== null ? r.sub_head : '-'}</td>
          <td align="center">${r.detail_head !== null ? r.detail_head : '-'}</td>
          <td align="center">
            ${currentUserRole === 'ADMIN' ? `<button onclick="editMasterRecord(${r.id})" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem;">Edit</button>` : '-'}
          </td>
        </tr>
      `).join('') || `<tr><td colspan="9" align="center">No scheme master records found.</td></tr>`;
    }
  } catch (e) { }
}

function openMasterModal(record) {
  const modal = document.getElementById('master-modal');
  const modalTitle = document.getElementById('master-modal-title');

  if (modalTitle) {
    modalTitle.innerText = record ? "Edit Scheme Master Record" : "Add New Scheme Master Record";
  }

  const elId = document.getElementById('master-id');
  const elTrCode = document.getElementById('master-tr-code');
  const elTrDesc = document.getElementById('master-tr-desc');
  const elController = document.getElementById('master-controller');
  const elCss = document.getElementById('master-css');
  const elCentral = document.getElementById('master-central');
  const elState = document.getElementById('master-state');
  const elSub = document.getElementById('master-sub');
  const elDetail = document.getElementById('master-detail');

  if (elId) elId.value = record ? record.id : '';
  if (elTrCode) elTrCode.value = record ? record.tr_code : '';
  if (elTrDesc) elTrDesc.value = record ? record.tr_desc : '';
  if (elController) elController.value = record ? record.controller : '';
  if (elCss) elCss.value = record ? record.css : '';
  if (elCentral) elCentral.value = record ? record.central_share : '100';
  if (elState) elState.value = record ? record.state_share : '0';
  if (elSub) elSub.value = record ? record.sub_head : '';
  if (elDetail) elDetail.value = record ? record.detail_head : '';

  if (modal) modal.classList.add('active');
}

function closeMasterModal() {
  const modal = document.getElementById('master-modal');
  if (modal) modal.classList.remove('active');
}

function editMasterRecord(id) {
  const rec = masterRecords.find(r => String(r.id) === String(id));
  if (rec) openMasterModal(rec);
}

async function deleteMasterRecord(id) {
  const rec = masterRecords.find(r => String(r.id) === String(id));
  const label = rec ? (rec.tr_code || ('ID ' + id)) : ('ID ' + id);
  if (!confirm(`Are you sure you want to delete Scheme Master record '${label}'?`)) return;
  const formData = new FormData();
  formData.append('id', id);
  try {
    const res = await fetch('api.php?action=delete_master_record', { method: 'POST', body: formData });
    const data = await res.json();
    if (!data.success) {
      showAlertModal("Delete Failed", data.error, true);
      return;
    }
    loadMasterRecords();
  } catch (err) {
    showAlertModal("Error", err.message, true);
  }
}

async function saveMasterRecord() {
  const elId = document.getElementById('master-id');
  const elTrCode = document.getElementById('master-tr-code');

  const id = elId ? elId.value : '';
  const trCode = elTrCode ? elTrCode.value : '';

  if (!trCode.trim()) {
    alert("TR Code is required.");
    return;
  }

  const formData = new FormData();
  formData.append('id', id);
  formData.append('tr_code', trCode);
  formData.append('tr_desc', document.getElementById('master-tr-desc') ? document.getElementById('master-tr-desc').value : '');
  formData.append('controller', document.getElementById('master-controller') ? document.getElementById('master-controller').value : '');
  formData.append('css', document.getElementById('master-css') ? document.getElementById('master-css').value : '');
  formData.append('central_share', document.getElementById('master-central') ? document.getElementById('master-central').value : '');
  formData.append('state_share', document.getElementById('master-state') ? document.getElementById('master-state').value : '');
  formData.append('sub_head', document.getElementById('master-sub') ? document.getElementById('master-sub').value : '');
  formData.append('detail_head', document.getElementById('master-detail') ? document.getElementById('master-detail').value : '');

  const action = id ? 'update_master_record' : 'add_master_record';

  try {
    const res = await fetch(`api.php?action=${action}`, { method: 'POST', body: formData });
    const data = await res.json();

    if (!data.success) {
      showAlertModal("Save Failed", data.error, true);
      return;
    }

    closeMasterModal();
    loadMasterRecords();
    alert("Master record saved successfully!");
  } catch (err) {
    showAlertModal("Error", err.message, true);
  }
}

// Global Exports
window.editMasterRecord = editMasterRecord;
window.deleteMasterRecord = deleteMasterRecord;
window.openMasterModal = openMasterModal;
window.closeMasterModal = closeMasterModal;
window.saveMasterRecord = saveMasterRecord;