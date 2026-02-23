import { apiFetch } from '/assets/app.js';
import { renderSidebar } from '/assets/sidebar.js';

document.getElementById('sidebar-container').outerHTML = renderSidebar('endeavours');

const searchInput = document.getElementById('search-input');
const entityFilter = document.getElementById('entity-filter');
const grid = document.getElementById('endeavour-grid');
const emptyState = document.getElementById('endeavour-empty');
const notificationBadge = document.getElementById('notification-badge');

let initialEmptyState = '';

const renderEndeavours = (rows) => {
    grid.innerHTML = '';
    if (!rows.length) {
        emptyState.classList.remove('hidden');
        emptyState.classList.add('flex');
        return;
    }
    emptyState.classList.add('hidden');
    emptyState.classList.remove('flex');

    rows.forEach((row) => {
        const card = document.createElement('div');
        card.className = 'card card-hoverable flex flex-col p-6 h-full';

        const header = document.createElement('div');
        header.className = 'flex items-center justify-between mb-3';

        const status = document.createElement('span');
        const now = new Date();
        const deadline = row.volunteer_registration_deadline ? new Date(row.volunteer_registration_deadline) : null;
        const registrationClosed = deadline && now > deadline;

        let badgeText = '';
        let badgeClass = 'badge ';

        if (row.registered) {
            badgeText = 'Registered';
            badgeClass += 'badge-info';
        } else if (row.phase === 'COMPLETED') {
            badgeText = 'Completed';
            badgeClass += 'bg-slate-800 text-slate-300 border-slate-700';
        } else if (registrationClosed) {
            badgeText = 'Registration Closed';
            badgeClass += 'badge-danger';
        } else {
            badgeText = 'Registration Open';
            badgeClass += 'badge-success';
        }

        status.className = badgeClass;
        status.textContent = badgeText;

        header.appendChild(status);

        const title = document.createElement('h2');
        title.className = 'text-xl font-bold tracking-tight text-slate-100';
        title.textContent = row.name;

        const entityLabel = document.createElement('p');
        entityLabel.className = 'text-xs font-semibold text-indigo-400 uppercase tracking-wider mt-1 mb-3';
        entityLabel.textContent = row.entity_name;

        const desc = document.createElement('p');
        desc.className = 'text-sm text-slate-400 leading-relaxed flex-1 line-clamp-3';
        desc.textContent = row.description || 'No description provided.';

        const meta = document.createElement('div');
        meta.className = 'mt-5 pt-4 border-t border-slate-800 space-y-2';

        const fmtDate = (value) => value ? new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : 'TBD';

        const dateRow = document.createElement('div');
        dateRow.className = 'flex justify-between items-center text-xs';
        const dateLabel = document.createElement('span');
        dateLabel.className = 'text-slate-500';
        dateLabel.textContent = 'Event Dates';
        const dateSpan = document.createElement('span');
        dateSpan.className = 'font-medium text-slate-300';
        dateSpan.textContent = `${fmtDate(row.event_start_at)} \u2013 ${fmtDate(row.event_end_at)}`;
        dateRow.appendChild(dateLabel);
        dateRow.appendChild(dateSpan);

        const applyRow = document.createElement('div');
        applyRow.className = 'flex justify-between items-center text-xs';
        const applyLabel = document.createElement('span');
        applyLabel.className = 'text-slate-500';
        applyLabel.textContent = 'Apply By';
        const applySpan = document.createElement('span');
        applySpan.className = 'font-medium text-amber-400';
        applySpan.textContent = fmtDate(row.volunteer_registration_deadline);
        applyRow.appendChild(applyLabel);
        applyRow.appendChild(applySpan);

        meta.appendChild(dateRow);
        meta.appendChild(applyRow);

        const actions = document.createElement('div');
        actions.className = 'mt-6 pt-2 flex items-center gap-3';

        const registerButton = document.createElement('button');
        registerButton.className = 'btn btn-primary flex-1';

        const isRegistrationDisabled = row.registered || registrationClosed || row.phase === 'COMPLETED';
        registerButton.disabled = isRegistrationDisabled;

        if (row.registered) {
            registerButton.textContent = 'Registered ✓';
        } else if (row.phase === 'COMPLETED') {
            registerButton.textContent = 'Completed';
        } else if (registrationClosed) {
            registerButton.textContent = 'Registration Closed';
        } else {
            registerButton.textContent = 'Register Now';
        }

        const viewLink = document.createElement('a');
        viewLink.href = `/endeavour_view.html?id=${row.id}`;
        viewLink.className = 'btn btn-secondary';
        viewLink.textContent = 'Details';

        const registerError = document.createElement('p');
        registerError.className = 'text-xs text-red-400 mt-2 font-medium hidden text-center w-full';

        registerButton.addEventListener('click', async () => {
            if (registerButton.disabled) return;
            registerButton.disabled = true;
            registerError.classList.add('hidden');
            const originalText = registerButton.textContent;
            registerButton.textContent = 'Registering...';
            try {
                await apiFetch(`/endeavours/${row.id}/register`, { method: 'POST', body: JSON.stringify({}) });
                registerButton.textContent = 'Registered ✓';
                registerButton.disabled = true;
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
        actionsWrap.className = 'flex flex-col w-full';
        actionsWrap.appendChild(actions);
        actionsWrap.appendChild(registerError);

        card.appendChild(actionsWrap);
        grid.appendChild(card);
    });
};

const loadEndeavours = async () => {
    if (!initialEmptyState) initialEmptyState = emptyState.innerHTML;

    const params = new URLSearchParams();
    if (entityFilter.value) params.set('entity_id', entityFilter.value);
    if (searchInput.value) params.set('q', searchInput.value);
    try {
        const response = await apiFetch(`/endeavours/volunteering?${params.toString()}`);
        emptyState.innerHTML = initialEmptyState;
        renderEndeavours(response?.data || []);
    } catch (err) {
        grid.innerHTML = '';
        emptyState.innerHTML = `
    <svg class="w-12 h-12 text-slate-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
    </svg>
    <h3 class="text-lg font-medium text-slate-300">Failed to load</h3>
    <p class="text-sm text-slate-500 mt-1 max-w-sm">Failed to load endeavours. Please try again.</p>
    `;
        emptyState.classList.remove('hidden');
        emptyState.classList.add('flex');
    }
};

const loadNotifications = async () => {
    try {
        const response = await apiFetch('/notifications');
        const unread = response?.meta?.unread || 0;
        if (unread > 0) {
            notificationBadge.textContent = `${unread} new`;
            notificationBadge.classList.remove('hidden');
        } else {
            notificationBadge.classList.add('hidden');
        }
    } catch (err) {
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
