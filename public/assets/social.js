import { apiFetch, getPublicBaseUrl, loadConfig, normalizeError } from "/assets/app.js";
import { renderSidebar } from "/assets/sidebar.js";

const MAX_IMAGES = 10;
const MAX_IMAGE_SIZE = 10 * 1024 * 1024;
const ALLOWED_IMAGE_TYPES = new Set(["image/jpeg", "image/png", "image/webp"]);

const initialParams = new URLSearchParams(window.location.search);
const sidebarMount = document.getElementById("sidebar-container");
const entitySelect = document.getElementById("social-entity");
const entityFilterWrap = document.getElementById("entity-filter-wrap");
const postsEl = document.getElementById("social-posts");
const emptyEl = document.getElementById("social-empty");
const emptyTitle = document.getElementById("social-empty-title");
const emptySubtitle = document.getElementById("social-empty-subtitle");
const loadingEl = document.getElementById("social-loading");
const pageHeader = document.querySelector(".app-page-header");
const feedShell = document.querySelector(".social-feed-shell");
const detailView = document.getElementById("social-detail-view");
const detailBackBtn = document.getElementById("social-detail-back");
const detailStatus = document.getElementById("social-detail-status");
const detailContent = document.getElementById("social-detail-content");
const openPostModalBtn = document.getElementById("open-post-modal");
const signInButton = document.getElementById("social-signin-button");
const feedNotice = document.getElementById("social-feed-notice");
const copyLive = document.getElementById("social-copy-live");
const newPostLabel = document.getElementById("new-post-label");
const postModal = document.getElementById("post-modal");
const modalCard = postModal?.querySelector(".social-modal-card");
const closePostModalBtn = document.getElementById("close-post-modal");
const postModalTitle = document.getElementById("post-modal-title");
const postModalDesc = document.getElementById("post-modal-desc");
const form = document.getElementById("social-form");
const scopeSelect = document.getElementById("social-scope");
const postAsWrap = document.getElementById("social-post-as-wrap");
const postAsSelect = document.getElementById("social-post-as");
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

if (postModal && postModal.parentElement !== document.body) {
  document.body.appendChild(postModal);
}

let activeFeed = initialParams.get("feed") === "global" ? "global" : "entity";
let commentsByPost = new Map();
let postsById = new Map();
let selectedImages = [];
let existingImages = [];
let editingPost = null;
let deletingItem = null;
let deleteTrigger = null;
let isSubmitting = false;
let modalTrigger = null;
let lockedScrollY = 0;
let currentUser = null;
let isAuthenticated = false;
let currentDetailPostId = 0;
let currentDetailPostKey = "";
let isShowingPostDetail = false;
let lastFeedUrl = "";
let pendingCommentKey = new URLSearchParams(window.location.search).get("c") || new URLSearchParams(window.location.search).get("comment") || "";
const expandedReplyThreads = new Set();
let mentionAbort = null;
let currentFeedPermissions = {
  scope: activeFeed,
  can_view: false,
  can_post: false,
  can_interact: false,
  can_like: false,
  can_comment: false,
  authenticated: false
};

