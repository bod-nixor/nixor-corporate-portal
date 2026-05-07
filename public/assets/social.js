import { apiFetch, normalizeError } from "/assets/app.js";
import { renderSidebar } from "/assets/sidebar.js";

document.getElementById("sidebar-container").outerHTML = renderSidebar("social");

const MAX_IMAGES = 10;
const MAX_IMAGE_SIZE = 10 * 1024 * 1024;
const ALLOWED_IMAGE_TYPES = new Set(["image/jpeg", "image/png", "image/webp"]);

const entitySelect = document.getElementById("social-entity");
const entityFilterWrap = document.getElementById("entity-filter-wrap");
const postsEl = document.getElementById("social-posts");
const emptyEl = document.getElementById("social-empty");
const emptyTitle = document.getElementById("social-empty-title");
const emptySubtitle = document.getElementById("social-empty-subtitle");
const loadingEl = document.getElementById("social-loading");
const openPostModalBtn = document.getElementById("open-post-modal");
const newPostLabel = document.getElementById("new-post-label");
const postModal = document.getElementById("post-modal");
const modalCard = postModal?.querySelector(".social-modal-card");
const closePostModalBtn = document.getElementById("close-post-modal");
const postModalTitle = document.getElementById("post-modal-title");
const postModalDesc = document.getElementById("post-modal-desc");
const form = document.getElementById("social-form");
const scopeSelect = document.getElementById("social-scope");
const contentInput = document.getElementById("social-content");
const imageInput = document.getElementById("social-images");
const imagePreview = document.getElementById("social-image-preview");
const statusEl = document.getElementById("social-status");
const submitBtn = document.getElementById("social-submit");
const cancelEditBtn = document.getElementById("social-cancel-edit");
const feedTabs = [...document.querySelectorAll(".social-feed-tab")];
const deleteModal = document.getElementById("delete-modal");
const deleteMessage = document.getElementById("delete-message");
const deleteConfirmBtn = document.getElementById("delete-confirm");
const deleteCancelBtn = document.getElementById("delete-cancel");
const deleteCloseBtn = document.getElementById("delete-close");

let activeFeed = new URLSearchParams(window.location.search).get("feed") === "global" ? "global" : "entity";
let commentsByPost = new Map();
let selectedImages = [];
let existingImages = [];
let editingPost = null;
let deletingItem = null;
let isSubmitting = false;
let modalTrigger = null;
let lockedScrollY = 0;
let currentFeedPermissions = {
  scope: activeFeed,
  can_view: false,
  can_post: false,
  can_interact: false,
  can_like: false,
  can_comment: false,
  authenticated: true
};

const icons = {
  like: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 9V5a3 3 0 00-6 0v4M5 21h11.2a2 2 0 001.94-1.52l1.5-6A2 2 0 0017.7 11H13l.55-2.2A2.25 2.25 0 0011.36 6H10M5 21V9H3v12h2z"></path></svg>',
  comment: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8M8 14h5m8-2a8 8 0 11-4.68-7.28L21 4l-1.28 4.68A7.96 7.96 0 0121 12z"></path></svg>',
  copy: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 8h9a2 2 0 012 2v9a2 2 0 01-2 2H8a2 2 0 01-2-2v-9a2 2 0 012-2zm-3 8H4a2 2 0 01-2-2V5a2 2 0 012-2h9a2 2 0 012 2v1"></path></svg>',
  edit: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>',
  trash: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>',
  close: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M6 18L18 6M6 6l12 12"></path></svg>'
};

function setStatus(message, ok = false) {
  if (!statusEl) return;
  statusEl.textContent = message;
  statusEl.className = `text-sm rounded-lg px-4 py-3 font-medium border ${
    ok
      ? "bg-[rgba(16,185,129,0.1)] text-[#6ee7b7] border-[rgba(16,185,129,0.2)]"
      : "bg-[rgba(239,68,68,0.1)] text-[#fca5a5] border-[rgba(239,68,68,0.2)]"
  }`;
  statusEl.classList.remove("hidden");
}

function hideStatus() {
  statusEl?.classList.add("hidden");
}

function defaultFeedPermissions(feed = activeFeed) {
  return {
    scope: feed,
    can_view: false,
    can_post: false,
    can_interact: false,
    can_like: false,
    can_comment: false,
    authenticated: true
  };
}

function postPermissionMessage() {
  return activeFeed === "global"
    ? "Only C-level executives can publish to the Global Feed."
    : "Only executives of the selected entity can publish to its feed.";
}

