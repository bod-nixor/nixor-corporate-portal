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
const entityModalTitle = document.getElementById('entity-modal-title');
const entitySubmit = document.getElementById('entity-submit');
const connectSearch = document.getElementById('connect-search');
const connectNew = document.getElementById('connect-new');
const connectList = document.getElementById('connect-entitlements-list');
const connectForm = document.getElementById('connect-form');
const connectFormTitle = document.getElementById('connect-form-title');
const connectStatus = document.getElementById('connect-status');
const connectId = document.getElementById('connect-id');
const connectEmail = document.getElementById('connect-email');
const connectDisplayName = document.getElementById('connect-display-name');
const connectGoogleSub = document.getElementById('connect-google-sub');
const connectMatrixUserId = document.getElementById('connect-matrix-user-id');
const connectAutoMatrix = document.getElementById('connect-auto-matrix');
const connectIsAllowed = document.getElementById('connect-is-allowed');
const connectIsSchoolAdmin = document.getElementById('connect-is-school-admin');
const connectIsApprovedDeveloper = document.getElementById('connect-is-approved-developer');
const connectDeveloperPermissionsEl = document.getElementById('connect-developer-permissions');
const connectOwnedApps = document.getElementById('connect-owned-apps');
const connectMemberships = document.getElementById('connect-memberships');
const connectAddMembership = document.getElementById('connect-add-membership');
const connectReset = document.getElementById('connect-reset');
const connectTestForm = document.getElementById('connect-test-form');
const connectTestOutput = document.getElementById('connect-test-output');

let editingEntity = null;
let connectEntitlements = [];
let connectSearchTimer = null;
let connectDeveloperPermissions = ['apps:create', 'apps:manage:own', 'apps:manage:all', 'tokens:dangerous-scopes'];
let connectMembershipRoles = ['member', 'moderator', 'admin', 'owner'];

const setStatus = (el, message, ok) => {
    el.textContent = message;
    el.className = `text-sm rounded-xl px-4 py-3 ${ok ? 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/30' : 'bg-red-500/10 text-red-300 border border-red-500/30'}`;
    el.classList.remove('hidden');
};

const entityInitials = (name) => String(name || 'Entity').trim().split(/\s+/).slice(0, 2).map(part => part[0] || '').join('').toUpperCase() || 'E';

const renderEntityAvatar = (entity) => {
    if (entity.avatar_url) {
        return `<img src="${entity.avatar_url}" alt="" class="w-9 h-9 rounded-full object-cover border border-[--border-strong]" />`;
    }
    return `<div class="w-9 h-9 rounded-full bg-[--bg-surface-hover] border border-[--border-strong] flex items-center justify-center text-xs font-bold text-[--text-secondary]">${entityInitials(entity.name)}</div>`;
};

const connectSplitList = (value) => String(value || '').split(/[\s,]+/).map(item => item.trim()).filter(Boolean);

const connectMatrixIdForEmail = (email) => {
    const local = String(email || '').toLowerCase().split('@')[0] || '';
    let safe = local.replace(/[^a-z0-9._=/-]+/g, '.').replace(/[.]{2,}/g, '.').replace(/^[._=/-]+|[._=/-]+$/g, '');
    if (!safe) safe = 'user';
    return `@${safe.slice(0, 120)}:connect.nixorcorporate.com`;
};

const connectBooleanBadge = (value, label) => {
    const span = document.createElement('span');
    span.className = value ? 'badge badge-success' : 'badge bg-slate-800 text-slate-300 border border-slate-700';
    span.textContent = value ? label : 'No';
    return span;
};

const renderConnectPermissions = (selected = []) => {
    connectDeveloperPermissionsEl.innerHTML = '';
    connectDeveloperPermissions.forEach((permission) => {
        const label = document.createElement('label');
        label.className = 'flex items-center justify-between gap-3 rounded-lg border border-[var(--border-subtle)] px-3 py-2 text-sm font-semibold';
        const text = document.createElement('span');
        text.textContent = permission;
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.value = permission;
        input.className = 'h-4 w-4 accent-[var(--color-primary)]';
        input.checked = selected.includes(permission);
        label.append(text, input);
        connectDeveloperPermissionsEl.appendChild(label);
    });
};

