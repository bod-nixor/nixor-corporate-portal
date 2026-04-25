import { apiFetch } from '/assets/app.js';
import { renderSidebar } from '/assets/sidebar.js';

document.getElementById('sidebar-container').outerHTML = renderSidebar('endeavours');

const searchInput = document.getElementById('search-input');
const entityFilter = document.getElementById('entity-filter');
const grid = document.getElementById('endeavour-grid');
const emptyState = document.getElementById('endeavour-empty');
const notificationBadge = document.getElementById('notification-badge');

const initialEmptyState = emptyState.innerHTML;
let unreadNotificationCount = 0;
let visibleOpportunityCount = 0;

const updateNotificationBadge = () => {
    if (unreadNotificationCount <= 0) {
        notificationBadge.classList.add('hidden');
        return;
    }
    notificationBadge.textContent = visibleOpportunityCount > 0
        ? `${unreadNotificationCount} new`
        : `${unreadNotificationCount} notifications`;
    notificationBadge.classList.remove('hidden');
};

const renderAccurateEmptyState = () => {
    const hasFilters = Boolean(searchInput.value.trim() || entityFilter.value);
    const heading = emptyState.querySelector('h3');
    const copy = emptyState.querySelector('p');
    if (!heading || !copy) return;
    if (hasFilters) {
        heading.textContent = 'No opportunities match your filters';
        copy.textContent = 'Try a broader search or select All Entities to see every active registration window.';
        return;
    }
    heading.textContent = 'No active volunteering opportunities are open';
    copy.textContent = 'Volunteer registration opens here when an endeavour reaches the registration phase.';
};

const renderEndeavours = (rows) => {
    grid.innerHTML = '';
    if (!rows.length) {
        renderAccurateEmptyState();
        emptyState.classList.remove('hidden');
        emptyState.classList.add('flex');
        return;
    }
    emptyState.classList.add('hidden');
    emptyState.classList.remove('flex');

    rows.forEach((row) => {
        const card = document.createElement('article');
        card.className = 'bg-[var(--bg-surface)] border border-[var(--border-subtle)] rounded-2xl p-6 hover:border-[var(--color-primary)] transition-colors shadow-sm flex flex-col h-full relative group';

        const header = document.createElement('div');
        header.className = 'flex items-center justify-between mb-4';

        const status = document.createElement('span');
        const now = new Date();
        const deadline = row.volunteer_registration_deadline ? new Date(row.volunteer_registration_deadline) : null;
        const registrationClosed = deadline && now > deadline;

        let badgeText = '';
        let badgeClass = 'inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold tracking-widest uppercase ';

        if (row.registered) {
            badgeText = 'Registered';
            badgeClass += 'bg-[rgba(14,165,233,0.1)] text-[#7dd3fc] border border-[rgba(14,165,233,0.2)]';
        } else if (row.phase === 'COMPLETED') {
            badgeText = 'Completed';
            badgeClass += 'bg-[var(--bg-surface-hover)] text-[var(--text-tertiary)] border border-[var(--border-subtle)]';
        } else if (registrationClosed) {
            badgeText = 'Registration Closed';
            badgeClass += 'bg-[rgba(239,68,68,0.1)] text-[#fca5a5] border border-[rgba(239,68,68,0.2)]';
        } else {
            badgeText = 'Registration Open';
            badgeClass += 'bg-[rgba(16,185,129,0.1)] text-[#6ee7b7] border border-[rgba(16,185,129,0.2)]';
        }

        status.className = badgeClass;
        status.textContent = badgeText;

        header.appendChild(status);

        const title = document.createElement('h2');
        title.className = 'text-lg font-bold tracking-tight text-[var(--text-primary)] leading-snug break-words';
        title.textContent = row.name;

        const entityLabel = document.createElement('p');
        entityLabel.className = 'text-[10px] font-bold text-[var(--text-tertiary)] uppercase tracking-widest mt-1 mb-3';
        entityLabel.textContent = row.entity_name;

        const desc = document.createElement('p');
        desc.className = 'text-[13px] font-medium text-[var(--text-secondary)] leading-relaxed flex-1 line-clamp-3 mb-4';
        desc.textContent = row.description || 'No description provided.';

        const meta = document.createElement('div');
        meta.className = 'mt-auto pt-4 border-t border-[var(--border-subtle)] space-y-2.5';

        const fmtDate = (value) => value ? new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : 'TBD';

        const dateRow = document.createElement('div');
        dateRow.className = 'flex justify-between items-center text-xs font-medium';
        const dateLabel = document.createElement('span');
        dateLabel.className = 'text-[var(--text-tertiary)]';
        dateLabel.textContent = 'Event Dates';
        const dateSpan = document.createElement('span');
        dateSpan.className = 'text-[var(--text-primary)]';
        dateSpan.textContent = `${fmtDate(row.event_start_at)} \u2013 ${fmtDate(row.event_end_at)}`;
        dateRow.appendChild(dateLabel);
        dateRow.appendChild(dateSpan);

        const applyRow = document.createElement('div');
        applyRow.className = 'flex justify-between items-center text-xs font-medium';
        const applyLabel = document.createElement('span');
        applyLabel.className = 'text-[var(--text-tertiary)]';
        applyLabel.textContent = 'Apply By';
        const applySpan = document.createElement('span');
        applySpan.className = 'text-[#fcd34d] font-bold';
        applySpan.textContent = fmtDate(row.volunteer_registration_deadline);
        applyRow.appendChild(applyLabel);
        applyRow.appendChild(applySpan);

        meta.appendChild(dateRow);
        meta.appendChild(applyRow);

        const actions = document.createElement('div');
        actions.className = 'mt-5 pt-1 flex items-center gap-3 w-full';

        const registerButton = document.createElement('button');
        registerButton.className = 'btn btn-primary flex-1 shadow-sm';

        const isRegistrationDisabled = row.registered || registrationClosed || row.phase === 'COMPLETED';
        registerButton.disabled = isRegistrationDisabled;

        if (row.registered) {
            registerButton.textContent = 'Registered \u2713';
        } else if (row.phase === 'COMPLETED') {
            registerButton.textContent = 'Completed';
            registerButton.className = 'btn btn-ghost border border-[var(--border-subtle)] flex-1 w-full bg-[var(--bg-surface-hover)]';
        } else if (registrationClosed) {
            registerButton.textContent = 'Registration Closed';
            registerButton.className = 'btn btn-ghost border border-[var(--border-subtle)] flex-1 w-full bg-[var(--bg-surface-hover)]';
        } else {
            registerButton.textContent = 'Register Now';
        }

        const viewLink = document.createElement('a');
        viewLink.href = `/endeavour_view.html?id=${row.id}`;
        viewLink.className = 'btn btn-secondary px-5 shadow-sm';
        viewLink.textContent = 'Details';

        const registerError = document.createElement('p');
        registerError.className = 'text-[11px] font-bold text-[#fca5a5] mt-2 hidden text-center w-full px-2 py-1 bg-[rgba(239,68,68,0.1)] rounded-md border border-[rgba(239,68,68,0.2)]';

        registerButton.addEventListener('click', async () => {
            if (registerButton.disabled) return;
            registerButton.disabled = true;
            registerError.classList.add('hidden');
            const originalText = registerButton.textContent;
            registerButton.textContent = 'Registering...';
            try {
                await apiFetch(`/endeavours/${row.id}/register`, { method: 'POST', body: JSON.stringify({}) });
                registerError.classList.add('hidden');
                registerButton.textContent = 'Registered \u2713';
                setTimeout(() => {
                    registerButton.disabled = true;
                }, 1500);
            } catch (err) {
                registerButton.textContent = originalText;
                registerButton.disabled = false;
                registerError.textContent = err?.message || 'Unable to register. Please try again.';
                registerError.classList.remove('hidden');
            }
        });

        actions.appendChild(registerButton);
        actions.appendChild(viewLink);

        card.appendChild(header);
        card.appendChild(title);
        card.appendChild(entityLabel);
        card.appendChild(desc);
        card.appendChild(meta);

        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'flex flex-col w-full z-10';
        actionsWrap.appendChild(actions);
        actionsWrap.appendChild(registerError);

        card.appendChild(actionsWrap);

        // Make the entire card clickable except for the buttons
        card.addEventListener('click', (e) => {
            if (e.target.closest('button') || e.target.closest('a')) return;
            window.location.href = `/endeavour_view.html?id=${row.id}`;
        });
        card.classList.add('cursor-pointer');

        grid.appendChild(card);
    });
};

