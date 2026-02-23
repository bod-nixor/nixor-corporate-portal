import { apiFetch, normalizeError } from '/assets/app.js';
import { renderSidebar } from '/assets/sidebar.js';

document.getElementById('sidebar-container').outerHTML = renderSidebar('admin');

const metricMissing = document.getElementById('metric-missing');
const metricUnpaid = document.getElementById('metric-unpaid');
const metricConsent = document.getElementById('metric-consent');
const entitiesList = document.getElementById('entities-list');
const usersList = document.getElementById('users-list');
const userForm = document.getElementById('user-form');
const userStatus = document.getElementById('user-status');
const membershipForm = document.getElementById('membership-form');
const membershipStatus = document.getElementById('membership-status');
const entityModal = document.getElementById('entity-modal');
const entityForm = document.getElementById('entity-form');
const entityStatus = document.getElementById('entity-status');
const openEntityForm = document.getElementById('open-entity-form');
const closeEntityForm = document.getElementById('entity-close');



const setStatus = (el, message, ok) => {
    el.textContent = message;
    el.className = `text-sm rounded-xl px-4 py-3 ${ok ? 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/30' : 'bg-red-500/10 text-red-300 border border-red-500/30'}`;
    el.classList.remove('hidden');
};

const loadSummary = async () => {
    try {
        const response = await apiFetch('/admin/summary');
        metricMissing.textContent = response?.data?.missing_docs ?? 0;
        metricUnpaid.textContent = response?.data?.unpaid ?? 0;
        metricConsent.textContent = response?.data?.consents_pending ?? 0;
    } catch (err) {
        console.error('Failed to load summary:', err);
        metricMissing.textContent = '--';
        metricUnpaid.textContent = '--';
        metricConsent.textContent = '--';
    }
};

const loadEntities = async () => {
    try {
        const response = await apiFetch('/entities');
        const entities = response?.data || [];
        entitiesList.innerHTML = '';
        membershipForm.entity_id.innerHTML = '';
        entities.forEach((entity) => {
            const row = document.createElement('tr');
            row.className = 'hover:bg-slate-800/30 transition-colors cursor-default text-slate-300';
            const name = document.createElement('td');
            name.className = 'py-3 font-medium text-slate-200';
            name.textContent = entity.name;
            const desc = document.createElement('td');
            desc.className = 'py-3 text-slate-400 truncate max-w-[200px]';
            desc.textContent = entity.description || '-';
            row.appendChild(name);
            row.appendChild(desc);
            entitiesList.appendChild(row);

            const option = document.createElement('option');
            option.value = entity.id;
            option.textContent = entity.name;
            membershipForm.entity_id.appendChild(option);
        });
    } catch (err) {
        console.error('Failed to load entities:', err);
        entitiesList.innerHTML = '<tr><td colspan="2" class="py-3 text-red-400 text-center border-t border-slate-800">Failed to load entities</td></tr>';
    }
};

const getRoleBadge = (role) => {
    const badges = {
        'admin': 'badge-danger',
        'board': 'badge-warning',
        'ceo': 'badge-success',
        'staff': 'badge-info',
        'volunteer': 'bg-slate-800 text-slate-300 border border-slate-700'
    };
    const r = (role || 'volunteer').toLowerCase();
    const badgeCls = badges[r] || badges['volunteer'];
    return `badge ${badgeCls}`;
};

const loadUsers = async () => {
    try {
        const response = await apiFetch('/users');
        const users = response?.data || [];
        usersList.innerHTML = '';
        membershipForm.user_id.innerHTML = '';
        users.forEach((user) => {
            const row = document.createElement('tr');
            row.className = 'hover:bg-slate-800/30 transition-colors cursor-default text-slate-300';
            const name = document.createElement('td');
            name.className = 'py-3 font-medium text-slate-200';
            name.textContent = user.full_name;
            const email = document.createElement('td');
            email.className = 'py-3 text-slate-400 truncate max-w-[150px]';
            email.textContent = user.email;
            const role = document.createElement('td');
            role.className = 'py-3';
            const roleSpan = document.createElement('span');
            roleSpan.className = `${getRoleBadge(user.global_role)} capitalize`;
            roleSpan.textContent = user.global_role;
            role.appendChild(roleSpan);
            row.appendChild(name);
            row.appendChild(email);
            row.appendChild(role);
            usersList.appendChild(row);

            const option = document.createElement('option');
            option.value = user.id;
            option.textContent = `${user.full_name} (${user.email})`;
            membershipForm.user_id.appendChild(option);
        });
    } catch (err) {
        console.error('Failed to load users:', err);
        const message = err?.message ? `Failed to load users (${err.message})` : 'Failed to load users';
        usersList.innerHTML = '';
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        row.className = 'border-t border-slate-800';
        cell.className = 'py-3 text-red-400 text-center';
        cell.colSpan = 3;
        cell.textContent = message;
        row.appendChild(cell);
        usersList.appendChild(row);
    }
};

let previouslyFocusedElement = null;

const closeEntityModal = () => {
    entityModal.classList.add('hidden');
    entityModal.setAttribute('aria-hidden', 'true');
    document.removeEventListener('keydown', handleModalKeydown);
    if (previouslyFocusedElement) previouslyFocusedElement.focus();
};

const handleModalKeydown = (e) => {
    if (e.key === 'Escape') closeEntityModal();
    if (e.key === 'Tab') {
        const focusableElements = entityModal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
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

openEntityForm.addEventListener('click', () => {
    previouslyFocusedElement = document.activeElement;
    entityModal.classList.remove('hidden');
    entityModal.setAttribute('aria-hidden', 'false');
    document.addEventListener('keydown', handleModalKeydown);

    setTimeout(() => entityForm.querySelector('input').focus(), 50);
});

closeEntityForm.addEventListener('click', closeEntityModal);
entityModal.addEventListener('click', (e) => {
    if (e.target === entityModal) {
        closeEntityModal();
    }
});

entityForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitBtn = entityForm.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    try {
        await apiFetch('/entities', { method: 'POST', body: JSON.stringify({ name: entityForm.name.value, description: entityForm.description.value }) });
        setStatus(entityStatus, 'Entity created.', true);
        entityForm.reset();
        loadEntities();
    } catch (err) {
        setStatus(entityStatus, normalizeError(err), false);
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
});

userForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitBtn = userForm.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    const payload = Object.fromEntries(new FormData(userForm).entries());
    try {
        await apiFetch('/users', { method: 'POST', body: JSON.stringify(payload) });
        setStatus(userStatus, 'User created.', true);
        userForm.reset();
        loadUsers();
    } catch (err) {
        setStatus(userStatus, normalizeError(err), false);
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
});

membershipForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitBtn = membershipForm.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    const payload = Object.fromEntries(new FormData(membershipForm).entries());
    try {
        await apiFetch('/members', { method: 'POST', body: JSON.stringify(payload) });
        setStatus(membershipStatus, 'Membership assigned.', true);
        membershipForm.reset();
    } catch (err) {
        setStatus(membershipStatus, normalizeError(err), false);
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
});

loadSummary();
loadEntities();
loadUsers();
