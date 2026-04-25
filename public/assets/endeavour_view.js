import { apiFetch } from '/assets/app.js';
import { renderSidebar } from '/assets/sidebar.js';

document.getElementById('sidebar-container').outerHTML = renderSidebar('entity_endeavours');

const params = new URLSearchParams(window.location.search);
const nameEl = document.getElementById('endeavour-name');
const id = params.get('id');

const initEndeavourView = () => {
  if (!nameEl) return;
  if (id && !/^\d+$/.test(id)) {
    nameEl.textContent = 'Invalid endeavour ID';
    return;
  }
  const statusEl = document.getElementById('endeavour-status');
  const metaEl = document.getElementById('endeavour-meta');
  const timelineList = document.getElementById('timeline-list');
  const documentsList = document.getElementById('documents-list');
  const documentsEmpty = document.getElementById('documents-empty');
  const pipelineApplicants = document.getElementById('pipeline-applicants');
  const pipelineShortlisted = document.getElementById('pipeline-shortlisted');
  const pipelineConsent = document.getElementById('pipeline-consent');
  const postsList = document.getElementById('posts-list');
  const applicationsList = document.getElementById('applications-list');
  const activityList = document.getElementById('activity-list');
  const approvalForm = document.getElementById('approval-form');
  const documentForm = document.getElementById('document-form');
  const postForm = document.getElementById('post-form');
  const approvalStatus = document.getElementById('approval-status');
  const documentStatus = document.getElementById('document-status');
  const postStatus = document.getElementById('post-status');
  const actionStatus = document.getElementById('action-status');

  const normalizeError = (err) => {
    const message = err?.message || '';
    return message === 'Forbidden' ? 'You do not have permission.' : (message || 'Action failed.');
  };

  const setStatus = (el, message, ok) => {
    if (!el) return;
    el.textContent = message;
    el.className = `text-sm font-semibold rounded-lg px-4 py-3 ${ok ? 'bg-[rgba(16,185,129,0.1)] text-[#6ee7b7] border-[rgba(16,185,129,0.2)]' : 'bg-[rgba(239,68,68,0.1)] text-[#fca5a5] border-[rgba(239,68,68,0.2)]'}`;
    el.classList.remove('hidden');
  };

  const loadingEl = document.getElementById('page-loading');

  const loadEndeavour = async () => {
    if (!id) {
      nameEl.textContent = 'Missing endeavour ID';
      if (loadingEl) loadingEl.classList.add('hidden');
      return;
    }
    try {
      const { data } = await apiFetch(`/endeavours/${id}`);
      const endeavour = data?.endeavour;
      if (!endeavour) {
        console.error('Endeavour data not found for id:', id);
        nameEl.textContent = 'Endeavour not found';
        if (loadingEl) loadingEl.classList.add('hidden');
        return;
      }
      statusEl.textContent = (endeavour.status || '').replaceAll('_', ' ');
      nameEl.textContent = endeavour.name || 'Endeavour';
      const dates = [endeavour.start_date, endeavour.end_date].filter(Boolean).join(' - ');
      const dateSuffix = dates ? ` \u00B7 ${dates}` : '';
      metaEl.textContent = `Entity: ${endeavour.entity_name || 'Nixor Entity'}${dateSuffix}`;

      timelineList.innerHTML = '';
      const timelineItems = (data.activity || []).slice(0, 6);
      if (!timelineItems.length) {
        const empty = document.createElement('p');
        empty.className = 'text-[13px] font-medium text-[var(--text-secondary)]';
        empty.textContent = 'No activity logged yet.';
        timelineList.appendChild(empty);
      }
      timelineItems.forEach((entry) => {
        const item = document.createElement('div');
        item.className = 'flex items-start gap-4 relative';

        const dot = document.createElement('div');
        dot.className = 'w-2.5 h-2.5 rounded-full bg-[var(--color-primary)] mt-1.5 flex-shrink-0 shadow-[0_0_0_3px_rgba(59,130,246,0.2)]';

        const content = document.createElement('div');
        content.className = 'min-w-0 flex-1';

        const title = document.createElement('p');
        title.className = 'text-sm font-bold text-[var(--text-primary)] capitalize';
        title.textContent = entry.action.replaceAll('_', ' ');

        const meta = document.createElement('p');
        meta.className = 'text-[11px] font-bold tracking-widest uppercase text-[var(--text-tertiary)] mt-1';
        const who = entry.full_name ? `by ${entry.full_name}` : 'system';
        meta.textContent = `${who} \u00B7 ${new Date(entry.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}`;

        content.appendChild(title);
        content.appendChild(meta);
        item.appendChild(dot);
        item.appendChild(content);
        timelineList.appendChild(item);
      });

      documentsList.innerHTML = '';
      const documents = data.documents || [];
      if (documents.length) {
        documentsEmpty.classList.add('hidden');
        documents.forEach((doc) => {
          const row = document.createElement('div');
          row.className = 'flex justify-between items-center group bg-[var(--bg-surface)] p-3 rounded-xl border border-[var(--border-subtle)]';

          const left = document.createElement('span');
          left.className = 'text-[13px] font-bold text-[var(--text-primary)] truncate pr-4';
          left.textContent = doc.original_name || doc.doc_type;

          const right = document.createElement('a');
          const dlParams = new URLSearchParams({ type: 'endeavour_document', id: String(doc.id) });
          right.href = `/api/files/download?${dlParams.toString()}`;
          right.className = 'text-[11px] font-bold uppercase tracking-widest text-[#7dd3fc] px-3 py-1 rounded-md bg-[rgba(14,165,233,0.1)] border border-[rgba(14,165,233,0.2)] hover:bg-[rgba(14,165,233,0.2)] transition-colors transform shrink-0';
          right.textContent = 'Download';

          row.appendChild(left);
          row.appendChild(right);
          documentsList.appendChild(row);
        });
      } else {
        documentsEmpty.classList.remove('hidden');
      }

      const apps = data.applications || [];
      const shortlisted = apps.filter((app) => app.status === 'shortlisted').length;
      const consented = (data.consents || []).filter((consent) => consent.status === 'signed').length;
      pipelineApplicants.textContent = apps.length;
      pipelineShortlisted.textContent = shortlisted;
      pipelineConsent.textContent = consented;

      postsList.innerHTML = '';
      (data.posts || []).forEach((post) => {
        const row = document.createElement('div');
        row.className = 'flex sm:items-center flex-col sm:flex-row justify-between bg-[var(--bg-surface)] border border-[var(--border-subtle)] rounded-xl p-4 gap-4';

        const info = document.createElement('div');
        info.className = 'min-w-0 flex-1';

        const title = document.createElement('p');
        title.className = 'text-[13px] font-bold text-[var(--text-primary)] leading-snug break-words';
        title.textContent = post.description || 'Volunteer post';

        const meta = document.createElement('p');
        meta.className = 'text-[10px] uppercase font-bold tracking-widest mt-1.5 flex items-center gap-1.5';
        if (post.published) {
          meta.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span><span class="text-[#6ee7b7]">Published</span>';
        } else {
          meta.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span><span class="text-[#fcd34d]">Pending approval</span>';
        }

        info.appendChild(title);
        info.appendChild(meta);
        row.appendChild(info);

        if (!post.published) {
          const publishBtn = document.createElement('button');
          publishBtn.className = 'btn btn-secondary px-3 py-1.5 text-xs h-auto shadow-sm whitespace-nowrap sm:self-center self-end';
          publishBtn.textContent = 'Publish';
          publishBtn.addEventListener('click', async () => {
            publishBtn.disabled = true;
            const originalText = publishBtn.textContent;
            publishBtn.textContent = 'Publishing...';
            try {
              await apiFetch(`/endeavours/${id}/publish_post`, { method: 'POST', body: JSON.stringify({ post_id: post.id }) });
              loadEndeavour();
            } catch (err) {
              setStatus(actionStatus, normalizeError(err) || 'Publish failed', false);
            } finally {
              publishBtn.disabled = false;
              publishBtn.textContent = originalText;
            }
          });
          row.appendChild(publishBtn);
        }
        postsList.appendChild(row);
      });

      applicationsList.innerHTML = '';
      if (!apps.length) {
        document.getElementById('applications-empty')?.classList.remove('hidden');
      } else {
        document.getElementById('applications-empty')?.classList.add('hidden');
        apps.forEach((app) => {
          const row = document.createElement('tr');
          row.className = 'hover:bg-[rgba(255,255,255,0.02)] transition-colors';

          const nameCell = document.createElement('td');
          nameCell.className = 'px-6 py-3 font-bold text-[var(--text-primary)] break-words';
          nameCell.textContent = app.full_name;

          const statusCell = document.createElement('td');
          statusCell.className = 'px-6 py-3';
          const statusBadge = document.createElement('span');
          statusBadge.className = 'inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-bold tracking-widest uppercase bg-[var(--bg-surface-hover)] border border-[var(--border-subtle)] text-[var(--text-secondary)]';
          statusBadge.textContent = app.status;
          statusCell.appendChild(statusBadge);

          const actionCell = document.createElement('td');
          actionCell.className = 'px-6 py-3';

          const actions = document.createElement('div');
          actions.className = 'flex flex-wrap gap-2';

          const btnClass = 'inline-flex items-center justify-center rounded-lg border border-[var(--border-subtle)] bg-[var(--bg-surface)] text-[11px] font-bold tracking-wide text-[var(--text-secondary)] hover:bg-[rgba(255,255,255,0.05)] hover:text-white hover:border-[var(--border-strong)] transition-colors px-2.5 py-1.5 shadow-sm';

          const shortlistBtn = document.createElement('button');
          shortlistBtn.className = btnClass;
          shortlistBtn.textContent = 'Shortlist';
          shortlistBtn.addEventListener('click', async () => {
            shortlistBtn.disabled = true;
            const originalText = shortlistBtn.textContent;
            shortlistBtn.textContent = '...';
            try {
              await apiFetch(`/endeavours/${id}/shortlist`, { method: 'POST', body: JSON.stringify({ application_id: app.id }) });
              loadEndeavour();
            } catch (err) {
              setStatus(actionStatus, normalizeError(err) || 'Shortlist failed', false);
            } finally {
              shortlistBtn.disabled = false;
              shortlistBtn.textContent = originalText;
            }
          });
          const consentBtn = document.createElement('button');
          consentBtn.className = btnClass;
          consentBtn.textContent = 'Ask Consent';
          consentBtn.addEventListener('click', async () => {
            consentBtn.disabled = true;
            const originalText = consentBtn.textContent;
            consentBtn.textContent = '...';
            try {
              await apiFetch(`/endeavours/${id}/consent/request`, { method: 'POST', body: JSON.stringify({ application_id: app.id }) });
              loadEndeavour();
            } catch (err) {
              setStatus(actionStatus, normalizeError(err) || 'Consent request failed', false);
            } finally {
              consentBtn.disabled = false;
              consentBtn.textContent = originalText;
            }
          });
          const paymentBtn = document.createElement('button');
          paymentBtn.className = btnClass;
          paymentBtn.textContent = 'Mark Paid';

          const paymentForm = document.createElement('form');
          paymentForm.className = 'hidden mt-3 flex flex-col sm:flex-row gap-2 w-full max-w-sm';
          const receiptInput = document.createElement('input');
          receiptInput.className = 'input-field py-1.5 text-xs flex-1 min-w-0';
          receiptInput.placeholder = 'Receipt reference';
          receiptInput.setAttribute('aria-label', `Receipt reference for ${app.full_name}`);
          const savePaymentBtn = document.createElement('button');
          savePaymentBtn.type = 'submit';
          savePaymentBtn.className = 'btn btn-secondary px-3 py-1.5 text-xs';
          savePaymentBtn.textContent = 'Save';
          const cancelPaymentBtn = document.createElement('button');
          cancelPaymentBtn.type = 'button';
          cancelPaymentBtn.className = 'btn btn-ghost px-3 py-1.5 text-xs';
          cancelPaymentBtn.textContent = 'Cancel';
          paymentForm.append(receiptInput, savePaymentBtn, cancelPaymentBtn);

          paymentBtn.addEventListener('click', () => {
            paymentForm.classList.remove('hidden');
            receiptInput.focus();
          });
          cancelPaymentBtn.addEventListener('click', () => {
            paymentForm.classList.add('hidden');
            receiptInput.value = '';
          });
          paymentForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const receiptRef = receiptInput.value.trim();
            if (!receiptRef) {
              setStatus(actionStatus, 'Receipt reference is required.', false);
              receiptInput.focus();
              return;
            }
            paymentBtn.disabled = true;
            savePaymentBtn.disabled = true;
            savePaymentBtn.textContent = '...';
            try {
              await apiFetch(`/endeavours/${id}/payment/mark_paid`, { method: 'POST', body: JSON.stringify({ application_id: app.id, receipt_ref: receiptRef }) });
              loadEndeavour();
            } catch (err) {
              setStatus(actionStatus, normalizeError(err) || 'Payment update failed', false);
            } finally {
              paymentBtn.disabled = false;
              savePaymentBtn.disabled = false;
              savePaymentBtn.textContent = 'Save';
            }
          });
          const attendanceBtn = document.createElement('button');
          attendanceBtn.className = btnClass;
          attendanceBtn.textContent = 'Mark Present';
          attendanceBtn.addEventListener('click', async () => {
            attendanceBtn.disabled = true;
            const originalText = attendanceBtn.textContent;
            attendanceBtn.textContent = '...';
            try {
              await apiFetch(`/endeavours/${id}/attendance/mark`, { method: 'POST', body: JSON.stringify({ application_id: app.id, status: 'present' }) });
              loadEndeavour();
            } catch (err) {
              setStatus(actionStatus, normalizeError(err) || 'Attendance update failed', false);
            } finally {
              attendanceBtn.disabled = false;
              attendanceBtn.textContent = originalText;
            }
          });

          actions.appendChild(shortlistBtn);
          if (['shortlisted', 'consent_sent', 'consent_signed', 'approved'].includes(app.status)) actions.appendChild(consentBtn);
          actions.appendChild(paymentBtn);
          actions.appendChild(attendanceBtn);

          actionCell.appendChild(actions);
          actionCell.appendChild(paymentForm);
          row.appendChild(nameCell);
          row.appendChild(statusCell);
          row.appendChild(actionCell);
          applicationsList.appendChild(row);
        });
      }

      activityList.innerHTML = '';
      const activityItems = (data.activity || []).slice(0, 8);
      if (!activityItems.length) {
        const empty = document.createElement('p');
        empty.className = 'text-[13px] font-medium text-[var(--text-secondary)]';
        empty.textContent = 'No recent activity.';
        activityList.appendChild(empty);
      }
      activityItems.forEach((entry) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'py-2 border-b border-[rgba(255,255,255,0.03)] last:border-0';
        const line = document.createElement('p');
        line.className = 'text-[13px] font-bold text-[var(--text-primary)] leading-snug';
        line.textContent = entry.notes || entry.action.replaceAll('_', ' ');
        const meta = document.createElement('p');
        meta.className = 'text-[10px] uppercase font-bold tracking-widest text-[var(--text-tertiary)] mt-1.5';
        const who = entry.full_name ? `by ${entry.full_name}` : 'system';
        meta.textContent = `${who} · ${new Date(entry.created_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}`;
        wrapper.appendChild(line);
        wrapper.appendChild(meta);
        activityList.appendChild(wrapper);
      });
      if (loadingEl) loadingEl.classList.add('hidden');
    } catch (err) {
      console.error('Failed to load endeavour:', err);
      nameEl.textContent = 'Failed to load endeavour details';
      if (loadingEl) loadingEl.classList.add('hidden');
    }
  };

  const uploadShortcut = document.getElementById('upload-shortcut');
  if (uploadShortcut) {
    uploadShortcut.addEventListener('click', () => {
      document.getElementById('upload-doc-section')?.scrollIntoView({ behavior: 'smooth' });
    });
  }

  if (approvalForm) {
    approvalForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await apiFetch(`/endeavours/${id}/approve`, {
          method: 'POST',
          body: JSON.stringify({
            decision: approvalForm.decision.value,
            notes: approvalForm.notes.value
          })
        });
        setStatus(approvalStatus, 'Decision recorded successfully.', true);
        loadEndeavour();
      } catch (err) {
        setStatus(approvalStatus, normalizeError(err), false);
      }
    });
  }

  const docTypeEndpointMap = {
    ops_plan: 'submit_ops_plan',
    operational_plan: 'submit_ops_plan',
    mou: 'submit_mou',
    pre_financial: 'submit_pre_financial',
    post_financial: 'submit_post_financial',
    epilogue: 'submit_epilogue'
  };

  if (documentForm) {
    documentForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const formData = new FormData(documentForm);
      const docType = formData.get('doc_type');
      if (!docType) {
        setStatus(documentStatus, 'Document type is required.', false);
        return;
      }
      const endpoint = docTypeEndpointMap[docType];
      if (!endpoint) {
        setStatus(documentStatus, 'Invalid document type.', false);
        return;
      }
      try {
        await apiFetch(`/endeavours/${id}/${endpoint}`, {
          method: 'POST',
          body: formData
        });
        setStatus(documentStatus, 'Document uploaded successfully.', true);
        documentForm.reset();
        loadEndeavour();
      } catch (err) {
        setStatus(documentStatus, normalizeError(err), false);
      }
    });
  }

  if (postForm) {
    postForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const payload = Object.fromEntries(new FormData(postForm).entries());
      try {
        await apiFetch(`/endeavours/${id}/request_post_to_feed`, { method: 'POST', body: JSON.stringify(payload) });
        setStatus(postStatus, 'Volunteer post requested.', true);
        postForm.reset();
        loadEndeavour();
      } catch (err) {
        setStatus(postStatus, normalizeError(err), false);
      }
    });
  }

  const openApproval = document.getElementById('open-approval');
  if (openApproval) openApproval.addEventListener('click', () => approvalForm?.scrollIntoView({ behavior: 'smooth' }));
  const openUpload = document.getElementById('open-upload');
  if (openUpload) openUpload.addEventListener('click', () => document.getElementById('upload-doc-section')?.scrollIntoView({ behavior: 'smooth' }));

  loadEndeavour();
};
initEndeavourView();