const icons = {
  like: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 9V5a3 3 0 00-6 0v4M5 21h11.2a2 2 0 001.94-1.52l1.5-6A2 2 0 0017.7 11H13l.55-2.2A2.25 2.25 0 0011.36 6H10M5 21V9H3v12h2z"></path></svg>',
  comment: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8M8 14h5m8-2a8 8 0 11-4.68-7.28L21 4l-1.28 4.68A7.96 7.96 0 0121 12z"></path></svg>',
  copy: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 8h9a2 2 0 012 2v9a2 2 0 01-2 2H8a2 2 0 01-2-2v-9a2 2 0 012-2zm-3 8H4a2 2 0 01-2-2V5a2 2 0 012-2h9a2 2 0 012 2v1"></path></svg>',
  check: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.4" d="M5 13l4 4L19 7"></path></svg>',
  reply: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l-4-4m0 0l4-4m-4 4h10a5 5 0 015 5v1"></path></svg>',
  edit: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>',
  trash: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>',
  more: '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.6" d="M12 6.75h.01M12 12h.01M12 17.25h.01"></path></svg>',
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

function parsePositiveId(value) {
  const id = Number.parseInt(String(value || ""), 10);
  return Number.isFinite(id) && id > 0 ? id : 0;
}

function routePostKey() {
  const params = new URLSearchParams(window.location.search);
  return params.get("p") || params.get("post") || "";
}

function routeCommentKey() {
  const params = new URLSearchParams(window.location.search);
  return params.get("c") || params.get("comment") || "";
}

function routeEntityKey() {
  const params = new URLSearchParams(window.location.search);
  return params.get("e") || params.get("entity_public_id") || params.get("entity_id") || "";
}

function postKey(postOrId) {
  if (typeof postOrId === "object" && postOrId) {
    return String(postOrId.public_id || postOrId.id || "");
  }
  return String(postOrId || "");
}

function commentKey(commentOrId) {
  if (typeof commentOrId === "object" && commentOrId) {
    return String(commentOrId.public_id || commentOrId.id || "");
  }
  return String(commentOrId || "");
}

function feedUrlForState(feed = activeFeed, entityId = entitySelect?.value || "") {
  const url = new URL("/social.html", window.location.origin);
  if (feed === "global") {
    url.searchParams.set("feed", "global");
  } else if (entityId) {
    url.searchParams.set("feed", "entity");
    url.searchParams.set("e", String(entityId));
  }
  return `${url.pathname}${url.search}`;
}

function postUrl(postOrId, commentOrId = null) {
  const post = typeof postOrId === "object" && postOrId ? postOrId : { id: postOrId, feed_scope: activeFeed, entity_id: entitySelect?.value };
  const publicBaseUrl = getPublicBaseUrl();
  const normalizedPublicBaseUrl = publicBaseUrl.endsWith("/") ? publicBaseUrl : `${publicBaseUrl}/`;
  const url = new URL("social.html", normalizedPublicBaseUrl);
  url.searchParams.set("feed", post.feed_scope === "global" ? "global" : "entity");
  const entityPublicId = post.entity_public_id || (post.feed_scope !== "global" ? entitySelect?.value : "");
  if (post.feed_scope !== "global" && entityPublicId) {
    url.searchParams.set("e", String(entityPublicId));
  }
  url.searchParams.set("p", postKey(post));
  const cKey = commentKey(commentOrId);
  if (cKey) url.searchParams.set("c", cKey);
  return url.href;
}

function setFeedNotice(message) {
  if (!feedNotice) return;
  feedNotice.textContent = message;
  feedNotice.classList.toggle("hidden", !message);
}

function defaultFeedPermissions(feed = activeFeed) {
  return {
    scope: feed,
    can_view: false,
    can_post: false,
    can_interact: false,
    can_like: false,
    can_comment: false,
    authenticated: isAuthenticated
  };
}

function loginUrlForFeed(feed = activeFeed) {
  const next = feed === "global" ? "/social.html?feed=global" : "/social.html";
  return `/login.html?next=${encodeURIComponent(next)}`;
}

function loginUrlForPost(postOrId) {
  const url = new URL(postUrl(postOrId));
  return `/login.html?next=${encodeURIComponent(`${url.pathname}${url.search}${url.hash}`)}`;
}

function publicGlobalMode() {
  return activeFeed === "global" && !isAuthenticated;
}

function userRequiresPasswordSetup(user) {
  return Number(user?.force_password_reset || 0) === 1 || Number(user?.password_setup_required || 0) === 1;
}

function renderAuthenticatedShell() {
  document.body.classList.remove("social-public-mode");
  if (sidebarMount && !document.getElementById("sidebar")) {
    sidebarMount.outerHTML = renderSidebar("social");
  }
}

function renderPublicShell() {
  document.body.classList.add("social-public-mode");
  sidebarMount?.remove();
}

function promptSignIn(action = "continue", nextUrl = loginUrlForFeed("global")) {
  if (feedNotice) {
    feedNotice.textContent = `Sign in to ${action}.`;
    feedNotice.classList.remove("hidden");
  }
  if (signInButton) {
    signInButton.href = nextUrl;
    signInButton.classList.remove("hidden");
  }
}

function postPermissionMessage() {
  return activeFeed === "global"
    ? "Only C-level executives can publish to the Global Feed."
    : "Only executives of the selected entity can publish to its feed.";
}

function updatePostControls() {
  const publicGlobal = publicGlobalMode();
  if (newPostLabel) {
    newPostLabel.textContent = activeFeed === "global" ? "New Global Post" : "New Entity Post";
  }
  if (signInButton) {
    signInButton.href = loginUrlForFeed("global");
    signInButton.classList.toggle("hidden", !publicGlobal);
  }
  if (!openPostModalBtn) return;
  const canPost = Boolean(currentFeedPermissions.can_post);
  openPostModalBtn.classList.toggle("hidden", !canPost || publicGlobal);
  openPostModalBtn.disabled = !canPost;
  openPostModalBtn.title = canPost ? "" : postPermissionMessage();
}

function updateFeedNotice() {
  if (!feedNotice) return;
  let message = "";
  if (publicGlobalMode()) {
    message = "Public view: global posts are read-only. Sign in to like or comment.";
  } else if (activeFeed === "global" && isAuthenticated && !currentFeedPermissions.can_post) {
    message = "Only C-level executives can post to the Global Feed.";
  }
  feedNotice.textContent = message;
  feedNotice.classList.toggle("hidden", !message);
}

function populatePostAsOptions() {
  if (!postAsSelect || !postAsWrap) return;
  const postingEntities = Array.isArray(currentFeedPermissions.posting_entities) ? currentFeedPermissions.posting_entities : [];
  postAsSelect.innerHTML = "";
  postingEntities.forEach((entity) => {
    const option = document.createElement("option");
    option.value = entity.public_id || entity.id;
    option.textContent = entity.name || "Entity";
    postAsSelect.appendChild(option);
  });
  postAsWrap.classList.toggle("hidden", activeFeed !== "global" || postingEntities.length <= 1);
}

function applyFeedPermissions(permissions = {}) {
  currentFeedPermissions = {
    ...defaultFeedPermissions(activeFeed),
    ...permissions,
    scope: permissions.scope || activeFeed,
    authenticated: Boolean(permissions.authenticated ?? isAuthenticated)
  };
  updatePostControls();
  updateFeedNotice();
  populatePostAsOptions();
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
  const modalFeed = post?.feed_scope || activeFeed;
  modalTrigger = trigger;
  editingPost = post;
  isSubmitting = false;
  hideStatus();
  clearSelectedImages();
  existingImages = Array.isArray(post?.images) ? post.images.map((image) => ({ ...image })) : [];
  form?.reset();
  if (contentInput) contentInput.value = post?.content || "";
  if (contentInput) syncMentionPublicIdsFromText(contentInput);
  if (scopeSelect) {
    scopeSelect.value = modalFeed;
    scopeSelect.disabled = true;
  }
  populatePostAsOptions();
  if (postAsSelect && post?.entity_public_id) {
    postAsSelect.value = post.entity_public_id;
  }
  if (postModalTitle) {
    postModalTitle.textContent = post ? "Edit Post" : modalFeed === "global" ? "New Global Post" : "New Entity Post";
  }
  if (postModalDesc) {
    postModalDesc.textContent = post
      ? "Update the post content and attached images."
      : modalFeed === "global"
        ? "Share a public update with the Global Feed."
        : "Share an update with the selected entity feed.";
  }
  if (submitBtn) {
    submitBtn.textContent = post ? "Save Changes" : modalFeed === "global" ? "Publish Global Post" : "Publish Entity Post";
  }
  cancelEditBtn?.classList.toggle("hidden", !post);
  renderImagePreviews();
  postModal?.classList.remove("hidden");
  lockBodyScroll();
  requestAnimationFrame(() => {
    modalCard?.focus();
    contentInput?.focus();
  });
}

function closePostModal(force = false) {
  if (isSubmitting && !force) return;
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
  if (activeFeed === "entity" && !isAuthenticated) {
    window.location.href = loginUrlForFeed("entity");
    return;
  }
  document.body.classList.toggle("social-public-mode", publicGlobalMode());
  applyFeedPermissions(defaultFeedPermissions(activeFeed));
  feedTabs.forEach((tab) => {
    const selected = tab.dataset.feed === activeFeed;
    tab.setAttribute("aria-selected", selected ? "true" : "false");
  });
  entityFilterWrap?.classList.toggle("hidden", activeFeed === "global");
  updatePostControls();
  updateFeedNotice();
  if (scopeSelect && !editingPost) scopeSelect.value = activeFeed;
  const nextFeedUrl = feedUrlForState(activeFeed, entitySelect?.value || "");
  lastFeedUrl = nextFeedUrl;
  if (!isShowingPostDetail) {
    window.history.replaceState({ view: "feed" }, "", nextFeedUrl);
  }
  if (!options.skipLoad) loadPosts();
}

function setEmptyState(kind) {
  if (kind === "global") {
    emptyTitle.textContent = "No posts yet.";
    emptySubtitle.textContent = publicGlobalMode()
      ? "Sign in to like or comment."
      : currentFeedPermissions.can_post
        ? "Share a public update for the wider NCP community."
        : "Only C-level executives can post to the Global Feed.";
  } else if (kind === "error") {
    emptyTitle.textContent = "Failed to load posts.";
    emptySubtitle.textContent = "Please try again later.";
  } else if (kind === "no-entity") {
    emptyTitle.textContent = "No entity feed is available.";
    emptySubtitle.textContent = "Switch to the global feed or ask an admin to verify your entity access.";
  } else {
    emptyTitle.textContent = "No posts yet.";
    emptySubtitle.textContent = "Share the first professional update with this feed.";
  }
}

function buildAvatar(name, extraClass = "", imageUrl = "") {
  const avatar = document.createElement("div");
  avatar.className = `social-avatar ${extraClass}`;
  const safeUrl = safeImageUrl(imageUrl);
  if (safeUrl) {
    const img = document.createElement("img");
    img.src = safeUrl;
    img.alt = "";
    img.loading = "lazy";
    avatar.appendChild(img);
    return avatar;
  }
  avatar.style.background = avatarStyle(name);
  avatar.textContent = initials(name);
  return avatar;
}

function closePostActionMenus(except = null) {
  document.querySelectorAll(".social-post-menu-wrap.is-open").forEach((wrap) => {
    if (except && wrap === except) return;
    const button = wrap.querySelector(".social-post-menu-button");
    const hadFocus = wrap.contains(document.activeElement);
    wrap.classList.remove("is-open");
    button?.setAttribute("aria-expanded", "false");
    if (hadFocus) button?.focus();
  });
}

function buildMenuItem(label, icon, handler, options = {}) {
  const item = document.createElement("button");
  item.type = "button";
  item.className = `social-post-menu-item ${options.danger ? "is-danger" : ""}`;
  item.setAttribute("role", "menuitem");
  item.dataset.originalLabel = label;
  item.dataset.originalIcon = icon;
  item.innerHTML = `${icon}<span>${label}</span>`;
  item.addEventListener("click", (event) => {
    event.preventDefault();
    event.stopPropagation();
    if (!options.keepOpen) {
      closePostActionMenus();
    }
    const overflowButtonElement = item.closest(".social-post-menu-wrap")?.querySelector(".social-post-menu-button");
    handler(item, overflowButtonElement || item);
  });
  return item;
}

async function copyUrlToClipboard(href) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(href);
    return;
  }
  const input = document.createElement("textarea");
  input.value = href;
  input.setAttribute("readonly", "");
  input.style.position = "fixed";
  input.style.opacity = "0";
  document.body.appendChild(input);
  input.select();
  const ok = document.execCommand("copy");
  input.remove();
  if (!ok) {
    throw new Error("Clipboard unavailable");
  }
}

