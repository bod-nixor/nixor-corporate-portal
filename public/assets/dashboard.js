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
const modalHeading = document.getElementById('announcement-heading');
const modalSubmit = document.getElementById('announcement-submit');

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

const formatDateTime = (value, options = {}) => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        ...options
    });
};

const formatDocLabel = (docType) => {
    const labels = {
        operational_plan: 'Operational plan',
        ops_plan: 'Operational plan',
        budget_plan: 'Budget plan',
        pre_financial: 'Pre-financial report',
        post_financial: 'Post-financial report',
        epilogue: 'Epilogue',
        mou: 'MOU'
    };
    return labels[docType] || String(docType || 'Document').replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
};

const formatActionLabel = (item) => {
    if (item.action_label) return item.action_label;
    const group = item.approver_group === 'bod' ? 'MoB' : item.approver_group === 'student_affairs' ? 'Student Affairs' : '';
    if (item.category === 'rejected') return 'Rejected';
    if (item.category === 'pending_approval') return group ? `To approve - ${group}` : 'To approve';
    if (item.category === 'pending_approval_waiting') return group ? `Awaiting ${group}` : 'Awaiting approval';
    if (item.category === 'pending_submission') return 'To submit';
    if (item.category === 'overdue') return 'Overdue';
    return 'Pending';
};

const actionToneClass = (category) => {
    if (category === 'rejected' || category === 'overdue') return 'badge-danger';
    if (category === 'pending_approval' || category === 'pending_approval_waiting') return 'badge-warning';
    if (category === 'pending_submission') return 'badge-info';
    return 'bg-[rgba(255,255,255,0.05)] text-[var(--text-secondary)] border border-[var(--border-subtle)]';
};

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
            li.className = 'p-3.5 bg-[rgba(255,255,255,0.025)] rounded-xl border border-[var(--border-subtle)] hover:border-[var(--border-strong)] transition-colors min-w-0';

            const nameSpan = document.createElement('div');
            nameSpan.className = 'text-[var(--text-primary)] font-semibold text-sm leading-snug break-words';
            nameSpan.textContent = item.endeavour_name || 'Untitled endeavour';

            const detailRow = document.createElement('div');
            detailRow.className = 'mt-2 flex flex-wrap items-center gap-2 min-w-0';

            const docName = item.doc_label || formatDocLabel(item.doc_type);
            const docType = document.createElement('span');
            docType.className = 'text-xs font-medium text-[var(--text-secondary)] leading-snug break-words min-w-0';
            docType.textContent = docName;

            const badge = document.createElement('span');
            badge.className = `badge whitespace-normal break-words leading-snug text-[11px] py-1 ${actionToneClass(item.category)}`;
            badge.textContent = formatActionLabel(item);

            detailRow.append(docType, badge);

            const dueDate = formatDateTime(item.due_at, { year: 'numeric' });
            if (dueDate) {
                const due = document.createElement('p');
                due.className = 'mt-2 text-[11px] font-medium text-[var(--text-tertiary)]';
                due.textContent = `Due ${dueDate}`;
                li.append(nameSpan, detailRow, due);
            } else {
                li.append(nameSpan, detailRow);
            }

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
            item.className = 'flex flex-col p-3.5 bg-[rgba(255,255,255,0.025)] rounded-xl border border-[var(--border-subtle)] hover:border-[var(--border-strong)] transition-colors min-w-0';
            const left = document.createElement('span');
            left.className = 'text-sm font-semibold text-[var(--text-primary)] leading-snug break-words';
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
            item.className = 'flex flex-col p-3.5 bg-[rgba(255,255,255,0.025)] rounded-xl border border-[var(--border-subtle)] hover:border-[var(--border-strong)] transition-colors gap-2 min-w-0';

            const left = document.createElement('span');
            left.className = 'text-sm font-semibold text-[var(--text-primary)] min-w-0 max-w-full break-words leading-snug';
            left.textContent = deadline.name || 'Untitled endeavour';

            const meta = document.createElement('div');
            meta.className = 'flex flex-wrap items-center gap-2 min-w-0';

            const labelEl = document.createElement('span');
            labelEl.className = 'text-xs font-medium text-[var(--text-secondary)] break-words min-w-0';
            labelEl.textContent = deadline.deadline_label || 'Deadline';

            const right = document.createElement('span');

            let badgeClass = 'badge ';
            if (daysUntil === 0) {
                right.textContent = 'Due today';
                badgeClass += 'badge-danger';
            } else if (daysUntil < 0) {
                const overdueDays = Math.abs(daysUntil);
                right.textContent = `Overdue (${overdueDays}d)`;
                badgeClass += 'badge-danger';
            } else if (daysUntil <= 3) {
                right.textContent = `${daysUntil}d left`;
                badgeClass += 'badge-warning';
            } else {
                right.textContent = `${daysUntil}d left`;
                badgeClass += 'bg-[rgba(255,255,255,0.05)] text-[var(--text-secondary)] border border-[var(--border-subtle)]';
            }

            right.className = badgeClass + ' whitespace-normal break-words leading-snug text-[11px] py-1 shrink-0';
            meta.append(labelEl, right);

            const dateText = formatDateTime(deadline.deadline_at, { year: 'numeric' });
            if (dateText) {
                const dateEl = document.createElement('span');
                dateEl.className = 'text-[11px] font-medium text-[var(--text-tertiary)]';
                dateEl.textContent = dateText;
                meta.appendChild(dateEl);
            }

            item.appendChild(left);
            item.appendChild(meta);
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
let announcementsMayHaveMore = false;
let editingAnnouncementId = null;
let editingAnnouncementCard = null;
const ANNOUNCEMENTS_LIMIT = 5;
const loadMoreBtn = document.getElementById('load-more-announcements');

