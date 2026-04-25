import { apiFetch } from '/assets/app.js';
import { renderSidebar } from '/assets/sidebar.js';

document.getElementById('sidebar-container').outerHTML = renderSidebar('entity_endeavours');

const entitySelect = document.getElementById('entity-select');
const createForm = document.getElementById('create-form');
const createStatus = document.getElementById('create-status');
const listEl = document.getElementById('endeavour-list');
const emptyEl = document.getElementById('endeavour-empty');
let currentUser = null;
let endeavoursRequestId = 0;

const setStatus = (el, message, ok) => {
    if (el._hideTimer) clearTimeout(el._hideTimer);
    if (!el._originalClass) el._originalClass = el.className;
    el.textContent = message;

    const baseClasses = el._originalClass.split(/\s+/).filter(c => c !== 'hidden').join(' ');
    el.className = `${baseClasses} text-sm rounded-lg px-4 py-3 font-medium border ${ok ? 'bg-[var(--color-success-bg)] text-[var(--color-success)] border-[rgba(16,185,129,0.2)]' : 'bg-[var(--color-danger-bg)] text-[var(--color-danger)] border-[rgba(239,68,68,0.2)]'}`;

    if (el.id === 'create-status') {
        el.classList.add('md:col-span-2', 'mt-6');
    }

    el._hideTimer = setTimeout(() => {
        el.className = el._originalClass;
        el.classList.add('hidden');
    }, 4000);
};

const loadDriveFiles = async (entityId) => {
    try {
        const response = await apiFetch(`/drive/list?entity_id=${encodeURIComponent(entityId)}`);
        return (response?.data || []).filter((item) => item.item_type === 'file');
    } catch (err) {
        console.warn('Failed to load drive files:', err);
        return [];
    }
};

const escapeHtml = (value) => {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
};

const fileOptions = (selectedId, driveFiles) => {
    const options = ['<option value="">Select file...</option>'];
    driveFiles.forEach((file) => {
        const selected = selectedId && Number(selectedId) === Number(file.id) ? 'selected' : '';
        const safeName = escapeHtml(file.name);
        options.push(`<option value="${file.id}" ${selected}>${safeName}</option>`);
    });
    return options.join('');
};

const formatDateTime = (value) => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
};

const nextDeadlineSummary = (row) => {
    const candidates = [
        ['Volunteer deadline', row.volunteer_registration_deadline],
        ['Pre-financial', row.pre_financial_deadline],
        ['Event starts', row.event_start_at || row.start_date],
        ['Event ends', row.event_end_at || row.end_date],
        ['Post-financial', row.post_financial_deadline]
    ];
    const now = Date.now();
    const upcoming = candidates
        .map(([label, value]) => {
            if (!value) return null;
            const date = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return null;
            return { label, date };
        })
        .filter(Boolean)
        .sort((left, right) => Math.abs(left.date.getTime() - now) - Math.abs(right.date.getTime() - now))[0];
    return upcoming ? `${upcoming.label}: ${formatDateTime(upcoming.date)}` : 'No deadline set';
};

