import { apiFetch } from '/assets/app.js';

const feed = document.getElementById('global-feed');
const empty = document.getElementById('global-empty');

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function safeImageUrl(value) {
  try {
    const url = new URL(String(value || ''), window.location.origin);
    if (!['http:', 'https:'].includes(url.protocol)) {
      return '';
    }
    return url.href;
  } catch (err) {
    return '';
  }
}

function postTemplate(post) {
  const images = Array.isArray(post.images) ? post.images : [];
  const safeImages = images.slice(0, 10).map((image) => safeImageUrl(image?.url ?? image)).filter(Boolean);
  return `
    <article class="card p-5">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="font-bold">${escapeHtml(post.full_name || 'NCP User')}</p>
          <p class="text-xs uppercase tracking-wider text-[var(--text-tertiary)]">${post.created_at ? new Date(String(post.created_at).replace(' ', 'T')).toLocaleString() : ''}</p>
        </div>
        <span class="text-xs font-bold text-[var(--text-tertiary)]">${post.likes_count || 0} likes</span>
      </div>
      <div class="mt-4 text-sm leading-relaxed text-[var(--text-primary)]">${post.safe_html || ''}</div>
      ${safeImages.length ? `<div class="mt-4 grid grid-cols-2 gap-3">${safeImages.map((url) => `<img src="${escapeHtml(url)}" alt="" loading="lazy" class="aspect-video w-full rounded-lg object-cover border border-[var(--border-subtle)]" />`).join('')}</div>` : ''}
    </article>
  `;
}

if (feed && empty) {
  apiFetch('/public/social_global', { skipFallback: true })
    .then((response) => {
      if (!feed || !empty) return;
      const posts = response?.data?.posts || [];
      feed.innerHTML = posts.map(postTemplate).join('');
      empty.classList.toggle('hidden', posts.length > 0);
    })
    .catch(() => {
      if (!feed || !empty) return;
      feed.innerHTML = '';
      empty.textContent = 'Unable to load the global feed.';
      empty.classList.remove('hidden');
    });
}
