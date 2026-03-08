import { apiFetch, normalizeError } from '/assets/app.js';
import { renderSidebar } from '/assets/sidebar.js';

document.getElementById('sidebar-container').outerHTML = renderSidebar('entity_drive');

const DESKTOP_INSPECTOR_BREAKPOINT = 1280;

const state = {
  entityId: null,
  parentId: null,
  breadcrumbs: [],
  items: [],
  selection: new Set(),
  activeId: null,
  activeItem: null,
  activePreview: null,
  viewMode: 'list',
  sort: 'name_asc',
  query: '',
  loadToken: null,
  loading: false,
  actionMenuItemId: null,
  shareTargets: { departments: [], users: [] },
  shareTargetsEntityId: null,
  shareDraft: null,
  itemModalMode: null,
  itemModalItemId: null,
  deleteTargetIds: [],
  mobileInspectorOpen: false
};

const el = {
  entity: document.getElementById('drive-entity'),
  up: document.getElementById('drive-up'),
  breadcrumbs: document.getElementById('drive-breadcrumbs'),
  locationLabel: document.getElementById('drive-location-label'),
  locationName: document.getElementById('drive-location-name'),
  locationNote: document.getElementById('drive-location-note'),
  listWrapper: document.getElementById('drive-list-wrapper'),
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
  inspectorBackdrop: document.getElementById('inspector-backdrop'),
  inspectorToggle: document.getElementById('inspector-toggle'),
  inspectorClose: document.getElementById('inspector-close'),
  inspectorPreview: document.getElementById('inspector-preview'),
  inspectorName: document.getElementById('inspector-name'),
  inspectorMeta: document.getElementById('inspector-meta'),
  inspectorLocation: document.getElementById('inspector-location'),
  inspectorSharing: document.getElementById('inspector-sharing'),
  inspectorDetails: document.getElementById('inspector-details'),
  inspectorAccessList: document.getElementById('inspector-access-list'),
  inspectorOpenFolder: document.getElementById('inspector-open-folder'),
  inspectorOpen: document.getElementById('inspector-open'),
  inspectorDownload: document.getElementById('inspector-download'),
  inspectorRename: document.getElementById('inspector-rename'),
  inspectorShare: document.getElementById('inspector-share'),
  inspectorDelete: document.getElementById('inspector-delete'),
  uploadBtn: document.getElementById('upload-file'),
  uploadInput: document.getElementById('upload-input'),
  newBtn: document.getElementById('new-menu-btn'),
  newMenu: document.getElementById('new-menu'),
  newFolder: document.getElementById('new-folder'),
  newLink: document.getElementById('new-link'),
  toast: document.getElementById('toast'),
  actionMenu: document.getElementById('action-menu'),
  itemModal: document.getElementById('item-modal'),
  itemModalTitle: document.getElementById('item-modal-title'),
  itemModalClose: document.getElementById('item-modal-close'),
  itemForm: document.getElementById('item-form'),
  itemName: document.getElementById('item-name'),
  itemUrlGroup: document.getElementById('item-url-group'),
  itemUrl: document.getElementById('item-url'),
  itemCancel: document.getElementById('item-cancel'),
  itemSubmit: document.getElementById('item-submit'),
  deleteModal: document.getElementById('delete-modal'),
  deleteMessage: document.getElementById('delete-message'),
  deleteClose: document.getElementById('delete-close'),
  deleteCancel: document.getElementById('delete-cancel'),
  deleteConfirm: document.getElementById('delete-confirm'),
  shareModal: document.getElementById('share-modal'),
  shareItemName: document.getElementById('share-item-name'),
  shareClose: document.getElementById('share-close'),
  shareCancel: document.getElementById('share-cancel'),
  shareSave: document.getElementById('share-save'),
  shareScope: document.getElementById('share-scope'),
  shareScopeHelp: document.getElementById('share-scope-help'),
  shareDepartmentsSection: document.getElementById('share-departments-section'),
  shareDepartmentsList: document.getElementById('share-departments-list'),
  shareUsersSection: document.getElementById('share-users-section'),
  shareUserSearch: document.getElementById('share-user-search'),
  shareUsersList: document.getElementById('share-users-list'),
  shareExtraUsers: document.getElementById('share-extra-users')
};

const itemTypeLabel = (itemType) => {
  if (itemType === 'folder') return 'Folder';
  if (itemType === 'link') return 'Link';
  return 'File';
};

const scopeLabel = (scope) => {
  if (scope === 'private') return 'Private';
  if (scope === 'department') return 'Departments';
  if (scope === 'users') return 'Specific users';
  return 'Entity';
};

const scopeHelp = {
  private: 'Only the creator, entity leadership, and admins can view this item.',
  entity: 'Everyone with access to this entity can view this item.',
  department: 'Only selected departments can view this item.',
  users: 'Only the selected users can view this item.'
};

const inspectorActionNodes = [
  el.inspectorOpenFolder,
  el.inspectorOpen,
  el.inspectorDownload,
  el.inspectorRename,
  el.inspectorShare,
  el.inspectorDelete
];

const iconMarkup = (itemType) => {
  if (itemType === 'folder') {
    return '<svg class="w-4 h-4 text-amber-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2 5.75A1.75 1.75 0 0 1 3.75 4h3.08a1.75 1.75 0 0 1 1.24.51l.91.91a.75.75 0 0 0 .53.22h6.74A1.75 1.75 0 0 1 18 7.39v6.86A1.75 1.75 0 0 1 16.25 16H3.75A1.75 1.75 0 0 1 2 14.25V5.75Z" /></svg>';
  }
  if (itemType === 'link') {
    return '<svg class="w-4 h-4 text-sky-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4.25 10A3.75 3.75 0 0 1 8 6.25h2a.75.75 0 0 1 0 1.5H8a2.25 2.25 0 1 0 0 4.5h2a.75.75 0 0 1 0 1.5H8A3.75 3.75 0 0 1 4.25 10Zm5-3a.75.75 0 0 1 .75-.75H12A3.75 3.75 0 0 1 12 13.75H10a.75.75 0 0 1 0-1.5h2a2.25 2.25 0 1 0 0-4.5h-2A.75.75 0 0 1 9.25 7ZM7 9.25a.75.75 0 0 1 .75-.75h4.5a.75.75 0 0 1 0 1.5h-4.5A.75.75 0 0 1 7 9.25Z" /></svg>';
  }
  return '<svg class="w-4 h-4 text-slate-300" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4.75 2A1.75 1.75 0 0 0 3 3.75v12.5C3 17.216 3.784 18 4.75 18h10.5A1.75 1.75 0 0 0 17 16.25V7.83a1.75 1.75 0 0 0-.513-1.237l-3.08-3.08A1.75 1.75 0 0 0 12.17 3H4.75Zm7.5 1.81 2.94 2.94h-1.94A1.25 1.25 0 0 1 12 5.5V3.56a.58.58 0 0 1 .25.25Z" /></svg>';
};

