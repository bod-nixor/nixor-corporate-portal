import { apiFetch } from '/assets/app.js';
import { renderSidebar } from '/assets/sidebar.js';

document.getElementById('sidebar-container').outerHTML = renderSidebar('calendar');
const entitySelect = document.getElementById('calendar-entity');
const list = document.getElementById('calendar-list');
const emptyState = document.getElementById('calendar-empty');
const form = document.getElementById('calendar-form');
const statusEl = document.getElementById('calendar-status');
const eventDateInput = document.getElementById('calendar-event-date');

let currentUser = null;
let editingEventId = null;
let deletingEventId = null;

const deleteModal = document.getElementById('delete-modal');
const deleteMessage = document.getElementById('delete-message');
const deleteConfirmBtn = document.getElementById('delete-confirm');
const deleteCancelBtn = document.getElementById('delete-cancel');
const deleteCloseBtn = document.getElementById('delete-close');

const defaultEmptyHeading = emptyState.querySelector('[data-empty-heading]')?.textContent || 'No events scheduled.';
const defaultEmptySubtext = emptyState.querySelector('[data-empty-subtext]')?.textContent || 'Select a different entity or create a new event.';

const normalizeError = (err) => {
  const message = err?.message || '';
  return message === 'Forbidden' ? 'You do not have permission.' : (message || 'Action failed.');
};

const setStatus = (message, ok) => {
  statusEl.textContent = message;
  statusEl.className = `text-sm font-semibold rounded-lg px-4 py-3 border ${ok ? 'bg-[rgba(16,185,129,0.1)] text-[#6ee7b7] border-[rgba(16,185,129,0.2)]' : 'bg-[rgba(239,68,68,0.1)] text-[#fca5a5] border-[rgba(239,68,68,0.2)]'}`;
  statusEl.classList.remove('hidden');
};

const dateInAllowedRange = (value) => {
  return value >= '2000-01-01T00:00' && value <= '2100-12-31T23:59';
};

