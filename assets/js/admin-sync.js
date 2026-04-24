document.addEventListener('DOMContentLoaded', () => {
  if (typeof window.syncStageToProd === 'undefined') {
    return;
  }

  const cfg = window.syncStageToProd;

  const form = document.querySelector('.sync-stp__form');
  const startBtn = document.getElementById('sync-stp-start');
  const statusBox = document.getElementById('sync-stp-status');
  const loader = document.querySelector('.sync-stp__loader');
  const progressBar = document.querySelector('.sync-stp__progress-bar');
  const progressLabel = document.querySelector('.sync-stp__progress-label');
  const tokenBtn = document.getElementById('sync-stp-generate-token');
  const tokenInput = document.getElementById('sync-stp-auth-token');
  const targetUrlInput = document.getElementById('sync-stp-target-url');
  const endpointPathInput = document.getElementById('sync-stp-endpoint-path');
  const pageRoot = document.querySelector('.sync-stp');

  const msgGenerate = (cfg.messages && cfg.messages.generate) ? String(cfg.messages.generate) : 'Generate';
  const msgGenerating = (cfg.messages && cfg.messages.generating) ? String(cfg.messages.generating) : 'Generating...';
  const msgTokenGenerated = (cfg.messages && cfg.messages.tokenGenerated) ? String(cfg.messages.tokenGenerated) : 'Generated!';
  const msgTokenError = (cfg.messages && cfg.messages.tokenError) ? String(cfg.messages.tokenError) : 'Token generation error';
  const msgTokenTooShort = (cfg.messages && cfg.messages.tokenTooShort) ? String(cfg.messages.tokenTooShort) : `Token must be at least ${cfg.tokenMinLength || 32} characters.`;
  const msgNoTypes = (cfg.messages && cfg.messages.noTypes) ? String(cfg.messages.noTypes) : 'Select at least one post type.';
  const msgSyncError = (cfg.messages && cfg.messages.error) ? String(cfg.messages.error) : 'Sync error.';

  const msgMissingTarget = (cfg.messages && cfg.messages.missingTargetUrl) ? String(cfg.messages.missingTargetUrl) : 'Не заданий URL (ціль/джерело). Збережіть налаштування.';
  const msgInvalidTarget = (cfg.messages && cfg.messages.invalidTargetUrl) ? String(cfg.messages.invalidTargetUrl) : 'Некоректний URL (має бути http/https).';
  const msgMissingEndpoint = (cfg.messages && cfg.messages.missingEndpointPath) ? String(cfg.messages.missingEndpointPath) : 'Не заданий REST ендпоінт. Збережіть налаштування.';
  const msgInvalidEndpoint = (cfg.messages && cfg.messages.invalidEndpointPath) ? String(cfg.messages.invalidEndpointPath) : 'Некоректний REST ендпоінт (має починатися з "/").';
  const msgMissingToken = (cfg.messages && cfg.messages.missingToken) ? String(cfg.messages.missingToken) : 'Не заданий секретний токен. Збережіть налаштування.';

  function createMessageParagraph(label, content) {
    const p = document.createElement('p');
    const strong = document.createElement('strong');
    strong.textContent = `${label}:`;
    p.appendChild(strong);
    p.appendChild(document.createTextNode(` ${content}`));
    return p;
  }

  function setStatus(type, content, source = '') {
    if (!statusBox) {
      return;
    }

    statusBox.className = 'sync-stp__status';

    if (type === 'error') {
      statusBox.classList.add('sync-stp__status--error');
    } else if (type === 'success') {
      statusBox.classList.add('sync-stp__status--success');
    }

    statusBox.textContent = '';
    statusBox.dataset.source = source ? String(source) : '';

    if (!content) {
      return;
    }

    if (typeof content === 'string') {
      statusBox.textContent = content;
      return;
    }

    if (content instanceof HTMLElement) {
      statusBox.appendChild(content);
      return;
    }

    if (Array.isArray(content)) {
      content.forEach((el) => {
        if (el instanceof HTMLElement) {
          statusBox.appendChild(el);
        }
      });
    }
  }

  function setLoading(isLoading, opts = {}) {
    if (!loader || !progressBar || !progressLabel || !startBtn) {
      return;
    }

    const success = opts && typeof opts.success === 'boolean' ? opts.success : true;

    if (isLoading) {
      loader.classList.add('sync-stp__loader--visible');
      progressBar.style.width = '25%';
      progressLabel.textContent = '...';
      startBtn.disabled = true;
      return;
    }

    if (success) {
      progressBar.style.width = '100%';
      progressLabel.textContent = '100%';
      setTimeout(() => {
        loader.classList.remove('sync-stp__loader--visible');
        progressBar.style.width = '0%';
        progressLabel.textContent = '0%';
      }, 600);
    } else {
      progressBar.style.width = '0%';
      progressLabel.textContent = '0%';
      setTimeout(() => {
        loader.classList.remove('sync-stp__loader--visible');
      }, 150);
    }

    startBtn.disabled = false;
  }

  function isValidTokenLength(token) {
    if (!token || typeof token !== 'string') {
      return false;
    }
    const minLength = cfg.tokenMinLength || 32;
    return token.length >= minLength;
  }

  function isValidHttpUrl(raw) {
    if (!raw || typeof raw !== 'string') {
      return false;
    }

    const value = raw.trim();
    if (value === '') {
      return false;
    }

    try {
      const u = new URL(value);
      if (!u.hostname) {
        return false;
      }
      return u.protocol === 'http:' || u.protocol === 'https:';
    } catch (_) {
      return false;
    }
  }

  function isValidEndpointPath(raw) {
    if (!raw || typeof raw !== 'string') {
      return false;
    }
    const value = raw.trim();
    if (value === '') {
      return false;
    }
    if (!value.startsWith('/')) {
      return false;
    }
    return true;
  }

  function generateTokenServerSide() {
    if (!tokenBtn || !tokenInput) {
      return;
    }

    tokenBtn.disabled = true;
    tokenBtn.textContent = msgGenerating;

    const formData = new FormData();
    formData.append('action', 'sync_stp_generate_token');
    formData.append('nonce', cfg.nonce);

    let didSucceed = false;

    fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    })
      .then((res) => {
        if (!res.ok) {
          throw new Error(`HTTP ${res.status}`);
        }
        return res.json();
      })
      .then((json) => {
        if (!json || !json.success || !json.data || !json.data.token) {
          const message = (json && json.data && json.data.message) ? String(json.data.message) : msgTokenError;
          setStatus('error', message, 'token');
          return;
        }

        const generatedToken = String(json.data.token);

        if (!isValidTokenLength(generatedToken)) {
          setStatus('error', msgTokenTooShort, 'token');
          return;
        }

        didSucceed = true;
        tokenInput.value = generatedToken;

        tokenBtn.textContent = msgTokenGenerated;
        setTimeout(() => {
          tokenBtn.textContent = msgGenerate;
        }, 1200);
      })
      .catch(() => {
        setStatus('error', msgTokenError, 'token');
      })
      .finally(() => {
        tokenBtn.disabled = false;
        if (!didSucceed) {
          tokenBtn.textContent = msgGenerate;
        }
      });
  }

  function validateTokenInput() {
    if (!tokenInput || !statusBox) {
      return;
    }

    const token = tokenInput.value.trim();

    if (token === '') {
      if (statusBox.dataset.source === 'token') {
        setStatus('', '', '');
      }
      return;
    }

    if (!isValidTokenLength(token)) {
      setStatus('error', msgTokenTooShort, 'token');
      return;
    }

    if (statusBox.dataset.source === 'token') {
      setStatus('', '', '');
    }
  }

  if (tokenBtn && tokenInput) {
    tokenBtn.addEventListener('click', generateTokenServerSide);
    tokenInput.addEventListener('blur', validateTokenInput);
  }

  if (!form || !startBtn) {
    return;
  }

  function runSync(types) {
    const formData = new FormData();
    formData.append('action', 'sync_stp_run');
    formData.append('nonce', cfg.nonce);

    types.forEach((type) => {
      formData.append('post_types[]', type);
    });

    setLoading(true);

    fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    })
      .then((res) => {
        if (!res.ok) {
          throw new Error(`HTTP ${res.status}`);
        }
        return res.json();
      })
      .then((json) => {
        if (!json || !json.success) {
          const message = (json && json.data && json.data.message) ? String(json.data.message) : msgSyncError;
          setStatus('error', message, 'sync');
          setLoading(false, { success: false });
          return;
        }

        const data = json.data || {};
        const posts = data.posts || {};
        const terms = data.terms || {};
        const options = data.options || {};

        const postsSent = Number(posts.sent || data.sent || 0);
        const postsCreated = Number(posts.created || data.created || 0);
        const postsUpdated = Number(posts.updated || data.updated || 0);

        const termsSent = Number(terms.sent || 0);
        const termsCreated = Number(terms.created || 0);
        const termsUpdated = Number(terms.updated || 0);

        const optGroups = Number(options.groups_updated || 0);
        const optFields = Number(options.fields_updated || 0);

        const messages = [
          createMessageParagraph('Записи', `відправлено ${postsSent}, створено ${postsCreated}, оновлено ${postsUpdated}.`),
          createMessageParagraph('Таксономії', `отримано ${termsSent}, створено ${termsCreated}, оновлено ${termsUpdated}.`),
          createMessageParagraph('Опції', `оновлено груп: ${optGroups}, полів: ${optFields}.`),
        ];

        setStatus('success', messages, 'sync');
        setLoading(false, { success: true });
      })
      .catch(() => {
        setStatus('error', msgSyncError, 'sync');
        setLoading(false, { success: false });
      });
  }

  startBtn.addEventListener('click', () => {
    const isProdMode = pageRoot && pageRoot.classList.contains('sync-stp--mode-prod');

    if (isProdMode) {
      setStatus('error', 'У режимі Prod цей сайт лише приймає дані. Запуск синхронізації можливий тільки в режимі Stage/Dev.', 'sync');
      return;
    }

    const rawTargetUrl = targetUrlInput ? String(targetUrlInput.value || '').trim() : '';
    if (rawTargetUrl === '') {
      setStatus('error', msgMissingTarget, 'sync');
      return;
    }
    if (!isValidHttpUrl(rawTargetUrl)) {
      setStatus('error', msgInvalidTarget, 'sync');
      return;
    }

    const rawEndpointPath = endpointPathInput ? String(endpointPathInput.value || '').trim() : '';
    if (rawEndpointPath === '') {
      setStatus('error', msgMissingEndpoint, 'sync');
      return;
    }
    if (!isValidEndpointPath(rawEndpointPath)) {
      setStatus('error', msgInvalidEndpoint, 'sync');
      return;
    }

    const rawToken = tokenInput ? String(tokenInput.value || '').trim() : '';
    if (rawToken === '') {
      setStatus('error', msgMissingToken, 'sync');
      return;
    }
    if (!isValidTokenLength(rawToken)) {
      setStatus('error', msgTokenTooShort, 'sync');
      return;
    }

    const checkboxes = document.querySelectorAll('input[name="sync_stp_post_types[]"]:checked');
    const types = [];

    checkboxes.forEach((cb) => {
      if (cb && cb.value) {
        types.push(cb.value);
      }
    });

    if (!types.length) {
      setStatus('error', msgNoTypes, 'sync');
      return;
    }

    runSync(types);
  });
});