function isDesktopInspector() {
  return window.innerWidth >= DESKTOP_INSPECTOR_BREAKPOINT;
}

function makeBadge(label, tone = 'slate') {
  const span = document.createElement('span');
  const toneClasses = {
    slate: 'border-slate-700 bg-slate-900 text-slate-200',
    indigo: 'border-indigo-500/30 bg-indigo-500/10 text-indigo-200',
    amber: 'border-amber-500/30 bg-amber-500/10 text-amber-200',
    red: 'border-red-500/30 bg-red-500/10 text-red-200',
    sky: 'border-sky-500/30 bg-sky-500/10 text-sky-200'
  };
  span.className = `inline-flex items-center rounded-full border px-2.5 py-1 text-xs ${toneClasses[tone] || toneClasses.slate}`;
  span.textContent = label;
  return span;
}

function toast(message, kind = 'ok') {
  el.toast.textContent = message;
  el.toast.className = `fixed bottom-4 right-4 z-50 max-w-sm rounded-xl px-4 py-3 text-sm shadow-2xl ${kind === 'error' ? 'bg-red-500/20 text-red-100 border border-red-500/40' : 'bg-emerald-500/20 text-emerald-100 border border-emerald-500/40'}`;
  clearTimeout(toast.timer);
  toast.timer = window.setTimeout(() => el.toast.classList.add('hidden'), 2400);
}

function setStatus(message, tone = 'muted') {
  const classes = {
    muted: 'text-sm text-slate-400',
    success: 'text-sm text-emerald-300',
    error: 'text-sm text-red-300'
  };
  el.status.textContent = message;
  el.status.className = classes[tone] || classes.muted;
}

function formatDate(value) {
  if (!value) return '-';
  return new Date(value).toLocaleString();
}

function formatSize(item) {
  if (item.item_type !== 'file') return '-';
  const size = Number(item.size_bytes) || 0;
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

function currentFolderName() {
  return state.breadcrumbs[state.breadcrumbs.length - 1]?.name || 'Root';
}

function currentFolderParentId() {
  if (!state.parentId) return null;
  if (state.breadcrumbs.length <= 1) return null;
  return Number(state.breadcrumbs[state.breadcrumbs.length - 2].id);
}

function visibleItems() {
  const query = state.query.trim().toLowerCase();
  const filtered = state.items.filter((item) => !query || item.name.toLowerCase().includes(query));
  filtered.sort((left, right) => {
    if (left.item_type !== right.item_type) {
      if (left.item_type === 'folder') return -1;
      if (right.item_type === 'folder') return 1;
    }
    if (state.sort === 'name_desc') return right.name.localeCompare(left.name);
    if (state.sort === 'updated_desc') return new Date(right.updated_at || right.created_at) - new Date(left.updated_at || left.created_at);
    if (state.sort === 'updated_asc') return new Date(left.updated_at || left.created_at) - new Date(right.updated_at || right.created_at);
    return left.name.localeCompare(right.name);
  });
  return filtered;
}

function getItemById(id) {
  return state.items.find((item) => Number(item.id) === Number(id)) || null;
}

function setNodeHidden(node, hidden) {
  node.hidden = hidden;
  node.classList.toggle('hidden', hidden);
}

function renderLocation() {
  const folderName = currentFolderName();
  el.locationLabel.textContent = folderName;
  el.locationName.textContent = folderName;
  el.locationNote.textContent = state.parentId
    ? 'Items stay in this folder while you rename, share, search, or switch views.'
    : 'Everything shared to this entity starts here.';
  el.up.disabled = !state.parentId;
}

function renderBreadcrumbs() {
  el.breadcrumbs.innerHTML = '';
  const crumbs = [{ id: null, name: 'Root' }, ...state.breadcrumbs.map((crumb) => ({ id: Number(crumb.id), name: crumb.name }))];

  crumbs.forEach((crumb, index) => {
    const isLast = index === crumbs.length - 1;
    const button = document.createElement(isLast ? 'span' : 'button');
    button.className = isLast
      ? 'inline-flex items-center rounded-full border border-slate-700 bg-slate-900 px-3 py-1.5 text-xs font-medium text-slate-200'
      : 'inline-flex items-center rounded-full border border-slate-700 bg-slate-950 px-3 py-1.5 text-xs font-medium text-slate-300 hover:border-slate-600 hover:text-white';
    button.textContent = crumb.name;
    if (!isLast) {
      button.type = 'button';
      button.dataset.parentId = crumb.id == null ? '' : String(crumb.id);
    }
    el.breadcrumbs.appendChild(button);
    if (!isLast) {
      const divider = document.createElement('span');
      divider.className = 'text-slate-600 text-xs';
      divider.textContent = '/';
      el.breadcrumbs.appendChild(divider);
    }
  });
}

function renderSummary() {
  const folders = state.items.filter((item) => item.item_type === 'folder').length;
  const files = state.items.filter((item) => item.item_type === 'file').length;
  const links = state.items.filter((item) => item.item_type === 'link').length;
  const selected = state.selection.size;

  el.summary.innerHTML = '';
  [
    ['Folders', folders, 'amber'],
    ['Files', files, 'slate'],
    ['Links', links, 'sky'],
    ['Selected', selected, 'indigo']
  ].forEach(([label, value, tone]) => {
    const card = document.createElement('div');
    card.className = 'rounded-2xl border border-slate-800 bg-slate-950/70 p-4';

    const labelEl = document.createElement('p');
    labelEl.className = 'text-xs uppercase tracking-[0.2em] text-slate-500';
    labelEl.textContent = label;

    const valueEl = document.createElement('h3');
    valueEl.className = 'text-2xl font-semibold mt-2';
    valueEl.textContent = String(value);

    const badge = makeBadge(value === 0 ? 'Idle' : 'Active', tone);
    badge.classList.add('mt-3');

    card.append(labelEl, valueEl, badge);
    el.summary.appendChild(card);
  });
}

function syncViewMode() {
  const listActive = state.viewMode === 'list';
  el.viewList.classList.toggle('bg-slate-800', listActive);
  el.viewList.classList.toggle('text-slate-100', listActive);
  el.viewGrid.classList.toggle('bg-slate-800', !listActive);
  el.viewGrid.classList.toggle('text-slate-100', !listActive);
  el.viewGrid.classList.toggle('text-slate-300', listActive);
  el.viewList.classList.toggle('text-slate-300', !listActive);
  el.listWrapper.classList.toggle('hidden', !listActive);
  el.grid.classList.toggle('hidden', listActive);
}

function renderBulkToolbar(items) {
  const selectedCount = state.selection.size;
  el.bulkToolbar.classList.toggle('hidden', selectedCount === 0);
  el.bulkCount.textContent = `${selectedCount} selected`;
  el.bulkShare.disabled = selectedCount !== 1;
  el.bulkDelete.disabled = selectedCount === 0;
  if (selectedCount === 0) {
    el.bulkNote.textContent = 'Select items to share or delete them.';
  } else if (selectedCount === 1) {
    el.bulkNote.textContent = '';
  } else {
    el.bulkNote.textContent = 'Share is limited to one selected item at a time.';
  }

  const visibleIds = items.map((item) => item.id);
  const selectedVisible = visibleIds.filter((id) => state.selection.has(id)).length;
  el.selectAll.checked = visibleIds.length > 0 && selectedVisible === visibleIds.length;
  el.selectAll.indeterminate = selectedVisible > 0 && selectedVisible < visibleIds.length;
}

function createIconContainer(itemType) {
  const icon = document.createElement('span');
  icon.className = 'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-800 bg-slate-900';
  icon.innerHTML = iconMarkup(itemType);
  return icon;
}

function createSharingCell(item) {
  const wrapper = document.createElement('div');
  wrapper.className = 'flex flex-wrap gap-2';
  const tone = item.sharing_scope === 'private'
    ? 'red'
    : item.sharing_scope === 'department'
      ? 'amber'
      : item.sharing_scope === 'users'
        ? 'sky'
        : 'indigo';
  wrapper.appendChild(makeBadge(scopeLabel(item.sharing_scope), tone));
  return wrapper;
}

function createActionButton(item) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-700 bg-slate-900 text-slate-300 hover:border-slate-600 hover:text-white';
  button.dataset.rowMenu = String(item.id);
  button.setAttribute('aria-label', `More actions for ${item.name}`);
  button.innerHTML = '<svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 5.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 6a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 6a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" /></svg>';
  return button;
}

