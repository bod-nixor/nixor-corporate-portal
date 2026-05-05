import { apiFetch } from '/assets/app.js';

document.addEventListener('DOMContentLoaded', async () => {
  const urlParams = new URLSearchParams(window.location.search);
  const returnTo = urlParams.get('return_to');
  
  let backUrl = null;
  let backText = 'Back';

  if (returnTo && returnTo.startsWith('/') && !returnTo.startsWith('//')) {
    backUrl = returnTo;
  } else if (document.referrer) {
    try {
      const referrerUrl = new URL(document.referrer);
      if (referrerUrl.origin === window.location.origin) {
        backUrl = referrerUrl.pathname + referrerUrl.search + referrerUrl.hash;
      }
    } catch (e) {
      // invalid referrer
    }
  }

  if (!backUrl) {
    try {
      const response = await apiFetch('/auth/me', { skipFallback: true });
      if (response?.data?.user) {
        backUrl = '/dashboard.html';
      } else {
        backUrl = '/login.html';
      }
    } catch (e) {
      backUrl = '/login.html';
    }
  }

  const normalizedBack = backUrl.split('?')[0];
  if (['/login.html', '/forgot_password.html', '/reset_password.html'].includes(normalizedBack)) {
    backText = 'Back to sign in';
  } else if (normalizedBack === '/dashboard.html' || normalizedBack === '/home.html') {
    backText = 'Back to dashboard';
  } else if (normalizedBack.endsWith('.html') && !['/terms.html', '/privacy.html'].includes(normalizedBack)) {
    backText = 'Back to portal';
  }

  // Ensure terms/privacy page links append return_to if they are present.
  document.querySelectorAll('footer a').forEach(a => {
    const href = a.getAttribute('href');
    if (href === '/terms.html' || href === '/privacy.html') {
      a.href = `${href}?return_to=${encodeURIComponent(backUrl)}`;
    }
  });

  const main = document.querySelector('main');
  if (main) {
    const header = main.querySelector('header');
    
    const backNav = document.createElement('nav');
    backNav.className = 'mb-6 md:mb-8';
    
    const backLink = document.createElement('a');
    backLink.href = backUrl;
    backLink.className = 'inline-flex items-center gap-2 text-sm font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--border-strong)] rounded-md py-1 -ml-1 pl-1 pr-3';
    backLink.innerHTML = `
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="m15 18-6-6 6-6"/>
      </svg>
      <span>${backText}</span>
    `;
    
    backNav.appendChild(backLink);
    
    if (header) {
      main.insertBefore(backNav, header);
    } else {
      main.prepend(backNav);
    }
  }
});