function updatePostControls() {
  if (newPostLabel) {
    newPostLabel.textContent = activeFeed === "global" ? "New Global Post" : "New Entity Post";
  }
  if (!openPostModalBtn) return;
  const canPost = Boolean(currentFeedPermissions.can_post);
  openPostModalBtn.classList.toggle("hidden", !canPost);
  openPostModalBtn.disabled = !canPost;
  openPostModalBtn.title = canPost ? "" : postPermissionMessage();
}

function applyFeedPermissions(permissions = {}) {
  currentFeedPermissions = {
    ...defaultFeedPermissions(activeFeed),
    ...permissions,
    scope: permissions.scope || activeFeed
  };
  updatePostControls();
}

function canInteractWithRecord(record, action = "interact") {
  const feedAllowed = action === "comment"
    ? currentFeedPermissions.can_comment
    : action === "like"
      ? currentFeedPermissions.can_like
      : currentFeedPermissions.can_interact;
  const recordAllowed = action === "comment"
    ? record?.can_comment
    : action === "like"
      ? record?.can_like
      : record?.can_interact;
  return feedAllowed !== false && recordAllowed !== false;
}

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

function lockBodyScroll() {
  if (document.body.classList.contains("modal-scroll-lock")) return;
  lockedScrollY = window.scrollY || 0;
  document.body.style.position = "fixed";
  document.body.style.top = `-${lockedScrollY}px`;
  document.body.style.left = "0";
  document.body.style.right = "0";
  document.body.style.width = "100%";
  document.body.classList.add("modal-scroll-lock");
}

function unlockBodyScroll() {
  if (!document.body.classList.contains("modal-scroll-lock")) return;
  document.body.classList.remove("modal-scroll-lock");
  document.body.style.position = "";
  document.body.style.top = "";
  document.body.style.left = "";
  document.body.style.right = "";
  document.body.style.width = "";
  window.scrollTo(0, lockedScrollY);
}

function clearSelectedImages() {
  selectedImages.forEach((item) => URL.revokeObjectURL(item.previewUrl));
  selectedImages = [];
}

function imageCount() {
  return existingImages.length + selectedImages.length;
}

function renderImagePreviews() {
  if (!imagePreview) return;
  imagePreview.innerHTML = "";
  const allImages = [
    ...existingImages.map((image) => ({ type: "existing", image })),
    ...selectedImages.map((image) => ({ type: "new", image }))
  ];
  imagePreview.classList.toggle("hidden", allImages.length === 0);
  allImages.forEach((item, index) => {
    const tile = document.createElement("div");
    tile.className = "social-preview-tile";

    const img = document.createElement("img");
    img.src = item.type === "existing" ? safeImageUrl(item.image.url) : item.image.previewUrl;
    img.alt = item.image.name || `Selected image ${index + 1}`;
    tile.appendChild(img);

    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "social-preview-remove";
    remove.setAttribute("aria-label", "Remove image");
    remove.innerHTML = icons.close;
    remove.addEventListener("click", () => {
      if (item.type === "existing") {
        existingImages = existingImages.filter((image) => image.id !== item.image.id);
      } else {
        URL.revokeObjectURL(item.image.previewUrl);
        selectedImages = selectedImages.filter((image) => image !== item.image);
      }
      renderImagePreviews();
    });
    tile.appendChild(remove);
    imagePreview.appendChild(tile);
  });
}

function validateClientImage(file) {
  if (!ALLOWED_IMAGE_TYPES.has(file.type)) {
    return "Only JPG, PNG, and WebP images can be uploaded.";
  }
  if (file.size > MAX_IMAGE_SIZE) {
    return "Each image must be 10MB or smaller.";
  }
  return "";
}

function addSelectedFiles(files) {
  const incoming = [...files];
  if (!incoming.length) return;
  if (imageCount() + incoming.length > MAX_IMAGES) {
    setStatus("Posts may include up to 10 images.", false);
    return;
  }
  for (const file of incoming) {
    const error = validateClientImage(file);
    if (error) {
      setStatus(error, false);
      return;
    }
  }
  incoming.forEach((file) => {
    selectedImages.push({ file, previewUrl: URL.createObjectURL(file), name: file.name });
  });
  hideStatus();
  renderImagePreviews();
}

