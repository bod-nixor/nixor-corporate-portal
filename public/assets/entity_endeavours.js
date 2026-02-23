import { apiFetch } from '/assets/app.js';
import { renderSidebar } from '/assets/sidebar.js';

document.getElementById('sidebar-container').outerHTML = renderSidebar('entity_endeavours');

const entitySelect = document.getElementById('entity-select');
const createForm = document.getElementById('create-form');
const createStatus = document.getElementById('create-status');
const listEl = document.getElementById('endeavour-list');
const emptyEl = document.getElementById('endeavour-empty');
let driveFiles = [];
let currentUser = null;

const setStatus = (el, message, ok) => {
    el.textContent = message;
    el.className = `text-sm rounded-xl px-4 py-3 ${ok ? 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/30' : 'bg-red-500/10 text-red-300 border border-red-500/30'}`;
    if (el.id === 'create-status') {
        el.classList.add('md:col-span-2', 'mt-4');
    }
    el.classList.remove('hidden');
};

const loadDriveFiles = async (entityId) => {
    try {
        const response = await apiFetch(`/drive/list?entity_id=${entityId}`);
        driveFiles = (response?.data || []).filter((item) => item.item_type === 'file');
    } catch (err) {
        driveFiles = [];
    }
};

const escapeHtml = (value) => {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#39;');
};

const fileOptions = (selectedId) => {
    const options = ['<option value=\"\">Select file</option>'];
    driveFiles.forEach((file) => {
        const selected = selectedId && Number(selectedId) === Number(file.id) ? 'selected' : '';
        const safeName = escapeHtml(file.name);
        options.push(`<option value=\"${file.id}\" ${selected}>${safeName}</option>`);
    });
    return options.join('');
};

const renderRegistrations = async (container, endeavourId, phase) => {
    container.innerHTML = '<p class=\"text-xs text-slate-500\">Loading registrations...</p>';
    try {
        const response = await apiFetch(`/endeavours/${endeavourId}/registrations`);
        const registrations = response?.data || [];
        if (!registrations.length) {
            container.innerHTML = '<p class=\"text-xs text-slate-500\">No registrations yet.</p>';
            return;
        }
        container.innerHTML = '';
        registrations.forEach((reg) => {
            const row = document.createElement('div');
            row.className = 'flex flex-wrap items-center justify-between gap-3 text-xs text-slate-300 border border-slate-800 rounded-xl p-3';
            const name = document.createElement('span');
            name.textContent = `${reg.full_name} (${reg.status})`;
            row.appendChild(name);
            const actions = document.createElement('div');
            actions.className = 'flex gap-2';
            if (phase === 'VOLUNTEER_SHORTLISTING') {
                const shortlist = document.createElement('button');
                shortlist.className = 'btn btn-secondary text-xs py-1 px-3 bg-emerald-500/10 text-emerald-400 border-none hover:bg-emerald-500/20';
                shortlist.textContent = 'Shortlist';
                shortlist.addEventListener('click', async () => {
                    try {
                        await apiFetch(`/endeavours/${endeavourId}/registrations/shortlist`, { method: 'POST', body: JSON.stringify({ registration_id: reg.id }) });
                        await renderRegistrations(container, endeavourId, phase);
                    } catch (err) {
                        alert(err?.message || 'Unable to shortlist volunteer.');
                        console.error(err);
                    }
                });
                const reject = document.createElement('button');
                reject.className = 'btn btn-secondary text-xs py-1 px-3 bg-red-500/10 text-red-400 border-none hover:bg-red-500/20';
                reject.textContent = 'Reject';
                reject.addEventListener('click', async () => {
                    try {
                        await apiFetch(`/endeavours/${endeavourId}/registrations/reject`, { method: 'POST', body: JSON.stringify({ registration_id: reg.id }) });
                        await renderRegistrations(container, endeavourId, phase);
                    } catch (err) {
                        alert(err?.message || 'Unable to reject volunteer.');
                        console.error(err);
                    }
                });
                actions.appendChild(shortlist);
                actions.appendChild(reject);
            }
            if (phase === 'ON_DAY') {
                const present = document.createElement('button');
                present.className = 'btn btn-secondary text-xs py-1 px-3 bg-indigo-500/10 text-indigo-400 border-none hover:bg-indigo-500/20';
                present.textContent = 'Present';
                present.addEventListener('click', async () => {
                    try {
                        await apiFetch(`/endeavours/${endeavourId}/registrations/attendance`, { method: 'POST', body: JSON.stringify({ registration_id: reg.id, attendance_status: 'present' }) });
                        await renderRegistrations(container, endeavourId, phase);
                    } catch (err) {
                        alert(err?.message || 'Unable to mark attendance.');
                        console.error(err);
                    }
                });
                const paid = document.createElement('button');
                paid.className = 'btn btn-secondary text-xs py-1 px-3 bg-amber-500/10 text-amber-400 border-none hover:bg-amber-500/20';
                paid.textContent = 'Paid Fee';
                paid.addEventListener('click', async () => {
                    try {
                        await apiFetch(`/endeavours/${endeavourId}/registrations/transport_fee`, { method: 'POST', body: JSON.stringify({ registration_id: reg.id }) });
                        await renderRegistrations(container, endeavourId, phase);
                    } catch (err) {
                        alert(err?.message || 'Unable to mark transport fee.');
                        console.error(err);
                    }
                });
                actions.appendChild(present);
                actions.appendChild(paid);
            }
            row.appendChild(actions);
            container.appendChild(row);
        });
    } catch (err) {
        container.innerHTML = '<p class=\"text-xs text-red-300\">Failed to load registrations.</p>';
    }
};

