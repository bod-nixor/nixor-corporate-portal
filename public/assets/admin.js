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
        entitiesList.innerHTML = '';
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 2;
        td.className = 'py-3 text-red-400 text-center border-t border-slate-800';
        td.textContent = 'Failed to load entities';
        tr.appendChild(td);
        entitiesList.appendChild(tr);
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

const passwordStateLabel = (user) => {
    if (Number(user.password_setup_required || 0) === 1) {
        return 'Setup required';
    }
    if (Number(user.force_password_reset || 0) === 1) {
        return 'Reset required';
    }
    return user.password_changed_at ? 'Set' : 'Not set';
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
            const password = document.createElement('td');
            password.className = 'py-3 text-slate-400';
            password.textContent = passwordStateLabel(user);
            const actions = document.createElement('td');
            actions.className = 'py-3';
            const actionWrap = document.createElement('div');
            actionWrap.className = 'flex flex-wrap gap-2';
            const forceButton = document.createElement('button');
            forceButton.type = 'button';
            forceButton.className = 'btn btn-secondary text-xs px-3 py-1.5';
            forceButton.dataset.forceResetUser = user.id;
            forceButton.dataset.sendEmail = '0';
            forceButton.textContent = 'Force reset';
            const emailButton = document.createElement('button');
            emailButton.type = 'button';
            emailButton.className = 'btn btn-secondary text-xs px-3 py-1.5';
            emailButton.dataset.forceResetUser = user.id;
            emailButton.dataset.sendEmail = '1';
            emailButton.textContent = 'Send link';
            actionWrap.appendChild(forceButton);
            actionWrap.appendChild(emailButton);
            actions.appendChild(actionWrap);
            row.appendChild(name);
            row.appendChild(email);
            row.appendChild(role);
            row.appendChild(password);
            row.appendChild(actions);
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
        cell.colSpan = 5;
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
const entityCancelForm = document.getElementById('entity-cancel');
if (entityCancelForm) {
    entityCancelForm.addEventListener('click', closeEntityModal);
}
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
        entityForm.reset();
        entityStatus.classList.add('hidden');
        closeEntityModal();
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
    payload.send_invite = userForm.querySelector('input[name="send_invite"]')?.checked ?? true;
    try {
        const response = await apiFetch('/users', { method: 'POST', body: JSON.stringify(payload) });
        const emailText = payload.send_invite
            ? (response?.data?.invite_email_sent ? ' Setup email sent.' : ' Mail delivery was not confirmed.')
            : '';
        setStatus(userStatus, `User created. Password setup required.${emailText}`, true);
        userForm.reset();
        loadUsers();
    } catch (err) {
        setStatus(userStatus, normalizeError(err), false);
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
});

usersList.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-force-reset-user]');
    if (!button) return;
    const userId = button.dataset.forceResetUser;
    const sendEmail = button.dataset.sendEmail === '1';
    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = sendEmail ? 'Sending...' : 'Saving...';
    try {
        const response = await apiFetch(`/users/${userId}/force-password-reset`, {
            method: 'POST',
            body: JSON.stringify({ send_email: sendEmail })
        });
        setStatus(userStatus, sendEmail
            ? (response?.data?.email_sent ? 'Password reset required and setup link sent.' : 'Password reset required. Mail delivery was not confirmed.')
            : 'Password reset required at next login.', true);
        await loadUsers();
    } catch (err) {
        setStatus(userStatus, normalizeError(err), false);
    } finally {
        if (document.contains(button)) {
            button.disabled = false;
            button.textContent = originalText;
        }
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
