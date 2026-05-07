import { apiFetch } from "/assets/app.js";

const feed = document.getElementById("global-feed");
const empty = document.getElementById("global-empty");
const loading = document.getElementById("global-loading");
const signInMessage = document.getElementById("public-signin-message");

const icons = {
  like: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 9V5a3 3 0 00-6 0v4M5 21h11.2a2 2 0 001.94-1.52l1.5-6A2 2 0 0017.7 11H13l.55-2.2A2.25 2.25 0 0011.36 6H10M5 21V9H3v12h2z"></path></svg>',
  comment: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8M8 14h5m8-2a8 8 0 11-4.68-7.28L21 4l-1.28 4.68A7.96 7.96 0 0121 12z"></path></svg>'
};

function parseDate(value) {
  const raw = String(value || "");
  return new Date(raw.includes("T") ? raw : raw.replace(" ", "T"));
}

function formatTime(value) {
  const date = parseDate(value);
  if (Number.isNaN(date.getTime())) return "";
  const diff = Date.now() - date.getTime();
  const minute = 60 * 1000;
  const hour = 60 * minute;
  const day = 24 * hour;
  if (diff < minute) return "Just now";
  if (diff < hour) return `${Math.max(1, Math.floor(diff / minute))}m`;
  if (diff < day) return `${Math.floor(diff / hour)}h`;
  if (diff < 7 * day) return `${Math.floor(diff / day)}d`;
  return date.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
}

function initials(name) {
  const parts = String(name || "NCP User").trim().split(/\s+/).slice(0, 2);
  return parts.map((part) => part[0]?.toUpperCase() || "").join("") || "N";
}

function avatarStyle(name) {
  let hash = 0;
  for (const char of String(name || "NCP")) {
    hash = (hash * 31 + char.charCodeAt(0)) % 360;
  }
  return `linear-gradient(135deg, hsl(${hash}, 68%, 54%), hsl(${(hash + 42) % 360}, 64%, 34%))`;
}

function safeImageUrl(value) {
  try {
    const url = new URL(String(value || ""), window.location.origin);
    if (!["http:", "https:"].includes(url.protocol)) return "";
    return url.href;
  } catch (err) {
    return "";
  }
}

function buildAvatar(name, extraClass = "") {
  const avatar = document.createElement("div");
  avatar.className = `social-avatar ${extraClass}`;
  avatar.style.background = avatarStyle(name);
  avatar.textContent = initials(name);
  return avatar;
}

function promptSignIn(action) {
  if (signInMessage) {
    signInMessage.textContent = `Please sign in to ${action}.`;
  }
  document.querySelector('a[href^="/login.html"]')?.focus();
}

function buildImageGrid(images) {
  const safeImages = (Array.isArray(images) ? images : [])
    .map((image) => ({ ...image, url: safeImageUrl(image.url) }))
    .filter((image) => image.url);
  if (!safeImages.length) return null;

  const visible = safeImages.slice(0, 4);
  const grid = document.createElement("div");
  const layout = visible.length === 1 ? "single" : visible.length === 2 ? "pair" : visible.length === 3 ? "three" : "grid";
  grid.className = `social-image-grid social-image-grid--${layout}`;
  visible.forEach((image, index) => {
    const cell = document.createElement("div");
    cell.className = "social-image-cell";
    const img = document.createElement("img");
    img.src = image.url;
    img.alt = image.name || "Post image";
    img.loading = "lazy";
    cell.appendChild(img);
    if (index === visible.length - 1 && safeImages.length > visible.length) {
      const more = document.createElement("div");
      more.className = "social-image-more";
      more.textContent = `+${safeImages.length - visible.length}`;
      cell.appendChild(more);
    }
    grid.appendChild(cell);
  });
  return grid;
}

