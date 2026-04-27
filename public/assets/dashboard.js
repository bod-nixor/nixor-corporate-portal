import { apiFetch, normalizeError } from '/assets/app.js';
import { renderSidebar } from '/assets/sidebar.js';

document.getElementById('sidebar-container').outerHTML = renderSidebar('dashboard');

const entitySelect = document.getElementById('entity-select');
const docProgress = document.getElementById('doc-progress');
const docSummary = document.getElementById('doc-summary');
const pendingDocsList = document.getElementById('pending-docs-list');
const meetingsList = document.getElementById('meetings-list');
const deadlinesList = document.getElementById('deadlines-list');
const announcementsList = document.getElementById('announcements-list');
const announcementsEmpty = document.getElementById('announcements-empty');
const modal = document.getElementById('announcement-modal');
const modalOpen = document.getElementById('announcement-button');
const modalClose = document.getElementById('announcement-close');
const form = document.getElementById('announcement-form');
const statusEl = document.getElementById('announcement-status');

const DEFAULT_ANNOUNCEMENTS_TITLE = 'No announcements yet';
const DEFAULT_ANNOUNCEMENTS_DETAIL = 'This space will show broadcasts sent to this entity.';

const logDashboard = (message, detail) => {
    if (detail === undefined) {
        console.log(`[NCP Dashboard] ${message}`);
    } else {
        console.log(`[NCP Dashboard] ${message}`, detail);
    }
};

const dashboardApiFetch = async (path) => {
    let sawResponse = false;
    try {
        return await apiFetch(path, {
            onResponse: ({ status }) => {
                sawResponse = true;
                logDashboard(`${path} status`, status);
            }
        });
    } catch (err) {
        if (!sawResponse) {
            logDashboard(`${path} status`, err?.status || 'request_failed');
        }
        throw err;
    }
};

const setStatus = (message, ok) => {
    statusEl.textContent = message;
    statusEl.className = `text-sm rounded-xl px-4 py-3 ${ok ? 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/30' : 'bg-red-500/10 text-red-300 border border-red-500/30'}`;
    statusEl.classList.remove('hidden');
};

const setListMessage = (listEl, message, tone = 'muted') => {
    listEl.innerHTML = '';
    const item = document.createElement('li');
    const toneClass = tone === 'error' ? 'text-red-300 border-red-500/30 bg-red-500/10' : 'text-slate-500 border-slate-700';
    item.className = `${toneClass} text-sm flex items-center justify-center p-4 h-full border border-dashed rounded-lg`;
    item.textContent = message;
    listEl.appendChild(item);
};

const setAnnouncementsMessage = (title, detail) => {
    const messages = announcementsEmpty.querySelectorAll('p');
    if (messages[0]) {
        messages[0].textContent = title;
    }
    if (messages[1]) {
        messages[1].textContent = detail;
    }
    announcementsEmpty.classList.remove('hidden');
};

const resetAnnouncementsMessage = () => {
    setAnnouncementsMessage(DEFAULT_ANNOUNCEMENTS_TITLE, DEFAULT_ANNOUNCEMENTS_DETAIL);
    announcementsEmpty.classList.add('hidden');
};

const setDashboardUnavailable = (message) => {
    docProgress.textContent = '--';
    docSummary.textContent = message;
    setListMessage(pendingDocsList, 'Unable to load pending docs.', 'error');
    setListMessage(meetingsList, 'Unable to load meetings.', 'error');
    setListMessage(deadlinesList, 'Unable to load deadlines.', 'error');
    announcementsList.innerHTML = '';
    setAnnouncementsMessage('Dashboard unavailable', message);
    modalOpen.disabled = true;
    modalOpen.classList.add('opacity-60', 'cursor-not-allowed');
};

const renderPendingDocs = (listEl, docs) => {
    listEl.innerHTML = '';
    if (docs?.length) {
        docs.forEach((item) => {
            const li = document.createElement('li');
            li.className = 'p-3 bg-slate-800/50 rounded-lg border border-slate-700/50 hover:border-slate-600 transition-colors min-w-0';

            const nameSpan = document.createElement('div');
            nameSpan.className = 'text-slate-100 font-medium text-sm mb-1 truncate';
            nameSpan.textContent = item.endeavour_name;

            const labelsContainer = document.createElement('div');
            labelsContainer.className = 'flex flex-wrap gap-1 mt-1';

            item.pending.forEach((pending) => {
                const group = pending.approver_group === 'bod' ? 'BoD' : 'Student Affairs';
                const docName = pending.doc_type.replace(/_/g, ' ');
                const badge = document.createElement('span');
                badge.className = 'badge badge-warning text-[10px] py-0.5';
                badge.textContent = `${docName} (${group})`;
                labelsContainer.appendChild(badge);
            });

            li.appendChild(nameSpan);
            li.appendChild(labelsContainer);
            listEl.appendChild(li);
        });
    } else {
        const empty = document.createElement('li');
        empty.className = 'text-slate-500 text-sm flex items-center justify-center p-4 h-full border border-dashed border-slate-700 rounded-lg';
        empty.textContent = 'All caught up.';
        listEl.appendChild(empty);
    }
};

