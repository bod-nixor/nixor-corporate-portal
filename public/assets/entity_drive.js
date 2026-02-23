import { apiFetch, normalizeError } from '/assets/app.js';

const state = {
  entityId: null,
  parentId: null,
  breadcrumbs: [],
  items: [],
  selection: new Set(),
  activeId: null,
  viewMode: 'list',
  sort: 'name_asc',
  query: '',
  contextItemId: null,
  shareItemId: null,
  loading: false
};

const el = {
  entity: document.getElementById('drive-entity'),
  breadcrumbs: document.getElementById('drive-breadcrumbs'),
  list: document.getElementById('drive-list'),
  grid: document.getElementById('drive-grid'),
  empty: document.getElementById('drive-empty'),
  count: document.getElementById('drive-count'),
  status: document.getElementById('drive-status'),
  summary: document.getElementById('drive-summary'),
  search: document.getElementById('drive-search'),
  sort: document.getElementById('drive-sort'),
  viewList: document.getElementById('view-list'),
  viewGrid: document.getElementById('view-grid'),
  skeleton: document.getElementById('drive-skeleton'),
  selectAll: document.getElementById('select-all'),
  bulkToolbar: document.getElementById('bulk-toolbar'),
  bulkCount: document.getElementById('bulk-count'),
  bulkDelete: document.getElementById('bulk-delete'),
  bulkShare: document.getElementById('bulk-share'),
  bulkClear: document.getElementById('bulk-clear'),
  bulkNote: document.getElementById('bulk-note'),
  inspectorPanel: document.getElementById('inspector-panel'),
  inspectorToggle: document.getElementById('inspector-toggle'),
  inspectorClose: document.getElementById('inspector-close'),
  inspectorPreview: document.getElementById('inspector-preview'),
  inspectorName: document.getElementById('inspector-name'),
  inspectorMeta: document.getElementById('inspector-meta'),
  inspectorOpen: document.getElementById('inspector-open'),
  inspectorDownload: document.getElementById('inspector-download'),
  uploadBtn: document.getElementById('upload-file'),
  uploadInput: document.getElementById('upload-input'),
  newBtn: document.getElementById('new-menu-btn'),
  newMenu: document.getElementById('new-menu'),
  newFolder: document.getElementById('new-folder'),
  newLink: document.getElementById('new-link'),
  toast: document.getElementById('toast'),
  contextMenu: document.getElementById('context-menu'),
  shareModal: document.getElementById('share-modal'),
  shareClose: document.getElementById('share-close'),
  shareCancel: document.getElementById('share-cancel'),
  shareSave: document.getElementById('share-save'),
  shareScope: document.getElementById('share-scope'),
  shareDepartments: document.getElementById('share-departments'),
  shareUsers: document.getElementById('share-users')
};

const iconFor = (type) => (type === 'folder' ? '📁' : type === 'link' ? '🔗' : '📄');
const safeDate = (v) => new Date(v || Date.now()).toLocaleString();
const sizeLabel = (item) => (item.item_type === 'file' ? `${((Number(item.size_bytes) || 0) / 1024).toFixed(1)} KB` : '-');

function toast(msg, kind = 'ok') {
  el.toast.textContent = msg;
  el.toast.className = `fixed bottom-4 right-4 px-4 py-3 rounded-xl text-sm z-50 ${kind === 'error' ? 'bg-red-500/20 text-red-200 border border-red-500/40' : 'bg-emerald-500/20 text-emerald-200 border border-emerald-500/40'}`;
  clearTimeout(toast.t);
  toast.t = setTimeout(() => el.toast.classList.add('hidden'), 2200);
}

function setStatus(msg, kind = 'muted') {
  el.status.textContent = msg;
  el.status.className = `text-xs ${kind === 'error' ? 'text-red-300' : kind === 'success' ? 'text-emerald-300' : 'text-slate-400'}`;
}

function renderBreadcrumbs() {
  el.breadcrumbs.innerHTML = '';
  const addCrumb = (label, id) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'text-indigo-300 hover:text-indigo-200 text-sm';
    btn.textContent = label;
    btn.dataset.parentId = id == null ? '' : String(id);
    el.breadcrumbs.appendChild(btn);
  };
  addCrumb('Root', null);
  state.breadcrumbs.forEach((b) => {
    const sep = document.createElement('span');
    sep.className = 'text-slate-600';
    sep.textContent = '/';
    el.breadcrumbs.appendChild(sep);
    addCrumb(b.name, b.id);
  });
}