const createConnectMembershipRow = (membership = {}) => {
    const row = document.createElement('div');
    row.className = 'connect-membership-row grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_140px_auto] gap-2 items-center';
    const server = document.createElement('input');
    server.className = 'input-field font-medium';
    server.placeholder = 'srv_...';
    server.value = membership.server_public_id || '';
    server.dataset.connectServerPublicId = '1';
    const role = document.createElement('select');
    role.className = 'input-field font-medium';
    role.dataset.connectMembershipRole = '1';
    connectMembershipRoles.forEach((roleValue) => {
        const option = document.createElement('option');
        option.value = roleValue;
        option.textContent = roleValue;
        role.appendChild(option);
    });
    role.value = membership.role || 'member';
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-ghost px-3 py-2';
    remove.textContent = 'Remove';
    remove.addEventListener('click', () => row.remove());
    row.append(server, role, remove);
    connectMemberships.appendChild(row);
};

const resetConnectForm = () => {
    connectForm.reset();
    connectId.value = '';
    connectFormTitle.textContent = 'Add Connect Entitlement';
    connectStatus.classList.add('hidden');
    connectMemberships.innerHTML = '';
    renderConnectPermissions([]);
};

const populateConnectForm = (entitlement) => {
    connectForm.reset();
    connectId.value = entitlement.id;
    connectEmail.value = entitlement.email || '';
    connectDisplayName.value = entitlement.display_name || '';
    connectGoogleSub.value = entitlement.google_sub || '';
    connectMatrixUserId.value = entitlement.matrix_user_id || '';
    connectIsAllowed.checked = Boolean(entitlement.is_allowed);
    connectIsSchoolAdmin.checked = Boolean(entitlement.is_school_admin);
    connectIsApprovedDeveloper.checked = Boolean(entitlement.is_approved_developer);
    connectOwnedApps.value = (entitlement.owned_developer_app_ids || []).join('\n');
    renderConnectPermissions(entitlement.developer_permissions || []);
    connectMemberships.innerHTML = '';
    (entitlement.memberships || []).forEach(createConnectMembershipRow);
    connectFormTitle.textContent = `Edit ${entitlement.email || 'Connect Entitlement'}`;
    connectStatus.classList.add('hidden');
    connectEmail.focus();
};

const collectConnectPayload = () => {
    if (!connectEmail.checkValidity()) {
        connectEmail.reportValidity();
        return null;
    }
    const ownedApps = connectSplitList(connectOwnedApps.value);
    const invalidApp = ownedApps.find(appId => !/^app_[A-Za-z0-9_-]+$/.test(appId));
    if (invalidApp) {
        setStatus(connectStatus, 'Owned app IDs must start with app_.', false);
        return null;
    }
    const memberships = [];
    for (const row of connectMemberships.querySelectorAll('.connect-membership-row')) {
        const serverPublicId = row.querySelector('[data-connect-server-public-id]')?.value.trim() || '';
        const role = row.querySelector('[data-connect-membership-role]')?.value || 'member';
        if (!serverPublicId) {
            continue;
        }
        if (!/^srv_[A-Za-z0-9_-]+$/.test(serverPublicId)) {
            setStatus(connectStatus, 'Server public IDs must start with srv_.', false);
            return null;
        }
        if (!connectMembershipRoles.includes(role)) {
            setStatus(connectStatus, 'Choose a valid membership role.', false);
            return null;
        }
        memberships.push({ server_public_id: serverPublicId, role });
    }
    return {
        email: connectEmail.value.trim(),
        display_name: connectDisplayName.value.trim(),
        google_sub: connectGoogleSub.value.trim(),
        matrix_user_id: connectMatrixUserId.value.trim(),
        is_allowed: connectIsAllowed.checked,
        is_school_admin: connectIsSchoolAdmin.checked,
        is_approved_developer: connectIsApprovedDeveloper.checked,
        developer_permissions: Array.from(connectDeveloperPermissionsEl.querySelectorAll('input[type="checkbox"]:checked')).map(input => input.value),
        owned_developer_app_ids: ownedApps,
        memberships,
    };
};

