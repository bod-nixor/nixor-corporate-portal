export function renderSidebar(activeId) {
  const links = [
    { id: 'dashboard', href: '/dashboard.html', text: 'Entity Dashboard' },
    { id: 'entity_endeavours', href: '/entity_endeavours.html', text: 'Entity Endeavours' },
    { id: 'entity_drive', href: '/entity_drive.html', text: 'Entity Drive' },
    { id: 'calendar', href: '/calendar.html', text: 'Calendar' },
    { id: 'social', href: '/social.html', text: 'Social' },
    { id: 'endeavours', href: '/endeavours.html', text: 'Volunteering' },
    { id: 'admin', href: '/admin.html', text: 'Settings' }
  ];

  const navHtml = links.map(link => {
    const isActive = link.id === activeId;
    const classes = isActive
      ? 'block px-4 py-2.5 rounded-xl bg-indigo-500/10 text-indigo-400 cursor-default'
      : 'block px-4 py-2.5 rounded-xl text-slate-400 hover:bg-slate-800 hover:text-slate-200 transition-colors';
    const aria = isActive ? ' aria-current="page"' : '';
    return `<a class="${classes}" href="${link.href}"${aria}>${link.text}</a>`;
  }).join('\n        ');

  return `
    <button id="mobile-menu-btn" class="block md:hidden absolute top-6 right-6 z-50 p-2 bg-slate-800 text-slate-200 rounded-md" aria-expanded="false" aria-controls="sidebar">
      <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
      </svg>
    </button>
    <aside id="sidebar" class="w-64 min-h-screen bg-slate-900 border-r border-slate-800 p-6 hidden md:block shrink-0 absolute md:static z-40">
      <div class="text-xs uppercase tracking-[0.3em] text-indigo-400 font-semibold mb-8">Nixor Portal</div>
      <nav class="space-y-1 text-sm font-medium">
        ${navHtml}
      </nav>
    </aside>
  `;
}

if (typeof document !== 'undefined') {
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('#mobile-menu-btn');
    const sidebar = document.getElementById('sidebar');

    if (btn) {
      if (sidebar) {
        sidebar.classList.toggle('hidden');
        const isHidden = sidebar.classList.contains('hidden');
        btn.setAttribute('aria-expanded', !isHidden);
      }
    } else if (sidebar && !sidebar.classList.contains('hidden')) {
      if (!e.target.closest('#sidebar')) {
        sidebar.classList.add('hidden');
        const menuBtn = document.getElementById('mobile-menu-btn');
        if (menuBtn) {
          menuBtn.setAttribute('aria-expanded', 'false');
        }
      }
    }
  });
}
