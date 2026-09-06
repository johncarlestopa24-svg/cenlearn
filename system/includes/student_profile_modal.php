<?php
// Student Profile Status Modal Component for CenLearn
// Clean, Perfectly Centered, Responsive, and Sleek Dark Slate Theme (Light + Dark Mode Ready)
?>
<!-- Student Profile Modal Backdrop -->
<div class="sp-modal-backdrop" id="spModalBackdrop" onclick="if(event.target===this) closeStudentProfileModal();">
  <div class="sp-modal-container" id="spModalContainer" role="dialog" aria-modal="true">
    
    <!-- Modal Header -->
    <div class="sp-modal-header">
      <div class="sp-modal-title">
        <i class="fa fa-user"></i>
        <span>Student Profile &bull; Status</span>
      </div>
      <button type="button" class="sp-modal-close" onclick="closeStudentProfileModal()" aria-label="Close">&times;</button>
    </div>

    <!-- Modal Body -->
    <div class="sp-modal-body">
      
      <!-- Student Information Card -->
      <div class="sp-info-card">
        <div class="sp-info-header">
          <i class="fa fa-user"></i>
          <span>Student Information</span>
        </div>

        <div class="sp-info-grid">
          <!-- Left Column -->
          <div class="sp-info-col">
            <div class="sp-info-row">
              <span class="sp-label">Student Name</span>
              <span class="sp-colon">:</span>
              <span class="sp-val" id="spStudentNameVal">John Carl Dara-Ug</span>
            </div>
            <div class="sp-info-row">
              <span class="sp-label">Student Code</span>
              <span class="sp-colon">:</span>
              <span class="sp-val mono" id="spStudentCodeVal">2023119490</span>
            </div>
            <div class="sp-info-row">
              <span class="sp-label">Program</span>
              <span class="sp-colon">:</span>
              <span class="sp-val" id="spProgramVal">Bachelor of Science in Information Technology</span>
            </div>
            <div class="sp-info-row no-border-mb">
              <span class="sp-label">Year Level</span>
              <span class="sp-colon">:</span>
              <span class="sp-val" id="spYearLevelVal">3rd Year</span>
            </div>
          </div>

          <!-- Right Column -->
          <div class="sp-info-col">
            <div class="sp-info-row">
              <span class="sp-label">Section</span>
              <span class="sp-colon">:</span>
              <span class="sp-val" id="spSectionVal">BSIT-3A</span>
            </div>
            <div class="sp-info-row">
              <span class="sp-label">Email</span>
              <span class="sp-colon">:</span>
              <span class="sp-val" id="spEmailVal">student@example.com</span>
            </div>
            <div class="sp-info-row">
              <span class="sp-label">Enrollment Date</span>
              <span class="sp-colon">:</span>
              <span class="sp-val" id="spEnrollDateVal">August 2026</span>
            </div>
            <div class="sp-info-row no-border">
              <span class="sp-label">Last Activity</span>
              <span class="sp-colon">:</span>
              <span class="sp-val" id="spLastActivityVal">September 6, 2026</span>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- Modal Footer Bar -->
    <div class="sp-modal-footer">
      <button type="button" class="sp-btn sp-btn-close" onclick="closeStudentProfileModal()">Close</button>
    </div>

  </div>
</div>

<style>
/* Perfectly Centered Student Profile Status Modal CSS */
.sp-modal-backdrop {
  position: fixed !important;
  top: 0 !important;
  left: 0 !important;
  right: 0 !important;
  bottom: 0 !important;
  width: 100vw !important;
  height: 100vh !important;
  background: rgba(15, 23, 42, 0.68);
  backdrop-filter: blur(5px);
  -webkit-backdrop-filter: blur(5px);
  z-index: 999999 !important;
  display: none;
  align-items: center !important;
  justify-content: center !important;
  padding: 20px !important;
  margin: 0 !important;
  box-sizing: border-box !important;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  opacity: 0;
  transition: opacity 0.22s ease-out;
}
.sp-modal-backdrop.show {
  display: flex !important;
  opacity: 1 !important;
}

.sp-modal-container {
  margin: auto !important;
  position: relative !important;
  background: #ffffff;
  width: 100% !important;
  max-width: 760px !important;
  max-height: 88vh !important;
  border-radius: 16px !important;
  overflow-y: auto !important;
  box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.4) !important;
  transform: scale(0.96) !important;
  transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1) !important;
  display: flex !important;
  flex-direction: column !important;
  box-sizing: border-box !important;
}
.sp-modal-backdrop.show .sp-modal-container {
  transform: scale(1) !important;
}

/* Custom Scrollbar */
.sp-modal-container::-webkit-scrollbar {
  width: 6px;
}
.sp-modal-container::-webkit-scrollbar-track {
  background: #f1f5f9;
}
.sp-modal-container::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 99px;
}

