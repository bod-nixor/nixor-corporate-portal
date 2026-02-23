import { apiFetch, normalizeError } from '/assets/app.js';

const state = {
  entities: [],
  entityId: null,
  parentId: null,
  breadcrumbs: [],
  items: [],
  selectedItem: null,
  shareTargets: { departments: [], users: [] }
};

const el = {
  entity: document.getElementById('drive-entity'),
  breadcrumbs: document.getElementById('drive-breadcrumbs'),
  list: document.getElementById('drive-list'),
  count: document.getElementById('drive-count'),
  status: document.getElementById('drive-status'),
  summary: document.getElementById('drive-summary'),
  uploadInput: document.getElementById('upload-input'),
  inspectorName: document.getElementById('inspector-name'),
  inspectorMeta: document.getElementById('inspector-meta'),
  inspectorPreview: document.getElementById('inspector-preview'),
  inspectorOpen: document.getElementById('inspector-open'),
  inspectorDownload: document.getElementById('inspector-download')
};

function setStatus(message, tone = 'muted') {
  el.status.textContent = message;
  el.status.className = 'text-xs';
  if (tone === 'error') el.status.classList.add('text-red-300');
  if (tone === 'muted') el.status.classList.add('text-slate-500');
  if (tone === 'success') el.status.classList.add('text-emerald-300');
}

function renderBreadcrumbs() {
  el.breadcrumbs.innerHTML = '';
  const root = document.createElement('button');
  root.className = 'text-indigo-300 hover:text-indigo-200';
  root.textContent = 'Root';
  root.addEventListener('click', () => {
    state.parentId = null;
    loadItems();
  });
  el.breadcrumbs.append(root);

  state.breadcrumbs.forEach((crumb) => {
    const sep = document.createElement('span');
    sep.textContent = '/';
    sep.className = 'mx-2 text-slate-600';
    const btn = document.createElement('button');
    btn.textContent = crumb.name;
    btn.className = 'text-indigo-300 hover:text-indigo-200';
    btn.addEventListener('click', () => {
      state.parentId = crumb.id;
      loadItems();
    });
    el.breadcrumbs.append(sep, btn);
  });
}

function renderSummary() {
  const folders = state.items.filter((i) => i.item_type === 'folder').length;
  const files = state.items.filter((i) => i.item_type === 'file').length;
  const links = state.items.filter((i) => i.item_type === 'link').length;
  el.summary.innerHTML = '';
  [
    { label: 'Folders', value: folders },
    { label: 'Files', value: files },
    { label: 'Links', value: links }
  ].forEach((entry) => {
    const card = document.createElement('div');
    card.className = 'bg-slate-900 border border-slate-800 rounded-2xl p-4';
    card.innerHTML = `<p class="text-xs uppercase text-indigo-300"></p><h3 class="text-lg font-semibold mt-2"></h3>`;
    card.querySelector('p').textContent = entry.label;
    card.querySelector('h3').textContent = String(entry.value);
    el.summary.append(card);
  });
}

function itemSizeLabel(item) {
  if (item.item_type !== 'file') return '-';
  return `${((Number(item.size_bytes) || 0) / 1024).toFixed(1)} KB`;
}

