(() => {
  if (window.__syncStpMetaboxInit) return;
  window.__syncStpMetaboxInit = true;

  const cfg = window.SyncSTPPostMetabox || null;
  if (!cfg || !cfg.ajaxUrl || !cfg.action) return;

  const getSpinner = (root) => root.querySelector('[data-sync-stp-spinner="1"]');

  const setStatus = (root, text, isError) => {
    const el = root.querySelector('.sync-stp-post-sync__status');
    if (!el) return;
    el.textContent = text || '';
    el.style.color = isError ? '#b32d2e' : '';
  };

  const setLoading = (btn, root, loading) => {
    if (btn) {
      btn.disabled = !!loading;
      btn.setAttribute('aria-busy', loading ? 'true' : 'false');
    }

    const spinner = getSpinner(root);
    if (!spinner) return;

    if (loading) spinner.classList.add('is-active');
    else spinner.classList.remove('is-active');
  };

  const readResponse = async (res) => {
    const ct = (res.headers.get('content-type') || '').toLowerCase();

    if (ct.includes('application/json')) {
      const json = await res.json().catch(() => null);
      return { json, text: '' };
    }

    const text = await res.text().catch(() => '');
    return { json: null, text };
  };

  const extractMessage = (payload, fallback) => {
    if (payload && typeof payload === 'object') {
      if (payload.data && payload.data.message) return String(payload.data.message);
      if (payload.message) return String(payload.message);
    }
    return fallback;
  };

  document.addEventListener('click', async (e) => {
    const btn = e.target && e.target.closest ? e.target.closest('[data-sync-stp-btn="1"]') : null;
    if (!btn) return;

    if (btn.getAttribute('aria-busy') === 'true' || btn.disabled) return;

    const root = btn.closest('.sync-stp-post-sync') || document;
    const postId = btn.getAttribute('data-post-id') || '';
    const nonce = btn.getAttribute('data-nonce') || '';

    if (!postId || !nonce) {
      setStatus(root, (cfg.i18n && cfg.i18n.error) ? cfg.i18n.error : 'Error.', true);
      return;
    }

    setStatus(root, (cfg.i18n && cfg.i18n.syncing) ? cfg.i18n.syncing : 'Syncing…', false);
    setLoading(btn, root, true);

    try {
      const fd = new FormData();
      fd.append('action', cfg.action);
      fd.append('post_id', postId);
      fd.append('nonce', nonce);

      const res = await fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
      });

      const { json, text } = await readResponse(res);

      if (json) {
        if (json.success) {
          const msg = extractMessage(json, (cfg.i18n && cfg.i18n.done) ? cfg.i18n.done : 'Done.');
          setStatus(root, msg, false);
        } else {
          const msg = extractMessage(json, (cfg.i18n && cfg.i18n.error) ? cfg.i18n.error : 'Error.');
          setStatus(root, msg, true);
        }
        setLoading(btn, root, false);
        return;
      }

      if (!res.ok) {
        const msg = text ? `Server error: ${res.status}. ${text}` : `Server error: ${res.status}.`;
        setStatus(root, msg, true);
        setLoading(btn, root, false);
        return;
      }

      setStatus(root, (cfg.i18n && cfg.i18n.error) ? cfg.i18n.error : 'Error.', true);
    } catch (_) {
      setStatus(root, (cfg.i18n && cfg.i18n.error) ? cfg.i18n.error : 'Error.', true);
    } finally {
      setLoading(btn, root, false);
    }
  });
})();