const renderConnectList = () => {
    connectList.innerHTML = '';
    if (connectEntitlements.length === 0) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5;
        cell.className = 'py-6 text-center text-[var(--text-tertiary)]';
        cell.textContent = 'No Connect entitlements found.';
        row.appendChild(cell);
        connectList.appendChild(row);
        return;
    }
    connectEntitlements.forEach((entitlement) => {
        const row = document.createElement('tr');
        row.className = 'hover:bg-slate-800/30 transition-colors text-slate-300';
        const userCell = document.createElement('td');
        userCell.className = 'px-6 py-3';
        const name = document.createElement('p');
        name.className = 'font-semibold text-slate-200';
        name.textContent = entitlement.display_name || entitlement.email;
        const email = document.createElement('p');
        email.className = 'text-xs text-slate-400 mt-1';
        email.textContent = entitlement.email;
        userCell.append(name, email);
        const allowed = document.createElement('td');
        allowed.className = 'px-6 py-3';
        allowed.appendChild(connectBooleanBadge(entitlement.is_allowed, 'Allowed'));
        const developer = document.createElement('td');
        developer.className = 'px-6 py-3';
        developer.textContent = entitlement.is_school_admin
            ? 'School admin'
            : (entitlement.is_approved_developer ? 'Approved developer' : `${(entitlement.owned_developer_app_ids || []).length} owned apps`);
        const memberships = document.createElement('td');
        memberships.className = 'px-6 py-3 text-slate-400';
        memberships.textContent = `${(entitlement.memberships || []).length}`;
        const actions = document.createElement('td');
        actions.className = 'px-6 py-3';
        const wrap = document.createElement('div');
        wrap.className = 'flex flex-wrap gap-2';
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'btn btn-secondary text-xs px-3 py-1.5';
        edit.textContent = 'Edit';
        edit.addEventListener('click', () => populateConnectForm(entitlement));
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-ghost text-xs px-3 py-1.5';
        remove.textContent = 'Delete';
        remove.addEventListener('click', async () => {
            if (!confirm(`Delete Connect entitlement for ${entitlement.email}?`)) return;
            remove.disabled = true;
            try {
                await apiFetch(`/admin/connect-entitlements/${entitlement.id}`, { method: 'DELETE' });
                await loadConnectEntitlements();
                resetConnectForm();
            } catch (err) {
                setStatus(connectStatus, normalizeError(err), false);
            } finally {
                remove.disabled = false;
            }
        });
        wrap.append(edit, remove);
        actions.appendChild(wrap);
        row.append(userCell, allowed, developer, memberships, actions);
        connectList.appendChild(row);
    });
};

const loadConnectEntitlements = async () => {
    try {
        const query = connectSearch?.value?.trim() || '';
        const response = await apiFetch(`/admin/connect-entitlements${query ? `?q=${encodeURIComponent(query)}` : ''}`);
        connectDeveloperPermissions = response?.data?.developer_permissions || connectDeveloperPermissions;
        connectMembershipRoles = response?.data?.membership_roles || connectMembershipRoles;
        connectEntitlements = response?.data?.entitlements || [];
        renderConnectPermissions(Array.from(connectDeveloperPermissionsEl.querySelectorAll('input[type="checkbox"]:checked')).map(input => input.value));
        renderConnectList();
    } catch (err) {
        console.error('Failed to load Connect entitlements:', err);
        connectList.innerHTML = '';
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5;
        cell.className = 'py-6 text-center text-red-400';
        cell.textContent = 'Failed to load Connect entitlements.';
        row.appendChild(cell);
        connectList.appendChild(row);
    }
};

