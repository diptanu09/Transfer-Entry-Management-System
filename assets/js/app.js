/**
 * Main Web Application Logic for PHP Version
 */

document.addEventListener('DOMContentLoaded', () => {
  initAuth();
  initTabs();
  initUploadPage();
  initViewDataPage();
  initGeneratePdfPage();
  initViewPdfPage();
  initSummaryPage();
  initDetailedPage();
  initMasterPage();
});

// Authentication System
async function initAuth() {
  const loginForm = document.getElementById('login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', handleLogin);
  }
  await checkAuthStatus();
}

async function checkAuthStatus() {
  const modal = document.getElementById('login-modal');
  const userArea = document.getElementById('user-info-area');
  const userName = document.getElementById('logged-user-name');

  try {
    const res = await fetch('api.php?action=check_auth');
    const data = await res.json();

    if (data.authenticated) {
      modal.classList.remove('active');
      if (userArea) userArea.style.display = 'flex';
      if (userName) userName.innerText = `User: ${data.user}`;
    } else {
      modal.classList.add('active');
      if (userArea) userArea.style.display = 'none';
    }
  } catch (err) {
    modal.classList.add('active');
  }
}

async function handleLogin(e) {
  e.preventDefault();
  const userId = document.getElementById('login-user-id').value.trim();
  const pwd = document.getElementById('login-password').value.trim();
  const errBox = document.getElementById('login-error-msg');

  errBox.style.display = 'none';

  const formData = new FormData();
  formData.append('user_id', userId);
  formData.append('password', pwd);

  try {
    const res = await fetch('api.php?action=login', { method: 'POST', body: formData });
    const data = await res.json();

    if (!data.success) {
      errBox.innerText = data.error || "Invalid Login ID or Password!";
      errBox.style.display = 'block';
      return;
    }

    document.getElementById('login-modal').classList.remove('active');
    document.getElementById('user-info-area').style.display = 'flex';
    document.getElementById('logged-user-name').innerText = `User: ${data.user}`;
    
    // Load initial data
    loadRecentUploads();
  } catch (err) {
    errBox.innerText = err.message;
    errBox.style.display = 'block';
  }
}

async function handleLogout() {
  try {
    await fetch('api.php?action=logout');
    checkAuthStatus();
  } catch (e) {
    location.reload();
  }
}

// Tab Navigation
function initTabs() {
  const tabBtns = document.querySelectorAll('.tab-btn');
  const tabContents = document.querySelectorAll('.tab-content');

  tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      tabBtns.forEach(b => b.classList.remove('active'));
      tabContents.forEach(c => c.classList.remove('active'));

      btn.classList.add('active');
      const targetId = btn.dataset.tab;
      document.getElementById(targetId).classList.add('active');

      // Refresh page data on tab activate
      if (targetId === 'tab-upload') loadRecentUploads();
      if (targetId === 'tab-view-data') loadViewData();
      if (targetId === 'tab-generate') loadNextSectionalNumber();
      if (targetId === 'tab-view-pdf') loadPdfList();
      if (targetId === 'tab-master') loadMasterRecords();
    });
  });
}

// Utility: Modal Alert
function showAlertModal(title, message, isError = false) {
  const modal = document.getElementById('alert-modal');
  document.getElementById('alert-modal-title').innerText = title;
  const body = document.getElementById('alert-modal-body');
  
  if (isError) {
    body.innerHTML = `<div class="alert-error-box">${escapeHtml(message)}</div>`;
  } else {
    body.innerText = message;
  }
  
  modal.classList.add('active');
}

function closeAlertModal() {
  document.getElementById('alert-modal').classList.remove('active');
}

function escapeHtml(text) {
  return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
}

function formatMoney(num) {
  return new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(num || 0);
}

// 1. Upload Page Logic
function initUploadPage() {
  const form = document.getElementById('upload-form');
  if (!form) return;

  loadRecentUploads();

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fileInput = document.getElementById('upload-file');
    const dateInput = document.getElementById('upload-date');
    const statusLabel = document.getElementById('upload-status');

    if (!fileInput.files.length) {
      alert("Choose a payment file first.");
      return;
    }

    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    formData.append('report_date', dateInput.value);

    statusLabel.innerText = "Uploading to database... Please wait.";

    try {
      const res = await fetch('api.php?action=upload_file', { method: 'POST', body: formData });
      const data = await res.json();

      if (!data.success) {
        statusLabel.innerText = "Upload failed.";
        showAlertModal("Upload Failed", data.error, true);
        return;
      }

      statusLabel.innerText = `Uploaded ${data.count} records successfully. Total amount: Rs. ${formatMoney(data.total_amount)}`;
      loadRecentUploads();
    } catch (err) {
      statusLabel.innerText = "Upload failed.";
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
      tableBody.innerHTML = data.uploads.map(u => `
        <tr>
          <td>${u.report_date}</td>
          <td><strong>${escapeHtml(u.source_file_name)}</strong></td>
          <td align="right">${u.record_count}</td>
          <td align="right">Rs. ${formatMoney(u.total_amount)}</td>
          <td>${u.uploaded_at}</td>
        </tr>
      `).join('') || `<tr><td colspan="5" align="center">No uploads yet.</td></tr>`;
    }
  } catch (err) {
    console.error(err);
  }
}