window.editEvent = (eventStr) => {
    const event = JSON.parse(decodeURIComponent(eventStr));
    editingEventId = event.id;
    document.getElementById('calendar-title').value = event.title;
    document.getElementById('calendar-event-date').value = String(event.event_date || '').replace(' ', 'T').slice(0, 16);
    document.getElementById('calendar-location').value = event.location || '';
    document.getElementById('calendar-description').value = event.description || '';
    
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.textContent = 'Update Event';
    
    let cancelBtn = document.getElementById('calendar-cancel-edit');
    if (!cancelBtn) {
        cancelBtn = document.createElement('button');
        cancelBtn.id = 'calendar-cancel-edit';
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn btn-ghost w-full mt-2';
        cancelBtn.textContent = 'Cancel Edit';
        cancelBtn.onclick = window.cancelEdit;
        form.appendChild(cancelBtn);
    }
    cancelBtn.classList.remove('hidden');
    
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

window.cancelEdit = () => {
    editingEventId = null;
    form.reset();
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.textContent = 'Create Event';
    const cancelBtn = document.getElementById('calendar-cancel-edit');
    if (cancelBtn) cancelBtn.classList.add('hidden');
    statusEl.classList.add('hidden');
};

window.deleteEvent = (eventStr) => {
    const event = JSON.parse(decodeURIComponent(eventStr));
    deletingEventId = event.id;
    deleteMessage.textContent = `Are you sure you want to delete "${event.title}"?`;
    deleteModal.classList.remove('hidden');
};

const closeDeleteModal = () => {
    deleteModal.classList.add('hidden');
    deletingEventId = null;
};

if (deleteCancelBtn) deleteCancelBtn.onclick = closeDeleteModal;
if (deleteCloseBtn) deleteCloseBtn.onclick = closeDeleteModal;

if (deleteConfirmBtn) {
    deleteConfirmBtn.onclick = async () => {
        if (!deletingEventId) return;
        try {
            deleteConfirmBtn.disabled = true;
            await apiFetch(`/calendar/${deletingEventId}`, { method: 'DELETE' });
            closeDeleteModal();
            loadEvents();
        } catch (err) {
            alert(normalizeError(err));
        } finally {
            deleteConfirmBtn.disabled = false;
        }
    };
}

const loadEvents = async () => {
  const entityId = entitySelect.value;
  if (!entityId) return;
  try {
    const response = await apiFetch(`/calendar?entity_id=${entityId}`);
    const events = (response?.data || []).filter((event) => {
      const value = String(event.event_date || '').replace(' ', 'T').slice(0, 16);
      return dateInAllowedRange(value);
    });
    list.innerHTML = '';
    if (!events.length) {
      emptyState.querySelector('[data-empty-heading]').textContent = defaultEmptyHeading;
      emptyState.querySelector('[data-empty-subtext]').textContent = defaultEmptySubtext;
      emptyState.classList.remove('hidden');
      return;
    }
    emptyState.classList.add('hidden');
    events.forEach((event) => {
      const card = document.createElement('div');
      card.className = 'bg-[var(--bg-surface)] border border-[var(--border-subtle)] rounded-xl p-5 hover:border-[var(--border-strong)] transition-colors shadow-sm relative group focus-within:border-[var(--border-strong)]';
      const title = document.createElement('p');
      title.className = 'font-bold text-[var(--text-primary)] text-base leading-tight pr-20';
      title.textContent = event.title;
      const meta = document.createElement('p');
      meta.className = 'text-[10px] font-bold tracking-widest uppercase text-[var(--text-tertiary)] mt-2 flex items-center gap-2';
      const timeSpan = document.createElement('span');
      timeSpan.textContent = new Date(event.event_date).toLocaleString();

      const separatorSpan = document.createElement('span');
      separatorSpan.className = 'text-[var(--border-strong)]';
      separatorSpan.textContent = '|';

      const locSpan = document.createElement('span');
      locSpan.className = 'truncate';
      locSpan.textContent = event.location || 'TBD';

      meta.innerHTML = `<svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>`;
      meta.appendChild(timeSpan);
      meta.appendChild(separatorSpan);

      const iconSpan = document.createElement('span');
      iconSpan.innerHTML = `<svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>`;
      meta.appendChild(iconSpan.firstChild);
      meta.appendChild(locSpan);

      const desc = document.createElement('p');
      desc.className = 'text-[13px] font-medium text-[var(--text-secondary)] mt-3 leading-relaxed';
      desc.textContent = event.description || 'No description provided.';
      
      card.appendChild(title);
      card.appendChild(meta);
      card.appendChild(desc);
      
      const canManage = currentUser?.global_role === 'admin' || Number(event.created_by) === Number(currentUser?.id);
      if (canManage) {
        const actionsDiv = document.createElement('div');
        actionsDiv.className = 'absolute top-4 right-4 flex gap-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity';
        
        const eventStr = encodeURIComponent(JSON.stringify(event));
        
        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'btn btn-ghost px-2 py-1 h-auto text-[var(--text-secondary)] hover:text-[var(--text-primary)] text-xs';
        editBtn.innerHTML = '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>';
        editBtn.onclick = () => window.editEvent(eventStr);
        editBtn.title = 'Edit';
        
        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'btn btn-ghost px-2 py-1 h-auto text-[var(--color-danger)] hover:bg-[var(--color-danger-bg)] text-xs';
        delBtn.innerHTML = '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>';
        delBtn.onclick = () => window.deleteEvent(eventStr);
        delBtn.title = 'Delete';
        
        actionsDiv.appendChild(editBtn);
        actionsDiv.appendChild(delBtn);
        card.appendChild(actionsDiv);
      }
      
      list.appendChild(card);
    });
  } catch (err) {
    console.error('Failed to load events:', err);
    list.innerHTML = '';
    emptyState.querySelector('[data-empty-heading]').textContent = 'Failed to load events.';
    emptyState.querySelector('[data-empty-subtext]').textContent = 'Please try again later.';
    emptyState.classList.remove('hidden');
  }
};

apiFetch('/auth/me')
  .then((response) => {
    currentUser = response?.data;
    const entities = response?.data?.entities || [];
    entitySelect.innerHTML = '';
    entities.forEach((entity) => {
      const option = document.createElement('option');
      option.value = entity.id;
      option.textContent = entity.name;
      entitySelect.appendChild(option);
    });
    if (entities.length) {
      entitySelect.value = entities[0].id;
      loadEvents();
    }
  })
  .catch((err) => {
    if (err.status === 401 || err.status === 403 || String(err.message).includes('401') || String(err.message).includes('Unauthorized') || String(err.message).includes('Forbidden')) {
      window.location.href = '/login.html';
      return;
    }
    console.error('Failed to load entities:', err);
    entitySelect.innerHTML = '';
    entitySelect.disabled = true;
    emptyState.querySelector('[data-empty-heading]').textContent = 'Unable to load calendar without access.';
    emptyState.querySelector('[data-empty-subtext]').textContent = '';
    emptyState.classList.remove('hidden');
    form.querySelectorAll('input, textarea, select, button').forEach(el => { el.disabled = true; });
  });

entitySelect.addEventListener('change', loadEvents);
form.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (form.dataset.submitting) return;
  if (!entitySelect.value) {
    setStatus('No entity selected.', false);
    return;
  }
  form.dataset.submitting = 'true';
  const submitBtn = form.querySelector('button[type="submit"]');
  if (submitBtn) submitBtn.disabled = true;
  const payload = Object.fromEntries(new FormData(form).entries());
  payload.entity_id = entitySelect.value;
  if (!dateInAllowedRange(payload.event_date || '')) {
    setStatus('Event date must be between years 2000 and 2100.', false);
    eventDateInput.focus();
    form.dataset.submitting = '';
    if (submitBtn) submitBtn.disabled = false;
    return;
  }
  try {
    if (editingEventId) {
        await apiFetch(`/calendar/${editingEventId}`, { method: 'PUT', body: JSON.stringify(payload) });
        setStatus('Event updated successfully.', true);
        window.cancelEdit();
    } else {
        await apiFetch('/calendar', { method: 'POST', body: JSON.stringify(payload) });
        setStatus('Event created successfully.', true);
        form.reset();
    }
    loadEvents();
  } catch (err) {
    setStatus(normalizeError(err), false);
  } finally {
    form.dataset.submitting = '';
    if (submitBtn) submitBtn.disabled = false;
  }
});