const renderEndeavours = (rows) => {
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
        container.className = 'stepper-container mt-6 mb-8 px-2';

        PHASES.forEach((phase, i) => {
            const stepItem = document.createElement('div');
            stepItem.className = 'step-item';
            if (i < currentIndex) stepItem.classList.add('completed');
            else if (i === currentIndex) stepItem.classList.add('current');

            const stepDot = document.createElement('div');
            stepDot.className = 'step-dot';
            stepDot.textContent = i < currentIndex ? '✓' : i + 1;

            const stepLabel = document.createElement('div');
            stepLabel.className = 'step-label hidden md:block mt-2';
            stepLabel.textContent = phaseLabels[phase];

            stepItem.appendChild(stepDot);
            stepItem.appendChild(stepLabel);
            container.appendChild(stepItem);
        });

        return container;
    };

    rows.forEach((row) => {
        const card = document.createElement('div');
        card.className = 'card animate-fade-in flex flex-col p-6 shadow-md border-slate-700/60';

        const header = document.createElement('div');
        header.className = 'flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 pb-4';
        const title = document.createElement('h3');
        title.className = 'text-xl font-bold tracking-tight';
        title.textContent = row.name;
        header.appendChild(title);
        card.appendChild(header);

        // Add Stepper
        card.appendChild(renderStepper(row.phase));

        // Create Content Grid for logical grouping
        const contentGrid = document.createElement('div');
        contentGrid.className = 'grid grid-cols-1 xl:grid-cols-2 gap-6 mt-4';

        // --- Column 1: Core Docs ---
        const col1 = document.createElement('div');
        col1.className = 'space-y-6';

        // Plans
        const plansCard = document.createElement('div');
        plansCard.className = 'p-5 bg-slate-800/30 border border-slate-700/50 rounded-xl';
        plansCard.innerHTML = `
      <h4 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-4">Planning</h4>
      <div class="flex flex-col sm:flex-row gap-3">
        <div class="flex-1 space-y-1">
          <label class="text-xs text-slate-400">Operational Plan</label>
          <select class="input-field py-2 text-sm" data-role="ops">${fileOptions(row.operational_plan_file_id)}</select>
        </div>
        <div class="flex-1 space-y-1">
          <label class="text-xs text-slate-400">Budget Plan</label>
          <select class="input-field py-2 text-sm" data-role="budget">${fileOptions(row.budget_plan_file_id)}</select>
        </div>
      </div>
      <div data-role="status" class="hidden"></div>
      <button class="btn btn-primary mt-4 w-full sm:w-auto" data-action="attach-plans">Save Plans</button>
    `;
        const plansStatusEl = plansCard.querySelector('[data-role="status"]');
        plansCard.querySelector('[data-action="attach-plans"]').addEventListener('click', async () => {
            const ops = plansCard.querySelector('[data-role="ops"]').value;
            const budget = plansCard.querySelector('[data-role="budget"]').value;
            try {
                await apiFetch(`/endeavours/${row.id}/attach_plans`, { method: 'POST', body: JSON.stringify({ operational_plan_file_id: ops, budget_plan_file_id: budget }) });
                setStatus(plansStatusEl, 'Plans attached successfully.', true);
                loadEndeavours(entitySelect.value);
            } catch (err) {
                setStatus(plansStatusEl, err.message || 'Unable to attach plans.', false);
            }
        });
        col1.appendChild(plansCard);

        // Financials
        const finCard = document.createElement('div');
        finCard.className = 'p-5 bg-slate-800/30 border border-slate-700/50 rounded-xl';
        finCard.innerHTML = `
      <h4 class="text-sm font-semibold text-slate-300 uppercase tracking-wider mb-4">Financials & Epilogue</h4>
      <div class="space-y-4">
        <div class="flex flex-col sm:flex-row gap-2 items-end">
          <div class="flex-1 w-full space-y-1">
             <label class="text-xs text-slate-400">Pre-Financial</label>
             <select class="input-field py-2 text-sm" data-role="pre">${fileOptions(row.pre_financial_file_id)}</select>
          </div>
          <button class="btn btn-secondary w-full sm:w-auto" data-action="pre">Submit Pre-Fin</button>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 items-end">
          <div class="flex-1 w-full space-y-1">
             <label class="text-xs text-slate-400">Post-Financial</label>
             <select class="input-field py-2 text-sm" data-role="post">${fileOptions(row.post_financial_file_id)}</select>
          </div>
          <button class="btn btn-secondary w-full sm:w-auto" data-action="post">Submit Post-Fin</button>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 items-end">
          <div class="flex-1 w-full space-y-1">
             <label class="text-xs text-slate-400">Epilogue</label>
             <select class="input-field py-2 text-sm" data-role="epilogue">${fileOptions(row.epilogue_file_id)}</select>
          </div>
          <button class="btn btn-secondary w-full sm:w-auto" data-action="epilogue">Submit Epilogue</button>
        </div>
      </div>
      <div data-role="status" class="hidden"></div>
    `;
        const finStatusEl = finCard.querySelector('[data-role="status"]');
        finCard.querySelector('[data-action="pre"]').addEventListener('click', async () => {
            try {
                await apiFetch(`/endeavours/${row.id}/attach_pre_financial`, { method: 'POST', body: JSON.stringify({ pre_financial_file_id: finCard.querySelector('[data-role="pre"]').value }) });
                setStatus(finStatusEl, 'Pre-financial submitted.', true);
                loadEndeavours(entitySelect.value);
            } catch (err) { setStatus(finStatusEl, err.message || 'Unable to submit', false); }
        });
        finCard.querySelector('[data-action="post"]').addEventListener('click', async () => {
            try {
                await apiFetch(`/endeavours/${row.id}/attach_post_financial`, { method: 'POST', body: JSON.stringify({ post_financial_file_id: finCard.querySelector('[data-role="post"]').value }) });
                setStatus(finStatusEl, 'Post-financial submitted.', true);
                loadEndeavours(entitySelect.value);
            } catch (err) { setStatus(finStatusEl, err.message || 'Unable to submit', false); }
        });
        finCard.querySelector('[data-action="epilogue"]').addEventListener('click', async () => {
            try {
                await apiFetch(`/endeavours/${row.id}/attach_epilogue`, { method: 'POST', body: JSON.stringify({ epilogue_file_id: finCard.querySelector('[data-role="epilogue"]').value }) });
                setStatus(finStatusEl, 'Epilogue submitted.', true);
                loadEndeavours(entitySelect.value);
            } catch (err) { setStatus(finStatusEl, err.message || 'Unable to submit', false); }
        });
        col1.appendChild(finCard);
        contentGrid.appendChild(col1);

        // --- Column 2: Volunteering & Admin ---
        const col2 = document.createElement('div');
        col2.className = 'space-y-6';

        const volCard = document.createElement('div');
        volCard.className = 'p-5 bg-slate-800/30 border border-slate-700/50 rounded-xl';

        const volHeader = document.createElement('div');
        volHeader.className = 'flex flex-wrap items-center justify-between gap-3 mb-4';
        volHeader.innerHTML = `<h4 class="text-sm font-semibold text-slate-300 uppercase tracking-wider">Volunteer Management</h4>`;

        const volControls = document.createElement('div');
        volControls.className = 'flex gap-2';

        const volStatusEl = document.createElement('div');
        volStatusEl.className = 'hidden';

        if (row.phase === 'VOLUNTEER_REGISTRATION') {
            const startShortlisting = document.createElement('button');
            startShortlisting.className = 'btn btn-primary px-3 py-1.5 text-xs';
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
            closeShortlisting.className = 'btn btn-primary bg-emerald-600 hover:bg-emerald-500 px-3 py-1.5 text-xs border-none';
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

        volHeader.appendChild(volControls);
        volCard.appendChild(volHeader);
        volCard.appendChild(volStatusEl);

        const regContainer = document.createElement('div');
        regContainer.className = 'space-y-2 mt-3';
        const loadRegs = document.createElement('button');
        loadRegs.className = 'btn btn-ghost btn-sm text-xs w-full border border-dashed border-slate-600';
        loadRegs.textContent = 'Load Volunteer Registrations';
        loadRegs.addEventListener('click', async () => {
            loadRegs.classList.add('hidden');
            await renderRegistrations(regContainer, row.id, row.phase);
        });
        volCard.appendChild(loadRegs);
        volCard.appendChild(regContainer);
        col2.appendChild(volCard);

        if (['board', 'student_affairs', 'admin'].includes(currentUser?.global_role)) {
            const adminCard = document.createElement('div');
            adminCard.className = 'p-5 bg-indigo-950/20 border border-indigo-500/20 rounded-xl';
            adminCard.innerHTML = `
        <h4 class="text-sm font-semibold text-indigo-300 uppercase tracking-wider mb-4 flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
          Admin Approvals
        </h4>
        <div class="flex flex-col sm:flex-row gap-2">
          <select class="input-field py-2 text-sm flex-1" data-role="doc">
            <option value="operational_plan">Operational Plan</option>
            <option value="budget_plan">Budget Plan</option>
            <option value="pre_financial">Pre-Financial</option>
            <option value="post_financial">Post-Financial</option>
            <option value="epilogue">Epilogue</option>
          </select>
          <select class="input-field py-2 text-sm flex-1" data-role="decision">
            <option value="approved">Approve</option>
            <option value="rejected">Reject</option>
          </select>
        </div>
        <div data-role="status" class="hidden"></div>
        <button class="btn bg-indigo-600 hover:bg-indigo-500 text-white mt-3 w-full sm:w-auto" data-action="approve">Submit Decision</button>
      `;
            const adminStatusEl = adminCard.querySelector('[data-role="status"]');
            adminCard.querySelector('[data-action="approve"]').addEventListener('click', async () => {
                const docType = adminCard.querySelector('[data-role="doc"]').value;
                const decision = adminCard.querySelector('[data-role="decision"]').value;
                try {
                    await apiFetch(`/endeavours/${row.id}/doc_approvals`, { method: 'POST', body: JSON.stringify({ doc_type: docType, decision }) });
                    setStatus(adminStatusEl, 'Decision recorded.', true);
                    loadEndeavours(entitySelect.value);
                } catch (err) {
                    setStatus(adminStatusEl, err.message || 'Unable to update approval.', false);
                }
            });
            col2.appendChild(adminCard);
        }

        contentGrid.appendChild(col2);
        card.appendChild(contentGrid);
        listEl.appendChild(card);
    });
};

const loadEndeavours = async (entityId) => {
    if (!entityId) {
        return;
    }
    await loadDriveFiles(entityId);
    try {
        const response = await apiFetch(`/endeavours?entity_id=${entityId}`);
        renderEndeavours(response?.data || []);
    } catch (err) {
        listEl.innerHTML = '';
        emptyEl.textContent = 'Failed to load endeavours.';
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
        if (entities[0]) {
            entitySelect.value = entities[0].id;
            loadEndeavours(entitySelect.value);
        }
    })
    .catch(() => {
        const option = document.createElement('option');
        option.textContent = 'No entities';
        entitySelect.appendChild(option);
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
                name: createForm.name.value,
                description: createForm.description.value,
                volunteering_enabled: createForm.volunteering_enabled.checked,
                transport_fee_required: createForm.transport_fee_required.checked,
                volunteer_registration_deadline: createForm.volunteer_registration_deadline.value || null,
                pre_financial_deadline: createForm.pre_financial_deadline.value || null,
                post_financial_deadline: createForm.post_financial_deadline.value || null,
                event_start_at: createForm.event_start_at.value || null,
                event_end_at: createForm.event_end_at.value || null
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