const avatarInput = document.getElementById('modal-entity-avatar');
if (avatarInput) {
    const maxAvatarSize = 3 * 1024 * 1024;
    avatarInput.addEventListener('change', () => {
        const file = avatarInput.files?.[0] || null;
        if (!file) {
            avatarInput.setCustomValidity('');
            return;
        }
        if (file.size > maxAvatarSize) {
            avatarInput.value = '';
            avatarInput.setCustomValidity('Profile image must be 3MB or smaller.');
            avatarInput.reportValidity();
            return;
        }
        avatarInput.setCustomValidity('');
    });
}

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
            const avatar = document.createElement('td');
            avatar.className = 'py-3 pl-6 pr-2';
            avatar.innerHTML = renderEntityAvatar(entity);
            const name = document.createElement('td');
            name.className = 'py-3 font-medium text-slate-200';
            name.textContent = entity.name;
            const desc = document.createElement('td');
            desc.className = 'py-3 text-slate-400 truncate max-w-[200px]';
            desc.textContent = entity.description || '-';
            const actions = document.createElement('td');
            actions.className = 'py-3 pr-6';
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'btn btn-secondary text-xs px-3 py-1.5';
            edit.textContent = 'Edit';
            edit.addEventListener('click', () => openEntityModal(entity, edit));
            actions.appendChild(edit);
            row.append(avatar, name, desc, actions);
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
        td.colSpan = 4;
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
    editingEntity = null;
    entityForm.reset();
    entityStatus.classList.add('hidden');
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

const openEntityModal = (entity = null, trigger = document.activeElement) => {
    previouslyFocusedElement = trigger;
    editingEntity = entity;
    entityForm.reset();
    entityForm.name.value = entity?.name || '';
    entityForm.description.value = entity?.description || '';
    entityStatus.classList.add('hidden');
    if (entityModalTitle) entityModalTitle.textContent = entity ? 'Edit Entity' : 'New Entity';
    if (entitySubmit) entitySubmit.textContent = entity ? 'Save Entity' : 'Create Entity';
    entityModal.classList.remove('hidden');
    entityModal.setAttribute('aria-hidden', 'false');
    document.addEventListener('keydown', handleModalKeydown);

    setTimeout(() => entityForm.querySelector('input').focus(), 50);
};

openEntityForm.addEventListener('click', () => openEntityModal(null, openEntityForm));

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
        const formData = new FormData(entityForm);
        const url = editingEntity ? `/entities/${encodeURIComponent(editingEntity.public_id || editingEntity.id)}/update` : '/entities';
        await apiFetch(url, { method: 'POST', body: formData });
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

connectSearch.addEventListener('input', () => {
    clearTimeout(connectSearchTimer);
    connectSearchTimer = setTimeout(loadConnectEntitlements, 250);
});

connectNew.addEventListener('click', resetConnectForm);
connectReset.addEventListener('click', resetConnectForm);
connectAddMembership.addEventListener('click', () => createConnectMembershipRow());
connectAutoMatrix.addEventListener('click', () => {
    connectMatrixUserId.value = connectMatrixIdForEmail(connectEmail.value);
});

connectForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const payload = collectConnectPayload();
    if (!payload) return;
    const submitBtn = connectForm.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    const id = connectId.value;
    try {
        const response = await apiFetch(id ? `/admin/connect-entitlements/${encodeURIComponent(id)}` : '/admin/connect-entitlements', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify(payload)
        });
        setStatus(connectStatus, 'Connect entitlement saved.', true);
        await loadConnectEntitlements();
        populateConnectForm(response?.data?.entitlement);
    } catch (err) {
        setStatus(connectStatus, normalizeError(err), false);
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
});

connectTestForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const emailInput = connectTestForm.querySelector('[name="email"]');
    if (!emailInput.checkValidity()) {
        emailInput.reportValidity();
        return;
    }
    const submitBtn = connectTestForm.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    try {
        const response = await apiFetch('/admin/connect-entitlements/test-resolve', {
            method: 'POST',
            body: JSON.stringify({ email: emailInput.value.trim() })
        });
        connectTestOutput.textContent = JSON.stringify({
            http_status: response?.data?.status,
            body: response?.data?.response
        }, null, 2);
        connectTestOutput.classList.remove('hidden');
    } catch (err) {
        connectTestOutput.textContent = normalizeError(err);
        connectTestOutput.classList.remove('hidden');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
});

resetConnectForm();
loadSummary();
loadConnectEntitlements();
loadEntities();
loadUsers();