function rowTemplate(item) {
  const row = document.createElement('tr');
  const active = state.activeId === item.id;
  row.className = `transition-colors ${active ? 'bg-indigo-500/10' : 'hover:bg-slate-900/70'}`;
  row.dataset.id = String(item.id);

  const checkCell = document.createElement('td');
  checkCell.className = 'px-4 py-3 align-top';
  const checkbox = document.createElement('input');
  checkbox.type = 'checkbox';
  checkbox.className = 'drive-check mt-1';
  checkbox.dataset.id = String(item.id);
  checkbox.checked = state.selection.has(item.id);
  checkbox.setAttribute('aria-label', `Select ${item.name}`);
  checkCell.appendChild(checkbox);

  const nameCell = document.createElement('td');
  nameCell.className = 'px-4 py-3';
  const nameWrapper = document.createElement('div');
  nameWrapper.className = 'flex items-start gap-3 min-w-0';
  nameWrapper.appendChild(createIconContainer(item.item_type));

  const nameMeta = document.createElement('div');
  nameMeta.className = 'min-w-0 flex-1';
  const nameButton = document.createElement('button');
  nameButton.type = 'button';
  nameButton.className = 'block max-w-full text-left text-sm font-medium text-slate-100 hover:text-white';
  nameButton.dataset.selectId = String(item.id);
  if (item.item_type === 'folder') {
    nameButton.dataset.openFolder = String(item.id);
  }
  nameButton.textContent = item.name;
  const subline = document.createElement('p');
  subline.className = 'mt-1 text-xs text-slate-500';
  subline.textContent = item.item_type === 'folder'
    ? 'Open folder'
    : item.item_type === 'link'
      ? 'Open in inspector or external tab'
      : 'Open in inspector to preview or download';
  nameMeta.append(nameButton, subline);
  nameWrapper.appendChild(nameMeta);
  nameCell.appendChild(nameWrapper);

  const sharingCell = document.createElement('td');
  sharingCell.className = 'px-4 py-3 align-top';
  sharingCell.appendChild(createSharingCell(item));

  const sizeCell = document.createElement('td');
  sizeCell.className = 'px-4 py-3 text-slate-300 align-top';
  sizeCell.textContent = formatSize(item);

  const modifiedCell = document.createElement('td');
  modifiedCell.className = 'px-4 py-3 text-slate-300 align-top whitespace-nowrap';
  modifiedCell.textContent = formatDate(item.updated_at || item.created_at);

  const actionCell = document.createElement('td');
  actionCell.className = 'px-4 py-3 text-right align-top';
  actionCell.appendChild(createActionButton(item));

  row.append(checkCell, nameCell, sharingCell, sizeCell, modifiedCell, actionCell);
  return row;
}

function cardTemplate(item) {
  const card = document.createElement('article');
  const active = state.activeId === item.id;
  card.className = `rounded-2xl border p-4 transition-colors ${active ? 'border-indigo-500/40 bg-indigo-500/10' : 'border-slate-800 bg-slate-900/60 hover:border-slate-700'}`;
  card.dataset.id = String(item.id);

  const top = document.createElement('div');
  top.className = 'flex items-start justify-between gap-3';

  const selector = document.createElement('label');
  selector.className = 'inline-flex items-center gap-2 text-xs text-slate-400';
  const checkbox = document.createElement('input');
  checkbox.type = 'checkbox';
  checkbox.className = 'drive-check';
  checkbox.dataset.id = String(item.id);
  checkbox.checked = state.selection.has(item.id);
  checkbox.setAttribute('aria-label', `Select ${item.name}`);
  selector.append(checkbox, document.createTextNode('Select'));

  top.append(selector, createActionButton(item));

  const body = document.createElement('button');
  body.type = 'button';
  body.className = 'mt-4 w-full text-left';
  if (item.item_type === 'folder') {
    body.dataset.openFolder = String(item.id);
  } else {
    body.dataset.selectId = String(item.id);
  }

  const icon = createIconContainer(item.item_type);
  icon.classList.add('h-12', 'w-12');
  const title = document.createElement('h3');
  title.className = 'mt-4 text-base font-semibold break-words';
  title.textContent = item.name;
  const meta = document.createElement('p');
  meta.className = 'mt-2 text-sm text-slate-400';
  meta.textContent = `${scopeLabel(item.sharing_scope)} / ${formatSize(item)}`;
  const updated = document.createElement('p');
  updated.className = 'mt-1 text-xs text-slate-500';
  updated.textContent = `Updated ${formatDate(item.updated_at || item.created_at)}`;

  body.append(icon, title, meta, updated);
  card.append(top, body);
  return card;
}