function viewItems() {
  const q = state.query.trim().toLowerCase();
  let out = state.items.filter((i) => !q || i.name.toLowerCase().includes(q));
  out.sort((a, b) => {
    if (a.item_type !== b.item_type) return a.item_type === 'folder' ? -1 : b.item_type === 'folder' ? 1 : 0;
    if (state.sort === 'name_desc') return b.name.localeCompare(a.name);
    if (state.sort === 'updated_desc') return new Date(b.updated_at || b.created_at) - new Date(a.updated_at || a.created_at);
    if (state.sort === 'updated_asc') return new Date(a.updated_at || a.created_at) - new Date(b.updated_at || b.created_at);
    return a.name.localeCompare(b.name);
  });
  return out;
}

function renderSummary(items) {
  const folders = items.filter((i) => i.item_type === 'folder').length;
  const files = items.filter((i) => i.item_type === 'file').length;
  const links = items.filter((i) => i.item_type === 'link').length;
  el.summary.innerHTML = '';
  [['Folders', folders], ['Files', files], ['Links', links]].forEach(([label, value]) => {
    const c = document.createElement('div');
    c.className = 'bg-slate-900 border border-slate-800 rounded-xl p-3';
    const p = document.createElement('p'); p.className = 'text-xs text-indigo-300 uppercase'; p.textContent = label;
    const h = document.createElement('h3'); h.className = 'text-lg font-semibold'; h.textContent = String(value);
    c.append(p, h);
    el.summary.append(c);
  });
}

function renderBulk() {
  const n = state.selection.size;
  el.bulkToolbar.classList.toggle('hidden', n === 0);
  el.bulkCount.textContent = `${n} selected`;
  const blocked = [...state.selection].some((id) => {
    const item = state.items.find((i) => i.id === id);
    return !item;
  });
  el.bulkDelete.disabled = blocked;
  el.bulkShare.disabled = n !== 1;
  if (n === 0) el.bulkNote.textContent = 'Select at least one item to use bulk actions.';
  else if (n === 1) el.bulkNote.textContent = '';
  else el.bulkNote.textContent = 'Share supports one item at a time.';
}

function rowTemplate(item) {
  const tr = document.createElement('tr');
  tr.className = `border-t border-slate-800 hover:bg-slate-800/40 cursor-pointer ${state.activeId === item.id ? 'bg-slate-800/40' : ''}`;
  tr.dataset.id = String(item.id);

  const tdCheck = document.createElement('td'); tdCheck.className = 'px-3 py-2';
  const chk = document.createElement('input'); chk.type = 'checkbox'; chk.className = 'drive-check'; chk.dataset.id = String(item.id); chk.checked = state.selection.has(item.id);
  tdCheck.append(chk);

  const tdName = document.createElement('td'); tdName.className = 'px-3 py-2'; tdName.textContent = `${iconFor(item.item_type)} ${item.name}`;
  const tdShare = document.createElement('td'); tdShare.className = 'px-3 py-2'; tdShare.textContent = item.sharing_scope;
  const tdSize = document.createElement('td'); tdSize.className = 'px-3 py-2'; tdSize.textContent = sizeLabel(item);
  const tdMod = document.createElement('td'); tdMod.className = 'px-3 py-2'; tdMod.textContent = safeDate(item.updated_at || item.created_at);

  tr.append(tdCheck, tdName, tdShare, tdSize, tdMod);
  return tr;
}

function cardTemplate(item) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = `text-left p-3 rounded-xl border ${state.activeId === item.id ? 'border-indigo-400 bg-slate-800/50' : 'border-slate-700 bg-slate-900 hover:bg-slate-800/40'}`;
  b.dataset.id = String(item.id);

  const i = document.createElement('div'); i.className = 'text-2xl'; i.textContent = iconFor(item.item_type);
  const n = document.createElement('div'); n.className = 'mt-2 text-sm font-medium line-clamp-2'; n.textContent = item.name;
  const m = document.createElement('div'); m.className = 'text-xs text-slate-400 mt-1'; m.textContent = `${item.sharing_scope} • ${sizeLabel(item)}`;
  b.append(i, n, m);
  return b;
}

function renderItems() {
  const items = viewItems();
  el.list.innerHTML = '';
  el.grid.innerHTML = '';
  items.forEach((item) => {
    el.list.appendChild(rowTemplate(item));
    el.grid.appendChild(cardTemplate(item));
  });
  el.count.textContent = `Showing ${items.length} item${items.length === 1 ? '' : 's'}`;
  el.empty.classList.toggle('hidden', items.length > 0 || state.loading);
  renderSummary(items);
  renderBulk();
}