const renderRegistrations = async (container, endeavourId, phase, transportFeeRequired = false) => {
    container.innerHTML = '<p class="text-xs text-[var(--text-tertiary)] font-medium">Loading registrations...</p>';
    try {
        const response = await apiFetch(`/endeavours/${endeavourId}/registrations`);
        const registrations = response?.data || [];
        if (!registrations.length) {
            container.innerHTML = '<p class="text-xs text-[var(--text-tertiary)] font-medium">No registrations yet.</p>';
            return;
        }
        container.innerHTML = '';
        registrations.forEach((reg) => {
            const status = String(reg.status || '').toLowerCase();
            const row = document.createElement('div');
            row.className = 'flex flex-wrap items-center justify-between gap-3 text-sm text-[var(--text-primary)] border border-[var(--border-subtle)] bg-[rgba(255,255,255,0.02)] rounded-lg p-3 shadow-sm transition-all hover:bg-[rgba(255,255,255,0.04)]';

            const nameContent = document.createElement('div');
            nameContent.className = 'flex flex-col min-w-[120px]';
            const nameStr = `<span class="font-semibold">${escapeHtml(reg.full_name)}</span>`;
            const statusStr = `<span class="text-xs text-[var(--text-tertiary)] uppercase tracking-wide mt-0.5">${escapeHtml(status || 'pending')}</span>`;
            nameContent.innerHTML = nameStr + statusStr;

            row.appendChild(nameContent);
            const actions = document.createElement('div');
            actions.className = 'flex gap-2';

            if (phase === 'VOLUNTEER_SHORTLISTING') {
                if (status !== 'shortlisted') {
                    const shortlist = document.createElement('button');
                    shortlist.className = 'btn text-xs py-1.5 px-3 bg-[#10b9811a] text-[#10b981] border border-[#10b98133] hover:bg-[#10b98133] font-semibold';
                    shortlist.textContent = 'Shortlist';
                    shortlist.addEventListener('click', async () => {
                        shortlist.disabled = true;
                        try {
                            await apiFetch(`/endeavours/${endeavourId}/registrations/shortlist`, { method: 'POST', body: JSON.stringify({ registration_id: reg.id }) });
                            await renderRegistrations(container, endeavourId, phase, transportFeeRequired);
                        } catch (err) {
                            alert(err?.message || 'Unable to shortlist volunteer.');
                            console.error(err);
                        } finally {
                            shortlist.disabled = false;
                        }
                    });
                    actions.appendChild(shortlist);
                }

                if (status !== 'rejected') {
                    const reject = document.createElement('button');
                    reject.className = 'btn text-xs py-1.5 px-3 bg-[#ef44441a] text-[#ef4444] border border-[#ef444433] hover:bg-[#ef444433] font-semibold';
                    reject.textContent = 'Reject';
                    reject.addEventListener('click', async () => {
                        reject.disabled = true;
                        try {
                            await apiFetch(`/endeavours/${endeavourId}/registrations/reject`, { method: 'POST', body: JSON.stringify({ registration_id: reg.id }) });
                            await renderRegistrations(container, endeavourId, phase, transportFeeRequired);
                        } catch (err) {
                            alert(err?.message || 'Unable to reject volunteer.');
                            console.error(err);
                        } finally {
                            reject.disabled = false;
                        }
                    });
                    actions.appendChild(reject);
                }
            }
            if (phase === 'ON_DAY') {
                if (reg.attendance_status !== 'present') {
                    const present = document.createElement('button');
                    present.className = 'btn text-xs py-1.5 px-3 bg-[#3b82f61a] text-[#3b82f6] border border-[#3b82f633] hover:bg-[#3b82f633] font-semibold';
                    present.textContent = 'Present';
                    present.addEventListener('click', async () => {
                        present.disabled = true;
                        try {
                            await apiFetch(`/endeavours/${endeavourId}/registrations/attendance`, { method: 'POST', body: JSON.stringify({ registration_id: reg.id, attendance_status: 'present' }) });
                            await renderRegistrations(container, endeavourId, phase, transportFeeRequired);
                        } catch (err) {
                            alert(err?.message || 'Unable to mark attendance.');
                            console.error(err);
                        } finally {
                            present.disabled = false;
                        }
                    });
                    actions.appendChild(present);
                }

                if (transportFeeRequired && !Number(reg.transport_fee_paid || 0)) {
                    const paid = document.createElement('button');
                    paid.className = 'btn text-xs py-1.5 px-3 bg-[#f59e0b1a] text-[#f59e0b] border border-[#f59e0b33] hover:bg-[#f59e0b33] font-semibold';
                    paid.textContent = 'Paid Fee';
                    paid.addEventListener('click', async () => {
                        paid.disabled = true;
                        try {
                            await apiFetch(`/endeavours/${endeavourId}/registrations/transport_fee`, { method: 'POST', body: JSON.stringify({ registration_id: reg.id }) });
                            await renderRegistrations(container, endeavourId, phase, transportFeeRequired);
                        } catch (err) {
                            alert(err?.message || 'Unable to mark transport fee.');
                            console.error(err);
                        } finally {
                            paid.disabled = false;
                        }
                    });
                    actions.appendChild(paid);
                }
            }
            row.appendChild(actions);
            container.appendChild(row);
        });
    } catch (err) {
        console.error('Failed to render registrations:', err);
        container.innerHTML = '<p class="text-xs text-[var(--color-danger)] font-medium">Failed to load registrations.</p>';
    }
};