function renderEmptyState(items) {
  const hasItems = items.length > 0;
  el.empty.classList.toggle('hidden', hasItems || state.loading);
  if (!hasItems && !state.loading) {
    const heading = el.empty.querySelector('h2');
    const copy = el.empty.querySelector('p');
    if (state.query.trim()) {
      heading.textContent = 'No items match your search';
      copy.textContent = 'Try a broader term, switch entities, or clear the current folder search.';
    } else {
      heading.textContent = 'No items in this location';
      copy.textContent = 'Create a folder, upload a file, or add a link to build out this space.';
    }
  }
}

function renderItems() {
  const items = visibleItems();
  el.list.innerHTML = '';
  el.grid.innerHTML = '';
  items.forEach((item) => {
    el.list.appendChild(rowTemplate(item));
    el.grid.appendChild(cardTemplate(item));
  });
  el.count.textContent = `Showing ${items.length} item${items.length === 1 ? '' : 's'}`;
  renderEmptyState(items);
  renderSummary();
  renderBulkToolbar(items);
  syncViewMode();
}

function setInspectorVisible(open) {
  state.mobileInspectorOpen = open;
  if (isDesktopInspector()) {
    el.inspectorPanel.classList.remove('hidden');
    setNodeHidden(el.inspectorBackdrop, true);
    return;
  }
  el.inspectorPanel.classList.toggle('hidden', !open);
  setNodeHidden(el.inspectorBackdrop, !open);
}

function renderInspector() {
  const item = state.activeItem;
  const preview = state.activePreview;

  if (!item) {
    el.inspectorName.textContent = 'Select an item';
    el.inspectorMeta.textContent = '';
    el.inspectorPreview.textContent = 'Select a file or folder to inspect it.';
    el.inspectorLocation.textContent = '-';
    el.inspectorSharing.textContent = '-';
    el.inspectorDetails.textContent = '-';
    el.inspectorAccessList.innerHTML = '';
    inspectorActionNodes.forEach((node) => setNodeHidden(node, true));
    if (!isDesktopInspector()) {
      setInspectorVisible(false);
    }
    return;
  }

  const parentChain = item.parent_chain || [];
  const locationParts = ['Root', ...parentChain.map((crumb) => crumb.name)];
  const sharingParts = [scopeLabel(item.sharing_scope)];
  if (item.sharing_scope === 'department' && Array.isArray(item.shared_departments) && item.shared_departments.length) {
    sharingParts.push(item.shared_departments.join(', '));
  }
  if (item.sharing_scope === 'users' && Array.isArray(item.shared_users) && item.shared_users.length) {
    sharingParts.push(`${item.shared_users.length} specific user${item.shared_users.length === 1 ? '' : 's'}`);
  }

  const detailParts = [itemTypeLabel(item.item_type)];
  if (item.item_type === 'file') {
    detailParts.push(formatSize(item));
  }
  detailParts.push(`Updated ${formatDate(item.updated_at || item.created_at)}`);

  el.inspectorName.textContent = item.name;
  el.inspectorMeta.textContent = `${itemTypeLabel(item.item_type)} / ${scopeLabel(item.sharing_scope)}`;
  el.inspectorLocation.textContent = locationParts.join(' / ');
  el.inspectorSharing.textContent = sharingParts.join(' / ');
  el.inspectorDetails.textContent = detailParts.join(' / ');

  el.inspectorAccessList.innerHTML = '';
  if (item.sharing_scope === 'private') {
    el.inspectorAccessList.appendChild(makeBadge('Private', 'red'));
  } else if (item.sharing_scope === 'entity') {
    el.inspectorAccessList.appendChild(makeBadge('Entity members', 'indigo'));
  } else if (item.sharing_scope === 'department') {
    (item.shared_departments || []).forEach((department) => {
      el.inspectorAccessList.appendChild(makeBadge(department, 'amber'));
    });
  } else if (item.sharing_scope === 'users') {
    (item.shared_users || []).forEach((user) => {
      const label = user.full_name || user.email || `User ${user.user_id}`;
      el.inspectorAccessList.appendChild(makeBadge(label, 'sky'));
    });
  }

  if (!el.inspectorAccessList.childElementCount) {
    el.inspectorAccessList.appendChild(makeBadge('No additional access rules', 'slate'));
  }

  inspectorActionNodes.forEach((node) => setNodeHidden(node, true));

  if (item.item_type === 'folder') {
    setNodeHidden(el.inspectorOpenFolder, false);
  }

  if (item.can_manage) {
    setNodeHidden(el.inspectorRename, false);
    setNodeHidden(el.inspectorShare, false);
    setNodeHidden(el.inspectorDelete, false);
  }

  if (!preview) {
    el.inspectorPreview.textContent = 'Loading preview...';
  } else if (preview.error) {
    el.inspectorPreview.textContent = preview.error;
  } else if (preview.kind === 'folder') {
    el.inspectorPreview.innerHTML = '';
    const wrapper = document.createElement('div');
    wrapper.className = 'w-full rounded-2xl border border-slate-800 bg-slate-900/60 p-5 text-left';
    const title = document.createElement('p');
    title.className = 'text-xs uppercase tracking-[0.2em] text-slate-400';
    title.textContent = 'Folder';
    const body = document.createElement('h3');
    body.className = 'text-lg font-semibold mt-3';
    body.textContent = 'Open this folder to browse its contents.';
    const copy = document.createElement('p');
    copy.className = 'mt-2 text-sm text-slate-400';
    copy.textContent = 'Use the breadcrumbs and the Up button to move back through the current path.';
    wrapper.append(title, body, copy);
    el.inspectorPreview.replaceChildren(wrapper);
  } else if (preview.kind === 'pdf' || preview.kind === 'pdf_link') {
    const frame = document.createElement('iframe');
    frame.className = 'w-full h-72 rounded-2xl border border-slate-800 bg-slate-950';
    frame.src = preview.preview_url;
    el.inspectorPreview.replaceChildren(frame);
  } else if (preview.kind === 'youtube') {
    const frame = document.createElement('iframe');
    frame.className = 'w-full h-72 rounded-2xl border border-slate-800 bg-slate-950';
    frame.src = preview.preview_url;
    frame.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
    frame.allowFullscreen = true;
    el.inspectorPreview.replaceChildren(frame);
  } else if (preview.kind === 'link') {
    const wrapper = document.createElement('div');
    wrapper.className = 'w-full rounded-2xl border border-slate-800 bg-slate-900/60 p-5 text-left';
    const label = document.createElement('p');
    label.className = 'text-xs uppercase tracking-[0.2em] text-slate-400';
    label.textContent = 'External link';
    const anchor = document.createElement('a');
    anchor.className = 'mt-3 inline-block break-all text-sm text-indigo-300 underline';
    anchor.href = preview.open_url;
    anchor.target = '_blank';
    anchor.rel = 'noopener noreferrer';
    anchor.textContent = preview.open_url;
    wrapper.append(label, anchor);
    el.inspectorPreview.replaceChildren(wrapper);
  } else {
    el.inspectorPreview.textContent = preview.label || 'No inline preview available.';
  }

  if (preview?.open_url) {
    el.inspectorOpen.href = preview.open_url;
    setNodeHidden(el.inspectorOpen, false);
  }
  if (preview?.download_url) {
    el.inspectorDownload.href = preview.download_url;
    setNodeHidden(el.inspectorDownload, false);
  }

  setInspectorVisible(true);
}