function showCopiedState(item) {
  const originalIcon = item.dataset.originalIcon || icons.copy;
  const originalLabel = item.dataset.originalLabel || "Copy link";
  item.classList.add("is-copied");
  item.innerHTML = `${icons.check}<span>Copied</span>`;
  copyLive && (copyLive.textContent = "Link copied");
  window.setTimeout(() => {
    item.classList.remove("is-copied");
    item.innerHTML = `${originalIcon}<span>${originalLabel}</span>`;
  }, 1700);
}

function showCopyError(item, href) {
  item.classList.add("is-error");
  item.innerHTML = `${icons.copy}<span>Copy failed</span>`;
  copyLive && (copyLive.textContent = `Copy failed. Link: ${href}`);
  window.setTimeout(() => {
    item.classList.remove("is-error");
    item.innerHTML = `${item.dataset.originalIcon || icons.copy}<span>${item.dataset.originalLabel || "Copy link"}</span>`;
  }, 2200);
}

function buildPostActionsMenu(post) {
  const wrap = document.createElement("div");
  wrap.className = "social-post-menu-wrap";

  const button = document.createElement("button");
  button.type = "button";
  button.className = "social-post-menu-button";
  button.setAttribute("aria-label", "Post actions");
  button.setAttribute("aria-haspopup", "menu");
  button.setAttribute("aria-expanded", "false");
  button.innerHTML = icons.more;

  const menu = document.createElement("div");
  menu.className = "social-post-menu";
  menu.setAttribute("role", "menu");

  menu.appendChild(buildMenuItem("Copy link", icons.copy, async (item) => {
    await copyPostLink(post, item);
  }, { keepOpen: true }));
  if (post.can_edit || post.can_manage) {
    menu.appendChild(buildMenuItem("Edit post", icons.edit, (_item, trigger) => openPostModal(post, trigger)));
  }
  if (post.can_delete || post.can_manage) {
    menu.appendChild(buildMenuItem("Delete post", icons.trash, (_item, trigger) => openDeleteModal("post", postKey(post), post.content, trigger), { danger: true }));
  }

  button.addEventListener("click", (event) => {
    event.preventDefault();
    event.stopPropagation();
    const opening = !wrap.classList.contains("is-open");
    closePostActionMenus(wrap);
    wrap.classList.toggle("is-open", opening);
    button.setAttribute("aria-expanded", opening ? "true" : "false");
    if (opening) {
      requestAnimationFrame(() => menu.querySelector("button")?.focus());
    }
  });

  wrap.append(button, menu);
  return wrap;
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

function setCommentsForPost(postId, comments = []) {
  const id = Number(postId);
  if (!id) return;
  commentsByPost.set(id, Array.isArray(comments) ? comments : []);
}

function updateCommentCount(postId, explicitCount = null) {
  const id = Number(postId);
  if (!id) return;
  const count = explicitCount === null
    ? (commentsByPost.get(id) || []).length
    : Math.max(0, Number(explicitCount) || 0);
  document.querySelectorAll(`[data-post-comment-count="${id}"]`).forEach((countEl) => {
    countEl.textContent = `${count} ${count === 1 ? "comment" : "comments"}`;
  });
}

function upsertCommentInCache(comment) {
  const postId = Number(comment?.post_id);
  if (!postId || !comment?.id) return;
  const comments = [...(commentsByPost.get(postId) || [])];
  const index = comments.findIndex((item) => Number(item.id) === Number(comment.id));
  if (index >= 0) {
    comments[index] = comment;
  } else {
    comments.push(comment);
  }
  commentsByPost.set(postId, comments);
}

function removeCommentFromCache(postId, commentId) {
  const id = Number(postId);
  if (!id) return;
  const target = Number(commentId);
  const removeIds = new Set([target]);
  let changed = true;
  const existing = commentsByPost.get(id) || [];
  while (changed) {
    changed = false;
    existing.forEach((comment) => {
      if (removeIds.has(Number(comment.parent_comment_id)) && !removeIds.has(Number(comment.id))) {
        removeIds.add(Number(comment.id));
        changed = true;
      }
    });
  }
  const comments = existing.filter((comment) => !removeIds.has(Number(comment.id)));
  commentsByPost.set(id, comments);
}

function appendCommentToPost(postId, comment, beforeNode) {
  if (!comment?.id) return false;
  upsertCommentInCache(comment);
  beforeNode?.parentElement?.insertBefore(renderComment(comment), beforeNode);
  updateCommentCount(postId);
  return true;
}

function replaceComment(comment, row) {
  if (!comment?.id || !row) return false;
  upsertCommentInCache(comment);
  row.replaceWith(renderComment(comment));
  updateCommentCount(comment.post_id);
  return true;
}

function updateFeedEmptyState() {
  const hasPosts = Boolean(postsEl?.querySelector(".social-post-card"));
  if (hasPosts) {
    emptyEl.classList.add("hidden");
    return;
  }
  setEmptyState(activeFeed);
  emptyEl.classList.remove("hidden");
}

function upsertPostCard(post, comments = null, options = {}) {
  if (!post?.id) return false;
  postsById.set(Number(post.id), post);
  if (Array.isArray(comments)) {
    setCommentsForPost(post.id, comments);
  } else if (!commentsByPost.has(Number(post.id))) {
    setCommentsForPost(post.id, []);
  }
  const nextCard = renderPost(post);
  const currentCard = document.getElementById(`post-${post.id}`);
  if (currentCard) {
    currentCard.replaceWith(nextCard);
  } else if (options.prepend) {
    postsEl.prepend(nextCard);
  } else {
    postsEl.appendChild(nextCard);
  }
  if (isShowingPostDetail && Number(currentDetailPostId) === Number(post.id) && detailContent) {
    detailContent.innerHTML = "";
    detailContent.appendChild(renderPost(post, { detail: true }));
  }
  loadingEl.classList.add("hidden");
  emptyEl.classList.add("hidden");
  return true;
}

function removePostCard(postId) {
  const el = document.getElementById(`post-${postId}`);
  el?.remove();
  postsById.delete(Number(postId));
  commentsByPost.delete(Number(postId));
  updateFeedEmptyState();
}

function updateLikeButton(button, targetType, liked, likesCount) {
  button.classList.toggle("is-active", liked);
  button.setAttribute("aria-pressed", liked ? "true" : "false");
  const baseVerb = liked ? "Liked" : "Like";
  const labelText = targetType === "comment" && likesCount > 0
    ? `${baseVerb} (${likesCount})`
    : baseVerb;
  const span = button.querySelector?.("span");
  if (span) {
    span.textContent = labelText;
  } else {
    button.textContent = labelText;
  }
}

async function toggleLike(button, targetType, id) {
  if (button.disabled) return;
  button.disabled = true;
  try {
    const url = targetType === "comment" ? `/social/comments/${id}/like` : `/social/${id}/like`;
    const resp = await apiFetch(url, { method: "POST", body: JSON.stringify({}) });
    const liked = Boolean(resp?.data?.liked);
    const likesCount = typeof resp?.data?.likes_count === "number" ? resp.data.likes_count : 0;
    updateLikeButton(button, targetType, liked, likesCount);

    if (targetType === "post") {
      const countKey = button.closest(".social-post-card")?.dataset.postId || id;
      document.querySelectorAll(`[data-post-like-count="${countKey}"]`).forEach((countEl) => {
        countEl.textContent = `${likesCount} ${likesCount === 1 ? "like" : "likes"}`;
      });
    }
  } catch (err) {
    setStatus(normalizeError(err), false);
  } finally {
    button.disabled = false;
  }
}

function focusCommentInput(postId, detail = false) {
  const input = document.getElementById(`comment-input-${detail ? "detail-" : ""}${postId}`);
  input?.focus();
}

async function copyPostLink(post, item = null) {
  const href = postUrl(post);
  try {
    await copyUrlToClipboard(href);
    if (item) showCopiedState(item);
  } catch (err) {
    if (item) showCopyError(item, href);
    else setStatus(`Copy this link: ${href}`, false);
  }
}

async function copyCommentLink(comment, post, item = null) {
  const href = postUrl(post || { id: comment.post_id, public_id: comment.post_public_id, feed_scope: activeFeed }, comment);
  try {
    await copyUrlToClipboard(href);
    if (item) showCopiedState(item);
  } catch (err) {
    if (item) showCopyError(item, href);
    else setStatus(`Copy this link: ${href}`, false);
  }
}

function selectedMentionPublicIds(input) {
  return [...(input?._mentionPublicIds || new Set())];
}

function syncMentionPublicIdsFromText(input) {
  if (!input) return;
  const mentionMap = input._mentionPublicIdsByText instanceof Map ? input._mentionPublicIdsByText : null;
  if (!mentionMap) {
    if (!input._mentionPublicIds) input._mentionPublicIds = new Set();
    return;
  }
  const text = String(input.value || "");
  const nextIds = new Set();
  mentionMap.forEach((publicId, mentionText) => {
    if (publicId && mentionText && text.includes(mentionText)) {
      nextIds.add(publicId);
    }
  });
  input._mentionPublicIds = nextIds;
}

function mentionSearchTerm(input) {
  const value = input.value || "";
  const cursor = input.selectionStart ?? value.length;
  const before = value.slice(0, cursor);
  const at = before.lastIndexOf("@");
  if (at < 0 || (at > 0 && /\S/.test(before[at - 1]))) return null;
  const term = before.slice(at + 1);
  if (term.length > 40 || /[\n\r.,;:!?()[\]{}]/.test(term)) return null;
  return { at, term: term.trim(), before, after: value.slice(cursor) };
}

function closeMentionDropdown(input) {
  input?._mentionDropdown?.remove();
  if (input) input._mentionDropdown = null;
}

function attachMentionAutocomplete(input) {
  if (!input || input._mentionsReady) return;
  input._mentionsReady = true;
  syncMentionPublicIdsFromText(input);
  let timer = 0;
  input.addEventListener("input", () => {
    syncMentionPublicIdsFromText(input);
    clearTimeout(timer);
    const match = mentionSearchTerm(input);
    if (!match || match.term.length < 1) {
      closeMentionDropdown(input);
      return;
    }
    timer = window.setTimeout(async () => {
      mentionAbort?.abort?.();
      mentionAbort = new AbortController();
      try {
        const params = new URLSearchParams({ q: match.term, feed_scope: activeFeed });
        if (activeFeed === "entity" && entitySelect?.value) params.set("e", entitySelect.value);
        const response = await apiFetch(`/social/mentions?${params.toString()}`, { skipFallback: true, signal: mentionAbort.signal });
        renderMentionDropdown(input, response?.data || [], match);
      } catch (err) {
        if (err?.name !== "AbortError") closeMentionDropdown(input);
      }
    }, 150);
  });
  input.addEventListener("blur", () => window.setTimeout(() => closeMentionDropdown(input), 180));
}

function renderMentionDropdown(input, suggestions, match) {
  closeMentionDropdown(input);
  if (!suggestions.length) return;
  if (input.parentElement && getComputedStyle(input.parentElement).position === "static") {
    input.parentElement.style.position = "relative";
  }
  const menu = document.createElement("div");
  menu.className = "social-mention-menu";
  menu.setAttribute("role", "listbox");
  suggestions.forEach((suggestion) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "social-mention-option";
    button.setAttribute("role", "option");
    const name = document.createElement("span");
    name.className = "social-mention-name";
    name.textContent = suggestion.full_name || "NCP User";
    const tag = document.createElement("span");
    tag.className = "social-mention-tag";
    tag.textContent = suggestion.tag || "Member";
    button.append(name, tag);
    button.addEventListener("mousedown", (event) => {
      event.preventDefault();
      const current = mentionSearchTerm(input) || match;
      const mentionText = `@${suggestion.full_name} `;
      input.value = `${current.before.slice(0, current.at)}${mentionText}${current.after}`;
      input._mentionPublicIds ||= new Set();
      input._mentionPublicIdsByText ||= new Map();
      if (suggestion.public_id) input._mentionPublicIds.add(suggestion.public_id);
      if (suggestion.public_id) input._mentionPublicIdsByText.set(mentionText, suggestion.public_id);
      closeMentionDropdown(input);
      input.focus();
      input.dispatchEvent(new Event("input", { bubbles: true }));
    });
    menu.appendChild(button);
  });
  input.insertAdjacentElement("afterend", menu);
  input._mentionDropdown = menu;
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

function cssEscape(value) {
  return window.CSS?.escape ? CSS.escape(String(value)) : String(value).replace(/["\\]/g, "\\$&");
}

function commentParentId(comment) {
  return Number(comment?.parent_comment_id || 0);
}

function buildCommentThreads(comments = []) {
  const sorted = [...comments].sort((a, b) => parseDate(a.created_at) - parseDate(b.created_at));
  const byId = new Map();
  const children = new Map();
  sorted.forEach((comment) => {
    byId.set(Number(comment.id), comment);
  });
  const roots = [];
  sorted.forEach((comment) => {
    const parentId = commentParentId(comment);
    if (parentId && byId.has(parentId)) {
      const list = children.get(parentId) || [];
      list.push(comment);
      children.set(parentId, list);
    } else {
      roots.push(comment);
    }
  });
  return { roots, children };
}

function countReplies(comment, children) {
  const direct = children.get(Number(comment.id)) || [];
  return direct.reduce((total, child) => total + 1 + countReplies(child, children), 0);
}

function renderCommentMenu(comment, post) {
  const wrap = document.createElement("div");
  wrap.className = "social-post-menu-wrap social-comment-menu-wrap";

  const button = document.createElement("button");
  button.type = "button";
  button.className = "social-post-menu-button social-comment-menu-button";
  button.setAttribute("aria-label", "Comment actions");
  button.setAttribute("aria-haspopup", "menu");
  button.setAttribute("aria-expanded", "false");
  button.innerHTML = icons.more;

  const menu = document.createElement("div");
  menu.className = "social-post-menu social-comment-menu";
  menu.setAttribute("role", "menu");
  menu.appendChild(buildMenuItem("Copy link", icons.copy, async (item) => copyCommentLink(comment, post, item), { keepOpen: true }));
  if (comment.can_edit || comment.can_manage) {
    menu.appendChild(buildMenuItem("Edit comment", icons.edit, (_item, trigger) => startCommentEdit(comment, post, trigger)));
  }
  if (comment.can_delete || comment.can_manage) {
    menu.appendChild(buildMenuItem("Delete comment", icons.trash, (_item, trigger) => openDeleteModal("comment", commentKey(comment), comment.comment, trigger), { danger: true }));
  }

  button.addEventListener("click", (event) => {
    event.preventDefault();
    event.stopPropagation();
    const opening = !wrap.classList.contains("is-open");
    closePostActionMenus(wrap);
    wrap.classList.toggle("is-open", opening);
    button.setAttribute("aria-expanded", opening ? "true" : "false");
    if (opening) requestAnimationFrame(() => menu.querySelector("button")?.focus());
  });

  wrap.append(button, menu);
  return wrap;
}

function startCommentEdit(comment, post, trigger) {
  const row = document.querySelector(`[data-comment-key="${cssEscape(commentKey(comment))}"]`);
  const bubble = row?.querySelector(".social-comment-bubble");
  if (!row || !bubble) return;
  bubble.innerHTML = "";
  const editForm = document.createElement("form");
  editForm.className = "flex flex-col sm:flex-row gap-2";
  const input = document.createElement("input");
  input.className = "input-field py-2 text-sm flex-1";
  input.value = comment.comment || "";
  input.autocomplete = "off";
  attachMentionAutocomplete(input);
  const save = document.createElement("button");
  save.type = "submit";
  save.className = "btn btn-secondary px-3 py-2 text-xs";
  save.textContent = "Save";
  const cancel = document.createElement("button");
  cancel.type = "button";
  cancel.className = "btn btn-ghost px-3 py-2 text-xs";
  cancel.textContent = "Cancel";
  cancel.addEventListener("click", () => {
    row.replaceWith(renderComment(comment, { post, detail: Boolean(row.closest("[data-detail='1']")), depth: Number(row.dataset.depth || 0), rootKey: row.dataset.rootKey || commentKey(comment) }));
    trigger?.focus?.();
  });
  editForm.append(input, save, cancel);
  editForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const nextValue = input.value.trim();
    if (!nextValue) return;
    save.disabled = true;
    try {
      const resp = await apiFetch(`/social/comments/${encodeURIComponent(commentKey(comment))}`, {
        method: "PUT",
        body: JSON.stringify({ comment: nextValue, mentioned_user_public_ids: selectedMentionPublicIds(input) })
      });
      const updatedComment = resp?.data?.comment || { ...comment, comment: nextValue, safe_html: null };
      upsertCommentInCache(updatedComment);
      row.replaceWith(renderComment(updatedComment, { post, detail: Boolean(row.closest("[data-detail='1']")), depth: Number(row.dataset.depth || 0), rootKey: row.dataset.rootKey || commentKey(updatedComment) }));
    } catch (err) {
      setStatus(normalizeError(err), false);
    } finally {
      save.disabled = false;
    }
  });
  bubble.appendChild(editForm);
  input.focus();
}

function renderReplyForm(post, parentComment, rootKey, detail) {
  const form = document.createElement("form");
  form.className = "social-comment-form social-reply-form";
  const input = document.createElement("input");
  input.className = "input-field font-medium py-2.5 px-4 text-sm flex-1 bg-[var(--bg-base)]";
  input.placeholder = `Reply to ${parentComment.full_name || "comment"}...`;
  input.autocomplete = "off";
  attachMentionAutocomplete(input);
  const submit = document.createElement("button");
  submit.className = "btn btn-secondary px-4 py-2.5 text-sm w-full sm:w-auto";
  submit.type = "submit";
  submit.textContent = "Reply";
  submit.disabled = true;
  input.addEventListener("input", () => {
    submit.disabled = input.value.trim().length === 0;
  });
  form.append(input, submit);
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const value = input.value.trim();
    if (!value) return;
    submit.disabled = true;
    try {
      const resp = await apiFetch(`/social/${encodeURIComponent(postKey(post))}/comments`, {
        method: "POST",
        body: JSON.stringify({
          comment: value,
          parent_comment_public_id: commentKey(parentComment),
          mentioned_user_public_ids: selectedMentionPublicIds(input)
        })
      });
      const serverComment = resp?.data?.comment;
      if (!serverComment?.id) {
        setStatus("Reply saved, but the response was incomplete. Refresh to see the latest feed.", false);
        return;
      }
      expandedReplyThreads.add(rootKey);
      upsertCommentInCache(serverComment);
      rerenderPostComments(post, detail);
    } catch (err) {
      setStatus(normalizeError(err), false);
    } finally {
      submit.disabled = false;
    }
  });
  return form;
}

