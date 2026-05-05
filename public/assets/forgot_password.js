import { apiFetch, bootstrapCsrf, normalizeError } from '/assets/app.js';

const form = document.getElementById('forgot-password-form');
const emailInput = document.getElementById('email-input');
const statusEl = document.getElementById('forgot-status');
const submitBtn = form?.querySelector('button[type="submit"]');
const genericMessage = 'If an account exists, a reset link has been sent.';
const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const setStatus = (message, ok = true) => {
  statusEl.textContent = message;
  statusEl.className = `text-sm rounded-lg px-4 py-3 border ${ok ? 'bg-[rgba(16,185,129,0.1)] text-[#6ee7b7] border-[rgba(16,185,129,0.2)]' : 'bg-[rgba(239,68,68,0.1)] text-[#fca5a5] border-[rgba(239,68,68,0.2)]'}`;
  statusEl.classList.remove('hidden');
};

bootstrapCsrf().catch(() => {
  setStatus('Unable to start a secure reset request. Please refresh the page.', false);
  if (submitBtn) submitBtn.disabled = true;
});

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const email = emailInput.value.trim();
  if (!email || !emailPattern.test(email)) {
    setStatus('Enter a valid email address.', false);
    emailInput.focus();
    return;
  }
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sending...';
  }
  try {
    const response = await apiFetch('/auth/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email })
    });
    setStatus(response?.data?.message || genericMessage, true);
    form.reset();
  } catch (err) {
    setStatus(normalizeError(err), false);
  } finally {
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Send reset link';
    }
  }
});