const renderMeetings = (listEl, meetings) => {
    listEl.innerHTML = '';
    if (meetings?.length) {
        meetings.forEach((event) => {
            const item = document.createElement('li');
            item.className = 'flex flex-col p-3 bg-slate-800/50 rounded-lg border border-slate-700/50 min-w-0';
            const left = document.createElement('span');
            left.className = 'text-sm font-medium text-slate-200 truncate';
            left.textContent = event.title;
            const right = document.createElement('span');
            right.className = 'text-slate-400 text-xs mt-1';
            right.textContent = new Date(event.event_date).toLocaleString(undefined, {
                weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
            });
            item.appendChild(left);
            item.appendChild(right);
            listEl.appendChild(item);
        });
    } else {
        const empty = document.createElement('li');
        empty.className = 'text-slate-500 text-sm flex items-center justify-center p-4 h-full border border-dashed border-slate-700 rounded-lg';
        empty.textContent = 'No upcoming meetings.';
        listEl.appendChild(empty);
    }
};

const renderDeadlines = (listEl, deadlines) => {
    listEl.innerHTML = '';
    const validDeadlines = (deadlines || []).filter((deadline) => {
        const rawDaysUntil = deadline?.days_until;
        if (!deadline?.deadline_label || rawDaysUntil === null || rawDaysUntil === '') {
            return false;
        }
        const daysUntil = Number(rawDaysUntil);
        return Number.isFinite(daysUntil);
    });
    if (validDeadlines.length) {
        validDeadlines.forEach((deadline) => {
            const daysUntil = Number(deadline.days_until);
            const item = document.createElement('li');
            item.className = 'flex flex-col sm:flex-row sm:items-start sm:justify-between p-3 bg-slate-800/50 rounded-lg border border-slate-700/50 gap-2 min-w-0';

            const left = document.createElement('span');
            left.className = 'text-sm font-medium text-slate-200 min-w-0 max-w-full break-words leading-snug';
            left.textContent = deadline.name;

            const right = document.createElement('span');
            const label = deadline.deadline_label ? `${deadline.deadline_label}: ` : '';

            let badgeClass = 'badge ';
            if (daysUntil === 0) {
                right.textContent = `${label}Due today`;
                badgeClass += 'badge-danger';
            } else if (daysUntil < 0) {
                const overdueDays = Math.abs(daysUntil);
                right.textContent = `${label}Overdue (${overdueDays}d)`;
                badgeClass += 'badge-danger';
            } else if (daysUntil <= 3) {
                right.textContent = `${label}${daysUntil}d left`;
                badgeClass += 'badge-warning';
            } else {
                right.textContent = `${label}${daysUntil}d left`;
                badgeClass += 'bg-slate-700 text-slate-300 border border-slate-600';
            }

            right.className = badgeClass + ' max-w-full whitespace-normal break-words text-left sm:text-right leading-snug';
            item.appendChild(left);
            item.appendChild(right);
            listEl.appendChild(item);
        });
    } else {
        const empty = document.createElement('li');
        empty.className = 'text-slate-500 text-sm flex items-center justify-center p-4 h-full border border-dashed border-slate-700 rounded-lg';
        empty.textContent = 'No critical deadlines.';
        listEl.appendChild(empty);
    }
};