function renderComment(comment, context = {}) {
  const { post, detail = false, depth = 0, rootKey = commentKey(comment) } = context;
  const row = document.createElement("div");
  row.className = "social-comment group/comment";
  if (comment?.id) row.dataset.commentId = comment.id;
  row.dataset.commentKey = commentKey(comment);
  row.dataset.rootKey = rootKey;
  row.dataset.depth = String(depth);
  row.classList.toggle("social-comment--reply", depth > 0);
  row.style.setProperty("--comment-depth", String(Math.min(depth, 2)));

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
  if (depth > 1 && comment.replying_to_name) {
    const replying = document.createElement("div");
    replying.className = "social-replying-to";
    replying.textContent = `Replying to @${comment.replying_to_name}`;
    body.appendChild(replying);
  }
  if (comment.safe_html) {
    const content = document.createElement("span");
    content.innerHTML = comment.safe_html;
    body.appendChild(content);
  } else {
    body.append(document.createTextNode(comment.comment || ""));
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
    like.addEventListener("click", () => toggleLike(like, "comment", commentKey(comment)));
    tools.appendChild(like);
  }

  if (post && canInteractWithRecord(comment, "comment")) {
    const reply = document.createElement("button");
    reply.type = "button";
    reply.className = "social-comment-like";
    reply.textContent = "Reply";
    reply.addEventListener("click", () => {
      row.nextElementSibling?.classList?.contains("social-reply-form") && row.nextElementSibling.remove();
      row.after(renderReplyForm(post, comment, rootKey, detail));
      row.nextElementSibling?.querySelector("input")?.focus();
    });
    tools.appendChild(reply);
  }

  tools.appendChild(renderCommentMenu(comment, post));

  bodyWrap.append(bubble, tools);
  row.appendChild(bodyWrap);
  return row;
}

