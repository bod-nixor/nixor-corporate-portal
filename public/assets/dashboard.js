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



const setStatus = (message, ok) => {
    statusEl.textContent = message;
    statusEl.className = `text-sm rounded-xl px-4 py-3 ${ok ? 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/30' : 'bg-red-500/10 text-red-300 border border-red-500/30'}`;
    statusEl.classList.remove('hidden');
};

const renderPendingDocs = (listEl, docs) => {
    listEl.innerHTML = '';
    if (docs?.length) {
        docs.forEach((item) => {
            const li = document.createElement('li');
            li.className = 'p-3 bg-slate-800/50 rounded-lg border border-slate-700/50 hover:border-slate-600 transition-colors';

            const nameSpan = document.createElement('div');
            nameSpan.className = 'text-slate-100 font-medium text-sm mb-1';
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
        empty.textContent = 'All caught up! 🎉';
        listEl.appendChild(empty);
    }
};

const renderMeetings = (listEl, meetings) => {
    listEl.innerHTML = '';
    if (meetings?.length) {
        meetings.forEach((event) => {
            const item = document.createElement('li');
            item.className = 'flex flex-col p-3 bg-slate-800/50 rounded-lg border border-slate-700/50';
            const left = document.createElement('span');
            left.className = 'text-sm font-medium text-slate-200';
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
        const daysUntil = Number(deadline?.days_until);
        return deadline?.deadline_label && Number.isFinite(daysUntil);
    });
    if (validDeadlines.length) {
        validDeadlines.forEach((deadline) => {
            const item = document.createElement('li');
            item.className = 'flex justify-between items-center p-3 bg-slate-800/50 rounded-lg border border-slate-700/50';

            const left = document.createElement('span');
            left.className = 'text-sm font-medium text-slate-200';
            left.textContent = deadline.name;

            const right = document.createElement('span');
            const label = deadline.deadline_label ? `${deadline.deadline_label}: ` : '';

            let badgeClass = 'badge ';
            if (deadline.days_until === 0) {
                right.textContent = `${label}Due today`;
                badgeClass += 'badge-danger';
            } else if (deadline.days_until < 0) {
                const overdueDays = Math.abs(deadline.days_until);
                right.textContent = `${label}Overdue (${overdueDays}d)`;
                badgeClass += 'badge-danger';
            } else if (deadline.days_until <= 3) {
                right.textContent = `${label}${deadline.days_until}d left`;
                badgeClass += 'badge-warning';
            } else {
                right.textContent = `${label}${deadline.days_until}d left`;
                badgeClass += 'bg-slate-700 text-slate-300 border border-slate-600';
            }

            right.className = badgeClass;
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
    meetingsList.innerHTML = '';
    deadlinesList.innerHTML = '';
    pendingDocsList.innerHTML = '';
    announcementsList.innerHTML = '';
    announcementsEmpty.classList.add('hidden');
    try {
        const response = await apiFetch(`/dashboard?entity_id=${encodeURIComponent(entityId)}`);
        const data = response?.data || {};
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
                headerRow.className = 'flex items-center justify-between mb-2';

                const title = document.createElement('h3');
                title.className = 'text-lg font-semibold tracking-tight';
                title.textContent = announcement.title;

                const meta = document.createElement('p');
                meta.className = 'text-xs font-medium text-slate-500';
                meta.textContent = `${announcement.creator_name || announcement.full_name || 'System'}`;

                headerRow.appendChild(title);
                headerRow.appendChild(meta);

                const message = document.createElement('p');
                message.className = 'text-sm text-slate-300 leading-relaxed';
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
        docProgress.textContent = '--';
        docSummary.textContent = err.message || 'Unable to load dashboard.';
    }
};

apiFetch('/auth/me')
    .then((response) => {
        const entities = response?.data?.entities || [];
        entitySelect.innerHTML = '';
        entities.forEach((entity) => {
            const option = document.createElement('option');
            option.value = entity.id;
            option.textContent = entity.name;
            entitySelect.appendChild(option);
        });
        const selected = entities[0]?.id;
        if (selected) {
            entitySelect.value = selected;
            loadDashboard(selected);
        }
    })
    .catch(() => {
        const option = document.createElement('option');
        option.textContent = 'No entities';
        entitySelect.appendChild(option);
        modalOpen.disabled = true;
        modalOpen.classList.add('opacity-60', 'cursor-not-allowed');
    });

entitySelect.addEventListener('change', () => loadDashboard(entitySelect.value));

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