async function openInspector(item) {
  state.activeId = item?.id ?? null;
  state.activeItem = item ? { ...item } : null;
  state.activePreview = item ? null : null;
  renderItems();
  renderInspector();
  if (!item) {
    return;
  }

  const activeId = item.id;
  try {
    const [detailResponse, previewResponse] = await Promise.all([
      apiFetch(`/drive/item?id=${encodeURIComponent(item.id)}`),
      apiFetch(`/drive/preview?id=${encodeURIComponent(item.id)}`)
    ]);
    if (state.activeId !== activeId) {
      return;
    }
    state.activeItem = {
      ...item,
      ...(detailResponse?.data || {})
    };
    state.activePreview = previewResponse?.data || null;
    renderInspector();
  } catch (error) {
    if (state.activeId !== activeId) {
      return;
    }
    state.activePreview = { error: normalizeError(error) };
    renderInspector();
  }
}

function navigateToFolder(itemOrId) {
  const folderId = typeof itemOrId === 'object' ? Number(itemOrId.id) : Number(itemOrId);
  if (!folderId) {
    state.parentId = null;
  } else {
    state.parentId = folderId;
  }
  state.selection.clear();
  state.activeId = null;
  state.activeItem = null;
  state.activePreview = null;
  closeActionMenu();
  closeNewMenu();
  setInspectorVisible(false);
  loadItems({ preserveSelection: false, preserveActive: false }).catch((error) => {
    toast(normalizeError(error), 'error');
  });
}

function renderShareTargets() {
  const draft = state.shareDraft;
  const scope = draft?.scope || 'entity';
  el.shareScope.value = scope;
  el.shareScopeHelp.textContent = scopeHelp[scope] || scopeHelp.entity;
  el.shareDepartmentsSection.classList.toggle('hidden', scope !== 'department');
  el.shareUsersSection.classList.toggle('hidden', scope !== 'users');

  el.shareDepartmentsList.innerHTML = '';
  (state.shareTargets.departments || []).forEach((department) => {
    const label = document.createElement('label');
    label.className = 'flex items-center gap-3 rounded-2xl border border-slate-800 bg-slate-950/70 px-4 py-3 text-sm text-slate-200';
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.value = department;
    checkbox.checked = draft?.departments?.has(department) || false;
    checkbox.dataset.department = department;
    const text = document.createElement('span');
    text.textContent = department;
    label.append(checkbox, text);
    el.shareDepartmentsList.appendChild(label);
  });

  const query = draft?.userSearch?.trim().toLowerCase() || '';
  const users = (state.shareTargets.users || []).filter((user) => {
    const haystack = `${user.full_name || ''} ${user.email || ''}`.toLowerCase();
    return !query || haystack.includes(query);
  });
  el.shareUsersList.innerHTML = '';
  if (!users.length) {
    const empty = document.createElement('div');
    empty.className = 'px-4 py-5 text-sm text-slate-500';
    empty.textContent = query ? 'No people match this search.' : 'No share targets are available for this entity yet.';
    el.shareUsersList.appendChild(empty);
  } else {
    users.forEach((user) => {
      const label = document.createElement('label');
      label.className = 'flex items-start gap-3 px-4 py-3 text-sm text-slate-200';
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.value = String(user.id);
      checkbox.checked = draft?.users?.has(String(user.id)) || false;
      checkbox.dataset.userId = String(user.id);

      const textWrap = document.createElement('div');
      textWrap.className = 'min-w-0';
      const name = document.createElement('p');
      name.className = 'font-medium text-slate-100';
      name.textContent = user.full_name || user.email || `User ${user.id}`;
      const email = document.createElement('p');
      email.className = 'text-xs text-slate-500 break-all';
      email.textContent = user.email || '';
      textWrap.append(name, email);

      label.append(checkbox, textWrap);
      el.shareUsersList.appendChild(label);
    });
  }

  el.shareExtraUsers.value = draft?.extraUsers || '';
}

async function ensureShareTargets() {
  if (state.shareTargetsEntityId === state.entityId) {
    return;
  }
  const response = await apiFetch(`/drive/share_targets?entity_id=${encodeURIComponent(state.entityId)}`);
  state.shareTargets = response?.data || { departments: [], users: [] };
  state.shareTargetsEntityId = state.entityId;
}

