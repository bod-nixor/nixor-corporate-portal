import { renderSidebar } from './sidebar.js';
import { setTheme } from './app.js';

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
});