// 2. View Data Page Logic
function initViewDataPage() {
  const btn = document.getElementById('btn-filter-view-data');
  if (btn) btn.addEventListener('click', loadViewData);
}

async function loadViewData() {
  const startDate = document.getElementById('view-start-date').value;
  const endDate = document.getElementById('view-end-date').value;
  const body = document.getElementById('view-data-body');
  const summary = document.getElementById('view-data-summary');

  body.innerHTML = `<tr><td colspan="6" align="center">Loading records...</td></tr>`;

  try {
    const res = await fetch(`api.php?action=get_view_data&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`);
    const data = await res.json();

    if (!data.success) {
      showAlertModal("Error", data.error, true);
      return;
    }

    const records = data.records;
    let totalAmt = 0;
    records.forEach(r => totalAmt += parseFloat(r.amount || 0));

    summary.innerText = `${records.length} record(s) loaded | Total amount: Rs. ${formatMoney(totalAmt)}`;

    body.innerHTML = records.map(r => `
      <tr>
        <td>${r.posting_date} ${r.posting_time}</td>
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

// 3. Generate PDF Page Logic
function initGeneratePdfPage() {
  loadNextSectionalNumber();
  const btn = document.getElementById('btn-generate-pdfs');
  if (btn) btn.addEventListener('click', generatePdfs);
}

async function loadNextSectionalNumber() {
  const input = document.getElementById('gen-sec-num');
  if (!input) return;
  try {
    const res = await fetch('api.php?action=get_next_sectional_number');
    const data = await res.json();
    if (data.success) input.value = data.next_sectional_number;
  } catch (e) {}
}

async function generatePdfs() {
  const fromDate = document.getElementById('gen-from-date').value;
  const toDate = document.getElementById('gen-to-date').value;
  const acctMonth = document.getElementById('gen-acct-month').value;
  const secNum = document.getElementById('gen-sec-num').value;
  const statusLabel = document.getElementById('gen-status');

  statusLabel.innerText = "Validating TR codes & generating reports... Please wait.";

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
      statusLabel.innerText = "PDF generation failed.";
      showAlertModal("PDF Generation Failed", data.error || "An error occurred during PDF generation.", true);
      return;
    }

    statusLabel.innerText = `Generated ${data.generated} PDF report(s) successfully!`;
    loadNextSectionalNumber();
    alert(`Generated ${data.generated} PDF report(s) successfully!`);
  } catch (err) {
    statusLabel.innerText = "PDF generation failed.";
    showAlertModal("Error", err.message || "An unknown error occurred.", true);
  }
}

// 4. View PDF Page Logic
function initViewPdfPage() {
  const btn = document.getElementById('btn-filter-pdf');
  if (btn) btn.addEventListener('click', loadPdfList);
}

async function loadPdfList() {
  const body = document.getElementById('pdf-list-body');
  if (!body) return;

  const startDate = document.getElementById('pdf-start-date').value;
  const endDate = document.getElementById('pdf-end-date').value;

  try {
    const res = await fetch(`api.php?action=get_pdf_list&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`);
    const data = await res.json();

    if (data.success) {
      body.innerHTML = data.reports.map(r => `
        <tr>
          <td>BK/TE/${r.accounting_month}/${r.sectional_number}</td>
          <td>${r.posting_date} ${r.posting_time}</td>
          <td><strong>${escapeHtml(r.sg_account_name)}</strong></td>
          <td align="right">Rs. ${formatMoney(r.amount)}</td>
          <td>${r.accounting_month}</td>
          <td>${r.generation_date}</td>
          <td><a href="api.php?action=download_pdf&id=${r.id}" target="_blank" class="btn btn-secondary" style="padding: 4px 10px; font-size: 0.8rem;">Open PDF</a></td>
        </tr>
      `).join('') || `<tr><td colspan="7" align="center">No generated PDFs found.</td></tr>`;
    }
  } catch (e) {}
}

// 5. Summary Page Logic
function initSummaryPage() {
  const btnLoad = document.getElementById('btn-load-summary');
  const btnExcel = document.getElementById('btn-excel-summary');

  if (btnLoad) btnLoad.addEventListener('click', loadSummaryReport);
  if (btnExcel) btnExcel.addEventListener('click', exportSummaryExcel);
}

async function loadSummaryReport() {
  const mode = document.querySelector('input[name="summary-mode"]:checked').value;
  const from = document.getElementById('sum-from-date').value;
  const to = document.getElementById('sum-to-date').value;
  const month = document.getElementById('sum-acct-month').value;
  const fy = document.getElementById('sum-fy').value;

  const body = document.getElementById('summary-body');
  const status = document.getElementById('summary-status');

  body.innerHTML = `<tr><td colspan="11" align="center">Loading summary report...</td></tr>`;

  try {
    const url = `api.php?action=get_summary_report&filter_type=${mode}&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}&accounting_month=${encodeURIComponent(month)}&financial_year_val=${encodeURIComponent(fy)}`;
    const res = await fetch(url);
    const data = await res.json();

    if (!data.success) {
      showAlertModal("Error", data.error, true);
      return;
    }

    const records = data.records;
    status.innerText = `Loaded ${records.length} record(s). ${data.description}`;

    let totAmt = 0, totC = 0, totS = 0;
    let rowsHtml = records.map((r, i) => {
      totAmt += r.total_amount;
      totC += r.central_share_amount;
      totS += r.state_share_amount;
      return `
        <tr>
          <td align="center">${i + 1}</td>
          <td>${escapeHtml(r.ministry_name)}</td>
          <td align="center"><strong>${escapeHtml(r.tr_no)}</strong></td>
          <td>${escapeHtml(r.tr_desc)}</td>
          <td align="right">Rs. ${formatMoney(r.total_amount)}</td>
          <td align="right">Rs. ${formatMoney(r.central_share_amount)}</td>
          <td align="right">Rs. ${formatMoney(r.state_share_amount)}</td>
          <td align="center">${r.sub_head}</td>
          <td align="center">${r.detail_head}</td>
          <td align="center">${r.sectional_number}</td>
          <td align="center">${r.posting_date}</td>
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
  const mode = document.querySelector('input[name="summary-mode"]:checked').value;
  const from = document.getElementById('sum-from-date').value;
  const to = document.getElementById('sum-to-date').value;
  const month = document.getElementById('sum-acct-month').value;
  const fy = document.getElementById('sum-fy').value;

  window.location.href = `api.php?action=export_summary_excel&filter_type=${mode}&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}&accounting_month=${encodeURIComponent(month)}&financial_year_val=${encodeURIComponent(fy)}`;
}

// 6. Detailed Page Logic
function initDetailedPage() {
  const btnLoad = document.getElementById('btn-load-detailed');
  const btnExcel = document.getElementById('btn-excel-detailed');

  if (btnLoad) btnLoad.addEventListener('click', loadDetailedReport);
  if (btnExcel) btnExcel.addEventListener('click', exportDetailedExcel);
}

async function loadDetailedReport() {
  const mode = document.querySelector('input[name="detailed-mode"]:checked').value;
  const from = document.getElementById('det-from-date').value;
  const to = document.getElementById('det-to-date').value;
  const month = document.getElementById('det-acct-month').value;
  const fy = document.getElementById('det-fy').value;

  const body = document.getElementById('detailed-body');
  const status = document.getElementById('detailed-status');

  body.innerHTML = `<tr><td colspan="18" align="center">Loading detailed report...</td></tr>`;

  try {
    const url = `api.php?action=get_detailed_report&filter_type=${mode}&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}&accounting_month=${encodeURIComponent(month)}&financial_year_val=${encodeURIComponent(fy)}`;
    const res = await fetch(url);
    const data = await res.json();

    if (!data.success) {
      showAlertModal("Error", data.error, true);
      return;
    }

    const records = data.records;
    status.innerText = `Loaded ${records.length} record(s). ${data.description}`;

    let totAmt = 0;
    let rowsHtml = records.map(r => {
      totAmt += r.total_amount;
      return `
        <tr>
          <td align="center">${r.major_head_dr}</td>
          <td align="center">${r.sub_major_dr}</td>
          <td align="center">${r.minor_head_dr}</td>
          <td align="center">${r.sub_head_dr}</td>
          <td align="center">${r.detail_head_dr}</td>
          <td align="center">${r.sub_detail_dr}</td>
          <td align="right">Rs. ${formatMoney(r.total_amount)}</td>
          <td align="center">${r.major_head_cr}</td>
          <td align="center">${r.sub_major_cr}</td>
          <td align="center">${r.minor_head_cr}</td>
          <td align="center">${r.sub_head_cr}</td>
          <td align="center">${r.detail_head_cr}</td>
          <td align="center">${r.sub_detail_cr}</td>
          <td align="center">${r.sectional_no}</td>
          <td align="center"><strong>${escapeHtml(r.tr_no)}</strong></td>
          <td>${escapeHtml(r.tr_desc)}</td>
          <td>${escapeHtml(r.ministry_name)}</td>
          <td align="center">${r.posting_date}</td>
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
  const mode = document.querySelector('input[name="detailed-mode"]:checked').value;
  const from = document.getElementById('det-from-date').value;
  const to = document.getElementById('det-to-date').value;
  const month = document.getElementById('det-acct-month').value;
  const fy = document.getElementById('det-fy').value;

  window.location.href = `api.php?action=export_detailed_excel&filter_type=${mode}&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}&accounting_month=${encodeURIComponent(month)}&financial_year_val=${encodeURIComponent(fy)}`;
}

// 7. Master Management Page Logic
function initMasterPage() {
  const searchBtn = document.getElementById('btn-search-master');
  const addBtn = document.getElementById('btn-add-master');

  if (searchBtn) searchBtn.addEventListener('click', loadMasterRecords);
  if (addBtn) addBtn.addEventListener('click', () => openMasterModal(null));

  loadMasterRecords();
}

let masterRecords = [];

async function loadMasterRecords() {
  const q = document.getElementById('search-master-input').value;
  const body = document.getElementById('master-body');
  const status = document.getElementById('master-status');

  try {
    const res = await fetch(`api.php?action=get_master_records&search=${encodeURIComponent(q)}`);
    const data = await res.json();

    if (data.success) {
      masterRecords = data.records;
      status.innerText = `Showing ${masterRecords.length} Scheme Configuration Master record(s). Password '12345' is required to save changes.`;

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
            <button onclick="editMasterRecord(${r.id})" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem;">Edit</button>
          </td>
        </tr>
      `).join('') || `<tr><td colspan="9" align="center">No scheme master records found.</td></tr>`;
    }
  } catch (e) {}
}