const setWorkflowCardExpanded = (card, expanded) => {
    const body = card.querySelector(':scope > .workflow-body');
    const header = card.querySelector(':scope > .workflow-summary');
    const toggleText = card.querySelector('[data-role="toggle-text"]');
    const toggleIcon = card.querySelector('[data-role="toggle-icon"]');
    const workflowName = card.dataset.workflowName || 'workflow';

    if (!body || !header) return;

    body.hidden = !expanded;
    body.classList.toggle('is-open', expanded);
    card.classList.toggle('is-expanded', expanded);
    header.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    header.setAttribute('aria-label', `${expanded ? 'Collapse' : 'Expand'} workflow ${workflowName}`);
    if (toggleText) toggleText.textContent = expanded ? 'Collapse' : 'Expand';
    if (toggleIcon) toggleIcon.classList.toggle('rotate-180', expanded);
};

const buildPlansCard = (row, driveFiles) => {
    const plansCard = document.createElement('div');
    plansCard.className = 'workflow-panel';
    plansCard.innerHTML = `
      <div class="flex items-center gap-2 mb-4 pb-3 border-b border-[var(--border-subtle)]">
        <h4 class="text-sm font-bold text-[var(--text-secondary)] uppercase tracking-wider">Planning Phase</h4>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="min-w-0 space-y-1.5">
          <label for="ops-select-${row.id}" class="text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider">Operational Plan</label>
          <select id="ops-select-${row.id}" class="input-field py-2.5 text-sm" data-role="ops">${fileOptions(row.operational_plan_file_id, driveFiles)}</select>
        </div>
        <div class="min-w-0 space-y-1.5">
          <label for="budget-select-${row.id}" class="text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider">Budget Plan</label>
          <select id="budget-select-${row.id}" class="input-field py-2.5 text-sm" data-role="budget">${fileOptions(row.budget_plan_file_id, driveFiles)}</select>
        </div>
      </div>
      <div data-role="status" class="hidden mt-4"></div>
      <button class="btn btn-secondary mt-4 w-full sm:w-auto self-start" data-action="attach-plans">Save Plans</button>
    `;
    const plansStatusEl = plansCard.querySelector('[data-role="status"]');
    const attachBtn = plansCard.querySelector('[data-action="attach-plans"]');
    attachBtn.addEventListener('click', async () => {
        if (attachBtn.disabled) return;
        attachBtn.disabled = true;
        const ops = plansCard.querySelector('[data-role="ops"]').value;
        const budget = plansCard.querySelector('[data-role="budget"]').value;
        try {
            await apiFetch(`/endeavours/${row.id}/attach_plans`, { method: 'POST', body: JSON.stringify({ operational_plan_file_id: ops, budget_plan_file_id: budget }) });
            setStatus(plansStatusEl, 'Plans attached successfully.', true);
            loadEndeavours(entitySelect.value);
        } catch (err) {
            setStatus(plansStatusEl, err.message || 'Unable to attach plans.', false);
            attachBtn.disabled = false;
        }
    });
    return plansCard;
};