function renderCommentThread(comment, container, post, children, options = {}) {
  const rootKey = options.rootKey || commentKey(comment);
  const depth = options.depth || 0;
  container.appendChild(renderComment(comment, { post, detail: options.detail, depth: Math.min(depth, 2), rootKey }));
  const direct = children.get(Number(comment.id)) || [];
  if (!direct.length) return;
  const totalReplies = countReplies(comment, children);
  const expanded = options.detail || expandedReplyThreads.has(rootKey) || (pendingCommentKey && hasCommentInThread(comment, children, pendingCommentKey));
  if (!expanded) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "social-view-replies";
    button.textContent = `View replies (${totalReplies})`;
    button.addEventListener("click", () => {
      expandedReplyThreads.add(rootKey);
      rerenderPostComments(post, options.detail);
    });
    container.appendChild(button);
    return;
  }
  direct.forEach((reply) => renderCommentThread(reply, container, post, children, {
    detail: options.detail,
    depth: depth + 1,
    rootKey,
  }));
}

function hasCommentInThread(comment, children, key) {
  if (commentKey(comment) === key || String(comment.id) === key) return true;
  return (children.get(Number(comment.id)) || []).some((child) => hasCommentInThread(child, children, key));
}

function renderCommentsInto(commentsWrap, post, detail) {
  const comments = commentsByPost.get(Number(post.id)) || [];
  commentsWrap.querySelectorAll(":scope > .social-comment, :scope > .social-view-replies, :scope > .social-reply-form").forEach((node) => node.remove());
  const { roots, children } = buildCommentThreads(comments);
  roots.forEach((comment) => renderCommentThread(comment, commentsWrap, post, children, { detail, rootKey: commentKey(comment) }));
}