/* Modal Header */
.sp-modal-header {
  background: #0f2942;
  padding: 16px 24px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
  flex-shrink: 0 !important;
}
.sp-modal-title {
  color: #ffffff !important;
  font-size: 16px !important;
  font-weight: 700 !important;
  display: flex !important;
  align-items: center !important;
  gap: 10px !important;
  letter-spacing: -0.2px !important;
}
.sp-modal-title i {
  color: #93c5fd !important;
  font-size: 15px !important;
}
.sp-modal-close {
  background: none !important;
  border: none !important;
  color: #94a3b8 !important;
  font-size: 24px !important;
  font-weight: 400 !important;
  line-height: 1 !important;
  cursor: pointer !important;
  padding: 0 !important;
  margin: 0 !important;
  transition: color 0.15s ease !important;
}
.sp-modal-close:hover {
  color: #ffffff !important;
}

/* Modal Body */
.sp-modal-body {
  padding: 24px !important;
  background: #f8fafc;
  flex: 1 !important;
  box-sizing: border-box !important;
}

/* Student Information Card */
.sp-info-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px !important;
  padding: 22px 26px !important;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02) !important;
  box-sizing: border-box !important;
}

.sp-info-header {
  font-size: 15px !important;
  font-weight: 800 !important;
  color: #0f172a;
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
  margin-bottom: 16px !important;
}
.sp-info-header i {
  color: #3b82f6;
  font-size: 15px !important;
}

.sp-info-grid {
  display: grid !important;
  grid-template-columns: 1fr 1fr !important;
  gap: 0 36px !important;
}
@media (max-width: 640px) {
  .sp-info-grid {
    grid-template-columns: 1fr !important;
    gap: 0 !important;
  }
}

.sp-info-row {
  display: flex !important;
  align-items: center !important;
  padding: 12px 0 !important;
  border-bottom: 1px solid #f1f5f9;
  font-size: 13px !important;
}
@media (max-width: 640px) {
  .sp-info-row.no-border-mb {
    border-bottom: 1px solid #f1f5f9;
  }
}
.sp-info-row.no-border {
  border-bottom: none !important;
}

.sp-label {
  width: 120px !important;
  color: #64748b;
  font-weight: 500 !important;
  flex-shrink: 0 !important;
  font-size: 12.5px !important;
}
.sp-colon {
  color: #94a3b8;
  margin-right: 12px !important;
  flex-shrink: 0 !important;
}
.sp-val {
  color: #0f172a;
  font-weight: 700 !important;
  word-break: break-word !important;
  flex: 1 !important;
}
.sp-val.mono {
  font-family: monospace !important;
  font-size: 13.5px !important;
}

/* Modal Footer */
.sp-modal-footer {
  background: #f8fafc;
  border-top: 1px solid #e2e8f0;
  padding: 14px 24px !important;
  display: flex !important;
  align-items: center !important;
  justify-content: flex-end !important;
  flex-shrink: 0 !important;
}

.sp-btn {
  height: 38px !important;
  border-radius: 8px !important;
  padding: 0 24px !important;
  font-size: 13px !important;
  font-weight: 600 !important;
  font-family: inherit !important;
  cursor: pointer !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  transition: all 0.15s ease !important;
  border: none !important;
}
.sp-btn-close {
  background: #ffffff;
  color: #334155;
  border: 1px solid #cbd5e1;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
.sp-btn-close:hover {
  background: #f1f5f9;
  border-color: #94a3b8;
  color: #0f172a;
}

</style>

<script>
// Student Profile Status Modal Controller
function openStudentProfileModal(studentCode = '') {
  const backdrop = document.getElementById('spModalBackdrop');
  if(!backdrop) return;

  // Show modal backdrop
  backdrop.classList.add('show');

  // Fetch student profile status via AJAX
  const url = '/cenlearn/system/shared/get_student_profile_data.php' + (studentCode ? '?student_code=' + encodeURIComponent(studentCode) : '');
  
  fetch(url)
    .then(r => r.json())
    .then(res => {
      if(res && res.success && res.data) {
        renderStudentProfileModal(res.data);
      } else if(res && res.data) {
        renderStudentProfileModal(res.data);
      }
    })
    .catch(err => {
      console.error('Error fetching profile:', err);
    });
}

function closeStudentProfileModal() {
  const backdrop = document.getElementById('spModalBackdrop');
  if(backdrop) backdrop.classList.remove('show');
}

function renderStudentProfileModal(data) {
  if(!data) return;

  // Information fields
  const nameEl = document.getElementById('spStudentNameVal');
  const codeEl = document.getElementById('spStudentCodeVal');
  const progEl = document.getElementById('spProgramVal');
  const ylEl = document.getElementById('spYearLevelVal');
  const secEl = document.getElementById('spSectionVal');
  const emailEl = document.getElementById('spEmailVal');
  const dateEl = document.getElementById('spEnrollDateVal');
  const actEl = document.getElementById('spLastActivityVal');

  if(nameEl) nameEl.innerText = data.student_name || 'N/A';
  if(codeEl) codeEl.innerText = data.student_code || 'N/A';
  if(progEl) progEl.innerText = data.program || 'N/A';
  if(ylEl) ylEl.innerText = data.year_level || 'N/A';
  if(secEl) secEl.innerText = data.section || 'N/A';
  if(emailEl) emailEl.innerText = data.email || 'N/A';
  if(dateEl) dateEl.innerText = data.enrollment_date || 'August 2026';
  if(actEl) actEl.innerText = data.last_activity || 'September 6, 2026';
}
</script>