async function openShareModal(item) {
  if (!item.can_manage) {
    toast('You do not have permission to update sharing for this item.', 'error');
    return;
  }
  try {
    await ensureShareTargets();
    const selectedUserIds = new Set();
    const extraEmails = [];
    (item.shared_users || []).forEach((user) => {
      if (user.user_id && (state.shareTargets.users || []).some((candidate) => Number(candidate.id) === Number(user.user_id))) {
        selectedUserIds.add(String(user.user_id));
        return;
      }
      if (user.email) {
        extraEmails.push(user.email);
      }
    });

    state.shareDraft = {
      itemId: item.id,
      scope: item.sharing_scope || 'entity',
      departments: new Set(item.shared_departments || []),
      users: selectedUserIds,
      extraUsers: extraEmails.join(', '),
      userSearch: ''
    };

    el.shareItemName.textContent = item.name;
    el.shareUserSearch.value = '';
    renderShareTargets();
    el.shareModal.classList.remove('hidden');
    el.shareModal.classList.add('flex');
  } catch (error) {
    toast(normalizeError(error), 'error');
  }
}

function closeShareModal() {
  state.shareDraft = null;
  el.shareModal.classList.add('hidden');
  el.shareModal.classList.remove('flex');
}

async function saveShare() {
  if (!state.shareDraft) return;
  const payload = {
    id: state.shareDraft.itemId,
    sharing_scope: state.shareDraft.scope
  };
  if (payload.sharing_scope === 'department') {
    payload.departments = [...state.shareDraft.departments];
  }
  if (payload.sharing_scope === 'users') {
    const userIds = [...state.shareDraft.users].map((id) => Number(id));
    const extraEmails = state.shareDraft.extraUsers
      .split(',')
      .map((entry) => entry.trim())
      .filter(Boolean);
    payload.users = [...userIds, ...extraEmails];
  }
  await apiFetch('/drive/share', {
    method: 'POST',
    body: JSON.stringify(payload)
  });
  toast('Sharing updated');
  closeShareModal();
  await loadItems({ preserveSelection: true, preserveActive: true });
}

function openItemModal(mode, item = null) {
  state.itemModalMode = mode;
  state.itemModalItemId = item?.id ?? null;

  if (mode === 'create-folder') {
    el.itemModalTitle.textContent = 'New folder';
    el.itemSubmit.textContent = 'Create folder';
    el.itemName.value = '';
    el.itemUrl.value = '';
    el.itemUrlGroup.classList.add('hidden');
  } else if (mode === 'create-link') {
    el.itemModalTitle.textContent = 'New link';
    el.itemSubmit.textContent = 'Create link';
    el.itemName.value = '';
    el.itemUrl.value = '';
    el.itemUrlGroup.classList.remove('hidden');
  } else {
    el.itemModalTitle.textContent = `Rename ${itemTypeLabel(item?.item_type || 'file').toLowerCase()}`;
    el.itemSubmit.textContent = 'Save name';
    el.itemName.value = item?.name || '';
    el.itemUrl.value = '';
    el.itemUrlGroup.classList.add('hidden');
  }

  el.itemModal.classList.remove('hidden');
  el.itemModal.classList.add('flex');
  window.setTimeout(() => el.itemName.focus(), 10);
}

function closeItemModal() {
  state.itemModalMode = null;
  state.itemModalItemId = null;
  el.itemModal.classList.add('hidden');
  el.itemModal.classList.remove('flex');
  el.itemForm.reset();
}

async function submitItemModal(event) {
  event.preventDefault();
  const name = el.itemName.value.trim();
  const url = el.itemUrl.value.trim();
  const mode = state.itemModalMode;
  if (!mode) return;

  if (mode === 'create-folder') {
    await apiFetch('/drive/folder', {
      method: 'POST',
      body: JSON.stringify({
        entity_id: state.entityId,
        parent_id: state.parentId,
        name
      })
    });
    toast('Folder created');
    closeItemModal();
    await loadItems({ preserveSelection: false, preserveActive: false });
    return;
  }

  if (mode === 'create-link') {
    await apiFetch('/drive/link', {
      method: 'POST',
      body: JSON.stringify({
        entity_id: state.entityId,
        parent_id: state.parentId,
        name,
        url
      })
    });
    toast('Link created');
    closeItemModal();
    await loadItems({ preserveSelection: false, preserveActive: false });
    return;
  }

  await apiFetch('/drive/rename', {
    method: 'POST',
    body: JSON.stringify({
      id: state.itemModalItemId,
      name
    })
  });
  toast('Name updated');
  closeItemModal();
  await loadItems({ preserveSelection: true, preserveActive: true });
}

function openDeleteModal(items) {
  const targets = items.filter(Boolean);
  if (!targets.length) return;
  state.deleteTargetIds = targets.map((item) => Number(item.id));

  if (targets.length === 1) {
    const target = targets[0];
    const noun = target.item_type === 'folder' ? 'folder and everything inside it' : itemTypeLabel(target.item_type).toLowerCase();
    el.deleteMessage.textContent = `Delete ${noun} "${target.name}"?`;
  } else {
    el.deleteMessage.textContent = `Delete ${targets.length} selected items?`;
  }

  el.deleteModal.classList.remove('hidden');
  el.deleteModal.classList.add('flex');
}

function closeDeleteModal() {
  state.deleteTargetIds = [];
  el.deleteModal.classList.add('hidden');
  el.deleteModal.classList.remove('flex');
}

async function confirmDelete() {
  const targets = [...state.deleteTargetIds];
  for (const id of targets) {
    await apiFetch('/drive/delete', {
      method: 'POST',
      body: JSON.stringify({ id })
    });
  }
  toast(targets.length === 1 ? 'Item deleted' : 'Items deleted');
  state.selection.clear();
  if (targets.includes(Number(state.activeId))) {
    state.activeId = null;
    state.activeItem = null;
    state.activePreview = null;
  }
  closeDeleteModal();
  await loadItems({ preserveSelection: false, preserveActive: true });
}

function openActionMenuFor(item, anchorRect) {
  state.actionMenuItemId = item.id;
  const menuWidth = 192;
  const menuHeight = 188;
  const top = Math.max(12, Math.min(window.innerHeight - menuHeight - 12, anchorRect.bottom + 8));
  const left = Math.max(12, Math.min(window.innerWidth - menuWidth - 12, anchorRect.right - menuWidth));
  el.actionMenu.style.top = `${top}px`;
  el.actionMenu.style.left = `${left}px`;
  el.actionMenu.hidden = false;
  el.actionMenu.classList.remove('hidden');

  const openButton = el.actionMenu.querySelector('[data-action="open"]');
  const renameButton = el.actionMenu.querySelector('[data-action="rename"]');
  const shareButton = el.actionMenu.querySelector('[data-action="share"]');
  const deleteButton = el.actionMenu.querySelector('[data-action="delete"]');
  const canManage = Boolean(item.can_manage);

  openButton.textContent = item.item_type === 'folder' ? 'Open folder' : 'Open';
  [renameButton, shareButton, deleteButton].forEach((button) => {
    button.disabled = !canManage;
    button.classList.toggle('opacity-50', !canManage);
  });
}