const buildFinCard = (row, driveFiles) => {
    const finCard = document.createElement('div');
    finCard.className = 'workflow-panel';
    finCard.innerHTML = `
      <div class="flex items-center gap-2 mb-4 pb-3 border-b border-[var(--border-subtle)]">
        <h4 class="text-sm font-bold text-[var(--text-secondary)] uppercase tracking-wider">Financials & Epilogue</h4>
      </div>
      <div class="space-y-4">
        <div class="flex flex-col sm:flex-row gap-3 items-end">
          <div class="flex-1 w-full space-y-1.5">
             <label for="pre-select-${row.id}" class="text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider">Pre-Financial</label>
             <select id="pre-select-${row.id}" class="input-field py-2 text-sm" data-role="pre">${fileOptions(row.pre_financial_file_id, driveFiles)}</select>
          </div>
          <button class="btn btn-secondary w-full sm:w-auto whitespace-nowrap" data-action="pre">Submit</button>
        </div>
        <div class="flex flex-col sm:flex-row gap-3 items-end">
          <div class="flex-1 w-full space-y-1.5">
             <label for="post-select-${row.id}" class="text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider">Post-Financial</label>
             <select id="post-select-${row.id}" class="input-field py-2 text-sm" data-role="post">${fileOptions(row.post_financial_file_id, driveFiles)}</select>
          </div>
          <button class="btn btn-secondary w-full sm:w-auto whitespace-nowrap" data-action="post">Submit</button>
        </div>
        <div class="flex flex-col sm:flex-row gap-3 items-end">
          <div class="flex-1 w-full space-y-1.5">
             <label for="epi-select-${row.id}" class="text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider">Epilogue</label>
             <select id="epi-select-${row.id}" class="input-field py-2 text-sm" data-role="epilogue">${fileOptions(row.epilogue_file_id, driveFiles)}</select>
          </div>
          <button class="btn btn-secondary w-full sm:w-auto whitespace-nowrap" data-action="epilogue">Submit</button>
        </div>
      </div>
      <div data-role="status" class="hidden mt-4"></div>
    `;
    const finStatusEl = finCard.querySelector('[data-role="status"]');
    const preBtn = finCard.querySelector('[data-action="pre"]');
    const postBtn = finCard.querySelector('[data-action="post"]');
    const epiBtn = finCard.querySelector('[data-action="epilogue"]');

    preBtn.addEventListener('click', async () => {
        if (preBtn.disabled) return;
        preBtn.disabled = true;
        try {
            await apiFetch(`/endeavours/${row.id}/attach_pre_financial`, { method: 'POST', body: JSON.stringify({ pre_financial_file_id: finCard.querySelector('[data-role="pre"]').value }) });
            setStatus(finStatusEl, 'Pre-financial submitted.', true);
            loadEndeavours(entitySelect.value);
        } catch (err) {
            setStatus(finStatusEl, err?.message || 'Unable to submit', false);
            preBtn.disabled = false;
        }
    });
    postBtn.addEventListener('click', async () => {
        if (postBtn.disabled) return;
        postBtn.disabled = true;
        try {
            await apiFetch(`/endeavours/${row.id}/attach_post_financial`, { method: 'POST', body: JSON.stringify({ post_financial_file_id: finCard.querySelector('[data-role="post"]').value }) });
            setStatus(finStatusEl, 'Post-financial submitted.', true);
            loadEndeavours(entitySelect.value);
        } catch (err) {
            setStatus(finStatusEl, err?.message || 'Unable to submit', false);
            postBtn.disabled = false;
        }
    });
    epiBtn.addEventListener('click', async () => {
        if (epiBtn.disabled) return;
        epiBtn.disabled = true;
        try {
            await apiFetch(`/endeavours/${row.id}/attach_epilogue`, { method: 'POST', body: JSON.stringify({ epilogue_file_id: finCard.querySelector('[data-role="epilogue"]').value }) });
            setStatus(finStatusEl, 'Epilogue submitted.', true);
            loadEndeavours(entitySelect.value);
        } catch (err) {
            setStatus(finStatusEl, err?.message || 'Unable to submit', false);
            epiBtn.disabled = false;
        }
    });
    return finCard;
};

