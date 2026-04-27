import { apiFetch, clearMobileAuthToken, isNativeRuntime } from '/assets/app.js';

export function renderSidebar(activeId) {
  const links = [
    { id: 'dashboard', href: '/dashboard.html', text: 'Entity Dashboard' },
    { id: 'entity_endeavours', href: '/entity_endeavours.html', text: 'Entity Endeavours' },
    { id: 'entity_drive', href: '/entity_drive.html', text: 'Entity Drive' },
    { id: 'calendar', href: '/calendar.html', text: 'Calendar' },
    { id: 'social', href: '/social.html', text: 'Social' },
    { id: 'endeavours', href: '/endeavours.html', text: 'Volunteering' },
    { id: 'settings', href: '/settings.html', text: 'Settings' },
    { id: 'admin', href: '/admin.html', text: 'Admin Panel' }
  ];

  const navHtml = links.map(link => {
    const isActive = link.id === activeId;
    const classes = isActive
      ? 'block px-4 py-2.5 rounded-xl bg-[var(--text-primary)] text-[var(--bg-base)] font-semibold cursor-default shadow-sm'
      : 'block px-4 py-2.5 rounded-xl text-[var(--text-secondary)] hover:bg-[rgba(255,255,255,0.05)] hover:text-[var(--text-primary)] transition-colors font-medium';
    const aria = isActive ? ' aria-current="page"' : '';
    return `<a class="${classes}" href="${link.href}"${aria}>${link.text}</a>`;
  }).join('\n        ');

  return `
    <button id="mobile-menu-btn" class="app-mobile-menu-button" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="sidebar">
      <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
      </svg>
    </button>
    
    <div id="sidebar-backdrop" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-30 hidden md:hidden transition-opacity duration-300 opacity-0"></div>

    <aside id="sidebar" class="app-sidebar -translate-x-full md:translate-x-0">
      <div class="flex items-center gap-3 mb-8 px-2">
        <div class="w-8 h-8 rounded-lg bg-[var(--text-primary)] flex items-center justify-center">
          <svg class="w-5 h-5 text-[var(--bg-base)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z" />
          </svg>
        </div>
        <span class="text-sm uppercase tracking-widest text-[var(--text-primary)] font-bold">Nixor Portal</span>
      </div>
      <nav class="app-sidebar-nav space-y-1.5 flex-1">
        ${navHtml}
      </nav>
      <div class="mt-auto pt-6 border-t border-[var(--border-subtle)] px-2">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-full bg-[var(--bg-surface-hover)] border border-[var(--border-strong)] flex items-center justify-center text-sm font-medium text-[var(--text-secondary)]">
            U
          </div>
          <div class="text-sm min-w-0">
            <p class="font-medium text-[var(--text-primary)] truncate" id="sidebar-user-name">User Profile</p>
            <button type="button" id="sidebar-signout" class="text-[11px] font-medium text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors cursor-pointer mt-0.5">Sign out</button>
          </div>
        </div>
      </div>
    </aside>
  `;
}

if (typeof document !== 'undefined') {
  let backdropTimer = 0;
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('#mobile-menu-btn');
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');

    // Handle sign-out via event delegation (works even if sidebar is injected after DOMContentLoaded)
    if (e.target.closest('#sidebar-signout')) {
      e.preventDefault();
      (async () => {
        try {
          if (isNativeRuntime()) {
            await apiFetch('/auth/mobile/logout', { method: 'POST', skipCsrf: true });
            await clearMobileAuthToken();
          } else {
            await apiFetch('/auth/logout', { method: 'POST' });
          }
          window.location.href = '/login.html';
        } catch (err) {
          if (isNativeRuntime()) {
            await clearMobileAuthToken();
            window.location.href = '/login.html';
            return;
          }
          console.error('Logout failed:', err);
        }
      })();
      return;
    }

    if (btn) {
      if (sidebar && backdrop) {
        const isClosed = sidebar.classList.contains('-translate-x-full');

        if (isClosed) {
          sidebar.classList.remove('-translate-x-full');
          backdrop.classList.remove('hidden');
          clearTimeout(backdropTimer);
          // Allow display block to apply
          backdropTimer = setTimeout(() => backdrop.classList.remove('opacity-0'), 10);
          btn.setAttribute('aria-expanded', 'true');
        } else {
          sidebar.classList.add('-translate-x-full');
          backdrop.classList.add('opacity-0');
          clearTimeout(backdropTimer);
          backdropTimer = setTimeout(() => backdrop.classList.add('hidden'), 300);
          btn.setAttribute('aria-expanded', 'false');
        }
      }
    } else if (sidebar && !sidebar.classList.contains('-translate-x-full') && window.innerWidth < 768) {
      // Clicked outside on mobile
      if (!e.target.closest('#sidebar')) {
        sidebar.classList.add('-translate-x-full');
        if (backdrop) {
          backdrop.classList.add('opacity-0');
          clearTimeout(backdropTimer);
          backdropTimer = setTimeout(() => backdrop.classList.add('hidden'), 300);
        }
        const menuBtn = document.getElementById('mobile-menu-btn');
        if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
      }
    }
  });
}
