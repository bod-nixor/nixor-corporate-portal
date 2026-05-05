import { apiFetch, bootstrapCsrf, normalizeError } from '/assets/app.js';

const params = new URLSearchParams(window.location.search);
const token = params.get('token') || '';
const sessionMode = params.get('mode') === 'session' && !token;
const form = document.getElementById('reset-password-form');
const title = document.getElementById('reset-title');
const subtitle = document.getElementById('reset-subtitle');
const passwordInput = document.getElementById('password-input');
const confirmInput = document.getElementById('confirm-input');
const statusEl = document.getElementById('reset-status');
const submitBtn = form?.querySelector('button[type="submit"]');
const loginLink = document.getElementById('reset-login-link');
let tokenType = sessionMode ? 'password_setup' : '';

const rules = {
  length: (value) => value.length >= 12,
  uppercase: (value) => /[A-Z]/.test(value),
  lowercase: (value) => /[a-z]/.test(value),
  number: (value) => /\d/.test(value),
  symbol: (value) => /[^A-Za-z0-9\s]/.test(value),
  match: (value, confirmation) => value !== '' && value === confirmation
};

const setStatus = (message, ok = true) => {
  statusEl.textContent = message;
  statusEl.className = `text-sm rounded-lg px-4 py-3 border ${ok ? 'bg-[rgba(16,185,129,0.1)] text-[#6ee7b7] border-[rgba(16,185,129,0.2)]' : 'bg-[rgba(239,68,68,0.1)] text-[#fca5a5] border-[rgba(239,68,68,0.2)]'}`;
  statusEl.classList.remove('hidden');
};

const setFormDisabled = (disabled) => {
  form?.querySelectorAll('input, button').forEach((node) => {
    node.disabled = disabled;
  });
};

const updateRuleUI = () => {
  const password = passwordInput.value;
  const confirmation = confirmInput.value;
  Object.entries(rules).forEach(([rule, test]) => {
    const node = document.querySelector(`[data-rule="${rule}"]`);
    if (!node) return;
    const ok = test(password, confirmation);
    node.classList.toggle('text-[#6ee7b7]', ok);
    node.classList.toggle('text-[var(--text-secondary)]', !ok);
  });
};

const passwordIsValid = () => Object.values(rules).every((test) => test(passwordInput.value, confirmInput.value));

const configureCopy = (type, email = '') => {
  if (type === 'password_setup') {
    title.textContent = sessionMode ? 'Password setup required' : 'Set up your password';
    subtitle.textContent = email ? `Choose a strong password for ${email}.` : 'Choose a strong password for your portal account.';
  } else {
    title.textContent = 'Reset your password';
    subtitle.textContent = email ? `Choose a strong password for ${email}.` : 'Choose a strong replacement password.';
  }
  if (sessionMode && loginLink) {
    loginLink.textContent = 'Go to dashboard';
    loginLink.href = '/dashboard.html';
  }
};

const validateToken = async () => {
  if (sessionMode) {
    configureCopy('password_setup');
    return;
  }
  if (!token) {
    setStatus('Invalid or expired password link.', false);
    setFormDisabled(true);
    return;
  }
  const response = await apiFetch('/auth/reset-password/validate', {
    method: 'POST',
    body: JSON.stringify({ token })
  });
  tokenType = response?.data?.type || 'password_reset';
  configureCopy(tokenType, response?.data?.email || '');
};

bootstrapCsrf()
  .then(validateToken)
  .catch((err) => {
    setStatus(normalizeError(err), false);
    if (!sessionMode) {
      setFormDisabled(true);
    }
  });

passwordInput?.addEventListener('input', updateRuleUI);
confirmInput?.addEventListener('input', updateRuleUI);
updateRuleUI();

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  updateRuleUI();
  if (!passwordIsValid()) {
    setStatus('Password does not meet the listed requirements.', false);
    return;
  }
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.textContent = 'Updating...';
  }
  try {
    const payload = {
      password: passwordInput.value,
      password_confirmation: confirmInput.value
    };
    const endpoint = sessionMode ? '/auth/password/setup' : '/auth/reset-password';
    if (!sessionMode) {
      payload.token = token;
    }
    const response = await apiFetch(endpoint, {
      method: 'POST',
      body: JSON.stringify(payload)
    });
    setStatus(response?.data?.message || 'Password updated.', true);
    form.reset();
    updateRuleUI();
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Password updated';
    }
  } catch (err) {
    setStatus(normalizeError(err), false);
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Update password';
    }
  }
});

const returnTo = encodeURIComponent(window.location.pathname + window.location.search);
const privacyLink = document.getElementById('legal-privacy');
const termsLink = document.getElementById('legal-terms');
if (privacyLink) privacyLink.href = `/privacy.html?return_to=${returnTo}`;
if (termsLink) termsLink.href = `/terms.html?return_to=${returnTo}`;