const showAnnouncementCardStatus = (card, message, ok = false) => {
    let status = card.querySelector('[data-announcement-status]');
    if (!status) {
        status = document.createElement('p');
        status.dataset.announcementStatus = 'true';
        card.appendChild(status);
    }
    status.className = `mt-4 text-xs font-semibold rounded-lg px-3 py-2 border ${ok ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30' : 'bg-red-500/10 text-red-300 border-red-500/30'}`;
    status.textContent = message;
};

const syncAnnouncementsAfterRemoval = () => {
    announcementsOffset = Math.max(0, announcementsOffset - 1);
    const hasAnnouncements = Boolean(announcementsList.children.length);
    announcementsEmpty.classList.toggle('hidden', hasAnnouncements);
    if (!loadMoreBtn) return;
    loadMoreBtn.disabled = !hasAnnouncements;
    loadMoreBtn.classList.toggle('hidden', !hasAnnouncements || !announcementsMayHaveMore);
};

const renderAnnouncement = (announcement, canManage) => {
    const acard = document.createElement('article');
    acard.className = 'card card-hoverable group relative announcement-card';
    acard.dataset.announcementId = announcement.id;

    const headerRow = document.createElement('div');
    headerRow.className = 'flex flex-col md:flex-row md:items-start md:justify-between gap-4 min-w-0';

    const textWrap = document.createElement('div');
    textWrap.className = 'min-w-0 flex-1';

    const title = document.createElement('h3');
    title.className = 'text-lg font-semibold tracking-tight min-w-0 break-words text-[var(--text-primary)] leading-snug';
    title.textContent = announcement.title || 'Untitled announcement';

    const metaInfo = document.createElement('div');
    metaInfo.className = 'mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] font-medium text-[var(--text-tertiary)] min-w-0';

    const author = document.createElement('span');
    author.className = 'break-words min-w-0';
    author.textContent = `${announcement.creator_name || announcement.full_name || 'System'}`;

    const time = document.createElement('span');
    const dateObj = new Date(announcement.created_at);
    time.textContent = Number.isNaN(dateObj.getTime()) ? '' : dateObj.toLocaleString();

    metaInfo.append(author, time);
    textWrap.append(title, metaInfo);

    headerRow.appendChild(textWrap);

    const canEdit = Boolean(announcement.can_edit || canManage);
    const canDelete = Boolean(announcement.can_delete || canManage);
    if (canEdit || canDelete) {
        const actions = document.createElement('div');
        actions.className = 'flex flex-wrap items-center gap-2 shrink-0 md:justify-end';

        if (canEdit) {
            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'btn btn-ghost px-2.5 py-1.5 text-xs';
            editBtn.setAttribute('aria-label', `Edit announcement ${announcement.title || announcement.id}`);
            editBtn.textContent = 'Edit';
            editBtn.addEventListener('click', () => openAnnouncementModal('edit', announcement, acard));
            actions.appendChild(editBtn);
        }

        if (canDelete) {
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'btn btn-ghost px-2.5 py-1.5 text-xs text-red-300 hover:text-red-200';
            delBtn.setAttribute('aria-label', `Delete announcement ${announcement.title || announcement.id}`);
            delBtn.textContent = 'Delete';
            let resetTimer = 0;
            delBtn.addEventListener('click', async () => {
                if (delBtn.dataset.confirming !== 'true') {
                    delBtn.dataset.confirming = 'true';
                    delBtn.textContent = 'Confirm delete';
                    delBtn.classList.add('btn-danger');
                    window.clearTimeout(resetTimer);
                    resetTimer = window.setTimeout(() => {
                        delBtn.dataset.confirming = 'false';
                        delBtn.textContent = 'Delete';
                        delBtn.classList.remove('btn-danger');
                    }, 4500);
                    return;
                }
                delBtn.disabled = true;
                delBtn.textContent = 'Deleting...';
                try {
                    await apiFetch(`/announcements/${announcement.id}`, { method: 'DELETE' });
                    acard.remove();
                    syncAnnouncementsAfterRemoval();
                } catch (e) {
                    delBtn.disabled = false;
                    delBtn.dataset.confirming = 'false';
                    delBtn.textContent = 'Delete';
                    delBtn.classList.remove('btn-danger');
                    showAnnouncementCardStatus(acard, normalizeError(e) || 'Unable to delete announcement.');
                }
            });
            actions.appendChild(delBtn);
        }

        headerRow.appendChild(actions);
    }

    const message = document.createElement('p');
    message.className = 'mt-4 text-sm text-[var(--text-secondary)] leading-relaxed whitespace-pre-wrap break-words';
    message.textContent = announcement.message || '';

    acard.append(headerRow, message);
    return acard;
};