function openPostModal(post = null, trigger = document.activeElement) {
  if (!post && !currentFeedPermissions.can_post) {
    setStatus(postPermissionMessage(), false);
    return;
  }
  modalTrigger = trigger;
  editingPost = post;
  isSubmitting = false;
  hideStatus();
  clearSelectedImages();
  existingImages = Array.isArray(post?.images) ? post.images.map((image) => ({ ...image })) : [];
  form?.reset();
  if (contentInput) contentInput.value = post?.content || "";
  if (scopeSelect) {
    scopeSelect.value = post?.feed_scope || activeFeed;
    scopeSelect.disabled = true;
  }
  if (postModalTitle) postModalTitle.textContent = post ? "Edit Post" : "New Post";
  if (postModalDesc) {
    postModalDesc.textContent = post
      ? "Update the post content and attached images."
      : "Share an update with the selected feed.";
  }
  if (submitBtn) submitBtn.textContent = post ? "Save Changes" : "Publish Update";
  cancelEditBtn?.classList.toggle("hidden", !post);
  renderImagePreviews();
  postModal?.classList.remove("hidden");
  lockBodyScroll();
  requestAnimationFrame(() => {
    modalCard?.focus();
    contentInput?.focus();
  });
}

function closePostModal() {
  if (isSubmitting) return;
  postModal?.classList.add("hidden");
  unlockBodyScroll();
  editingPost = null;
  existingImages = [];
  clearSelectedImages();
  form?.reset();
  if (scopeSelect) scopeSelect.disabled = false;
  cancelEditBtn?.classList.add("hidden");
  hideStatus();
  if (modalTrigger && typeof modalTrigger.focus === "function") {
    modalTrigger.focus();
  }
}

function setActiveFeed(feed, options = {}) {
  activeFeed = feed === "global" ? "global" : "entity";
  applyFeedPermissions(defaultFeedPermissions(activeFeed));
  feedTabs.forEach((tab) => {
    const selected = tab.dataset.feed === activeFeed;
    tab.setAttribute("aria-selected", selected ? "true" : "false");
  });
  entityFilterWrap?.classList.toggle("hidden", activeFeed === "global");
  updatePostControls();
  if (scopeSelect && !editingPost) scopeSelect.value = activeFeed;
  const url = new URL(window.location.href);
  if (activeFeed === "global") {
    url.searchParams.set("feed", "global");
  } else {
    url.searchParams.delete("feed");
  }
  window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
  if (!options.skipLoad) loadPosts();
}

function setEmptyState(kind) {
  if (kind === "global") {
    emptyTitle.textContent = "No global updates yet.";
    emptySubtitle.textContent = "Share a public update for the wider NCP community.";
  } else if (kind === "error") {
    emptyTitle.textContent = "Failed to load posts.";
    emptySubtitle.textContent = "Please try again later.";
  } else if (kind === "no-entity") {
    emptyTitle.textContent = "No entity feed is available.";
    emptySubtitle.textContent = "Switch to the global feed or ask an admin to verify your entity access.";
  } else {
    emptyTitle.textContent = "No posts yet for this entity.";
    emptySubtitle.textContent = "Share the first professional update with this feed.";
  }
}

function buildAvatar(name, extraClass = "") {
  const avatar = document.createElement("div");
  avatar.className = `social-avatar ${extraClass}`;
  avatar.style.background = avatarStyle(name);
  avatar.textContent = initials(name);
  return avatar;
}

function buildManageActions(post) {
  const actions = document.createElement("div");
  actions.className = "flex shrink-0 items-center gap-1";

  const editBtn = document.createElement("button");
  editBtn.type = "button";
  editBtn.className = "btn btn-ghost px-2 py-1 h-auto";
  editBtn.title = "Edit post";
  editBtn.setAttribute("aria-label", "Edit post");
  editBtn.innerHTML = icons.edit;
  editBtn.addEventListener("click", () => openPostModal(post, editBtn));

  const deleteBtn = document.createElement("button");
  deleteBtn.type = "button";
  deleteBtn.className = "btn btn-ghost px-2 py-1 h-auto text-[var(--color-danger)]";
  deleteBtn.title = "Delete post";
  deleteBtn.setAttribute("aria-label", "Delete post");
  deleteBtn.innerHTML = icons.trash;
  deleteBtn.addEventListener("click", () => openDeleteModal("post", post.id, post.content));

  actions.append(editBtn, deleteBtn);
  return actions;
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

async function toggleLike(button, targetType, id) {
  if (button.disabled) return;
  button.disabled = true;
  try {
    const url = targetType === "comment" ? `/social/comments/${id}/like` : `/social/${id}/like`;
    await apiFetch(url, { method: "POST", body: JSON.stringify({}) });
    await loadPosts();
  } catch (err) {
    setStatus(normalizeError(err), false);
  } finally {
    button.disabled = false;
  }
}

function focusCommentInput(postId) {
  const input = document.getElementById(`comment-input-${postId}`);
  input?.focus();
}

function copyPostLink(postId) {
  const url = new URL(window.location.href);
  url.hash = `post-${postId}`;
  navigator.clipboard?.writeText(url.href).catch(() => {});
}

function buildActionButton(label, icon, options = {}) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = `social-action-button ${options.active ? "is-active" : ""}`;
  if (options.pressed !== undefined) {
    button.setAttribute("aria-pressed", options.pressed ? "true" : "false");
  }
  button.innerHTML = `${icon}<span>${label}</span>`;
  return button;
}