function rerenderPostComments(post, detail = false) {
  document.querySelectorAll(`[data-comments-for-post="${post.id}"][data-detail="${detail ? "1" : "0"}"]`).forEach((wrap) => {
    renderCommentsInto(wrap, post, detail);
  });
  updateCommentCount(post.id);
}

function shouldShowReadMore(post) {
  return String(post?.content || "").trim().length > 360;
}

function renderPost(post, options = {}) {
  const detail = Boolean(options.detail);
  const card = document.createElement("article");
  card.id = `${detail ? "detail-post" : "post"}-${post.id}`;
  card.className = `social-post-card ${detail ? "social-post-card--detail" : ""}`;
  card.dataset.postUserId = post.user_id ?? '';
  card.dataset.postId = String(post.id);
  card.dataset.postKey = postKey(post);
  card.dataset.feedScope = post.feed_scope ?? '';

  const header = document.createElement("header");
  header.className = "social-post-header";
  const author = document.createElement("div");
  author.className = "social-author";
  const authorNameText = post.author_name || post.full_name || "NCP User";
  author.appendChild(buildAvatar(authorNameText, "", post.author_avatar_url));
  const authorText = document.createElement("div");
  authorText.className = "min-w-0";
  const authorName = document.createElement("p");
  authorName.className = "social-author-name truncate";
  authorName.textContent = authorNameText;
  const meta = document.createElement("div");
  meta.className = "social-post-meta";
  const scope = document.createElement("span");
  scope.className = "social-scope-pill";
  scope.textContent = post.feed_scope === "global" ? "Global Feed" : post.entity_name || "Entity Feed";
  const time = document.createElement("span");
  time.textContent = formatTime(post.created_at);
  meta.append(scope);
  if (post.author_meta) {
    const authorMeta = document.createElement("span");
    authorMeta.textContent = post.author_meta;
    meta.append(document.createTextNode("•"), authorMeta);
  }
  meta.append(document.createTextNode("•"), time);
  authorText.append(authorName, meta);
  author.appendChild(authorText);
  header.appendChild(author);
  header.appendChild(buildPostActionsMenu(post));
  card.appendChild(header);

  const content = document.createElement("div");
  content.className = `social-post-content ${!detail && shouldShowReadMore(post) ? "social-post-content--clamped" : ""}`;
  if (post.safe_html) {
    content.innerHTML = post.safe_html;
  } else {
    content.textContent = post.content || "";
  }
  card.appendChild(content);

  if (!detail && shouldShowReadMore(post)) {
    const readMoreWrap = document.createElement("div");
    readMoreWrap.className = "social-read-more-row";
    const readMore = document.createElement("button");
    readMore.type = "button";
    readMore.className = "social-read-more";
    readMore.textContent = "Read more";
    readMore.addEventListener("click", () => openPostDetail(postKey(post), { push: true, post }));
    readMoreWrap.appendChild(readMore);
    card.appendChild(readMoreWrap);
  }

  const images = buildImageGrid(post.images);
  if (images) card.appendChild(images);

  const comments = commentsByPost.get(Number(post.id)) || [];
  const stats = document.createElement("div");
  stats.className = "social-stats-row";
  const likeCount = document.createElement("span");
  likeCount.id = `post-like-count-${detail ? "detail-" : ""}${post.id}`;
  likeCount.dataset.postLikeCount = String(post.id);
  likeCount.textContent = `${post.likes_count || 0} ${Number(post.likes_count || 0) === 1 ? "like" : "likes"}`;
  const commentCount = document.createElement("span");
  commentCount.id = `post-comment-count-${detail ? "detail-" : ""}${post.id}`;
  commentCount.dataset.postCommentCount = String(post.id);
  commentCount.textContent = `${comments.length} ${comments.length === 1 ? "comment" : "comments"}`;
  stats.append(likeCount, commentCount);
  card.appendChild(stats);

  const actions = document.createElement("div");
  actions.className = "social-actions";
  const canLike = canInteractWithRecord(post, "like");
  const canComment = canInteractWithRecord(post, "comment");
  if (publicGlobalMode()) {
    const likeButton = buildActionButton("Like", icons.like);
    likeButton.addEventListener("click", () => promptSignIn("like this update"));
    actions.appendChild(likeButton);

    const commentButton = buildActionButton("Comment", icons.comment);
    commentButton.addEventListener("click", () => promptSignIn("comment on this update"));
    actions.appendChild(commentButton);
  } else if (canLike) {
    const likeButton = buildActionButton(post.liked_by_me ? "Liked" : "Like", icons.like, {
      active: post.liked_by_me,
      pressed: post.liked_by_me
    });
    likeButton.addEventListener("click", () => toggleLike(likeButton, "post", postKey(post)));
    actions.appendChild(likeButton);
  }
  if (!publicGlobalMode() && canComment) {
    const commentButton = buildActionButton("Comment", icons.comment);
    commentButton.addEventListener("click", () => focusCommentInput(post.id, detail));
    actions.appendChild(commentButton);
  }
  card.appendChild(actions);

  const commentsWrap = document.createElement("div");
  commentsWrap.className = "social-comments";
  commentsWrap.dataset.commentsForPost = String(post.id);
  commentsWrap.dataset.detail = detail ? "1" : "0";
  renderCommentsInto(commentsWrap, post, detail);

  if (canComment) {
    const commentForm = document.createElement("form");
    commentForm.className = "social-comment-form";
    const commentInput = document.createElement("input");
    commentInput.id = `comment-input-${detail ? "detail-" : ""}${post.id}`;
    commentInput.className = "input-field font-medium py-2.5 px-4 text-sm flex-1 bg-[var(--bg-base)]";
    commentInput.placeholder = "Add a comment...";
    commentInput.autocomplete = "off";
    attachMentionAutocomplete(commentInput);
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
          const resp = await apiFetch(`/social/${encodeURIComponent(postKey(post))}/comments`, {
            method: "POST",
            body: JSON.stringify({ comment: value, mentioned_user_public_ids: selectedMentionPublicIds(commentInput) })
          });
          const serverComment = resp?.data?.comment;
          const commentId = serverComment?.id || resp?.data?.id;
          if (!commentId) {
            setStatus("Comment saved, but the response was incomplete. Refresh to see the latest feed.", false);
            return;
          }
          const newComment = serverComment || {
            id: commentId,
            post_id: post.id,
            comment: value,
            safe_html: null,
            full_name: currentUser?.full_name || "NCP User",
            created_at: new Date().toISOString(),
            likes_count: 0,
            liked_by_me: false,
            can_interact: true,
            can_like: true,
            can_manage: Boolean(currentUser),
          };
          upsertCommentInCache(newComment);
          rerenderPostComments(post, detail);
          commentInput.value = "";
          syncMentionPublicIdsFromText(commentInput);
      } catch (err) {
        setStatus(normalizeError(err), false);
      } finally {
        commentSubmit.disabled = commentInput.value.trim().length === 0;
      }
    });
    commentsWrap.appendChild(commentForm);
  } else if (publicGlobalMode()) {
    const prompt = document.createElement("button");
    prompt.type = "button";
    prompt.className = "btn btn-secondary w-full";
    prompt.textContent = "Sign in to comment";
    prompt.addEventListener("click", () => promptSignIn("comment on this update"));
    commentsWrap.appendChild(prompt);
  } else {
    const note = document.createElement("p");
    note.className = "text-xs font-semibold text-[var(--text-tertiary)]";
    note.textContent = "You can view this feed, but comments are restricted for your role.";
    commentsWrap.appendChild(note);
  }
  card.appendChild(commentsWrap);

  return card;
}

