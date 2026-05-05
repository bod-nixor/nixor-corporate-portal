import { apiFetch } from '/assets/app.js';

const feed = document.getElementById('global-feed');
const empty = document.getElementById('global-empty');

function postTemplate(post) {
  const images = Array.isArray(post.images) ? post.images : [];
  return `
    <article class="card p-5">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="font-bold">${post.full_name || 'NCP User'}</p>
          <p class="text-xs uppercase tracking-wider text-[var(--text-tertiary)]">${post.created_at ? new Date(String(post.created_at).replace(' ', 'T')).toLocaleString() : ''}</p>
        </div>
        <span class="text-xs font-bold text-[var(--text-tertiary)]">${post.likes_count || 0} likes</span>
      </div>
      <div class="mt-4 text-sm leading-relaxed text-[var(--text-primary)]">${post.safe_html || ''}</div>
      ${images.length ? `<div class="mt-4 grid grid-cols-2 gap-3">${images.slice(0, 10).map((image) => `<img src="${image.url}" alt="" loading="lazy" class="aspect-video w-full rounded-lg object-cover border border-[var(--border-subtle)]" />`).join('')}</div>` : ''}
    </article>
  `;
}

apiFetch('/public/social_global', { skipFallback: true })
  .then((response) => {
    const posts = response?.data?.posts || [];
    feed.innerHTML = posts.map(postTemplate).join('');
    empty.classList.toggle('hidden', posts.length > 0);
  })
  .catch(() => {
    feed.innerHTML = '';
    empty.textContent = 'Unable to load the global feed.';
    empty.classList.remove('hidden');
  });
