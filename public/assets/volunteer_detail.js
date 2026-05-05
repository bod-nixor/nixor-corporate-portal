import { apiFetch } from '/assets/app.js';

const params = new URLSearchParams(window.location.search);
const id = params.get('endeavour_id') || params.get('id') || '';
const loading = document.getElementById('detail-loading');
const content = document.getElementById('detail-content');
const errorEl = document.getElementById('detail-error');
const copyBtn = document.getElementById('copy-link');
const copyStatus = document.getElementById('copy-status');
const loginRegister = document.getElementById('login-register');

const fmt = (value) => value ? new Date(String(value).replace(' ', 'T')).toLocaleString() : 'TBD';

function setText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value || '';
}

async function loadDetail() {
  if (!id) {
    throw new Error('Volunteer opportunity not found.');
  }
  const response = await apiFetch(`/public/volunteer_detail?endeavour_id=${encodeURIComponent(id)}`, { skipCsrf: true });
  const row = response?.data || {};
  setText('detail-entity', row.entity_name);
  setText('detail-title', row.title);
  setText('detail-description', row.long_description || row.description || 'Details will be shared by the entity team.');
  setText('detail-venue', row.venue || 'TBD');
  setText('detail-time', `${fmt(row.start_at)}${row.end_at ? ` to ${fmt(row.end_at)}` : ''}`);
  setText('detail-deadline', fmt(row.volunteer_signup_deadline));
  setText('detail-transport', row.transport_fee_enabled ? `Transport fee: ${row.transport_fee_amount || 'configured'}` : 'No transport fee listed');
  if (loginRegister) {
    loginRegister.href = `/login.html?next=${encodeURIComponent(`/endeavours.html?endeavour_id=${id}`)}`;
  }
  loading.classList.add('hidden');
  content.classList.remove('hidden');
}

copyBtn?.addEventListener('click', async () => {
  const url = window.location.href;
  try {
    await navigator.clipboard.writeText(url);
    copyStatus.textContent = 'Link copied.';
    copyStatus.classList.remove('hidden');
  } catch (err) {
    copyStatus.textContent = url;
    copyStatus.classList.remove('hidden');
  }
});

loadDetail().catch((err) => {
  loading.classList.add('hidden');
  errorEl.textContent = err?.message || 'Unable to load volunteer opportunity.';
  errorEl.classList.remove('hidden');
});