async function openInspector(item) {
  state.activeId = item?.id ?? null;
  const targetId = state.activeId;
  el.inspectorName.textContent = item?.name || 'Select a file';
  el.inspectorMeta.textContent = item ? `${item.item_type} • ${item.sharing_scope}` : '';
  el.inspectorOpen.classList.add('hidden');
  el.inspectorDownload.classList.add('hidden');
  el.inspectorPreview.textContent = item ? 'Loading preview…' : 'Preview';
  renderItems();
  if (!item) return;
  try {
    const res = await apiFetch(`/drive/preview?id=${encodeURIComponent(item.id)}`);
    if (state.activeId !== targetId) {
      return;
    }
    const p = res?.data || {};
    el.inspectorPreview.innerHTML = '';
    if (p.kind === 'pdf' || p.kind === 'pdf_link') {
      const f = document.createElement('iframe');
      f.className = 'w-full h-64 rounded-lg border border-slate-800';
      f.src = p.preview_url;
      if (state.activeId !== targetId) {
        return;
      }
      el.inspectorPreview.appendChild(f);
      if (p.open_url) { el.inspectorOpen.href = p.open_url; el.inspectorOpen.classList.remove('hidden'); }
      if (p.download_url) { el.inspectorDownload.href = p.download_url; el.inspectorDownload.classList.remove('hidden'); }
      return;
    }
    if (p.kind === 'youtube') {
      const f = document.createElement('iframe');
      f.className = 'w-full h-64 rounded-lg border border-slate-800';
      f.src = p.preview_url;
      f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
      f.allowFullscreen = true;
      if (state.activeId !== targetId) {
        return;
      }
      el.inspectorPreview.appendChild(f);
      el.inspectorOpen.href = p.open_url;
      el.inspectorOpen.classList.remove('hidden');
      return;
    }
    if (p.kind === 'link') {
      const a = document.createElement('a');
      a.className = 'text-indigo-300 underline text-sm break-all';
      a.href = p.open_url; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = p.open_url;
      if (state.activeId !== targetId) {
        return;
      }
      el.inspectorPreview.appendChild(a);
      el.inspectorOpen.href = p.open_url;
      el.inspectorOpen.classList.remove('hidden');
      return;
    }
    if (state.activeId !== targetId) {
      return;
    }
    el.inspectorPreview.textContent = 'No inline preview available.';
    if (p.download_url) { el.inspectorDownload.href = p.download_url; el.inspectorDownload.classList.remove('hidden'); }
  } catch (err) {
    if (state.activeId !== targetId) {
      return;
    }
    el.inspectorPreview.textContent = normalizeError(err);
  }
}

async function loadItems() {
  if (!state.entityId) return;
  state.loading = true;
  el.skeleton.classList.remove('hidden');
  setStatus('Loading...', 'muted');
  try {
    const qs = new URLSearchParams({ entity_id: String(state.entityId) });
    if (state.parentId) qs.set('parent_id', String(state.parentId));
    const res = await apiFetch(`/drive/list?${qs.toString()}`);
    state.items = res?.data || [];
    state.breadcrumbs = res?.meta?.breadcrumbs || [];
    state.selection.clear();
    renderBreadcrumbs();
    renderItems();
    await openInspector(null);
    setStatus('Loaded', 'success');
  } catch (err) {
    state.items = [];
    renderItems();
    setStatus(normalizeError(err), 'error');
  } finally {
    state.loading = false;
    el.skeleton.classList.add('hidden');
  }
}

async function createFolder() {
  const name = window.prompt('Folder name');
  if (!name) return;
  await apiFetch('/drive/folder', { method: 'POST', body: JSON.stringify({ entity_id: state.entityId, parent_id: state.parentId, name }) });
  toast('Folder created');
  loadItems();
}

async function createLink() {
  const name = window.prompt('Link name/title');
  if (!name) return;
  const url = window.prompt('URL (http/https)');
  if (!url) return;
  await apiFetch('/drive/link', { method: 'POST', body: JSON.stringify({ entity_id: state.entityId, parent_id: state.parentId, name, url }) });
  toast('Link created');
  loadItems();
}

async function renameItem(item) {
  const name = window.prompt('New name', item.name);
  if (!name || name === item.name) return;
  await apiFetch('/drive/rename', { method: 'POST', body: JSON.stringify({ id: item.id, name }) });
  toast('Renamed');
  loadItems();
}