const buildVolCard = (row, currentUser) => {
    const volCard = document.createElement('div');
    volCard.className = 'workflow-panel';

    const volHeader = document.createElement('div');
    volHeader.className = 'flex flex-wrap items-center justify-between gap-3 mb-4 pb-3 border-b border-[var(--border-subtle)]';
    volHeader.innerHTML = `<h4 class="text-sm font-bold text-[var(--text-secondary)] uppercase tracking-wider">Volunteer Management</h4>`;

    const volControls = document.createElement('div');
    volControls.className = 'flex gap-2';

    const volStatusEl = document.createElement('div');
    volStatusEl.className = 'hidden mb-4';

    if (row.phase === 'VOLUNTEER_REGISTRATION') {
        const startShortlisting = document.createElement('button');
        startShortlisting.className = 'btn btn-primary px-4 py-1.5 text-xs';
        startShortlisting.textContent = 'Start Shortlisting';
        startShortlisting.addEventListener('click', async () => {
            if (startShortlisting.disabled) return;
            startShortlisting.disabled = true;
            try {
                await apiFetch(`/endeavours/${row.id}/start_shortlisting`, { method: 'POST', body: JSON.stringify({}) });
                loadEndeavours(entitySelect.value);
            } catch (err) {
                startShortlisting.disabled = false;
                setStatus(volStatusEl, err.message || 'Unable to start shortlisting.', false);
            }
        });
        volControls.appendChild(startShortlisting);
    }
    if (row.phase === 'VOLUNTEER_SHORTLISTING') {
        const closeShortlisting = document.createElement('button');
        closeShortlisting.className = 'btn px-4 py-1.5 text-xs bg-[var(--text-primary)] text-[var(--bg-base)] border border-transparent font-bold hover:opacity-90 transition-opacity';
        closeShortlisting.textContent = 'Close Shortlisting / Finalize';
        closeShortlisting.addEventListener('click', async () => {
            if (closeShortlisting.disabled) return;
            closeShortlisting.disabled = true;
            try {
                await apiFetch(`/endeavours/${row.id}/close_shortlisting`, { method: 'POST', body: JSON.stringify({}) });
                loadEndeavours(entitySelect.value);
            } catch (err) {
                closeShortlisting.disabled = false;
                setStatus(volStatusEl, err.message || 'Unable to finalize shortlisting.', false);
            }
        });
        volControls.appendChild(closeShortlisting);
    }

    if (volControls.children.length > 0) {
        volHeader.appendChild(volControls);
    }

    volCard.appendChild(volHeader);
    volCard.appendChild(volStatusEl);

    const regContainer = document.createElement('div');
    regContainer.className = 'workflow-scroll-list space-y-3 mt-3';

    const loadRegs = document.createElement('button');
    loadRegs.className = 'btn btn-ghost w-full py-3 border border-dashed border-[var(--border-strong)] text-[var(--text-secondary)] font-medium text-sm hover:border-[var(--text-tertiary)]';
    loadRegs.innerHTML = `
      <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
      Load Registrations
    `;
    loadRegs.addEventListener('click', async () => {
        loadRegs.classList.add('hidden');
        await renderRegistrations(regContainer, row.id, row.phase, Number(row.transport_fee_required || 0) === 1);
    });

    volCard.appendChild(loadRegs);
    volCard.appendChild(regContainer);
    return volCard;
};