function setDetailStatus(message, kind = "error") {
  if (!detailStatus) return;
  detailStatus.textContent = message;
  detailStatus.className = `social-detail-status ${kind === "loading" ? "is-loading" : ""}`;
  detailStatus.classList.toggle("hidden", !message);
}

function focusLinkedComment() {
  if (!pendingCommentKey) {
    window.scrollTo({ top: 0, behavior: "smooth" });
    return;
  }
  requestAnimationFrame(() => {
    const selector = `[data-comment-key="${cssEscape(pendingCommentKey)}"], [data-comment-id="${cssEscape(pendingCommentKey)}"]`;
    const target = detailContent?.querySelector(selector);
    if (!target) {
      setDetailStatus("Comment not found.");
      return;
    }
    setDetailStatus("");
    target.classList.add("social-comment--highlight");
    target.scrollIntoView({ block: "center", behavior: "smooth" });
    window.setTimeout(() => target.classList.remove("social-comment--highlight"), 2400);
  });
}

function showFeedView(options = {}) {
  isShowingPostDetail = false;
  currentDetailPostId = 0;
  currentDetailPostKey = "";
  detailView?.classList.add("hidden");
  detailContent && (detailContent.innerHTML = "");
  pageHeader?.classList.remove("hidden");
  feedShell?.classList.remove("hidden");
  setActiveFeed(activeFeed, { skipLoad: true });
  closePostActionMenus();
  if (options.updateUrl !== false) {
    const nextUrl = lastFeedUrl || feedUrlForState(activeFeed, entitySelect?.value || "");
    window.history.pushState({ view: "feed" }, "", nextUrl);
  }
}

function renderPostDetail(post, comments = [], permissions = {}) {
  if (!post?.id || !detailContent) return;
  postsById.set(Number(post.id), post);
  activeFeed = post.feed_scope === "global" ? "global" : "entity";
  if ((post.entity_public_id || post.entity_id) && entitySelect) {
    const entityValue = String(post.entity_public_id || post.entity_id);
    if ([...entitySelect.options].some((option) => option.value === entityValue)) {
      entitySelect.value = entityValue;
    }
  }
  lastFeedUrl = feedUrlForState(activeFeed, post.entity_public_id || entitySelect?.value || "");
  applyFeedPermissions(permissions || {});
  setCommentsForPost(post.id, comments);
  currentDetailPostId = Number(post.id);
  currentDetailPostKey = postKey(post);
  isShowingPostDetail = true;
  pageHeader?.classList.add("hidden");
  feedShell?.classList.add("hidden");
  detailView?.classList.remove("hidden");
  setDetailStatus("");
  detailContent.innerHTML = "";
  detailContent.appendChild(renderPost(post, { detail: true }));
  focusLinkedComment();
}

async function openPostDetail(postId, options = {}) {
  const key = String(postId || "").trim();
  if (!key) return;
  if (options.comment) {
    pendingCommentKey = commentKey(options.comment);
  }
  if (!lastFeedUrl) {
    lastFeedUrl = feedUrlForState(activeFeed, entitySelect?.value || "");
  }
  if (options.push) {
    window.history.pushState({ view: "post", postId: key }, "", postUrl(options.post || key, options.comment || null));
  }
  isShowingPostDetail = true;
  pageHeader?.classList.add("hidden");
  feedShell?.classList.add("hidden");
  detailView?.classList.remove("hidden");
  detailContent && (detailContent.innerHTML = "");
  setDetailStatus("Loading post...", "loading");
  try {
    const response = await apiFetch(`/social/post/${encodeURIComponent(key)}`, { skipFallback: true });
    renderPostDetail(response?.data?.post, response?.data?.comments || [], response?.meta?.permissions || {});
  } catch (err) {
    const status = Number(err?.status || 0);
    let message = "Post not found.";
    if (status === 401) {
      message = "Sign in to view this post.";
      const nextUrl = loginUrlForPost(key);
      promptSignIn("view this post", nextUrl);
      window.location.replace(nextUrl);
      return;
    } else if (status === 403) {
      message = "You do not have access to this post.";
    }
    detailContent && (detailContent.innerHTML = "");
    setDetailStatus(message);
  }
}

async function loadPosts(options = {}) {
  const preserveScroll = Boolean(options.preserveScroll);
  const previousScrollY = window.scrollY || 0;
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
    lastFeedUrl = feedUrlForState(activeFeed, entityId);
    const response = await apiFetch(url);
    applyFeedPermissions(response?.meta?.permissions || response?.data?.permissions || {});
    const posts = response?.data?.posts || [];
    const comments = response?.data?.comments || [];
    commentsByPost = new Map();
    postsById = new Map();
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

    posts.forEach((post) => {
      postsById.set(Number(post.id), post);
      postsEl.appendChild(renderPost(post));
    });
    if (!preserveScroll && /^#post-\d+$/.test(window.location.hash)) {
      document.querySelector(window.location.hash)?.scrollIntoView({ block: "center" });
    }
  } catch (err) {
    console.error("Failed to load social posts:", err);
    applyFeedPermissions(defaultFeedPermissions(activeFeed));
    setEmptyState("error");
    emptyEl.classList.remove("hidden");
  } finally {
    loadingEl.classList.add("hidden");
    if (preserveScroll) {
      window.scrollTo(0, previousScrollY);
    }
  }
}

function openDeleteModal(type, id, text, trigger = document.activeElement) {
  deletingItem = { type, id };
  deleteTrigger = trigger;
  const preview = String(text || "").slice(0, 120);
  deleteMessage.textContent = `Delete this ${type}${preview ? `: "${preview}${String(text || "").length > 120 ? "..." : ""}"` : ""}?`;
  deleteModal.classList.remove("hidden");
  lockBodyScroll();
  (deleteCancelBtn || deleteCloseBtn)?.focus();
}