async function deleteItem(item) {
  if (!window.confirm(`Delete ${item.name}?`)) return;
  await apiFetch('/drive/delete', { method: 'POST', body: JSON.stringify({ id: item.id }) });
  toast('Deleted');
  loadItems();
}

function openShareModal(item) {
  state.shareItemId = item.id;
  el.shareScope.value = item.sharing_scope || 'entity';
  syncShareInputs();
  el.shareDepartments.value = (item.shared_departments || []).join(',');
  el.shareUsers.value = (item.shared_users || []).map((u) => u.email || u.user_id).join(',');
  el.shareModal.classList.remove('hidden');
  el.shareModal.classList.add('flex');
}


function syncShareInputs() {
  const scope = el.shareScope.value;
  const dep = scope !== 'department';
  const usr = scope !== 'users';
  el.shareDepartments.disabled = dep;
  el.shareUsers.disabled = usr;
  el.shareDepartments.classList.toggle('opacity-50', dep);
  el.shareUsers.classList.toggle('opacity-50', usr);
}

function closeShareModal() {
  state.shareItemId = null;
  el.shareModal.classList.add('hidden');
  el.shareModal.classList.remove('flex');
}

async function saveShare() {
  const payload = { id: state.shareItemId, sharing_scope: el.shareScope.value };
  if (payload.sharing_scope === 'department') payload.departments = el.shareDepartments.value.split(',').map((d) => d.trim()).filter(Boolean);
  if (payload.sharing_scope === 'users') payload.users = el.shareUsers.value.split(',').map((u) => u.trim()).filter(Boolean);
  await apiFetch('/drive/share', { method: 'POST', body: JSON.stringify(payload) });
  toast('Sharing updated');
  closeShareModal();
  loadItems();
}

function openContextMenu(x, y, itemId) {
  state.contextItemId = itemId;
  el.contextMenu.style.left = `${x}px`;
  el.contextMenu.style.top = `${y}px`;
  el.contextMenu.classList.remove('hidden');
}

function closeContextMenu() {
  state.contextItemId = null;
  el.contextMenu.classList.add('hidden');
}

async function onContextAction(action) {
  const item = state.items.find((i) => i.id === state.contextItemId);
  closeContextMenu();
  if (!item) return;
  try {
    if (action === 'rename') await renameItem(item);
    if (action === 'delete') await deleteItem(item);
    if (action === 'share') openShareModal(item);
  } catch (e) {
    toast(normalizeError(e), 'error');
  }
}