function renderComment(comment) {
  const row = document.createElement("div");
  row.className = "social-comment group/comment";

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
  tools.appendChild(time);

  if (canInteractWithRecord(comment, "like")) {
    const like = document.createElement("button");
    like.type = "button";
    like.className = `social-comment-like ${comment.liked_by_me ? "is-active" : ""}`;
    like.setAttribute("aria-pressed", comment.liked_by_me ? "true" : "false");
    like.textContent = `${comment.liked_by_me ? "Liked" : "Like"}${comment.likes_count ? ` (${comment.likes_count})` : ""}`;
    like.addEventListener("click", () => toggleLike(like, "comment", comment.id));
    tools.appendChild(like);
  }

  if (comment.can_manage) {
    const edit = document.createElement("button");
    edit.type = "button";
    edit.textContent = "Edit";
    edit.addEventListener("click", () => {
      bubble.innerHTML = "";
      const editForm = document.createElement("form");
      editForm.className = "flex flex-col sm:flex-row gap-2";
      const input = document.createElement("input");
      input.className = "input-field py-2 text-sm flex-1";
      input.value = comment.comment || "";
      const save = document.createElement("button");
      save.type = "submit";
      save.className = "btn btn-secondary px-3 py-2 text-xs";
      save.textContent = "Save";
      const cancel = document.createElement("button");
      cancel.type = "button";
      cancel.className = "btn btn-ghost px-3 py-2 text-xs";
      cancel.textContent = "Cancel";
      cancel.addEventListener("click", loadPosts);
      editForm.append(input, save, cancel);
      editForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const nextValue = input.value.trim();
        if (!nextValue) return;
        save.disabled = true;
        try {
          await apiFetch(`/social/comments/${comment.id}`, {
            method: "PUT",
            body: JSON.stringify({ comment: nextValue })
          });
          await loadPosts();
        } catch (err) {
          setStatus(normalizeError(err), false);
        } finally {
          save.disabled = false;
        }
      });
      bubble.appendChild(editForm);
      input.focus();
    });
    const del = document.createElement("button");
    del.type = "button";
    del.textContent = "Delete";
    del.className = "text-[var(--color-danger)]";
    del.addEventListener("click", () => openDeleteModal("comment", comment.id, comment.comment));
    tools.append(edit, del);
  }

  bodyWrap.append(bubble, tools);
  row.appendChild(bodyWrap);
  return row;
}