function closeDeleteModal() {
  deleteModal.classList.add("hidden");
  deletingItem = null;
  unlockBodyScroll();
  if (deleteTrigger && typeof deleteTrigger.focus === "function") {
    deleteTrigger.focus();
  }
  deleteTrigger = null;
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
    selectedMentionPublicIds(contentInput).forEach((id) => formData.append("mentioned_user_public_ids[]", id));
    if (editingPost) {
      formData.append("keep_image_ids", existingImages.map((image) => String(image.id)).join(","));
    }
    selectedImages.forEach((image) => formData.append("images[]", image.file, image.file.name));

    if (editingPost) {
      const resp = await apiFetch(`/social/${encodeURIComponent(postKey(editingPost))}/update`, { method: "POST", body: formData });
      const ok = upsertPostCard(resp?.data?.post, resp?.data?.comments);
      if (!ok) {
        setStatus("Post saved, but the response was incomplete. Refresh to see the latest feed.", false);
      } else {
        closePostModal(true);
      }
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
    } else if (postAsSelect?.value) {
      formData.append("entity_public_id", postAsSelect.value);
    }
    const resp = await apiFetch("/social", { method: "POST", body: formData });
    const ok = upsertPostCard(resp?.data?.post, resp?.data?.comments, { prepend: true });
    if (!ok) {
      setStatus("Post published, but the response was incomplete. Refresh to see the latest feed.", false);
    } else {
      closePostModal(true);
    }
  } catch (err) {
    setStatus(normalizeError(err), false);
  } finally {
    isSubmitting = false;
    submitBtn.disabled = false;
    closePostModalBtn.disabled = false;
  }
}

feedTabs.forEach((tab) => {
  tab.addEventListener("click", (event) => {
    event.preventDefault();
    setActiveFeed(tab.dataset.feed);
  });
});

openPostModalBtn?.addEventListener("click", () => openPostModal(null, openPostModalBtn));
closePostModalBtn?.addEventListener("click", () => closePostModal());
cancelEditBtn?.addEventListener("click", () => closePostModal());
postModal?.addEventListener("click", (event) => {
  if (event.target === postModal) closePostModal();
});
imageInput?.addEventListener("change", () => {
  addSelectedFiles(imageInput.files || []);
  imageInput.value = "";
});
window.addEventListener("pagehide", clearSelectedImages);
entitySelect?.addEventListener("change", () => loadPosts({ preserveScroll: true }));
form?.addEventListener("submit", submitPost);
if (contentInput) attachMentionAutocomplete(contentInput);
detailBackBtn?.addEventListener("click", () => {
  if (routePostKey() && window.history.state?.view === "post") {
    window.history.back();
    return;
  }
  showFeedView({ updateUrl: true });
  if (!postsEl?.children.length) loadPosts();
});

document.addEventListener("click", (event) => {
  if (!event.target.closest?.(".social-post-menu-wrap")) {
    closePostActionMenus();
  }
});

deleteCancelBtn?.addEventListener("click", closeDeleteModal);
deleteCloseBtn?.addEventListener("click", closeDeleteModal);
deleteModal?.addEventListener("click", (event) => {
  if (event.target === deleteModal) closeDeleteModal();
});
deleteConfirmBtn?.addEventListener("click", async () => {
  if (!deletingItem) return;
  // snapshot values before modal close clears them
  const snapshotItem = { ...deletingItem };
  const snapshotTrigger = deleteTrigger;
  deleteConfirmBtn.disabled = true;
  try {
    const url = snapshotItem.type === "comment" ? `/social/comments/${snapshotItem.id}` : `/social/${snapshotItem.id}`;
    const resp = await apiFetch(url, { method: "DELETE" });
    closeDeleteModal();
    if (snapshotItem.type === "post") {
      const deletedPostId = resp?.data?.id || snapshotItem.id;
      removePostCard(deletedPostId);
      if (isShowingPostDetail && (String(currentDetailPostId) === String(deletedPostId) || currentDetailPostKey === String(snapshotItem.id))) {
        showFeedView({ updateUrl: true });
      }
    } else if (snapshotItem.type === "comment") {
      const responsePostId = resp?.data?.post_id;
      const deletedCommentId = resp?.data?.id || snapshotItem.id;
      const selector = `[data-comment-key="${cssEscape(snapshotItem.id)}"], [data-comment-id="${cssEscape(deletedCommentId)}"]`;
      const found = document.querySelector(selector);
      const isDetailComment = Boolean(found?.closest("#social-detail-content"));
      const postCard = found?.closest("[data-post-id]") || (snapshotTrigger?.closest ? snapshotTrigger.closest("[data-post-id]") : null);
      const postId = responsePostId || postCard?.dataset?.postId || "";
      if (postId) {
        removeCommentFromCache(postId, deletedCommentId);
        const post = postsById.get(Number(postId));
        if (post) {
          rerenderPostComments(post, false);
          if (isShowingPostDetail) rerenderPostComments(post, true);
        }
        updateCommentCount(postId, resp?.data?.comments_count ?? null);
      } else {
        found?.remove();
      }
    }
  } catch (err) {
    deleteMessage.textContent = normalizeError(err);
  } finally {
    deleteConfirmBtn.disabled = false;
  }
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    closePostActionMenus();
  }
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

function populateEntityOptions(entities) {
  if (!entitySelect) return;
  entitySelect.innerHTML = "";
  const requestedEntityId = routeEntityKey();
  entities.forEach((entity) => {
    const option = document.createElement("option");
    option.value = entity.public_id || entity.id;
    option.textContent = entity.name;
    entitySelect.appendChild(option);
  });
  if (requestedEntityId && [...entitySelect.options].some((option) => option.value === requestedEntityId)) {
    entitySelect.value = requestedEntityId;
  }
  if (!entities.length) {
    const option = document.createElement("option");
    option.value = "";
    option.textContent = "No entities available";
    entitySelect.appendChild(option);
  }
}

function initializeSocialPage(response = null) {
  currentUser = response?.data?.user || null;
  isAuthenticated = Boolean(currentUser);
  const postId = routePostKey();
  pendingCommentKey = routeCommentKey();

  if (userRequiresPasswordSetup(currentUser)) {
    window.location.replace("/reset_password.html?mode=session");
    return;
  }

  if (isAuthenticated) {
    renderAuthenticatedShell();
  } else if (activeFeed === "global" || routePostKey()) {
    renderPublicShell();
  } else {
    window.location.replace(loginUrlForFeed("entity"));
    return;
  }

  populateEntityOptions(isAuthenticated ? response?.data?.entities || [] : []);
  if (postId) {
    openPostDetail(postId, { push: false });
    return;
  }
  setActiveFeed(activeFeed, { skipLoad: true });
  loadPosts();
}

window.addEventListener("popstate", () => {
  const postId = routePostKey();
  pendingCommentKey = routeCommentKey();
  if (postId) {
    openPostDetail(postId, { push: false });
    return;
  }
  showFeedView({ updateUrl: false });
  const params = new URLSearchParams(window.location.search);
  activeFeed = params.get("feed") === "global" ? "global" : "entity";
  const entityId = routeEntityKey();
  if (entityId && entitySelect && [...entitySelect.options].some((option) => option.value === entityId)) {
    entitySelect.value = entityId;
  }
  setActiveFeed(activeFeed, { skipLoad: true });
  loadPosts();
});

loadConfig().then(() => apiFetch("/auth/me", { skipFallback: true }))
  .then(initializeSocialPage)
  .catch((err) => {
    if ((err.status === 401 || err.status === 403 || /Unauthorized|Forbidden/.test(String(err.message))) && activeFeed === "global") {
      initializeSocialPage(null);
      return;
    }
    if (err.status === 401 || err.status === 403 || /Unauthorized|Forbidden/.test(String(err.message))) {
      window.location.replace(loginUrlForFeed("entity"));
      return;
    }
    console.error("Failed to load social access:", err);
    setEmptyState("error");
    emptyEl.classList.remove("hidden");
  });