function openMasterModal(record) {
  const modal = document.getElementById('master-modal');
  document.getElementById('master-modal-title').innerText = record ? "Edit Scheme Master Record" : "Add New Scheme Master Record";
  document.getElementById('master-id').value = record ? record.id : '';
  document.getElementById('master-tr-code').value = record ? record.tr_code : '';
  document.getElementById('master-tr-desc').value = record ? record.tr_desc : '';
  document.getElementById('master-controller').value = record ? record.controller : '';
  document.getElementById('master-css').value = record ? record.css : '';
  document.getElementById('master-central').value = record ? record.central_share : '100';
  document.getElementById('master-state').value = record ? record.state_share : '0';
  document.getElementById('master-sub').value = record ? record.sub_head : '';
  document.getElementById('master-detail').value = record ? record.detail_head : '';
  document.getElementById('master-password').value = '';

  modal.classList.add('active');
}

function closeMasterModal() {
  document.getElementById('master-modal').classList.remove('active');
}

function editMasterRecord(id) {
  const rec = masterRecords.find(r => r.id === id);
  if (rec) openMasterModal(rec);
}

async function saveMasterRecord() {
  const id = document.getElementById('master-id').value;
  const pwd = document.getElementById('master-password').value;
  const trCode = document.getElementById('master-tr-code').value;

  if (!trCode.trim()) {
    alert("TR Code is required.");
    return;
  }
  if (!pwd) {
    alert("Password '12345' is required to save changes.");
    return;
  }

  const formData = new FormData();
  formData.append('id', id);
  formData.append('password', pwd);
  formData.append('tr_code', trCode);
  formData.append('tr_desc', document.getElementById('master-tr-desc').value);
  formData.append('controller', document.getElementById('master-controller').value);
  formData.append('css', document.getElementById('master-css').value);
  formData.append('central_share', document.getElementById('master-central').value);
  formData.append('state_share', document.getElementById('master-state').value);
  formData.append('sub_head', document.getElementById('master-sub').value);
  formData.append('detail_head', document.getElementById('master-detail').value);

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

async function deleteMasterRecord(id, trCode) {
  const pwd = prompt(`Enter password '12345' to confirm deletion of '${trCode}':`);
  if (pwd === null) return;

  const formData = new FormData();
  formData.append('id', id);
  formData.append('password', pwd);

  try {
    const res = await fetch('api.php?action=delete_master_record', { method: 'POST', body: formData });
    const data = await res.json();

    if (!data.success) {
      showAlertModal("Delete Failed", data.error, true);
      return;
    }

    loadMasterRecords();
    alert("Master record deleted successfully.");
  } catch (err) {
    showAlertModal("Error", err.message, true);
  }
}