const buildAdminCard = (row) => {
    const adminCard = document.createElement('div');
    adminCard.className = 'workflow-panel workflow-panel--admin relative bg-[rgba(59,130,246,0.05)] border-[rgba(59,130,246,0.15)]';

    adminCard.innerHTML = `
      <div class="absolute top-0 right-0 p-4 opacity-10 pointer-events-none">
        <svg class="w-16 h-16" fill="currentColor" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <div class="flex items-center gap-2 mb-5 pb-3 border-b border-[rgba(59,130,246,0.15)] relative z-10">
        <h4 class="text-sm font-bold text-[var(--color-primary)] uppercase tracking-wider flex items-center gap-2">
          Admin Approvals
        </h4>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5 relative z-10">
        <div class="min-w-0 space-y-1.5">
          <label class="text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider">Document</label>
          <select id="admin-doc-${row.id}" class="input-field py-2.5 text-sm w-full" data-role="doc" aria-label="Select Document">
            <option value="operational_plan">Operational Plan</option>
            <option value="budget_plan">Budget Plan</option>
            <option value="pre_financial">Pre-Financial</option>
            <option value="post_financial">Post-Financial</option>
            <option value="epilogue">Epilogue</option>
          </select>
        </div>
        ${currentUser?.global_role === 'admin' ? `
        <div class="min-w-0 space-y-1.5">
          <label class="text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider">Approver Group</label>
          <select id="admin-approver-${row.id}" class="input-field py-2.5 text-sm w-full" data-role="approver" aria-label="Select Approver Group">
            <option value="bod">BoD</option>
            <option value="student_affairs">Student Affairs</option>
          </select>
        </div>` : ''}
        <div class="min-w-0 space-y-1.5">
          <label class="text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider">Decision</label>
          <select id="admin-dec-${row.id}" class="input-field py-2.5 text-sm w-full" data-role="decision" aria-label="Select Decision">
            <option value="approved">Approve</option>
            <option value="rejected">Reject</option>
          </select>
        </div>
      </div>
      <div data-role="status" class="hidden mb-4 relative z-10"></div>
      <button class="btn btn-primary w-full sm:w-auto self-start relative z-10 bg-[var(--color-primary)] text-[var(--bg-base)] hover:bg-[var(--color-primary-hover)] border-transparent" data-action="approve">Submit Decision</button>
    `;
    const adminStatusEl = adminCard.querySelector('[data-role="status"]');
    const approveBtn = adminCard.querySelector('[data-action="approve"]');
    approveBtn.addEventListener('click', async () => {
        if (approveBtn.disabled) return;
        approveBtn.disabled = true;
        const docType = adminCard.querySelector('[data-role="doc"]').value;
        const decision = adminCard.querySelector('[data-role="decision"]').value;
        const approverGroup = adminCard.querySelector('[data-role="approver"]')?.value;
        const payload = { doc_type: docType, decision };
        if (approverGroup) payload.approver_group = approverGroup;
        try {
            await apiFetch(`/endeavours/${row.id}/doc_approvals`, { method: 'POST', body: JSON.stringify(payload) });
            setStatus(adminStatusEl, 'Decision recorded.', true);
            loadEndeavours(entitySelect.value);
        } catch (err) {
            setStatus(adminStatusEl, err.message || 'Unable to update approval.', false);
            approveBtn.disabled = false;
        }
    });
    return adminCard;
};