function closeActionMenu() {
  state.actionMenuItemId = null;
  el.actionMenu.hidden = true;
  el.actionMenu.classList.add('hidden');
}

async function runAction(action, item) {
  if (!item) return;
  if (action === 'open') {
    if (item.item_type === 'folder') {
      navigateToFolder(item);
      return;
    }
    await openInspector(item);
    if (item.item_type === 'link' && item.url) {
      window.open(item.url, '_blank', 'noopener,noreferrer');
    }
    return;
  }
  if (action === 'rename') {
    openItemModal('rename', item);
    return;
  }
  if (action === 'share') {
    await openShareModal(item);
    return;
  }
  if (action === 'delete') {
    openDeleteModal([item]);
  }
}

async function uploadSelectedFile() {
  const file = el.uploadInput.files?.[0];
  if (!file) return;
  const data = new FormData();
  data.append('entity_id', String(state.entityId));
  data.append('parent_id', state.parentId ? String(state.parentId) : '');
  data.append('file', file);
  await apiFetch('/drive/upload', {
    method: 'POST',
    body: data
  });
  el.uploadInput.value = '';
  toast('Upload complete');
  await loadItems({ preserveSelection: false, preserveActive: false });
}

async function loadItems({ preserveSelection = true, preserveActive = true } = {}) {
  if (!state.entityId) return;

  const previousSelection = preserveSelection ? new Set(state.selection) : new Set();
  const previousActiveId = preserveActive ? Number(state.activeId) : null;

  const token = Symbol('drive-load');
  state.loadToken = token;
  state.loading = true;
  el.skeleton.classList.remove('hidden');
  setStatus('Loading drive...', 'muted');

  try {
    const params = new URLSearchParams({ entity_id: String(state.entityId) });
    if (state.parentId) {
      params.set('parent_id', String(state.parentId));
    }
    const response = await apiFetch(`/drive/list?${params.toString()}`);
    if (state.loadToken !== token) {
      return;
    }

    state.items = response?.data || [];
    state.breadcrumbs = response?.meta?.breadcrumbs || [];
    state.selection = new Set(
      [...previousSelection].filter((id) => state.items.some((item) => Number(item.id) === Number(id)))
    );
    renderBreadcrumbs();
    renderLocation();
    renderItems();

    const restoredItem = previousActiveId
      ? state.items.find((item) => Number(item.id) === previousActiveId) || null
      : null;
    await openInspector(restoredItem);
    if (!restoredItem) {
      renderInspector();
    }
    setStatus(state.query ? `Filtering current folder by "${state.query}".` : 'Drive loaded.', 'success');
  } catch (error) {
    if (state.loadToken !== token) {
      return;
    }
    state.items = [];
    state.selection.clear();
    state.activeId = null;
    state.activeItem = null;
    state.activePreview = null;
    renderBreadcrumbs();
    renderLocation();
    renderItems();
    renderInspector();
    setStatus(normalizeError(error), 'error');
  } finally {
    if (state.loadToken === token) {
      state.loading = false;
      el.skeleton.classList.add('hidden');
    }
  }
}

function closeNewMenu() {
  el.newMenu.classList.add('hidden');
}

function bindListEvents(container) {
  container.addEventListener('click', async (event) => {
    const actionButton = event.target.closest('[data-row-menu]');
    if (actionButton) {
      event.stopPropagation();
      const item = getItemById(Number(actionButton.dataset.rowMenu));
      if (!item) return;
      const rect = actionButton.getBoundingClientRect();
      openActionMenuFor(item, rect);
      return;
    }

    const checkbox = event.target.closest('.drive-check');
    if (checkbox) {
      const id = Number(checkbox.dataset.id);
      if (checkbox.checked) state.selection.add(id);
      else state.selection.delete(id);
      renderItems();
      return;
    }

    const folderButton = event.target.closest('[data-open-folder]');
    if (folderButton) {
      event.preventDefault();
      navigateToFolder(Number(folderButton.dataset.openFolder));
      return;
    }

    const selectButton = event.target.closest('[data-select-id]');
    if (selectButton) {
      event.preventDefault();
      const item = getItemById(Number(selectButton.dataset.selectId));
      if (item) {
        await openInspector(item);
      }
      return;
    }

    const card = event.target.closest('[data-id]');
    if (!card) return;
    const item = getItemById(Number(card.dataset.id));
    if (item) {
      await openInspector(item);
    }
  });

  container.addEventListener('contextmenu', (event) => {
    const row = event.target.closest('[data-id]');
    if (!row) return;
    event.preventDefault();
    const item = getItemById(Number(row.dataset.id));
    if (!item) return;
    openActionMenuFor(item, {
      top: event.clientY,
      bottom: event.clientY,
      left: event.clientX,
      right: event.clientX + 48
    });
  });
}

