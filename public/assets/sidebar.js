import { apiFetch, clearMobileAuthToken, isNativeRuntime, loginUrlForCurrentPage } from '/assets/app.js';

const fallbackLinks = [
  { id: 'settings', href: '/settings.html', text: 'Settings', permission: 'nav.settings' }
];

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function initialsFor(name, email) {
  const source = String(name || email || 'User').trim();
  const parts = source.split(/\s+/).filter(Boolean).slice(0, 2);
  return (parts.map(part => part[0]).join('') || 'U').toUpperCase();
}

function renderNavLinks(links, activeId) {
  const safeLinks = Array.isArray(links) ? links : fallbackLinks;
  return safeLinks.map(link => {
    const isActive = link.id === activeId;
    const classes = isActive
      ? 'block px-4 py-2.5 rounded-xl bg-[var(--text-primary)] text-[var(--bg-base)] font-semibold cursor-default shadow-sm'
      : 'block px-4 py-2.5 rounded-xl text-[var(--text-secondary)] hover:bg-[rgba(255,255,255,0.05)] hover:text-[var(--text-primary)] transition-colors font-medium';
    const aria = isActive ? ' aria-current="page"' : '';
    return `<a class="${classes}" href="${escapeHtml(link.href)}"${aria} data-link-id="${escapeHtml(link.id)}">${escapeHtml(link.text)}</a>`;
  }).join('\n        ');
}

function renderAvatar(user) {
  const name = user?.full_name || user?.name || '';
  const email = user?.email || '';
  const picture = user?.google_picture_url || user?.picture || '';
  if (picture) {
    return `<img src="${escapeHtml(picture)}" alt="" referrerpolicy="no-referrer" class="w-8 h-8 rounded-full object-cover border border-[var(--border-strong)]" />`;
  }
  return `<div class="w-8 h-8 rounded-full bg-[var(--bg-surface-hover)] border border-[var(--border-strong)] flex items-center justify-center text-xs font-bold text-[var(--text-secondary)]">${escapeHtml(initialsFor(name, email))}</div>`;
}

async function hydrateSidebar(activeId) {
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) {
    return;
  }
  try {
    const response = await apiFetch('/auth/me', { skipFallback: true });
    const user = response?.data?.user;
    if (!user) {
      window.location.href = loginUrlForCurrentPage();
      return;
    }
    const nav = sidebar.querySelector('[data-sidebar-nav]');
    if (nav) {
      nav.innerHTML = renderNavLinks(response?.data?.navigation || [], activeId);
    }
    const avatar = sidebar.querySelector('[data-sidebar-avatar]');
    if (avatar) {
      avatar.innerHTML = renderAvatar(user);
    }
    const name = sidebar.querySelector('#sidebar-user-name');
    if (name) {
      name.textContent = user.full_name || user.email || 'User Profile';
    }
    await fetchNotifications();
  } catch (err) {
    if (err?.status === 401) {
      window.location.href = loginUrlForCurrentPage();
    }
  }
}

async function fetchNotifications() {
  try {
    const response = await apiFetch('/notifications?limit=10', { skipFallback: true });
    if (response?.ok && response.data) {
      const badge = document.getElementById('global-notification-badge');
      const list = document.getElementById('global-notification-list');
      if (badge && list) {
        const unreadCount = parseInt(response.meta?.unread || '0', 10);
        if (unreadCount > 0) {
          badge.classList.remove('hidden');
        } else {
          badge.classList.add('hidden');
        }
        
        if (response.data.length === 0) {
          list.innerHTML = '<li class="p-4 text-center text-sm text-[var(--text-tertiary)]">No notifications</li>';
        } else {
          list.innerHTML = response.data.map(n => `
            <li class="p-3 border-b border-[var(--border-subtle)] hover:bg-[var(--bg-surface-hover)] transition-colors ${n.is_read ? 'opacity-60' : ''}">
              <div class="flex items-start justify-between gap-2">
                <div>
                  <p class="text-sm text-[var(--text-primary)] leading-tight">${escapeHtml(n.message || 'Notification')}</p>
                  <p class="text-[10px] text-[var(--text-tertiary)] mt-1.5 font-medium tracking-wide">${new Date(n.created_at).toLocaleString()}</p>
                </div>
                ${!n.is_read ? `<button data-read-id="${n.id}" class="shrink-0 text-[10px] font-bold text-[var(--text-primary)] bg-[rgba(255,255,255,0.1)] px-2 py-0.5 rounded hover:bg-[rgba(255,255,255,0.2)]">Mark</button>` : ''}
              </div>
            </li>
          `).join('');
        }
      }
    }
  } catch (e) {
    console.error('Failed to fetch notifications', e);
  }
}

