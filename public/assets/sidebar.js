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
    return `<a class="${classes}" href="${link.href}">${link.text}</a>`;
  }).join('\n        ');

  return `
    <aside class="w-64 min-h-screen bg-slate-900 border-r border-slate-800 p-6 hidden md:block shrink-0">
      <div class="text-xs uppercase tracking-[0.3em] text-indigo-400 font-semibold mb-8">Nixor Portal</div>
      <nav class="space-y-1 text-sm font-medium">
        ${navHtml}
      </nav>
    </aside>
  `;
}