function bind() {
  el.newBtn.addEventListener('click', () => el.newMenu.classList.toggle('hidden'));
  document.addEventListener('click', (e) => {
    if (!el.newMenu.contains(e.target) && e.target !== el.newBtn) el.newMenu.classList.add('hidden');
    if (!el.contextMenu.contains(e.target)) closeContextMenu();
  });

  el.newFolder.addEventListener('click', () => createFolder().catch((e) => toast(normalizeError(e), 'error')));
  el.newLink.addEventListener('click', () => createLink().catch((e) => toast(normalizeError(e), 'error')));

  el.uploadBtn.addEventListener('click', () => el.uploadInput.click());
  el.uploadInput.addEventListener('change', async () => {
    const file = el.uploadInput.files?.[0];
    if (!file) return;
    const data = new FormData();
    data.append('entity_id', String(state.entityId));
    data.append('parent_id', state.parentId ? String(state.parentId) : '');
    data.append('file', file);
    try {
      await apiFetch('/drive/upload', { method: 'POST', body: data });
      toast('Upload complete');
      loadItems();
    } catch (e) { toast(normalizeError(e), 'error'); }
    finally { el.uploadInput.value = ''; }
  });

  el.entity.addEventListener('change', () => { state.entityId = Number(el.entity.value); state.parentId = null; loadItems(); });
  el.search.addEventListener('input', () => { state.query = el.search.value; renderItems(); setStatus(state.query ? `Filtered by "${state.query}"` : 'Loaded', state.query ? 'muted' : 'success'); });
  el.sort.addEventListener('change', () => { state.sort = el.sort.value; renderItems(); });
  el.viewList.addEventListener('click', () => { state.viewMode = 'list'; el.viewList.classList.add('bg-slate-800'); el.viewGrid.classList.remove('bg-slate-800'); el.grid.classList.add('hidden'); document.getElementById('drive-list-wrapper').classList.remove('hidden'); });
  el.viewGrid.addEventListener('click', () => { state.viewMode = 'grid'; el.viewGrid.classList.add('bg-slate-800'); el.viewList.classList.remove('bg-slate-800'); el.grid.classList.remove('hidden'); document.getElementById('drive-list-wrapper').classList.add('hidden'); });

  el.breadcrumbs.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-parent-id]');
    if (!btn) return;
    state.parentId = btn.dataset.parentId ? Number(btn.dataset.parentId) : null;
    loadItems();
  });

  el.list.addEventListener('click', (e) => {
    const chk = e.target.closest('.drive-check');
    if (chk) {
      const id = Number(chk.dataset.id);
      chk.checked ? state.selection.add(id) : state.selection.delete(id);
      renderBulk();
      return;
    }
    const row = e.target.closest('tr[data-id]');
    if (!row) return;
    const id = Number(row.dataset.id);
    const item = state.items.find((i) => i.id === id);
    if (!item) return;
    openInspector(item);
  });

  el.list.addEventListener('dblclick', (e) => {
    const row = e.target.closest('tr[data-id]');
    if (!row) return;
    const id = Number(row.dataset.id);
    const item = state.items.find((i) => i.id === id);
    if (item?.item_type === 'folder') { state.parentId = item.id; loadItems(); }
  });

  el.grid.addEventListener('click', (e) => {
    const card = e.target.closest('[data-id]');
    if (!card) return;
    const item = state.items.find((i) => i.id === Number(card.dataset.id));
    if (!item) return;
    openInspector(item);
  });
  el.grid.addEventListener('dblclick', (e) => {
    const card = e.target.closest('[data-id]');
    if (!card) return;
    const item = state.items.find((i) => i.id === Number(card.dataset.id));
    if (item?.item_type === 'folder') { state.parentId = item.id; loadItems(); }
  });

  [el.list, el.grid].forEach((node) => {
    node.addEventListener('contextmenu', (e) => {
    const row = e.target.closest('[data-id]');
    if (!row) return;
    e.preventDefault();
    openContextMenu(e.clientX, e.clientY, Number(row.dataset.id));
    });
  });

  el.contextMenu.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    onContextAction(btn.dataset.action);
  });

  el.selectAll.addEventListener('change', () => {
    if (el.selectAll.checked) viewItems().forEach((i) => { state.selection.add(i.id); });
    else state.selection.clear();
    renderItems();
  });
  el.bulkClear.addEventListener('click', () => { state.selection.clear(); renderItems(); });
  el.bulkDelete.addEventListener('click', async () => {
    const selected = [...state.selection].map((id) => state.items.find((i) => i.id === id)).filter(Boolean);
    for (const item of selected) await deleteItem(item);
  });
  el.bulkShare.addEventListener('click', () => {
    const id = [...state.selection][0];
    const item = state.items.find((i) => i.id === id);
    if (!item) return;
    openShareModal(item);
  });

  el.shareScope.addEventListener('change', syncShareInputs);
  el.shareClose.addEventListener('click', closeShareModal);
  el.shareCancel.addEventListener('click', closeShareModal);
  el.shareSave.addEventListener('click', () => saveShare().catch((e) => toast(normalizeError(e), 'error')));

  el.inspectorToggle.addEventListener('click', () => el.inspectorPanel.classList.toggle('hidden'));
  el.inspectorClose?.addEventListener('click', () => el.inspectorPanel.classList.add('hidden'));


  [el.inspectorOpen, el.inspectorDownload].forEach((anchor) => {
    anchor.addEventListener('click', (event) => {
      if (anchor.getAttribute('href') === '#') {
        event.preventDefault();
      }
    });
  });

  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') { e.preventDefault(); el.search.focus(); }
    if (e.key === 'Escape') { closeContextMenu(); closeShareModal(); el.newMenu.classList.add('hidden'); }
  });
}

async function boot() {
  bind();
  const me = await apiFetch('/auth/me');
  const entities = me?.data?.entities || [];
  if (!entities.length) {
    setStatus('No entities available', 'error');
    return;
  }
  el.entity.innerHTML = '';
  entities.forEach((en) => {
    const o = document.createElement('option');
    o.value = String(en.id); o.textContent = en.name; el.entity.append(o);
  });
  state.entityId = Number(entities[0].id);
  el.entity.value = String(state.entityId);
  await loadItems();
}

boot().catch(() => (window.location.href = '/login.html'));
