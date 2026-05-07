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

const dashboardApiFetch = async (path, options = {}) => {
    let sawResponse = false;
    const originalOnResponse = options.onResponse;
    try {
        return await apiFetch(path, {
            ...options,
            onResponse: (responseMeta) => {
                sawResponse = true;
                const { status } = responseMeta || {};
                logDashboard(`${path} status`, status);
                if (typeof originalOnResponse === 'function') {
                    originalOnResponse(responseMeta);
                }
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

let currentPendingDocs = [];
const pendingDocsFilter = document.getElementById('pending-docs-filter');

const renderPendingDocs = () => {
    const listEl = document.getElementById('pending-docs-list');
    listEl.innerHTML = '';
    const filterValue = pendingDocsFilter?.value || 'all';
    
    const docs = currentPendingDocs.filter(doc => {
        if (filterValue === 'all') return true;
        if (filterValue === 'actionable') return doc.is_actionable;
        return doc.category === filterValue;
    });

    if (docs?.length) {
        docs.forEach((item) => {
            const li = document.createElement('li');
            li.className = 'p-3 bg-[rgba(255,255,255,0.02)] rounded-lg border border-[var(--border-subtle)] hover:border-[var(--border-strong)] transition-colors min-w-0';

            const nameSpan = document.createElement('div');
            nameSpan.className = 'text-[var(--text-primary)] font-medium text-sm mb-1.5 truncate';
            nameSpan.textContent = item.endeavour_name;

            const docName = item.doc_type.replace(/_/g, ' ');
            const badge = document.createElement('span');
            badge.className = 'badge text-[10px] py-0.5 max-w-full truncate';
            
            if (item.category === 'rejected') {
                badge.classList.add('badge-danger');
                badge.textContent = `${docName} (Needs Changes)`;
            } else if (item.category === 'pending_approval') {
                badge.classList.add('badge-warning');
                const group = item.approver_group === 'bod' ? 'BoD' : 'Student Affairs';
                badge.textContent = `${docName} (To Approve - ${group})`;
            } else if (item.category === 'pending_approval_waiting') {
                badge.classList.add('bg-slate-700', 'text-slate-300');
                const group = item.approver_group === 'bod' ? 'BoD' : 'Student Affairs';
                badge.textContent = `${docName} (Awaiting ${group})`;
            } else if (item.category === 'pending_submission') {
                badge.classList.add('badge-primary');
                badge.textContent = `${docName} (To Submit)`;
            } else if (item.category === 'overdue') {
                badge.classList.add('badge-danger');
                badge.textContent = `${docName} (Overdue)`;
            } else {
                badge.classList.add('badge-ghost');
                badge.textContent = docName;
            }

            li.appendChild(nameSpan);
            li.appendChild(badge);
            listEl.appendChild(li);
        });
    } else {
        const empty = document.createElement('li');
        empty.className = 'text-[var(--text-secondary)] text-sm flex flex-col items-center justify-center p-6 h-full border border-dashed border-[var(--border-strong)] rounded-lg gap-2 text-center';
        let emptyMsg = 'All caught up.';
        if (filterValue === 'actionable') emptyMsg = 'No actionable docs right now.';
        if (filterValue === 'pending_submission') emptyMsg = 'No docs need submission.';
        if (filterValue === 'pending_approval') emptyMsg = 'No docs waiting for your approval.';
        if (filterValue === 'rejected') emptyMsg = 'No rejected docs.';
        if (filterValue === 'overdue') emptyMsg = 'No overdue docs.';
        empty.textContent = emptyMsg;
        listEl.appendChild(empty);
    }
};

if (pendingDocsFilter) {
    pendingDocsFilter.addEventListener('change', renderPendingDocs);
}

const renderMeetings = (listEl, meetings) => {
    listEl.innerHTML = '';
    if (meetings?.length) {
        meetings.forEach((event) => {
            const item = document.createElement('li');
            item.className = 'flex flex-col p-3 bg-[rgba(255,255,255,0.02)] rounded-lg border border-[var(--border-subtle)] hover:border-[var(--border-strong)] transition-colors min-w-0';
            const left = document.createElement('span');
            left.className = 'text-sm font-medium text-[var(--text-primary)] truncate';
            left.textContent = event.title;
            const right = document.createElement('span');
            right.className = 'text-[var(--text-tertiary)] text-xs mt-1';
            right.textContent = new Date(event.event_date).toLocaleString(undefined, {
                weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
            });
            item.appendChild(left);
            item.appendChild(right);
            listEl.appendChild(item);
        });
    } else {
        const empty = document.createElement('li');
        empty.className = 'text-[var(--text-secondary)] text-sm flex flex-col items-center justify-center p-6 h-full border border-dashed border-[var(--border-strong)] rounded-lg text-center';
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
            item.className = 'flex flex-col xl:flex-row xl:items-start xl:justify-between p-3 bg-[rgba(255,255,255,0.02)] rounded-lg border border-[var(--border-subtle)] hover:border-[var(--border-strong)] transition-colors gap-2 min-w-0';

            const left = document.createElement('span');
            left.className = 'text-sm font-medium text-[var(--text-primary)] min-w-0 max-w-full truncate leading-snug';
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
                badgeClass += 'bg-[rgba(255,255,255,0.05)] text-[var(--text-secondary)] border border-[var(--border-subtle)]';
            }

            right.className = badgeClass + ' max-w-full truncate text-left xl:text-right leading-snug shrink-0';
            item.appendChild(left);
            item.appendChild(right);
            listEl.appendChild(item);
        });
    } else {
        const empty = document.createElement('li');
        empty.className = 'text-[var(--text-secondary)] text-sm flex flex-col items-center justify-center p-6 h-full border border-dashed border-[var(--border-strong)] rounded-lg text-center';
        empty.textContent = 'No critical deadlines.';
        listEl.appendChild(empty);
    }
};

let currentUser = null;
let currentEntityId = null;
let announcementsOffset = 0;
const ANNOUNCEMENTS_LIMIT = 5;
const loadMoreBtn = document.getElementById('load-more-announcements');

const renderAnnouncement = (announcement, canPost) => {
    const acard = document.createElement('div');
    acard.className = 'card card-hoverable group relative';
    
    const headerRow = document.createElement('div');
    headerRow.className = 'flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 mb-2 min-w-0';

    const title = document.createElement('h3');
    title.className = 'text-lg font-semibold tracking-tight min-w-0 break-words text-[var(--text-primary)] pr-12';
    title.textContent = announcement.title;

    const metaInfo = document.createElement('div');
    metaInfo.className = 'flex flex-col sm:items-end min-w-0 shrink-0';

    const author = document.createElement('p');
    author.className = 'text-xs font-medium text-[var(--text-secondary)] truncate';
    author.textContent = `${announcement.creator_name || announcement.full_name || 'System'}`;
    
    const time = document.createElement('p');
    time.className = 'text-[10px] text-[var(--text-tertiary)]';
    const dateObj = new Date(announcement.created_at);
    time.textContent = isNaN(dateObj.getTime()) ? '' : dateObj.toLocaleString();

    metaInfo.appendChild(author);
    metaInfo.appendChild(time);

    headerRow.appendChild(title);
    headerRow.appendChild(metaInfo);

    const message = document.createElement('p');
    message.className = 'text-sm text-[var(--text-secondary)] leading-relaxed whitespace-pre-wrap break-words';
    message.textContent = announcement.message;

    acard.appendChild(headerRow);
    acard.appendChild(message);

    if (canPost || (currentUser && announcement.created_by == currentUser.id)) {
        const actions = document.createElement('div');
        actions.className = 'absolute top-3 right-3 opacity-0 group-hover:opacity-100 transition-opacity flex gap-2';
        
        const delBtn = document.createElement('button');
        delBtn.className = 'text-[var(--text-tertiary)] hover:text-red-400 p-1 bg-[var(--bg-base)] rounded shadow-sm border border-[var(--border-subtle)]';
        delBtn.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>';
        delBtn.onclick = async () => {
            if (confirm('Delete this announcement?')) {
                try {
                    await apiFetch(`/announcements/${announcement.id}`, { method: 'DELETE' });
                    acard.remove();
                } catch (e) {
                    alert(normalizeError(e) || 'Error deleting announcement');
                }
            }
        };
        actions.appendChild(delBtn);
        acard.appendChild(actions);
    }

    return acard;
};

if (loadMoreBtn) {
    loadMoreBtn.addEventListener('click', async () => {
        if (!currentEntityId) return;
        loadMoreBtn.disabled = true;
        loadMoreBtn.textContent = 'Loading...';
        try {
            const res = await dashboardApiFetch(`/announcements?entity_id=${encodeURIComponent(currentEntityId)}&offset=${announcementsOffset}&limit=${ANNOUNCEMENTS_LIMIT}`);
            if (res?.data && res.data.length > 0) {
                res.data.forEach(ann => {
                    announcementsList.appendChild(renderAnnouncement(ann, window._canPostAnnouncements));
                });
                announcementsOffset += res.data.length;
                if (res.data.length < ANNOUNCEMENTS_LIMIT) {
                    loadMoreBtn.classList.add('hidden');
                }
            } else {
                loadMoreBtn.classList.add('hidden');
            }
        } catch (e) {
            console.error(e);
        } finally {
            loadMoreBtn.disabled = false;
            loadMoreBtn.textContent = 'Load More';
        }
    });
}

const loadDashboard = async (entityId) => {
    if (!entityId) {
        setDashboardUnavailable('Select an entity to load dashboard data.');
        return;
    }
    currentEntityId = entityId;
    announcementsList.innerHTML = '';
    announcementsOffset = 0;
    if (loadMoreBtn) loadMoreBtn.classList.add('hidden');
    resetAnnouncementsMessage();
    currentPendingDocs = [];
    renderPendingDocs();
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

        window._canPostAnnouncements = data.can_post_announcements;

        if (data.announcements?.length) {
            announcementsEmpty.classList.add('hidden');
            data.announcements.forEach((announcement) => {
                announcementsList.appendChild(renderAnnouncement(announcement, data.can_post_announcements));
            });
            announcementsOffset = data.announcements.length;
            if (data.announcements.length >= ANNOUNCEMENTS_LIMIT && loadMoreBtn) {
                loadMoreBtn.classList.remove('hidden');
            }
        } else {
            announcementsEmpty.classList.remove('hidden');
        }

        modalOpen.disabled = !data.can_post_announcements;
        modalOpen.classList.toggle('opacity-60', !data.can_post_announcements);
        modalOpen.classList.toggle('cursor-not-allowed', !data.can_post_announcements);

        currentPendingDocs = data.pending_docs || [];
        renderPendingDocs();
        renderMeetings(meetingsList, data.calendar);
        renderDeadlines(deadlinesList, data.deadlines);
    } catch (err) {
        const message = normalizeError(err) || 'Unable to load dashboard.';
        logDashboard('dashboard data failure', { status: err?.status || null, message });
        currentPendingDocs = [];
        renderPendingDocs();
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
        currentUser = response?.data?.user;
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
