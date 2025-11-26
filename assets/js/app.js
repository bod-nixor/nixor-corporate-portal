(function () {
    const apiBase = document.querySelector('meta[name="api-base"]')?.content || '/api';
    let session = null;
    let wsClient = null;
    let csrfToken = null;
    let appConfig = {};
    let googleInitScheduled = false;

    document.addEventListener('DOMContentLoaded', () => {
        fetchConfig().then((config) => {
            appConfig = config || {};
            if (appConfig.googleClientId) {
                window.GOOGLE_CLIENT_ID = appConfig.googleClientId;
            }
            initAuth();
        });
        bindGlobalControls();
        bootstrapPage();
    });

    function fetchConfig() {
        return fetch(`${apiBase}/auth/config.php`, { credentials: 'include' })
            .then((res) => res.ok ? res.json() : {})
            .catch(() => ({}));
    }

    function bootstrapPage() {
        // Placeholder for any page level bootstrapping that must happen
        // before authentication completes. Left intentionally light to
        // keep bundle size minimal.
    }

    function initAuth() {
        fetch(`${apiBase}/auth/session.php`, { credentials: 'include' })
            .then((res) => res.ok ? res.json() : null)
            .then((data) => {
                if (data && data.user) {
                    session = data.user;
                    csrfToken = data.csrfToken;
                    updateUserUi();
                    openSocket(data.wsToken, data.wsUrl || appConfig.wsUrl);
                    onSessionReady();
                } else {
                    renderLoggedOut();
                }
            })
            .catch(() => renderLoggedOut());

        initializeGoogleIdentity();
    }

    function initializeGoogleIdentity() {
        const clientId = getGoogleClientId();
        if (!clientId) {
            console.warn('Google Identity Services client ID is not configured; login is disabled until it is provided.');
            return;
        }

        const setup = () => {
            if (!(window.google && window.google.accounts && window.google.accounts.id)) return false;
            google.accounts.id.initialize({
                client_id: clientId,
                callback: handleGoogleCredential,
                auto_select: false,
            });
            const button = document.getElementById('login-button');
            if (button) {
                button.addEventListener('click', () => google.accounts.id.prompt());
            }
            return true;
        };

        if (!setup() && !googleInitScheduled) {
            googleInitScheduled = true;
            window.addEventListener('load', setup, { once: true });
        }
    }

    function getGoogleClientId() {
        const metaValue = document.querySelector('meta[name="google-client-id"]')?.content || '';
        const provided = window.GOOGLE_CLIENT_ID || metaValue;
        return provided.trim();
    }

    function handleGoogleCredential(response) {
        const body = { credential: response.credential };
        fetch(`${apiBase}/auth/google-exchange.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(body),
        })
            .then((res) => {
                if (!res.ok) throw new Error('Authentication failed');
                return res.json();
            })
            .then((data) => {
                session = data.user;
                csrfToken = data.csrfToken;
                updateUserUi();
                openSocket(data.wsToken, data.wsUrl || appConfig.wsUrl);
                onSessionReady();
            })
            .catch((err) => alert(err.message));
    }

    function bindGlobalControls() {
        const logoutButton = document.getElementById('logout-button');
        if (logoutButton) {
            logoutButton.addEventListener('click', () => {
                fetch(`${apiBase}/auth/logout.php`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: csrfHeaders(),
                }).finally(() => {
                    session = null;
                    csrfToken = null;
                    renderLoggedOut();
                    closeSocket();
                });
            });
        }
    }

    function renderLoggedOut() {
        const loginButton = document.getElementById('login-button');
        const logoutButton = document.getElementById('logout-button');
        const info = document.getElementById('user-info');
        if (loginButton) loginButton.classList.remove('hidden');
        if (logoutButton) logoutButton.classList.add('hidden');
        if (info) {
            info.textContent = '';
            info.classList.add('hidden');
        }
    }

    function updateUserUi() {
        const loginButton = document.getElementById('login-button');
        const logoutButton = document.getElementById('logout-button');
        const info = document.getElementById('user-info');
        if (!session) return;
        if (loginButton) loginButton.classList.add('hidden');
        if (logoutButton) logoutButton.classList.remove('hidden');
        if (info) {
            info.textContent = `${session.name} (${session.role})`;
            info.classList.remove('hidden');
        }
    }

    function onSessionReady() {
        const page = document.body.dataset.page;
        if (page === 'dashboard') {
            loadEntities();
            loadEndeavours();
            setupDashboardEvents();
        } else if (page === 'hr') {
            ensureRole(['HR', 'ADMIN']);
            loadRegistrations();
            bindHrEvents();
        } else if (page === 'admin') {
            ensureRole(['ADMIN']);
            loadSettings();
            bindAdminEvents();
        } else if (page === 'consent') {
            ensureRole(['VOLUNTEER', 'HR', 'ADMIN', 'ENTITY_MANAGER']);
            bindConsent();
        }
    }

    function ensureRole(allowed) {
        if (!session || !allowed.includes(session.role)) {
            alert('You do not have access to this area.');
            window.location.href = '/index.html';
        }
    }

    function setupDashboardEvents() {
        document.getElementById('apply-filters')?.addEventListener('click', () => loadEndeavours());
    }

    function loadEntities() {
        fetch(`${apiBase}/entities.php`, { credentials: 'include' })
            .then((res) => res.ok ? res.json() : Promise.reject())
            .then((data) => {
                const select = document.getElementById('filter-entity');
                if (!select) return;
                select.innerHTML = '<option value="">All</option>';
                data.data.forEach((entity) => {
                    const option = document.createElement('option');
                    option.value = entity.id;
                    option.textContent = entity.name;
                    select.appendChild(option);
                });
            })
            .catch(() => {});
    }

    function loadEndeavours() {
        const params = new URLSearchParams();
        const entity = document.getElementById('filter-entity')?.value;
        const tags = document.getElementById('filter-tags')?.value;
        const start = document.getElementById('filter-start')?.value;
        const end = document.getElementById('filter-end')?.value;
        if (entity) params.set('entityId', entity);
        if (tags) params.set('tags', tags);
        if (start) params.set('startFrom', start);
        if (end) params.set('endTo', end);

        fetch(`${apiBase}/endeavours.php?${params.toString()}`, { credentials: 'include' })
            .then((res) => res.ok ? res.json() : Promise.reject(res))
            .then((data) => renderEndeavours(data.data || []))
            .catch(() => {
                document.getElementById('endeavour-list').innerHTML = '<p class="empty">No endeavours found.</p>';
            });
    }

    function renderEndeavours(items) {
        const container = document.getElementById('endeavour-list');
        const tpl = document.getElementById('endeavour-card-template');
        if (!container || !tpl) return;
        container.innerHTML = '';
        items.forEach((item) => {
            const node = tpl.content.cloneNode(true);
            node.querySelector('.title').textContent = item.title;
            node.querySelector('.entity').textContent = item.entity.name;
            node.querySelector('.description').textContent = truncate(item.description, 240);
            node.querySelector('.venue').textContent = item.venue;
            node.querySelector('.schedule').textContent = `${formatDate(item.startAt)} → ${formatDate(item.endAt)}`;
            node.querySelector('.status-chip').textContent = item.registrationStatus;
            node.querySelector('.transport').textContent = item.requiresTransportPayment ? 'Transport payment required' : 'Transport optional';
            const tagContainer = node.querySelector('.tags');
            if (item.tags) {
                item.tags.forEach((tag) => {
                    const span = document.createElement('span');
                    span.textContent = tag.name;
                    tagContainer.appendChild(span);
                });
            }
            const details = node.querySelector('.details');
            node.querySelector('.details-toggle').addEventListener('click', () => {
                details.classList.toggle('hidden');
            });
            node.querySelector('.register-button').addEventListener('click', () => registerInterest(item.id));
            container.appendChild(node);
        });
    }

    function registerInterest(endeavourId) {
        if (!session) return alert('Please sign in first.');
        fetch(`${apiBase}/registrations.php`, {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/json' }, csrfHeaders()),
            credentials: 'include',
            body: JSON.stringify({ endeavourId }),
        })
            .then((res) => {
                if (res.status === 422) return res.json().then((d) => { throw new Error(d.message); });
                if (!res.ok) throw new Error('Unable to register.');
                return res.json();
            })
            .then(() => {
                alert('Registration submitted.');
                loadEndeavours();
            })
            .catch((err) => alert(err.message));
    }

    function loadRegistrations() {
        fetch(`${apiBase}/registrations.php`, { credentials: 'include' })
            .then((res) => res.ok ? res.json() : Promise.reject(res))
            .then((data) => renderRegistrations(data.data || []))
            .catch(() => renderRegistrations([]));
    }

    function renderRegistrations(list) {
        const tbody = document.querySelector('#registrations-table tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        list.forEach((row) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeHtml(row.volunteer.name)}</td>
                <td>${escapeHtml(row.entity.name)}</td>
                <td>${escapeHtml(row.endeavour.title)}</td>
                <td><span class="status-chip">${row.status}</span></td>
                <td>${formatDate(row.registeredAt)}</td>
                <td><button class="secondary" data-action="notes" data-volunteer="${row.volunteer.id}" data-entity="${row.entity.id}">View</button></td>
                <td>
                    <select data-action="status" data-id="${row.id}">
                        <option value="REGISTERED">Registered</option>
                        <option value="SHORTLISTED">Shortlisted</option>
                        <option value="CONSENT_PENDING">Consent Pending</option>
                        <option value="PAYMENT_PENDING">Payment Pending</option>
                        <option value="CONFIRMED">Confirmed</option>
                        <option value="REJECTED">Rejected</option>
                        <option value="WITHDRAWN">Withdrawn</option>
                    </select>
                </td>`;
            tr.querySelector('select').value = row.status;
            tbody.appendChild(tr);
        });
    }

    function bindHrEvents() {
        document.querySelector('#registrations-table tbody')?.addEventListener('change', (event) => {
            const select = event.target;
            if (select.dataset.action === 'status') {
                updateRegistrationStatus(select.dataset.id, select.value);
            }
        });

        document.querySelector('#registrations-table tbody')?.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-action="notes"]');
            if (!button) return;
            loadNotes(button.dataset.volunteer, button.dataset.entity);
        });

        document.getElementById('note-form')?.addEventListener('submit', (event) => {
            event.preventDefault();
            const form = event.target;
            const volunteerId = form.dataset.volunteer;
            const entityId = form.dataset.entity;
            const note = form.note.value.trim();
            if (!volunteerId || !note) return;
            fetch(`${apiBase}/hr/notes.php`, {
                method: 'POST',
                headers: Object.assign({ 'Content-Type': 'application/json' }, csrfHeaders()),
                credentials: 'include',
                body: JSON.stringify({ volunteerId, entityId, note }),
            })
                .then((res) => res.json())
                .then(() => loadNotes(volunteerId, entityId));
        });

        document.getElementById('export-csv')?.addEventListener('click', () => {
            window.location.href = `${apiBase}/registrations.php?format=csv`;
        });

        document.getElementById('close-notes')?.addEventListener('click', () => {
            document.getElementById('notes-drawer')?.classList.add('hidden');
        });
    }

    function updateRegistrationStatus(id, status) {
        fetch(`${apiBase}/registrations.php`, {
            method: 'PATCH',
            headers: Object.assign({ 'Content-Type': 'application/json' }, csrfHeaders()),
            credentials: 'include',
            body: JSON.stringify({ id, status }),
        })
            .then((res) => {
                if (!res.ok) throw new Error('Unable to update');
                return res.json();
            })
            .then(() => loadRegistrations())
            .catch((err) => alert(err.message));
    }

    function loadNotes(volunteerId, entityId) {
        fetch(`${apiBase}/hr/notes.php?volunteerId=${volunteerId}&entityId=${entityId}`, { credentials: 'include' })
            .then((res) => res.json())
            .then((data) => {
                const drawer = document.getElementById('notes-drawer');
                const list = drawer.querySelector('.notes-list');
                const form = drawer.querySelector('form');
                form.dataset.volunteer = volunteerId;
                form.dataset.entity = entityId;
                list.innerHTML = '';
                data.data.forEach((note) => {
                    const article = document.createElement('article');
                    article.innerHTML = `<strong>${escapeHtml(note.author.name)}</strong><br>${escapeHtml(note.note)}<br><span class="muted">${formatDate(note.createdAt)}</span>`;
                    list.appendChild(article);
                });
                drawer.classList.remove('hidden');
            });
    }

    function bindAdminEvents() {
        document.getElementById('visibility-form')?.addEventListener('submit', (event) => {
            event.preventDefault();
            const mode = new FormData(event.target).get('visibility');
            if (!mode) return;
            fetch(`${apiBase}/admin/settings.php`, {
                method: 'POST',
                headers: Object.assign({ 'Content-Type': 'application/json' }, csrfHeaders()),
                credentials: 'include',
                body: JSON.stringify({ visibilityMode: mode }),
            }).then(() => loadSettings());
        });
    }

    function loadSettings() {
        fetch(`${apiBase}/admin/settings.php`, { credentials: 'include' })
            .then((res) => res.json())
            .then((data) => {
                const form = document.getElementById('visibility-form');
                if (form) {
                    const current = data.visibilityMode;
                    form.querySelectorAll('input[name="visibility"]').forEach((input) => {
                        input.checked = input.value === current;
                    });
                }
                const tbody = document.querySelector('#entity-quotas tbody');
                if (tbody) {
                    tbody.innerHTML = '';
                    data.entities.forEach((entity) => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${escapeHtml(entity.name)}</td>
                            <td><input type="number" min="0" value="${entity.publishQuotaPer7d}" data-entity="${entity.id}"></td>
                            <td><button class="secondary" data-action="save-quota" data-entity="${entity.id}">Save</button></td>`;
                        tbody.appendChild(tr);
                    });
                    tbody.addEventListener('click', (event) => {
                        const button = event.target.closest('button[data-action="save-quota"]');
                        if (!button) return;
                        const entityId = button.dataset.entity;
                        const quota = tbody.querySelector(`input[data-entity="${entityId}"]`).value;
                        fetch(`${apiBase}/entities.php`, {
                            method: 'PATCH',
                            headers: Object.assign({ 'Content-Type': 'application/json' }, csrfHeaders()),
                            credentials: 'include',
                            body: JSON.stringify({ id: entityId, publishQuotaPer7d: parseInt(quota, 10) }),
                        }).then(() => loadSettings());
                    });
                }
            });
    }

    function bindConsent() {
        const form = document.getElementById('consent-form');
        if (!form) return;
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const data = Object.fromEntries(new FormData(form).entries());
            if (!data.agreed) {
                alert('Consent is required.');
                return;
            }
            fetch(`${apiBase}/consent.php`, {
                method: 'POST',
                headers: Object.assign({ 'Content-Type': 'application/json' }, csrfHeaders()),
                credentials: 'include',
                body: JSON.stringify(data),
            })
                .then((res) => {
                    if (!res.ok) throw new Error('Unable to submit consent');
                    return res.json();
                })
                .then(() => alert('Consent submitted. Thank you!'))
                .catch((err) => alert(err.message));
        });
    }

    function openSocket(token, wsUrl) {
        if (!token || !wsUrl) return;
        closeSocket();
        try {
            wsClient = new WebSocket(`${wsUrl}?token=${encodeURIComponent(token)}`);
        } catch (error) {
            console.warn('WS connection failed', error);
            return;
        }
        wsClient.addEventListener('message', (event) => {
            const message = JSON.parse(event.data);
            if (message.type === 'endeavour.created' || message.type === 'endeavour.updated') {
                loadEndeavours();
            }
            if (message.type === 'registration.status') {
                if (document.body.dataset.page === 'hr') {
                    loadRegistrations();
                }
            }
        });
        wsClient.addEventListener('close', () => {
            wsClient = null;
        });
    }

    function closeSocket() {
        if (wsClient) {
            wsClient.close();
            wsClient = null;
        }
    }

    function csrfHeaders() {
        return csrfToken ? { 'X-CSRF-Token': csrfToken } : {};
    }

    function formatDate(value) {
        if (!value) return '';
        const date = new Date(value);
        return date.toLocaleString();
    }

    function truncate(str, limit) {
        if (!str) return '';
        return str.length > limit ? `${str.slice(0, limit)}…` : str;
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        })[char]);
    }
})();