const loadEndeavours = async () => {
    const params = new URLSearchParams();
    if (entityFilter.value) params.set('entity_id', entityFilter.value);
    if (searchInput.value) params.set('q', searchInput.value);

    grid.innerHTML = '<div class="col-span-1 md:col-span-2 lg:col-span-3 text-center py-16"><div class="w-8 h-8 mx-auto rounded-full border-2 border-[var(--color-primary)] border-t-transparent animate-spin mb-4"></div><p class="text-sm font-medium text-[var(--text-secondary)]">Loading opportunities...</p></div>';
    emptyState.classList.add('hidden');
    emptyState.classList.remove('flex');

    try {
        const response = await apiFetch(`/endeavours/volunteering?${params.toString()}`);
        emptyState.innerHTML = initialEmptyState;
        const rows = response?.data || [];
        visibleOpportunityCount = rows.length;
        renderEndeavours(rows);
        updateNotificationBadge();
    } catch (err) {
        visibleOpportunityCount = 0;
        updateNotificationBadge();
        grid.innerHTML = '';
        emptyState.innerHTML = `
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-[rgba(239,68,68,0.1)] border border-[rgba(239,68,68,0.2)] text-[#ef4444] mb-4">
          <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
          </svg>
        </span>
        <h3 class="text-sm font-bold text-[var(--text-primary)]">Failed to load</h3>
        <p class="text-[13px] font-medium text-[var(--text-secondary)] mt-1 max-w-sm">Failed to load endeavours. Please try again.</p>
        `;
        emptyState.classList.remove('hidden');
        emptyState.classList.add('flex');
    }
};

const loadNotifications = async () => {
    try {
        const response = await apiFetch('/notifications');
        unreadNotificationCount = response?.meta?.unread || 0;
        updateNotificationBadge();
    } catch (err) {
        unreadNotificationCount = 0;
        notificationBadge.classList.add('hidden');
    }
};

let searchTimer;
apiFetch('/auth/me')
    .then((response) => {
        const entities = response?.data?.entities || [];
        entityFilter.innerHTML = '<option value=\"\">All Entities</option>';
        entities.forEach((entity) => {
            const option = document.createElement('option');
            option.value = entity.id;
            option.textContent = entity.name;
            entityFilter.appendChild(option);
        });
        loadEndeavours();
        loadNotifications();
    })
    .catch(() => {
        entityFilter.innerHTML = '<option value=\"\">All Entities</option>';
        console.error('Failed to load user entities');
        loadEndeavours();
        loadNotifications();
    });

entityFilter.addEventListener('change', loadEndeavours);
searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadEndeavours, 250);
});