const renderEndeavours = (rows, driveFiles) => {
    listEl.innerHTML = '';
    if (!rows.length) {
        emptyEl.classList.remove('hidden');
        return;
    }
    emptyEl.classList.add('hidden');

    const PHASES = ['PRE_EVENT', 'PRE_FINANCIAL', 'VOLUNTEER_REGISTRATION', 'VOLUNTEER_SHORTLISTING', 'ON_DAY', 'POST_EVENT', 'COMPLETED'];
    const phaseLabels = {
        PRE_EVENT: 'Pre-Event',
        PRE_FINANCIAL: 'Pre-Financial',
        VOLUNTEER_REGISTRATION: 'Volunteering',
        VOLUNTEER_SHORTLISTING: 'Shortlisting',
        ON_DAY: 'On-Day',
        POST_EVENT: 'Post-Event',
        COMPLETED: 'Completed'
    };

    const renderStepper = (currentPhase) => {
        const p = currentPhase || 'PRE_EVENT';
        const currentIndex = Math.max(0, PHASES.indexOf(p));

        const container = document.createElement('div');
        container.className = 'stepper-container md:px-6 py-6 border-b border-[var(--border-subtle)] bg-[rgba(0,0,0,0.1)]';

        PHASES.forEach((phase, i) => {
            const stepItem = document.createElement('div');
            stepItem.className = 'step-item flex-1 shrink-0 px-2 min-w-[100px]';
            if (i < currentIndex) stepItem.classList.add('completed');
            else if (i === currentIndex) stepItem.classList.add('current');

            const stepDot = document.createElement('div');
            stepDot.className = 'step-dot shadow-sm';
            stepDot.textContent = i < currentIndex ? '✓' : i + 1;

            const stepLabel = document.createElement('div');
            stepLabel.className = 'step-label hidden sm:block mt-2 font-medium tracking-wide';
            stepLabel.textContent = phaseLabels[phase];

            stepItem.appendChild(stepDot);
            stepItem.appendChild(stepLabel);
            container.appendChild(stepItem);
        });

        return container;
    };

    rows.forEach((row) => {
        const card = document.createElement('article');
        card.className = 'workflow-card card animate-fade-in !p-0';
        card.dataset.workflowName = row.name || 'workflow';

        const bodyContainer = document.createElement('div');
        bodyContainer.id = `endeavour-body-${row.id}`;
        bodyContainer.className = 'workflow-body';
        bodyContainer.hidden = true;

        const header = document.createElement('button');
        header.className = 'workflow-summary';
        header.setAttribute('aria-expanded', 'false');
        header.setAttribute('aria-controls', bodyContainer.id);
        header.setAttribute('aria-label', `Expand workflow ${row.name}`);

        const titleContent = document.createElement('div');
        titleContent.className = 'flex items-center gap-4 min-w-0';
        const iconDiv = document.createElement('div');
        iconDiv.className = 'w-10 h-10 rounded-xl bg-[var(--color-primary-ghost)] text-[var(--color-primary)] flex items-center justify-center shadow-inner';
        iconDiv.innerHTML = `<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>`;

        const textDiv = document.createElement('div');
        textDiv.className = 'min-w-0';
        const title = document.createElement('span');
        title.className = 'block text-base md:text-lg font-bold tracking-tight text-[var(--text-primary)] leading-tight truncate';
        title.textContent = row.name;

        const summary = document.createElement('div');
        summary.className = 'mt-2 flex flex-wrap items-center gap-2 text-xs font-semibold text-[var(--text-tertiary)]';

        const phaseBadge = document.createElement('span');
        phaseBadge.className = 'badge badge-info';
        phaseBadge.textContent = phaseLabels[row.phase] || 'Unknown Phase';

        const progressBadge = document.createElement('span');
        progressBadge.className = 'badge bg-[rgba(255,255,255,0.04)] text-[var(--text-secondary)] border border-[var(--border-subtle)]';
        progressBadge.textContent = `Phase ${Math.max(1, PHASES.indexOf(row.phase) + 1)} of ${PHASES.length}`;

        const deadline = document.createElement('span');
        deadline.className = 'min-w-0 max-w-full break-words leading-snug';
        deadline.textContent = nextDeadlineSummary(row);

        summary.append(phaseBadge, progressBadge, deadline);

        textDiv.appendChild(title);
        textDiv.appendChild(summary);

        titleContent.appendChild(iconDiv);
        titleContent.appendChild(textDiv);

        header.appendChild(titleContent);
        card.appendChild(header);

        const toggleWrap = document.createElement('span');
        toggleWrap.className = 'inline-flex items-center gap-2 self-end sm:self-center shrink-0 text-xs font-bold text-[var(--text-secondary)]';
        const toggleText = document.createElement('span');
        toggleText.dataset.role = 'toggle-text';
        toggleText.textContent = 'Expand';
        const toggleIcon = document.createElement('span');
        toggleIcon.dataset.role = 'toggle-icon';
        toggleIcon.className = 'transition-transform duration-300';
        toggleIcon.innerHTML = `<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>`;
        toggleWrap.append(toggleText, toggleIcon);
        header.appendChild(toggleWrap);

        header.addEventListener('click', () => {
            const isExpanded = bodyContainer.classList.contains('is-open');
            listEl.querySelectorAll('.workflow-card.is-expanded').forEach((openCard) => {
                if (openCard !== card) setWorkflowCardExpanded(openCard, false);
            });
            setWorkflowCardExpanded(card, !isExpanded);
        });

        bodyContainer.appendChild(renderStepper(row.phase));

        const contentGrid = document.createElement('div');
        contentGrid.className = 'workflow-panels';

        contentGrid.appendChild(buildPlansCard(row, driveFiles));
        contentGrid.appendChild(buildVolCard(row, currentUser));
        contentGrid.appendChild(buildFinCard(row, driveFiles));
        if (['board', 'student_affairs', 'admin'].includes(currentUser?.global_role)) {
            contentGrid.appendChild(buildAdminCard(row));
        }

        bodyContainer.appendChild(contentGrid);
        card.appendChild(bodyContainer);
        listEl.appendChild(card);
    });
};

