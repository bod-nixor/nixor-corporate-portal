import { apiFetch } from '/assets/app.js';
import { renderSidebar } from '/assets/sidebar.js';

document.getElementById('sidebar-container').outerHTML = renderSidebar('volunteering_ops');

const form = document.getElementById('ops-filters');
const clearBtn = document.getElementById('ops-clear');
const body = document.getElementById('ops-body');
const empty = document.getElementById('ops-empty');
const statusEl = document.getElementById('ops-status');

const escapeHtml = (value) => String(value ?? '')
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;');

const setStatus = (message, ok = true) => {
  statusEl.textContent = message;
  statusEl.className = `text-sm font-semibold ${ok ? 'text-[#6ee7b7]' : 'text-[#fca5a5]'}`;
  statusEl.classList.remove('hidden');
};

const queryString = () => {
  const params = new URLSearchParams();
  new FormData(form).forEach((value, key) => {
    const trimmed = String(value || '').trim();
    if (trimmed) params.set(key, trimmed);
  });
  return params.toString();
};

async function loadRows() {
  body.innerHTML = '<tr><td colspan="6" class="px-5 py-8 text-center text-[var(--text-secondary)]">Loading...</td></tr>';
  try {
    const response = await apiFetch(`/endeavours/volunteering_ops${queryString() ? `?${queryString()}` : ''}`);
    const rows = response?.data || [];
    body.innerHTML = '';
    empty.classList.toggle('hidden', rows.length > 0);
    rows.forEach((row) => body.insertAdjacentHTML('beforeend', rowTemplate(row)));
  } catch (err) {
    body.innerHTML = '';
    empty.classList.remove('hidden');
    empty.textContent = err?.message || 'Unable to load volunteering operations.';
  }
}

function rowTemplate(row) {
  const attendance = row.attendance_status || '';
  return `
    <tr class="border-b border-[var(--border-subtle)]" data-registration-id="${Number(row.id)}">
      <td class="px-5 py-4">
        <div class="font-semibold">${escapeHtml(row.student_name || row.full_name || 'Student')}</div>
        <div class="text-xs text-[var(--text-tertiary)]">${escapeHtml(row.student_id || row.email || '')}</div>
      </td>
      <td class="px-5 py-4">${escapeHtml(row.entity_name || '')}</td>
      <td class="px-5 py-4">${escapeHtml(row.endeavour_title || row.endeavour_name || '')}</td>
      <td class="px-5 py-4">
        <select class="input-field py-2" data-field="attendance_status">
          <option value="" ${attendance === '' ? 'selected' : ''}>Unmarked</option>
          ${['present', 'absent'].map(value => `<option value="${value}" ${attendance === value ? 'selected' : ''}>${value}</option>`).join('')}
        </select>
      </td>
      <td class="px-5 py-4">
        <label class="inline-flex items-center gap-2">
          <input type="checkbox" data-field="transport_fee_paid" class="h-5 w-5 accent-[var(--color-primary)]" ${Number(row.transport_fee_paid || 0) ? 'checked' : ''} />
          <span class="text-xs text-[var(--text-secondary)]">Paid</span>
        </label>
      </td>
      <td class="px-5 py-4 text-right">
        <button type="button" class="btn btn-primary py-2 px-3" data-save>Save</button>
      </td>
    </tr>
  `;
}

form.addEventListener('submit', (event) => {
  event.preventDefault();
  loadRows();
});

clearBtn.addEventListener('click', () => {
  form.reset();
  loadRows();
});

body.addEventListener('click', async (event) => {
  const saveBtn = event.target.closest('[data-save]');
  if (!saveBtn) return;
  const row = saveBtn.closest('[data-registration-id]');
  const id = Number(row?.dataset.registrationId || 0);
  if (!id) return;
  const payload = {
    registration_id: id,
    transport_fee_paid: Boolean(row.querySelector('[data-field="transport_fee_paid"]')?.checked)
  };
  const attendance = row.querySelector('[data-field="attendance_status"]')?.value || '';
  payload.attendance_status = attendance;
  saveBtn.disabled = true;
  try {
    await apiFetch('/endeavours/volunteering_ops', {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    setStatus('Saved.');
  } catch (err) {
    setStatus(err?.message || 'Unable to save.', false);
  } finally {
    saveBtn.disabled = false;
  }
});

loadRows();