function renderPost(post) {
  const card = document.createElement("article");
  card.id = `post-${post.id}`;
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
  scope.textContent = post.feed_scope === "global" ? "Global Feed" : post.entity_name || "Entity Feed";
  const time = document.createElement("span");
  time.textContent = formatTime(post.created_at);
  meta.append(scope, document.createTextNode("•"), time);
  authorText.append(authorName, meta);
  author.appendChild(authorText);
  header.appendChild(author);
  if (post.can_manage) header.appendChild(buildManageActions(post));
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

  const comments = commentsByPost.get(Number(post.id)) || [];
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
  const canLike = canInteractWithRecord(post, "like");
  const canComment = canInteractWithRecord(post, "comment");
  if (canLike) {
    const likeButton = buildActionButton(post.liked_by_me ? "Liked" : "Like", icons.like, {
      active: post.liked_by_me,
      pressed: post.liked_by_me
    });
    likeButton.addEventListener("click", () => toggleLike(likeButton, "post", post.id));
    actions.appendChild(likeButton);
  }
  if (canComment) {
    const commentButton = buildActionButton("Comment", icons.comment);
    commentButton.addEventListener("click", () => focusCommentInput(post.id));
    actions.appendChild(commentButton);
  }
  const copyButton = buildActionButton("Copy Link", icons.copy);
  copyButton.addEventListener("click", () => copyPostLink(post.id));
  actions.appendChild(copyButton);
  card.appendChild(actions);

  const commentsWrap = document.createElement("div");
  commentsWrap.className = "social-comments";
  comments.forEach((comment) => commentsWrap.appendChild(renderComment(comment)));

  if (canComment) {
    const commentForm = document.createElement("form");
    commentForm.className = "social-comment-form";
    const commentInput = document.createElement("input");
    commentInput.id = `comment-input-${post.id}`;
    commentInput.className = "input-field font-medium py-2.5 px-4 text-sm flex-1 bg-[var(--bg-base)]";
    commentInput.placeholder = "Add a comment...";
    commentInput.autocomplete = "off";
    const commentSubmit = document.createElement("button");
    commentSubmit.className = "btn btn-secondary px-4 py-2.5 text-sm w-full sm:w-auto";
    commentSubmit.type = "submit";
    commentSubmit.textContent = "Comment";
    commentSubmit.disabled = true;
    commentInput.addEventListener("input", () => {
      commentSubmit.disabled = commentInput.value.trim().length === 0;
    });
    commentForm.append(commentInput, commentSubmit);
    commentForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const value = commentInput.value.trim();
      if (!value) return;
      commentSubmit.disabled = true;
      try {
        await apiFetch(`/social/${post.id}/comments`, {
          method: "POST",
          body: JSON.stringify({ comment: value })
        });
        commentInput.value = "";
        await loadPosts();
      } catch (err) {
        setStatus(normalizeError(err), false);
      } finally {
        commentSubmit.disabled = commentInput.value.trim().length === 0;
      }
    });
    commentsWrap.appendChild(commentForm);
  } else {
    const note = document.createElement("p");
    note.className = "text-xs font-semibold text-[var(--text-tertiary)]";
    note.textContent = "You can view this feed, but comments are restricted for your role.";
    commentsWrap.appendChild(note);
  }
  card.appendChild(commentsWrap);

  return card;
}

async function loadPosts() {
  postsEl.innerHTML = "";
  emptyEl.classList.add("hidden");
  loadingEl.classList.remove("hidden");

  const entityId = entitySelect.value;
  if (activeFeed === "entity" && !entityId) {
    loadingEl.classList.add("hidden");
    applyFeedPermissions(defaultFeedPermissions(activeFeed));
    setEmptyState("no-entity");
    emptyEl.classList.remove("hidden");
    return;
  }

  try {
    const url = activeFeed === "global" ? "/social/global" : `/social?entity_id=${encodeURIComponent(entityId)}`;
    const response = await apiFetch(url);
    applyFeedPermissions(response?.meta?.permissions || response?.data?.permissions || {});
    const posts = response?.data?.posts || [];
    const comments = response?.data?.comments || [];
    commentsByPost = new Map();
    comments.forEach((comment) => {
      const postId = Number(comment.post_id);
      const list = commentsByPost.get(postId) || [];
      list.push(comment);
      commentsByPost.set(postId, list);
    });

    if (!posts.length) {
      setEmptyState(activeFeed);
      emptyEl.classList.remove("hidden");
      return;
    }

    posts.forEach((post) => postsEl.appendChild(renderPost(post)));
    if (/^#post-\d+$/.test(window.location.hash)) {
      document.querySelector(window.location.hash)?.scrollIntoView({ block: "center" });
    }
  } catch (err) {
    console.error("Failed to load social posts:", err);
    applyFeedPermissions(defaultFeedPermissions(activeFeed));
    setEmptyState("error");
    emptyEl.classList.remove("hidden");
  } finally {
    loadingEl.classList.add("hidden");
  }
}

function openDeleteModal(type, id, text) {
  deletingItem = { type, id };
  const preview = String(text || "").slice(0, 120);
  deleteMessage.textContent = `Delete this ${type}${preview ? `: "${preview}${String(text || "").length > 120 ? "..." : ""}"` : ""}?`;
  deleteModal.classList.remove("hidden");
  deleteCancelBtn?.focus();
}

function closeDeleteModal() {
  deleteModal.classList.add("hidden");
  deletingItem = null;
}