function renderComment(comment) {
  const row = document.createElement("div");
  row.className = "social-comment";
  row.appendChild(buildAvatar(comment.full_name, "social-comment-avatar"));

  const bodyWrap = document.createElement("div");
  bodyWrap.className = "min-w-0 flex-1";
  const bubble = document.createElement("div");
  bubble.className = "social-comment-bubble";
  const name = document.createElement("p");
  name.className = "social-comment-name";
  name.textContent = comment.full_name || "NCP User";
  const body = document.createElement("div");
  body.className = "social-comment-body";
  if (comment.safe_html) {
    body.innerHTML = comment.safe_html;
  } else {
    body.textContent = comment.comment || "";
  }
  bubble.append(name, body);

  const tools = document.createElement("div");
  tools.className = "social-comment-tools";
  const time = document.createElement("span");
  time.textContent = formatTime(comment.created_at);
  const likes = document.createElement("span");
  likes.textContent = comment.likes_count ? `${comment.likes_count} liked` : "";
  tools.append(time, likes);
  bodyWrap.append(bubble, tools);
  row.appendChild(bodyWrap);
  return row;
}

function buildActionButton(label, icon, action) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = "social-action-button";
  button.innerHTML = `${icon}<span>${label}</span>`;
  button.addEventListener("click", () => promptSignIn(action));
  return button;
}

function renderPost(post, comments) {
  const card = document.createElement("article");
  card.className = "social-post-card";

  const header = document.createElement("header");
  header.className = "social-post-header";
  const author = document.createElement("div");
  author.className = "social-author";
  author.appendChild(buildAvatar(post.full_name));
  const authorText = document.createElement("div");
  authorText.className = "min-w-0";
  const authorName = document.createElement("p");
  authorName.className = "social-author-name truncate";
  authorName.textContent = post.full_name || "NCP User";
  const meta = document.createElement("div");
  meta.className = "social-post-meta";
  const scope = document.createElement("span");
  scope.className = "social-scope-pill";
  scope.textContent = "Global Feed";
  const time = document.createElement("span");
  time.textContent = formatTime(post.created_at);
  meta.append(scope, document.createTextNode("•"), time);
  authorText.append(authorName, meta);
  author.appendChild(authorText);
  header.appendChild(author);
  card.appendChild(header);

  const content = document.createElement("div");
  content.className = "social-post-content";
  if (post.safe_html) {
    content.innerHTML = post.safe_html;
  } else {
    content.textContent = post.content || "";
  }
  card.appendChild(content);

  const images = buildImageGrid(post.images);
  if (images) card.appendChild(images);

  const stats = document.createElement("div");
  stats.className = "social-stats-row";
  const likeCount = document.createElement("span");
  likeCount.textContent = `${post.likes_count || 0} ${Number(post.likes_count || 0) === 1 ? "like" : "likes"}`;
  const commentCount = document.createElement("span");
  commentCount.textContent = `${comments.length} ${comments.length === 1 ? "comment" : "comments"}`;
  stats.append(likeCount, commentCount);
  card.appendChild(stats);

  const actions = document.createElement("div");
  actions.className = "social-actions";
  actions.append(
    buildActionButton("Like", icons.like, "like this update"),
    buildActionButton("Comment", icons.comment, "comment on this update")
  );
  card.appendChild(actions);

  const commentsWrap = document.createElement("div");
  commentsWrap.className = "social-comments";
  comments.forEach((comment) => commentsWrap.appendChild(renderComment(comment)));
  const prompt = document.createElement("button");
  prompt.type = "button";
  prompt.className = "btn btn-secondary w-full";
  prompt.textContent = "Sign in to comment";
  prompt.addEventListener("click", () => promptSignIn("comment on this update"));
  commentsWrap.appendChild(prompt);
  card.appendChild(commentsWrap);

  return card;
}

apiFetch("/public/social_global", { skipFallback: true })
  .then((response) => {
    const posts = response?.data?.posts || [];
    const comments = response?.data?.comments || [];
    const commentsByPost = new Map();
    comments.forEach((comment) => {
      const postId = Number(comment.post_id);
      const list = commentsByPost.get(postId) || [];
      list.push(comment);
      commentsByPost.set(postId, list);
    });
    feed.innerHTML = "";
    posts.forEach((post) => feed.appendChild(renderPost(post, commentsByPost.get(Number(post.id)) || [])));
    empty.classList.toggle("hidden", posts.length > 0);
  })
  .catch(() => {
    feed.innerHTML = "";
    empty.querySelector("p").textContent = "Unable to load the global feed.";
    empty.classList.remove("hidden");
  })
  .finally(() => {
    loading.classList.add("hidden");
  });