function applySelection(item) {
  state.selectedItem = item;
  el.inspectorName.textContent = item?.name || 'Select a file';
  el.inspectorMeta.textContent = item ? `${item.item_type} • ${item.sharing_scope}` : '';
  el.inspectorOpen.classList.add('hidden');
  el.inspectorDownload.classList.add('hidden');
  el.inspectorPreview.innerHTML = '<div class="text-xs text-slate-500">Loading preview...</div>';

  if (!item) {
    el.inspectorPreview.innerHTML = '<div class="text-xs text-slate-500">Preview</div>';
    return;
  }

  apiFetch(`/drive/preview?id=${encodeURIComponent(item.id)}`).then((res) => {
    const preview = res?.data || {};
    el.inspectorPreview.innerHTML = '';
    if (preview.kind === 'pdf') {
      const frame = document.createElement('iframe');
      frame.className = 'w-full h-64 rounded-xl border border-slate-800';
      frame.src = preview.preview_url;
      frame.title = 'PDF preview';
      el.inspectorPreview.append(frame);
      el.inspectorDownload.classList.remove('hidden');
      el.inspectorDownload.href = preview.download_url;
      return;
    }
    if (preview.kind === 'youtube') {
      const frame = document.createElement('iframe');
      frame.className = 'w-full h-64 rounded-xl border border-slate-800';
      frame.src = preview.preview_url;
      frame.referrerPolicy = 'strict-origin-when-cross-origin';
      frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
      frame.allowFullscreen = true;
      el.inspectorPreview.append(frame);
      el.inspectorOpen.classList.remove('hidden');
      el.inspectorOpen.href = preview.open_url;
      return;
    }
    if (preview.kind === 'pdf_link') {
      const frame = document.createElement('iframe');
      frame.className = 'w-full h-64 rounded-xl border border-slate-800';
      frame.src = preview.preview_url;
      el.inspectorPreview.append(frame);
      el.inspectorOpen.classList.remove('hidden');
      el.inspectorOpen.href = preview.open_url;
      return;
    }
    if (preview.kind === 'link') {
      const anchor = document.createElement('a');
      anchor.className = 'text-sm text-indigo-300 underline';
      anchor.href = preview.open_url;
      anchor.target = '_blank';
      anchor.rel = 'noopener noreferrer';
      anchor.textContent = preview.open_url;
      el.inspectorPreview.append(anchor);
      el.inspectorOpen.classList.remove('hidden');
      el.inspectorOpen.href = preview.open_url;
      return;
    }
    el.inspectorPreview.innerHTML = '<div class="text-xs text-slate-500">No inline preview available.</div>';
    if (preview.download_url) {
      el.inspectorDownload.classList.remove('hidden');
      el.inspectorDownload.href = preview.download_url;
    }
  }).catch((err) => {
    el.inspectorPreview.innerHTML = `<div class="text-xs text-red-300">${normalizeError(err)}</div>`;
  });
}

function actionButton(label, onClick, danger = false) {
  const btn = document.createElement('button');
  btn.className = `px-2 py-1 rounded border text-xs ${danger ? 'border-red-500/40 text-red-300' : 'border-slate-700 text-slate-300'}`;
  btn.textContent = label;
  btn.addEventListener('click', (event) => {
    event.stopPropagation();
    onClick();
  });
  return btn;
}

function renderList() {
  el.list.innerHTML = '';
  state.items.forEach((item) => {
    const tr = document.createElement('tr');
    tr.className = 'border-t border-slate-800 hover:bg-slate-800/40 cursor-pointer';
    tr.addEventListener('click', () => {
      if (item.item_type === 'folder') {
        state.parentId = item.id;
        loadItems();
      }
      applySelection(item);
    });

    const name = document.createElement('td');
    name.className = 'py-3';
    name.textContent = `${item.item_type === 'folder' ? '📁' : item.item_type === 'link' ? '🔗' : '📄'} ${item.name}`;

    const sharing = document.createElement('td');
    sharing.textContent = item.sharing_scope;

    const size = document.createElement('td');
    size.textContent = itemSizeLabel(item);

    const modified = document.createElement('td');
    modified.textContent = new Date(item.updated_at || item.created_at).toLocaleString();

    const actions = document.createElement('td');
    actions.className = 'space-x-1';
    actions.append(
      actionButton('Rename', () => renameItem(item)),
      actionButton('Share', () => shareItem(item)),
      actionButton('Delete', () => deleteItem(item), true)
    );

    tr.append(name, sharing, size, modified, actions);
    el.list.append(tr);
  });

  el.count.textContent = `Showing ${state.items.length} items`;
  renderSummary();
}