const loadDashboard = async (entityId) => {
    if (!entityId) {
        setDashboardUnavailable('Select an entity to load dashboard data.');
        return;
    }
    meetingsList.innerHTML = '';
    deadlinesList.innerHTML = '';
    pendingDocsList.innerHTML = '';
    announcementsList.innerHTML = '';
    resetAnnouncementsMessage();
    setListMessage(pendingDocsList, 'Loading pending docs...');
    setListMessage(meetingsList, 'Loading meetings...');
    setListMessage(deadlinesList, 'Loading deadlines...');
    const dashboardPath = `/dashboard?entity_id=${encodeURIComponent(entityId)}`;
    try {
        const response = await dashboardApiFetch(dashboardPath);
        const data = response?.data || {};
        logDashboard('dashboard payload counts', {
            pendingDocs: data.pending_docs?.length || 0,
            meetings: data.calendar?.length || 0,
            deadlines: data.deadlines?.length || 0,
            announcements: data.announcements?.length || 0
        });
        docProgress.textContent = `${data.doc_progress || 0}%`;
        const approvedDocs = Number(data.doc_progress_approved || 0);
        const totalDocs = Number(data.doc_progress_total || 0);
        docSummary.textContent = totalDocs > 0
            ? `${approvedDocs} of ${totalDocs} docs approved across ${data.total_endeavours || 0} endeavours.`
            : `Tracking ${data.total_endeavours || 0} endeavours. No document approvals are active yet.`;

        if (data.announcements?.length) {
            data.announcements.forEach((announcement) => {
                const acard = document.createElement('div');
                acard.className = 'card card-hoverable';
                const headerRow = document.createElement('div');
                headerRow.className = 'flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 mb-2 min-w-0';

                const title = document.createElement('h3');
                title.className = 'text-lg font-semibold tracking-tight min-w-0 break-words';
                title.textContent = announcement.title;

                const meta = document.createElement('p');
                meta.className = 'text-xs font-medium text-slate-500 min-w-0 truncate';
                meta.textContent = `${announcement.creator_name || announcement.full_name || 'System'}`;

                headerRow.appendChild(title);
                headerRow.appendChild(meta);

                const message = document.createElement('p');
                message.className = 'text-sm text-slate-300 leading-relaxed whitespace-pre-wrap break-words';
                message.textContent = announcement.message;

                acard.appendChild(headerRow);
                acard.appendChild(message);
                announcementsList.appendChild(acard);
            });
        } else {
            announcementsEmpty.classList.remove('hidden');
        }

        modalOpen.disabled = !data.can_post_announcements;
        modalOpen.classList.toggle('opacity-60', !data.can_post_announcements);
        modalOpen.classList.toggle('cursor-not-allowed', !data.can_post_announcements);

        renderPendingDocs(pendingDocsList, data.pending_docs);
        renderMeetings(meetingsList, data.calendar);
        renderDeadlines(deadlinesList, data.deadlines);
    } catch (err) {
        const message = normalizeError(err) || 'Unable to load dashboard.';
        logDashboard('dashboard data failure', { status: err?.status || null, message });
        setDashboardUnavailable(message);
    }
};

const initDashboard = async () => {
    logDashboard('init started');
    entitySelect.disabled = true;
    entitySelect.innerHTML = '';
    const loadingOption = document.createElement('option');
    loadingOption.textContent = 'Loading entities...';
    entitySelect.appendChild(loadingOption);
    modalOpen.disabled = true;
    modalOpen.classList.add('opacity-60', 'cursor-not-allowed');

    try {
        const response = await dashboardApiFetch('/auth/me');
        const userPresent = Boolean(response?.data?.user);
        logDashboard(`user present ${userPresent ? 'yes' : 'no'}`);
        if (!userPresent) {
            throw new Error('Session not found. Please sign in again.');
        }

        const entities = response?.data?.entities || [];
        logDashboard('entities count', entities.length);
        entitySelect.innerHTML = '';
        entities.forEach((entity) => {
            const option = document.createElement('option');
            option.value = entity.id;
            option.textContent = entity.name;
            entitySelect.appendChild(option);
        });
        const selected = entities[0]?.id;
        logDashboard('selectedEntityId', selected || null);
        if (selected) {
            entitySelect.value = selected;
            entitySelect.disabled = false;
            await loadDashboard(selected);
        } else {
            const option = document.createElement('option');
            option.textContent = 'No entities';
            entitySelect.appendChild(option);
            setDashboardUnavailable('No entities are available for this account.');
        }
        logDashboard('init complete');
    } catch (err) {
        const message = normalizeError(err) || 'Unable to load dashboard.';
        logDashboard('init failure', { status: err?.status || null, message });
        entitySelect.innerHTML = '';
        const option = document.createElement('option');
        option.textContent = 'Unable to load entities';
        entitySelect.appendChild(option);
        entitySelect.disabled = true;
        setDashboardUnavailable(message);
    }
};

initDashboard();

entitySelect.addEventListener('change', () => {
    logDashboard('selectedEntityId', entitySelect.value || null);
    loadDashboard(entitySelect.value);
});

let previouslyFocusedElement = null;

const handleModalKeydown = (e) => {
    if (e.key === 'Escape') closeModal();
    if (e.key === 'Tab') {
        const focusableElements = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (e.shiftKey) {
            if (document.activeElement === firstElement) {
                lastElement.focus();
                e.preventDefault();
            }
        } else {
            if (document.activeElement === lastElement) {
                firstElement.focus();
                e.preventDefault();
            }
        }
    }
};

modalOpen.addEventListener('click', () => {
    if (modalOpen.disabled) return;
    statusEl.classList.add('hidden');

    previouslyFocusedElement = document.activeElement;

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    modal.setAttribute('aria-modal', 'true');
    document.addEventListener('keydown', handleModalKeydown);

    setTimeout(() => form.querySelector('input').focus(), 50);
});

const closeModal = () => {
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.removeEventListener('keydown', handleModalKeydown);
    if (previouslyFocusedElement) previouslyFocusedElement.focus();
};

document.getElementById('announcement-cancel').addEventListener('click', closeModal);
modalClose.addEventListener('click', closeModal);

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        await apiFetch('/announcements', {
            method: 'POST',
            body: JSON.stringify({
                entity_id: entitySelect.value,
                title: form.title.value,
                message: form.message.value
            })
        });
        form.reset();
        closeModal();
        loadDashboard(entitySelect.value);
    } catch (err) {
        setStatus(normalizeError(err), false);
    }
});
