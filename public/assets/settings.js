import { renderSidebar } from './sidebar.js';
import { apiFetch, setTheme } from './app.js';

document.addEventListener('DOMContentLoaded', () => {
    // Render sidebar
    const sidebarContainer = document.getElementById('sidebar-container');
    if (sidebarContainer) {
        sidebarContainer.outerHTML = renderSidebar('settings');
    }

    // Theme logic
    const currentTheme = localStorage.getItem('nixor_theme') || 'theme-default';
    const themeBtns = document.querySelectorAll('.theme-btn');

    function updateActiveThemeUI(themeId) {
        themeBtns.forEach(btn => {
            const isMatch = btn.getAttribute('data-theme') === themeId;
            const check = btn.querySelector('.theme-check');

            if (isMatch) {
                btn.classList.add('ring-2', 'ring-[var(--color-primary)]', 'border-transparent');
                // Reset border color style completely since ring overrides it
                btn.style.borderColor = 'transparent';
                btn.setAttribute('aria-pressed', 'true');
                if (check) check.classList.remove('hidden');
            } else {
                btn.classList.remove('ring-2', 'ring-[var(--color-primary)]', 'border-transparent');
                btn.style.borderColor = ''; // Reverts to inline style
                btn.setAttribute('aria-pressed', 'false');
                if (check) check.classList.add('hidden');
            }
        });
    }

    // Initial state load
    updateActiveThemeUI(currentTheme);

    // Click handlers
    themeBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const newTheme = btn.getAttribute('data-theme');

            // Update global CSS and storage
            if (setTheme(newTheme)) {
                // Update local UI
                updateActiveThemeUI(newTheme);
            }
        });
    });

    const notificationForm = document.getElementById('notification-form');
    const notificationStatus = document.getElementById('notification-status');
    const setNotificationStatus = (message, ok) => {
        if (!notificationStatus) return;
        notificationStatus.textContent = message;
        notificationStatus.className = `text-sm rounded-lg px-4 py-3 border ${ok ? 'bg-[rgba(16,185,129,0.1)] text-[#6ee7b7] border-[rgba(16,185,129,0.2)]' : 'bg-[rgba(239,68,68,0.1)] text-[#fca5a5] border-[rgba(239,68,68,0.2)]'}`;
        notificationStatus.classList.remove('hidden');
    };

    const applyNotificationPrefs = (prefs = {}) => {
        if (!notificationForm) return;
        notificationForm.querySelectorAll('input[type="checkbox"]').forEach((input) => {
            input.checked = Boolean(Number(prefs[input.name] ?? 1));
        });
    };

    if (notificationForm) {
        apiFetch('/settings/notifications')
            .then((response) => applyNotificationPrefs(response?.data || {}))
            .catch(() => setNotificationStatus('Unable to load notification preferences.', false));

        notificationForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const payload = {};
            notificationForm.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                payload[input.name] = input.checked;
            });
            const button = notificationForm.querySelector('button[type="submit"]');
            if (button) button.disabled = true;
            try {
                const response = await apiFetch('/settings/notifications', {
                    method: 'PUT',
                    body: JSON.stringify(payload)
                });
                applyNotificationPrefs(response?.data || payload);
                setNotificationStatus('Preferences saved.', true);
            } catch (err) {
                setNotificationStatus(err?.message || 'Unable to save preferences.', false);
            } finally {
                if (button) button.disabled = false;
            }
        });
    }
});