const loadEndeavours = async (entityId) => {
    if (!entityId) {
        listEl.innerHTML = '';
        emptyEl.textContent = 'Select an entity to view workflows.';
        emptyEl.classList.remove('hidden');
        return;
    }
    const reqId = ++endeavoursRequestId;

    try {
        const [driveFilesForRequest, response] = await Promise.all([
            loadDriveFiles(entityId),
            apiFetch(`/endeavours?entity_id=${encodeURIComponent(entityId)}`)
        ]);
        if (reqId !== endeavoursRequestId) return;

        if (!Array.isArray(response?.data)) {
            throw new Error('Malformed endeavours response');
        }
        renderEndeavours(response.data, driveFilesForRequest);
    } catch (err) {
        if (reqId !== endeavoursRequestId) return;
        console.error('Failed to load endeavours:', err);
        listEl.innerHTML = '';
        emptyEl.textContent = err?.status === 401
            ? 'Please sign in to view workflows.'
            : err?.status === 403
                ? 'You do not have access to workflows for this entity.'
                : `Failed to load endeavours${err?.message ? `: ${err.message}` : '.'}`;
        emptyEl.classList.remove('hidden');
    }
};

apiFetch('/auth/me')
    .then((response) => {
        const entities = response?.data?.entities || [];
        currentUser = response?.data?.user || null;
        entitySelect.innerHTML = '';
        entities.forEach((entity) => {
            const option = document.createElement('option');
            option.value = entity.id;
            option.textContent = entity.name;
            entitySelect.appendChild(option);
        });
        if (entities.length === 0) {
            const option = document.createElement('option');
            option.textContent = 'You have no entity memberships';
            entitySelect.appendChild(option);
            entitySelect.disabled = true;
            listEl.innerHTML = '';
            emptyEl.textContent = 'No entities found.';
            emptyEl.classList.remove('hidden');
        } else if (entities[0]) {
            entitySelect.value = entities[0].id;
            loadEndeavours(entitySelect.value);
        }
    })
    .catch(() => {
        entitySelect.innerHTML = '';
        const option = document.createElement('option');
        option.textContent = 'No entities';
        option.disabled = true;
        option.selected = true;
        entitySelect.appendChild(option);
        entitySelect.disabled = true;
    });

entitySelect.addEventListener('change', () => loadEndeavours(entitySelect.value));

createForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitButton = createForm.querySelector('[type="submit"]');
    if (submitButton) {
        submitButton.disabled = true;
    }
    try {
        await apiFetch('/endeavours', {
            method: 'POST',
            body: JSON.stringify({
                entity_id: entitySelect.value,
                name: createForm.elements.namedItem('name').value,
                description: createForm.elements.namedItem('description').value,
                volunteering_enabled: createForm.elements.namedItem('volunteering_enabled').checked,
                transport_fee_required: createForm.elements.namedItem('transport_fee_required').checked,
                volunteer_registration_deadline: createForm.elements.namedItem('volunteer_registration_deadline').value || null,
                pre_financial_deadline: createForm.elements.namedItem('pre_financial_deadline').value || null,
                post_financial_deadline: createForm.elements.namedItem('post_financial_deadline').value || null,
                event_start_at: createForm.elements.namedItem('event_start_at').value || null,
                event_end_at: createForm.elements.namedItem('event_end_at').value || null
            })
        });
        setStatus(createStatus, 'Endeavour created.', true);
        createForm.reset();
        loadEndeavours(entitySelect.value);
    } catch (err) {
        setStatus(createStatus, err.message || 'Unable to create endeavour.', false);
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
        }
    }
});