export function renderSidebar(activeId) {
  queueMicrotask(() => hydrateSidebar(activeId));
  return `
    <div id="global-header" class="fixed top-4 right-4 md:top-6 md:right-8 z-30 flex items-center gap-4">
      <div class="relative">
        <button id="global-notification-bell" class="relative p-2 text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--bg-surface-hover)] transition-colors rounded-full shadow-sm bg-[var(--bg-surface)] border border-[var(--border-strong)]">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
          </svg>
          <span id="global-notification-badge" class="hidden absolute top-1 right-1 w-2.5 h-2.5 bg-red-500 rounded-full border border-[var(--bg-base)]"></span>
        </button>
        <div id="global-notification-dropdown" class="hidden absolute right-0 mt-2 w-72 md:w-80 max-h-96 overflow-y-auto bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-xl shadow-2xl z-50 flex-col font-sans">
          <div class="p-3 border-b border-[var(--border-subtle)] flex justify-between items-center bg-[var(--bg-surface-hover)] rounded-t-xl sticky top-0 backdrop-blur-sm z-10">
             <h3 class="text-xs font-bold text-[var(--text-primary)] tracking-wider uppercase">Notifications</h3>
             <button id="global-mark-all-read" class="text-[11px] text-[var(--text-tertiary)] hover:text-[var(--text-primary)] font-medium transition-colors">Mark read</button>
          </div>
          <ul id="global-notification-list" class="flex-1 p-0 m-0 list-none">
             <li class="p-4 text-center text-sm text-[var(--text-tertiary)]">Loading...</li>
          </ul>
        </div>
      </div>
    </div>
    
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
      <nav class="app-sidebar-nav space-y-1.5 flex-1" data-sidebar-nav>
        <div class="space-y-2" aria-hidden="true">
          <div class="h-9 rounded-xl bg-[var(--bg-surface-hover)] animate-pulse"></div>
          <div class="h-9 rounded-xl bg-[var(--bg-surface-hover)] animate-pulse"></div>
          <div class="h-9 rounded-xl bg-[var(--bg-surface-hover)] animate-pulse"></div>
        </div>
      </nav>
      <div class="mt-auto pt-6 border-t border-[var(--border-subtle)] px-2">
        <div class="flex items-center gap-3">
          <div data-sidebar-avatar>
            ${renderAvatar(null)}
          </div>
          <div class="text-sm min-w-0">
            <p class="font-medium text-[var(--text-primary)] truncate" id="sidebar-user-name">User Profile</p>
            <button type="button" id="sidebar-signout" class="text-[11px] font-medium text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors cursor-pointer mt-0.5">Sign out</button>
          </div>
        </div>
        <div class="mt-4 flex flex-wrap gap-x-3 gap-y-1 text-[11px] font-medium text-[var(--text-tertiary)]">
          <a href="/privacy.html?return_to=${encodeURIComponent(window.location.pathname + window.location.search)}" class="hover:text-[var(--text-secondary)] transition-colors">Privacy</a>
          <a href="/terms.html?return_to=${encodeURIComponent(window.location.pathname + window.location.search)}" class="hover:text-[var(--text-secondary)] transition-colors">Terms</a>
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

    if (e.target.closest('#sidebar-signout')) {
      e.preventDefault();
      (async () => {
        try {
          if (isNativeRuntime()) {
            await apiFetch('/auth/mobile/logout', { method: 'POST', skipCsrf: true });
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

    const bellBtn = e.target.closest('#global-notification-bell');
    const dropdown = document.getElementById('global-notification-dropdown');
    
    if (bellBtn) {
      dropdown?.classList.toggle('hidden');
      if (!dropdown?.classList.contains('hidden')) {
        fetchNotifications();
      }
    } else if (dropdown && !dropdown.classList.contains('hidden') && !e.target.closest('#global-notification-dropdown')) {
      dropdown.classList.add('hidden');
    }

    const markAllBtn = e.target.closest('#global-mark-all-read');
    if (markAllBtn) {
      apiFetch('/notifications/read-all', { method: 'POST', skipFallback: true }).then(() => {
        fetchNotifications();
      }).catch(console.error);
    }

    const markBtn = e.target.closest('[data-read-id]');
    if (markBtn) {
      const id = markBtn.getAttribute('data-read-id');
      apiFetch(`/notifications/${id}/read`, { method: 'POST', skipFallback: true }).then(() => {
        fetchNotifications();
      }).catch(console.error);
    }
  });
}