function bind() {
  el.newBtn.addEventListener('click', () => {
    el.newMenu.classList.toggle('hidden');
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('#new-menu') && !event.target.closest('#new-menu-btn')) {
      closeNewMenu();
    }
    if (!event.target.closest('#action-menu') && !event.target.closest('[data-row-menu]')) {
      closeActionMenu();
    }
  });

  bindListEvents(el.list);
  bindListEvents(el.grid);

  el.newFolder.addEventListener('click', () => {
    closeNewMenu();
    openItemModal('create-folder');
  });
  el.newLink.addEventListener('click', () => {
    closeNewMenu();
    openItemModal('create-link');
  });

  el.uploadBtn.addEventListener('click', () => el.uploadInput.click());
  el.uploadInput.addEventListener('change', () => {
    uploadSelectedFile().catch((error) => toast(normalizeError(error), 'error'));
  });

  el.entity.addEventListener('change', () => {
    state.entityId = Number(el.entity.value);
    state.parentId = null;
    state.query = '';
    state.selection.clear();
    state.activeId = null;
    state.activeItem = null;
    state.activePreview = null;
    el.search.value = '';
    setInspectorVisible(false);
    loadItems({ preserveSelection: false, preserveActive: false }).catch((error) => {
      toast(normalizeError(error), 'error');
    });
  });

  el.up.addEventListener('click', () => {
    state.parentId = currentFolderParentId();
    state.selection.clear();
    state.activeId = null;
    state.activeItem = null;
    state.activePreview = null;
    setInspectorVisible(false);
    loadItems({ preserveSelection: false, preserveActive: false }).catch((error) => {
      toast(normalizeError(error), 'error');
    });
  });

  el.breadcrumbs.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-parent-id]');
    if (!button) return;
    const parentId = button.dataset.parentId ? Number(button.dataset.parentId) : null;
    state.parentId = parentId;
    state.selection.clear();
    state.activeId = null;
    state.activeItem = null;
    state.activePreview = null;
    setInspectorVisible(false);
    loadItems({ preserveSelection: false, preserveActive: false }).catch((error) => {
      toast(normalizeError(error), 'error');
    });
  });

  el.search.addEventListener('input', () => {
    state.query = el.search.value;
    renderItems();
    setStatus(state.query ? `Filtering current folder by "${state.query}".` : 'Drive loaded.', state.query ? 'muted' : 'success');
  });

  el.sort.addEventListener('change', () => {
    state.sort = el.sort.value;
    renderItems();
  });

  el.viewList.addEventListener('click', () => {
    state.viewMode = 'list';
    syncViewMode();
  });
  el.viewGrid.addEventListener('click', () => {
    state.viewMode = 'grid';
    syncViewMode();
  });

  el.selectAll.addEventListener('change', () => {
    const items = visibleItems();
    if (el.selectAll.checked) {
      items.forEach((item) => state.selection.add(item.id));
    } else {
      items.forEach((item) => state.selection.delete(item.id));
    }
    renderItems();
  });

  el.bulkClear.addEventListener('click', () => {
    state.selection.clear();
    renderItems();
  });

  el.bulkShare.addEventListener('click', () => {
    const selectedId = [...state.selection][0];
    const item = getItemById(selectedId);
    if (item) {
      openShareModal(item);
    }
  });

  el.bulkDelete.addEventListener('click', () => {
    const targets = [...state.selection].map((id) => getItemById(id)).filter(Boolean);
    openDeleteModal(targets);
  });

  el.actionMenu.addEventListener('click', (event) => {
    event.stopPropagation();
    const button = event.target.closest('button[data-action]');
    if (!button) return;
    const item = getItemById(Number(state.actionMenuItemId));
    closeActionMenu();
    runAction(button.dataset.action, item).catch((error) => {
      toast(normalizeError(error), 'error');
    });
  });

  el.itemForm.addEventListener('submit', (event) => {
    submitItemModal(event).catch((error) => toast(normalizeError(error), 'error'));
  });
  [el.itemModalClose, el.itemCancel].forEach((button) => button.addEventListener('click', closeItemModal));

  [el.deleteClose, el.deleteCancel].forEach((button) => button.addEventListener('click', closeDeleteModal));
  el.deleteConfirm.addEventListener('click', () => {
    confirmDelete().catch((error) => toast(normalizeError(error), 'error'));
  });

  el.shareScope.addEventListener('change', () => {
    if (!state.shareDraft) return;
    state.shareDraft.scope = el.shareScope.value;
    renderShareTargets();
  });
  el.shareUserSearch.addEventListener('input', () => {
    if (!state.shareDraft) return;
    state.shareDraft.userSearch = el.shareUserSearch.value;
    renderShareTargets();
  });
  el.shareDepartmentsList.addEventListener('change', (event) => {
    if (!state.shareDraft) return;
    const input = event.target.closest('input[data-department]');
    if (!input) return;
    if (input.checked) state.shareDraft.departments.add(input.value);
    else state.shareDraft.departments.delete(input.value);
  });
  el.shareUsersList.addEventListener('change', (event) => {
    if (!state.shareDraft) return;
    const input = event.target.closest('input[data-user-id]');
    if (!input) return;
    if (input.checked) state.shareDraft.users.add(input.value);
    else state.shareDraft.users.delete(input.value);
  });
  el.shareExtraUsers.addEventListener('input', () => {
    if (!state.shareDraft) return;
    state.shareDraft.extraUsers = el.shareExtraUsers.value;
  });

  [el.shareClose, el.shareCancel].forEach((button) => button.addEventListener('click', closeShareModal));
  el.shareSave.addEventListener('click', () => {
    saveShare().catch((error) => toast(normalizeError(error), 'error'));
  });

  el.inspectorToggle.addEventListener('click', () => {
    if (!state.activeItem) {
      toast('Select an item to inspect it.', 'error');
      return;
    }
    setInspectorVisible(!state.mobileInspectorOpen);
  });
  el.inspectorBackdrop.addEventListener('click', () => setInspectorVisible(false));
  el.inspectorClose.addEventListener('click', () => setInspectorVisible(false));
  el.inspectorOpenFolder.addEventListener('click', () => {
    if (state.activeItem) {
      navigateToFolder(state.activeItem);
    }
  });
  el.inspectorRename.addEventListener('click', () => {
    if (state.activeItem) {
      openItemModal('rename', state.activeItem);
    }
  });
  el.inspectorShare.addEventListener('click', () => {
    if (state.activeItem) {
      openShareModal(state.activeItem);
    }
  });
  el.inspectorDelete.addEventListener('click', () => {
    if (state.activeItem) {
      openDeleteModal([state.activeItem]);
    }
  });

  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f') {
      event.preventDefault();
      el.search.focus();
    }
    if (event.key === 'Escape') {
      closeNewMenu();
      closeActionMenu();
      closeItemModal();
      closeDeleteModal();
      closeShareModal();
      if (!isDesktopInspector()) {
        setInspectorVisible(false);
      }
    }
  });

  window.addEventListener('resize', () => {
    setInspectorVisible(state.mobileInspectorOpen && Boolean(state.activeItem));
  });
}

async function boot() {
  bind();
  const me = await apiFetch('/auth/me');
  const entities = me?.data?.entities || [];
  if (!entities.length) {
    setStatus('No entities are available for this user.', 'error');
    return;
  }

  el.entity.innerHTML = '';
  entities.forEach((entity) => {
    const option = document.createElement('option');
    option.value = String(entity.id);
    option.textContent = entity.name;
    el.entity.appendChild(option);
  });

  state.entityId = Number(entities[0].id);
  el.entity.value = String(state.entityId);
  syncViewMode();
  renderBreadcrumbs();
  renderLocation();
  renderItems();
  renderInspector();
  await loadItems({ preserveSelection: false, preserveActive: false });
}

boot().catch(() => {
  window.location.href = '/login.html';
});