if (loadMoreBtn) {
    loadMoreBtn.addEventListener('click', async () => {
        if (!currentEntityId) return;
        loadMoreBtn.disabled = true;
        loadMoreBtn.textContent = 'Loading...';
        try {
            const res = await dashboardApiFetch(`/announcements?e=${encodeURIComponent(currentEntityId)}&offset=${announcementsOffset}&limit=${ANNOUNCEMENTS_LIMIT}`);
            if (res?.data && res.data.length > 0) {
                res.data.forEach(ann => {
                    announcementsList.appendChild(renderAnnouncement(ann, window._canManageAnnouncements));
                });
                announcementsOffset += res.data.length;
                announcementsMayHaveMore = res.data.length >= ANNOUNCEMENTS_LIMIT;
                if (res.data.length < ANNOUNCEMENTS_LIMIT) {
                    loadMoreBtn.classList.add('hidden');
                }
            } else {
                announcementsMayHaveMore = false;
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
    announcementsMayHaveMore = false;
    if (loadMoreBtn) {
        loadMoreBtn.classList.add('hidden');
        loadMoreBtn.disabled = false;
    }
    resetAnnouncementsMessage();
    currentPendingDocs = [];
    renderPendingDocs();
    setListMessage(pendingDocsList, 'Loading pending docs...');
    setListMessage(meetingsList, 'Loading meetings...');
    setListMessage(deadlinesList, 'Loading deadlines...');
    const dashboardPath = `/dashboard?e=${encodeURIComponent(entityId)}`;
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

        const canPostAnnouncements = Boolean(data.can_post_announcements);
        const canManageAnnouncements = Boolean(data.can_manage_announcements || data.can_post_announcements);
        window._canPostAnnouncements = canPostAnnouncements;
        window._canManageAnnouncements = canManageAnnouncements;

        if (data.announcements?.length) {
            announcementsEmpty.classList.add('hidden');
            data.announcements.forEach((announcement) => {
                announcementsList.appendChild(renderAnnouncement(announcement, canManageAnnouncements));
            });
            announcementsOffset = data.announcements.length;
            announcementsMayHaveMore = data.announcements.length >= ANNOUNCEMENTS_LIMIT;
            if (data.announcements.length >= ANNOUNCEMENTS_LIMIT && loadMoreBtn) {
                loadMoreBtn.classList.remove('hidden');
            }
        } else {
            announcementsEmpty.classList.remove('hidden');
        }

        modalOpen.disabled = !canPostAnnouncements;
        modalOpen.classList.toggle('opacity-60', !canPostAnnouncements);
        modalOpen.classList.toggle('cursor-not-allowed', !canPostAnnouncements);

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
            option.value = entity.public_id || entity.id;
            option.textContent = entity.name;
            entitySelect.appendChild(option);
        });
        const requested = new URLSearchParams(window.location.search).get('e')
            || new URLSearchParams(window.location.search).get('entity_public_id')
            || new URLSearchParams(window.location.search).get('entity_id');
        const selected = requested && entities.some(entity => String(entity.public_id || entity.id) === String(requested))
            ? requested
            : (entities[0]?.public_id || entities[0]?.id);
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

const openAnnouncementModal = (mode = 'create', announcement = null, card = null) => {
    if (mode === 'create' && modalOpen.disabled) return;
    statusEl.classList.add('hidden');
    form.reset();

    editingAnnouncementId = mode === 'edit' ? announcement?.id : null;
    editingAnnouncementCard = mode === 'edit' ? card : null;
    modalHeading.textContent = mode === 'edit' ? 'Edit Announcement' : 'New Announcement';
    modalSubmit.textContent = mode === 'edit' ? 'Save Changes' : 'Publish Announcement';

    if (mode === 'edit' && announcement) {
        const titleInput = form.elements.namedItem('title');
        const messageInput = form.elements.namedItem('message');
        if (titleInput) titleInput.value = announcement.title || '';
        if (messageInput) messageInput.value = announcement.message || '';
    }

    previouslyFocusedElement = document.activeElement;

    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    modal.setAttribute('aria-modal', 'true');
    document.addEventListener('keydown', handleModalKeydown);

    setTimeout(() => form.querySelector('input').focus(), 50);
};

modalOpen.addEventListener('click', () => {
    openAnnouncementModal('create');
});

const closeModal = () => {
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.removeEventListener('keydown', handleModalKeydown);
    statusEl.classList.add('hidden');
    editingAnnouncementId = null;
    editingAnnouncementCard = null;
    modalHeading.textContent = 'New Announcement';
    modalSubmit.textContent = 'Publish Announcement';
    if (previouslyFocusedElement) previouslyFocusedElement.focus();
};

document.getElementById('announcement-cancel').addEventListener('click', closeModal);
modalClose.addEventListener('click', closeModal);

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const titleInput = form.elements.namedItem('title');
    const messageInput = form.elements.namedItem('message');
    const title = String(titleInput?.value || '').trim();
    const message = String(messageInput?.value || '').trim();
    if (!title || !message) {
        setStatus('Title and message are required.', false);
        return;
    }
    modalSubmit.disabled = true;
    const wasEditing = Boolean(editingAnnouncementId);
    const editId = editingAnnouncementId;
    const cardToReplace = editingAnnouncementCard;
    try {
        const response = await apiFetch(wasEditing ? `/announcements/${editId}` : '/announcements', {
            method: wasEditing ? 'PUT' : 'POST',
            body: JSON.stringify(wasEditing
                ? { title, message }
                : { entity_id: entitySelect.value, title, message })
        });
        form.reset();
        closeModal();
        if (wasEditing && cardToReplace && response?.data) {
            cardToReplace.replaceWith(renderAnnouncement(response.data, window._canManageAnnouncements));
        } else {
            loadDashboard(entitySelect.value);
        }
    } catch (err) {
        setStatus(normalizeError(err), false);
    } finally {
        modalSubmit.disabled = false;
    }
});
