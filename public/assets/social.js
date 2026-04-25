import { apiFetch } from "/assets/app.js";
import { renderSidebar } from "/assets/sidebar.js";
document.getElementById("sidebar-container").outerHTML =
  renderSidebar("social");
const entitySelect = document.getElementById("social-entity");
const postsEl = document.getElementById("social-posts");
const emptyEl = document.getElementById("social-empty");
const form = document.getElementById("social-form");
const statusEl = document.getElementById("social-status");
let currentUser = null;
let editingPostId = null;
let deletingItem = null;
let editingCommentId = null;
const deleteModal = document.getElementById("delete-modal");
const deleteMessage = document.getElementById("delete-message");
const deleteConfirmBtn = document.getElementById("delete-confirm");
const deleteCancelBtn = document.getElementById("delete-cancel");
const deleteCloseBtn = document.getElementById("delete-close");
const normalizeError = (err) => {
  const message = err?.message || "";
  return message === "Forbidden"
    ? "You do not have permission."
    : message || "Action failed.";
};
const setStatus = (message, ok) => {
  statusEl.textContent = message;
  statusEl.className = `text-sm font-semibold rounded-lg px-4 py-3 ${ok ? "bg-[rgba(16,185,129,0.1)] text-[#6ee7b7] border-[rgba(16,185,129,0.2)]" : "bg-[rgba(239,68,68,0.1)] text-[#fca5a5] border-[rgba(239,68,68,0.2)]"}`;
  statusEl.classList.remove("hidden");
};
const editPost = (post) => {
  editingPostId = post.id;
  document.getElementById("social-content").value = post.content;
  const submitBtn = form.querySelector('button[type="submit"]');
  if (submitBtn) submitBtn.textContent = "Update Post";
  let cancelBtn = document.getElementById("social-cancel-edit");
  if (!cancelBtn) {
    cancelBtn = document.createElement("button");
    cancelBtn.id = "social-cancel-edit";
    cancelBtn.type = "button";
    cancelBtn.className = "btn btn-ghost w-full mt-2";
    cancelBtn.textContent = "Cancel Edit";
    cancelBtn.onclick = cancelEdit;
    form.appendChild(cancelBtn);
  }
  cancelBtn.classList.remove("hidden");
  window.scrollTo({ top: 0, behavior: "smooth" });
};
const cancelEdit = () => {
  editingPostId = null;
  form.reset();
  const submitBtn = form.querySelector('button[type="submit"]');
  if (submitBtn) submitBtn.textContent = "Publish Update";
  const cancelBtn = document.getElementById("social-cancel-edit");
  if (cancelBtn) cancelBtn.classList.add("hidden");
  statusEl.classList.add("hidden");
};
const resetCommentEdit = () => {
  editingCommentId = null;
  loadPosts();
};
const deleteItem = (type, id, text) => {
  deletingItem = { type, id, text };
  const preview =
    (text || "").substring(0, 100) + (text && text.length > 100 ? "..." : "");
  deleteMessage.textContent = `Are you sure you want to delete this ${type}? «${preview}»`;
  deleteModal.classList.remove("hidden");
  if (deleteCancelBtn) deleteCancelBtn.focus();
};
const closeDeleteModal = () => {
  deleteModal.classList.add("hidden");
  deletingItem = null;
};
document.addEventListener("keydown", (e) => {
  if (!deleteModal.classList.contains("hidden")) {
    if (e.key === "Escape") {
      closeDeleteModal();
    } else if (e.key === "Tab") {
      const focusable = deleteModal.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
      );
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (e.shiftKey) {
        if (document.activeElement === first) {
          e.preventDefault();
          last.focus();
        }
      } else {
        if (document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    }
  }
});
if (deleteCancelBtn) deleteCancelBtn.onclick = closeDeleteModal;
if (deleteCloseBtn) deleteCloseBtn.onclick = closeDeleteModal;
if (deleteConfirmBtn) {
  deleteConfirmBtn.onclick = async () => {
    if (!deletingItem) return;
    try {
      deleteConfirmBtn.disabled = true;
      let url = `/social/${deletingItem.id}`;
      if (deletingItem.type === "comment") {
        url = `/social/comments/${deletingItem.id}`;
      }
      await apiFetch(url, { method: "DELETE" });
      closeDeleteModal();
      loadPosts();
    } catch (err) {
      setStatus(normalizeError(err), false);
    } finally {
      deleteConfirmBtn.disabled = false;
    }
  };
}
const loadPosts = async () => {
  const entityId = entitySelect.value;
  if (!entityId) return;
  try {
    const response = await apiFetch(`/social?entity_id=${entityId}`);
    const posts = response?.data?.posts || [];
    const comments = response?.data?.comments || [];
    postsEl.innerHTML = "";
    if (!posts.length) {
      emptyEl.classList.remove("hidden");
      return;
    }
    emptyEl.classList.add("hidden");
    posts.forEach((post) => {
      const card = document.createElement("article");
      card.className =
        "bg-[var(--bg-surface)] border border-[var(--border-subtle)] rounded-2xl p-6 hover:border-[var(--border-strong)] transition-colors shadow-sm relative group";
      const headerWrap = document.createElement("div");
      headerWrap.className = "flex items-center gap-3 mb-4 min-w-0";
      const avatar = document.createElement("div");
      const hue = ((post.full_name || "").length * 10) % 360;
      avatar.className = "w-10 h-10 rounded-full shadow-inner flex-shrink-0";
      avatar.style.background = `linear-gradient(135deg, hsl(${hue}, 80%, 60%), hsl(${(hue + 40) % 360}, 80%, 40%))`;
      const headerName = document.createElement("p");
      headerName.className = "font-bold text-[var(--text-primary)] text-sm truncate";
      headerName.textContent = post.full_name;
      const metaTime = document.createElement("p");
      metaTime.className =
        "text-[10px] font-bold text-[var(--text-tertiary)] tracking-widest uppercase mt-0.5";
      metaTime.textContent = new Date(post.created_at).toLocaleString();
      const nameMetaGroup = document.createElement("div");
      nameMetaGroup.className = "min-w-0";
      nameMetaGroup.appendChild(headerName);
      nameMetaGroup.appendChild(metaTime);
      headerWrap.appendChild(avatar);
      headerWrap.appendChild(nameMetaGroup);
      const content = document.createElement("p");
      content.className =
        "text-[15px] font-medium text-[var(--text-primary)] leading-relaxed whitespace-pre-wrap break-words";
      content.textContent = post.content;
      const commentList = document.createElement("div");
      const postComments = comments.filter((c) => Number(c.post_id) === Number(post.id));
      if (postComments.length > 0) {
        commentList.className =
          "mt-6 space-y-3.5 border-t border-[var(--border-subtle)] pt-4";
        postComments.forEach((comment) => {
          const line = document.createElement("div");
          line.className = "flex items-start gap-2.5 relative group/comment";
          const cHue = ((comment.full_name || "").length * 15) % 360;
          const cAvatar = document.createElement("div");
          cAvatar.className = "w-6 h-6 rounded-full flex-shrink-0 mt-0.5";
          cAvatar.style.background = `linear-gradient(135deg, hsl(${cHue}, 60%, 50%), hsl(${(cHue + 40) % 360}, 60%, 30%))`;
          const cContent = document.createElement("div");
          cContent.className =
            "bg-[var(--bg-base)] rounded-xl px-3 py-2 text-[13px] border border-[var(--border-strong)] flex items-start flex-wrap content-start flex-1 min-w-0";
          const nameSpan = document.createElement("span");
          nameSpan.className = "font-bold text-[var(--text-primary)] mr-2";
          nameSpan.textContent = comment.full_name;
          const commentSpan = document.createElement("span");
          commentSpan.className =
            "text-[var(--text-secondary)] font-medium leading-snug break-words";
          commentSpan.textContent = comment.comment;
          cContent.appendChild(nameSpan);
          cContent.appendChild(commentSpan);
          line.appendChild(cAvatar);
          line.appendChild(cContent);
          const canManageComment = Boolean(comment.can_manage);
          if (canManageComment) {
            const commentActions = document.createElement("div");
            commentActions.className =
              "flex gap-1 opacity-100 sm:opacity-0 sm:pointer-events-none sm:group-hover/comment:opacity-100 sm:group-hover/comment:pointer-events-auto focus-within:opacity-100 focus-within:pointer-events-auto transition-opacity shrink-0";
            const editCommentBtn = document.createElement("button");
            editCommentBtn.type = "button";
            editCommentBtn.className =
              "text-[var(--text-tertiary)] hover:text-[var(--text-primary)] p-1 mt-1";
            editCommentBtn.setAttribute("aria-label", "Edit reply");
            editCommentBtn.innerHTML =
              '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>';
            editCommentBtn.onclick = () => {
              if (editingCommentId === comment.id) return;
              editingCommentId = comment.id;
              cContent.innerHTML = "";
              const editForm = document.createElement("form");
              editForm.className = "flex w-full gap-2";
              const editInput = document.createElement("input");
              editInput.className = "input-field py-1.5 text-sm flex-1";
              editInput.value = comment.comment;
              const saveBtn = document.createElement("button");
              saveBtn.type = "submit";
              saveBtn.className = "btn btn-secondary py-1.5 px-3 text-xs";
              saveBtn.textContent = "Save";
              const cancelBtn = document.createElement("button");
              cancelBtn.type = "button";
              cancelBtn.className = "btn btn-ghost py-1.5 px-2 text-xs";
              cancelBtn.textContent = "Cancel";
              cancelBtn.onclick = resetCommentEdit;
              editForm.append(editInput, saveBtn, cancelBtn);
              editForm.addEventListener("submit", async (event) => {
                event.preventDefault();
                const nextValue = editInput.value.trim();
                if (!nextValue) return;
                try {
                  saveBtn.disabled = true;
                  await apiFetch(`/social/comments/${comment.id}`, {
                    method: "PUT",
                    body: JSON.stringify({ comment: nextValue }),
                  });
                  editingCommentId = null;
                  loadPosts();
                } catch (err) {
                  setStatus(normalizeError(err), false);
                } finally {
                  saveBtn.disabled = false;
                }
              });
              cContent.appendChild(editForm);
              editInput.focus();
            };
            const delCommentBtn = document.createElement("button");
            delCommentBtn.type = "button";
            delCommentBtn.className =
              "text-[var(--text-tertiary)] hover:text-[var(--color-danger)] p-1 mt-1";
            delCommentBtn.setAttribute("aria-label", "Delete reply");
            delCommentBtn.innerHTML =
              '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>';
            delCommentBtn.onclick = () =>
              deleteItem("comment", comment.id, comment.comment);
            commentActions.append(editCommentBtn, delCommentBtn);
            line.appendChild(commentActions);
          }
          commentList.appendChild(line);
        });
      }
      const commentForm = document.createElement("form");
      commentForm.className = `flex flex-col sm:flex-row gap-2 relative ${postComments.length === 0 ? "mt-6 pt-4 border-t border-[var(--border-subtle)]" : "mt-4"}`;
      const commentInput = document.createElement("input");
      commentInput.className =
        "input-field font-medium py-2 px-4 shadow-inner text-sm flex-1 bg-[var(--bg-base)]";
      commentInput.placeholder = "Write a reply...";
      const commentButton = document.createElement("button");
      commentButton.className = "btn btn-secondary px-4 py-2 shadow-sm text-sm w-full sm:w-auto";
      commentButton.textContent = "Reply";
      commentForm.appendChild(commentInput);
      commentForm.appendChild(commentButton);
      commentForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const val = commentInput.value.trim();
        if (!val) return;
        try {
          commentButton.disabled = true;
          await apiFetch(`/social/${post.id}/comments`, {
            method: "POST",
            body: JSON.stringify({ comment: val }),
          });
          commentInput.value = "";
          loadPosts();
        } catch (err) {
          setStatus(normalizeError(err) || "Comment failed", false);
        } finally {
          commentButton.disabled = false;
        }
      });
      card.appendChild(headerWrap);
      card.appendChild(content);
      if (commentList.children.length) card.appendChild(commentList);
      card.appendChild(commentForm);
      const canManagePost = Boolean(post.can_manage);
      if (canManagePost) {
        const actionsDiv = document.createElement("div");
        actionsDiv.className =
          "absolute top-4 right-4 flex gap-1 opacity-100 sm:opacity-0 sm:pointer-events-none sm:group-hover:opacity-100 sm:group-hover:pointer-events-auto focus-within:opacity-100 focus-within:pointer-events-auto transition-opacity";
        const postStr = encodeURIComponent(JSON.stringify(post));
        const editBtn = document.createElement("button");
        editBtn.type = "button";
        editBtn.className =
          "btn btn-ghost px-2 py-1 h-auto text-[var(--text-secondary)] hover:text-[var(--text-primary)] text-xs";
        editBtn.innerHTML =
          '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>';
        editBtn.onclick = () => editPost(post);
        editBtn.title = "Edit";
        const delBtn = document.createElement("button");
        delBtn.type = "button";
        delBtn.className =
          "btn btn-ghost px-2 py-1 h-auto text-[var(--color-danger)] hover:bg-[var(--color-danger-bg)] text-xs";
        delBtn.innerHTML =
          '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>';
        delBtn.onclick = () => deleteItem("post", post.id, post.content);
        delBtn.title = "Delete";
        actionsDiv.appendChild(editBtn);
        actionsDiv.appendChild(delBtn);
        card.appendChild(actionsDiv);
      }
      postsEl.appendChild(card);
    });
  } catch (err) {
    console.error("Failed to load social posts:", err);
    postsEl.innerHTML = "";
    emptyEl.querySelector("p.font-semibold").textContent =
      "Failed to load posts.";
    emptyEl.querySelector("p.text-xs").textContent = "Please try again later.";
    emptyEl.classList.remove("hidden");
  }
};
apiFetch("/auth/me")
  .then((response) => {
    currentUser = response?.data?.user || null;
    const entities = response?.data?.entities || [];
    entitySelect.innerHTML = "";
    entities.forEach((entity) => {
      const option = document.createElement("option");
      option.value = entity.id;
      option.textContent = entity.name;
      entitySelect.appendChild(option);
    });
    if (entities.length) {
      entitySelect.value = entities[0].id;
      loadPosts();
    }
  })
  .catch((err) => {
    if (
      err.status === 401 ||
      err.status === 403 ||
      String(err.message).includes("401") ||
      String(err.message).includes("Unauthorized") ||
      String(err.message).includes("Forbidden")
    ) {
      window.location.replace("/login.html");
      return;
    }
    console.error("Failed to load entities:", err);
    entitySelect.innerHTML = "";
    emptyEl.querySelector("p.font-semibold").textContent =
      "Unable to load posts without access.";
    emptyEl.querySelector("p.text-xs").textContent = "";
    emptyEl.classList.remove("hidden");
  });
entitySelect.addEventListener("change", loadPosts);
form.addEventListener("submit", async (event) => {
  event.preventDefault();
  const submitBtn = form.querySelector('button[type="submit"]');
  if (submitBtn) submitBtn.disabled = true;
  try {
    if (editingPostId) {
      await apiFetch(`/social/${editingPostId}`, {
        method: "PUT",
        body: JSON.stringify({ content: form.content.value }),
      });
      setStatus("Post updated successfully.", true);
      cancelEdit();
    } else {
      await apiFetch("/social", {
        method: "POST",
        body: JSON.stringify({
          entity_id: entitySelect.value,
          content: form.content.value,
        }),
      });
      setStatus("Post published successfully.", true);
      form.reset();
    }
    setTimeout(() => statusEl.classList.add("hidden"), 3000);
    loadPosts();
  } catch (err) {
    setStatus(normalizeError(err), false);
  } finally {
    if (submitBtn) submitBtn.disabled = false;
  }
});