async function submitPost(event) {
  event.preventDefault();
  if (isSubmitting) return;
  isSubmitting = true;
  submitBtn.disabled = true;
  closePostModalBtn.disabled = true;

  try {
    const formData = new FormData();
    const content = contentInput.value.trim();
    if (!content) {
      setStatus("Post content is required.", false);
      return;
    }
    formData.append("content", content);
    existingImages.forEach((image) => formData.append("keep_image_ids[]", String(image.id)));
    selectedImages.forEach((image) => formData.append("images[]", image.file, image.file.name));

    if (editingPost) {
      await apiFetch(`/social/${editingPost.id}/update`, { method: "POST", body: formData });
      closePostModal();
      await loadPosts();
      return;
    }

    const feedScope = scopeSelect.value === "global" ? "global" : "entity";
    if (!currentFeedPermissions.can_post || feedScope !== activeFeed) {
      setStatus(postPermissionMessage(), false);
      return;
    }
    formData.append("feed_scope", feedScope);
    if (feedScope === "entity") {
      const entityId = entitySelect.value;
      if (!entityId) {
        setStatus("Choose an entity before publishing to an entity feed.", false);
        return;
      }
      formData.append("entity_id", entityId);
    }
    await apiFetch("/social", { method: "POST", body: formData });
    closePostModal();
    setActiveFeed(feedScope, { skipLoad: true });
    await loadPosts();
  } catch (err) {
    setStatus(normalizeError(err), false);
  } finally {
    isSubmitting = false;
    submitBtn.disabled = false;
    closePostModalBtn.disabled = false;
  }
}

feedTabs.forEach((tab) => {
  tab.addEventListener("click", () => setActiveFeed(tab.dataset.feed));
});

openPostModalBtn?.addEventListener("click", () => openPostModal(null, openPostModalBtn));
closePostModalBtn?.addEventListener("click", closePostModal);
cancelEditBtn?.addEventListener("click", closePostModal);
postModal?.addEventListener("click", (event) => {
  if (event.target === postModal) closePostModal();
});
imageInput?.addEventListener("change", () => {
  addSelectedFiles(imageInput.files || []);
  imageInput.value = "";
});
entitySelect?.addEventListener("change", loadPosts);
form?.addEventListener("submit", submitPost);

deleteCancelBtn?.addEventListener("click", closeDeleteModal);
deleteCloseBtn?.addEventListener("click", closeDeleteModal);
deleteModal?.addEventListener("click", (event) => {
  if (event.target === deleteModal) closeDeleteModal();
});
deleteConfirmBtn?.addEventListener("click", async () => {
  if (!deletingItem) return;
  deleteConfirmBtn.disabled = true;
  try {
    const url = deletingItem.type === "comment" ? `/social/comments/${deletingItem.id}` : `/social/${deletingItem.id}`;
    await apiFetch(url, { method: "DELETE" });
    closeDeleteModal();
    await loadPosts();
  } catch (err) {
    deleteMessage.textContent = normalizeError(err);
  } finally {
    deleteConfirmBtn.disabled = false;
  }
});

document.addEventListener("keydown", (event) => {
  if (!postModal?.classList.contains("hidden")) {
    if (event.key === "Escape" && !isSubmitting) {
      event.preventDefault();
      closePostModal();
    }
    if (event.key === "Tab") {
      const focusable = [...postModal.querySelectorAll('button:not(:disabled), [href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])')]
        .filter((el) => el.offsetParent !== null || el === modalCard);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (!first || !last) return;
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  }
  if (!deleteModal?.classList.contains("hidden") && event.key === "Escape") {
    closeDeleteModal();
  }
});

apiFetch("/auth/me")
  .then((response) => {
    const entities = response?.data?.entities || [];
    entitySelect.innerHTML = "";
    entities.forEach((entity) => {
      const option = document.createElement("option");
      option.value = entity.id;
      option.textContent = entity.name;
      entitySelect.appendChild(option);
    });
    if (!entities.length) {
      const option = document.createElement("option");
      option.value = "";
      option.textContent = "No entities available";
      entitySelect.appendChild(option);
    }
    setActiveFeed(activeFeed, { skipLoad: true });
    loadPosts();
  })
  .catch((err) => {
    if (err.status === 401 || err.status === 403 || /Unauthorized|Forbidden/.test(String(err.message))) {
      window.location.replace("/login.html?next=/social.html");
      return;
    }
    console.error("Failed to load social access:", err);
    setEmptyState("error");
    emptyEl.classList.remove("hidden");
  });