async function loadItems() {
  if (!state.entityId) return;
  setStatus('Loading...', 'muted');
  const qs = new URLSearchParams({ entity_id: String(state.entityId) });
  if (state.parentId) qs.set('parent_id', String(state.parentId));
  try {
    const res = await apiFetch(`/drive/list?${qs.toString()}`);
    state.items = res?.data || [];
    state.breadcrumbs = res?.meta?.breadcrumbs || [];
    renderBreadcrumbs();
    renderList();
    setStatus('Loaded', 'success');
  } catch (err) {
    state.items = [];
    renderList();
    setStatus(normalizeError(err), 'error');
  }
}

async function createFolder() {
  const name = window.prompt('Folder name');
  if (!name) return;
  await apiFetch('/drive/folder', {
    method: 'POST',
    body: JSON.stringify({ entity_id: state.entityId, parent_id: state.parentId, name })
  });
  await loadItems();
}

async function createLink() {
  const name = window.prompt('Link name/title');
  if (!name) return;
  const url = window.prompt('URL (http/https)');
  if (!url) return;
  await apiFetch('/drive/link', {
    method: 'POST',
    body: JSON.stringify({ entity_id: state.entityId, parent_id: state.parentId, name, url })
  });
  await loadItems();
}

async function renameItem(item) {
  const name = window.prompt('New name', item.name);
  if (!name || name === item.name) return;
  await apiFetch('/drive/rename', { method: 'POST', body: JSON.stringify({ id: item.id, name }) });
  await loadItems();
}

async function deleteItem(item) {
  if (!window.confirm(`Delete ${item.name}?`)) return;
  await apiFetch('/drive/delete', { method: 'POST', body: JSON.stringify({ id: item.id }) });
  await loadItems();
}

async function shareItem(item) {
  const scope = window.prompt('Sharing scope: private | entity | department | users', item.sharing_scope || 'entity');
  if (!scope) return;
  const payload = { id: item.id, sharing_scope: scope };
  if (scope === 'department') {
    const departments = window.prompt('Departments (comma separated)', (item.shared_departments || []).join(','));
    payload.departments = (departments || '').split(',').map((d) => d.trim()).filter(Boolean);
  }
  if (scope === 'users') {
    const users = window.prompt('User emails or ids (comma separated)', (item.shared_users || []).map((u) => u.email || u.user_id).join(','));
    payload.users = (users || '').split(',').map((u) => u.trim()).filter(Boolean);
  }
  await apiFetch('/drive/share', { method: 'POST', body: JSON.stringify(payload) });
  await loadItems();
}

async function uploadFile() {
  el.uploadInput.click();
}

async function boot() {
  document.getElementById('new-folder').addEventListener('click', () => createFolder().catch((e) => setStatus(normalizeError(e), 'error')));
  document.getElementById('new-link').addEventListener('click', () => createLink().catch((e) => setStatus(normalizeError(e), 'error')));
  document.getElementById('upload-file').addEventListener('click', uploadFile);

  el.uploadInput.addEventListener('change', async () => {
    const file = el.uploadInput.files?.[0];
    if (!file) return;
    const data = new FormData();
    data.append('entity_id', String(state.entityId));
    data.append('parent_id', state.parentId ? String(state.parentId) : '');
    data.append('file', file);
    try {
      await apiFetch('/drive/upload', { method: 'POST', body: data });
      setStatus('Upload complete.', 'success');
      await loadItems();
    } catch (err) {
      setStatus(normalizeError(err), 'error');
    } finally {
      el.uploadInput.value = '';
    }
  });

  const me = await apiFetch('/auth/me');
  state.entities = me?.data?.entities || [];
  if (!state.entities.length) {
    setStatus('No entities found.', 'error');
    return;
  }

  el.entity.innerHTML = '';
  state.entities.forEach((entity) => {
    const option = document.createElement('option');
    option.value = entity.id;
    option.textContent = entity.name;
    el.entity.append(option);
  });
  state.entityId = Number(state.entities[0].id);
  el.entity.value = String(state.entityId);

  el.entity.addEventListener('change', () => {
    state.entityId = Number(el.entity.value);
    state.parentId = null;
    loadItems();
  });

  loadItems();
}

boot().catch(() => {
  window.location.href = '/login.html';
});
