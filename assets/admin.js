document.addEventListener('DOMContentLoaded', function () {
  const cfg = window.swioAdmin || {};
  const ajaxUrl = typeof cfg.ajaxUrl === 'string' && cfg.ajaxUrl ? cfg.ajaxUrl : (window.ajaxurl || '/wp-admin/admin-ajax.php');
  const nonce = cfg.nonce || '';
  const globalNoticeWrap = document.getElementById(cfg.globalNoticeId || 'swio-global-notices');

  document.querySelectorAll('[data-swio-accordion]').forEach((accordion) => {
    accordion.querySelectorAll('.swio-accordion-item').forEach((item) => {
      const toggle = item.querySelector('.swio-accordion-toggle');
      const panel = item.querySelector('.swio-accordion-panel');
      if (!toggle || !panel) return;

      item.classList.add('is-open');
      toggle.setAttribute('aria-expanded', 'true');
      panel.hidden = false;

      toggle.addEventListener('click', () => {
        const isOpen = item.classList.contains('is-open');
        item.classList.toggle('is-open', !isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        panel.hidden = isOpen;
      });
    });
  });

  document.querySelectorAll('.swio-run-action').forEach((button) => {
    button.addEventListener('click', async () => {
      if (button.disabled) return;

      const action = button.dataset.swioAction;
      const isBatch = button.dataset.swioBatch === '1';
      const card = button.closest('.swio-action-card, .swio-inline-action');
      const statusBox = card ? card.querySelector('.swio-action-status') : null;
      if (!action || !statusBox) return;

      button.disabled = true;
      statusBox.hidden = false;
      statusBox.innerHTML = '<p><strong>Probíhá zpracování…</strong></p>';

      let offset = 0;
      const aggregated = { processed: 0, changed: 0, details: [], errors: [], stats: {}, message: '' };

      try {
        while (true) {
          const params = new URLSearchParams();
          params.append('action', 'swio_run_action');
          params.append('nonce', nonce);
          params.append('swio_action', action);
          params.append('swio_offset', String(offset));

          const includeManual = card ? card.querySelector('.swio-include-manual') : null;
          if (includeManual && includeManual.checked) {
            params.append('swio_include_manual', '1');
          }

          const response = await fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
          });

          const payload = await parseJsonResponse(response);
          if (!payload.success) {
            throw new Error((payload.data && payload.data.message) ? payload.data.message : 'Akce selhala.');
          }

          const data = payload.data || {};
          if (isBatch) {
            aggregated.processed += Number(data.processed || 0);
            aggregated.changed += Number(data.changed || 0);
            aggregated.details = aggregated.details.concat(data.details || []).slice(0, 80);
            aggregated.errors = aggregated.errors.concat(data.errors || []).slice(0, 40);
            aggregated.stats = mergeStats(aggregated.stats, data.stats || {});
            renderBatchStatus(statusBox, data, aggregated);
            if (data.finished) break;
            offset = Number(data.next_offset || 0);
          } else {
            aggregated.message = data.message || 'Akce byla dokončena.';
            aggregated.details = data.details || [];
            renderSimpleStatus(statusBox, aggregated.message, aggregated.details);
            if (action === 'clear_logs') {
              renderGlobalNotice('success', aggregated.message || 'Logy byly smazány.');
              statusBox.hidden = true;
              statusBox.innerHTML = '';
            }
            break;
          }
        }

        await refreshLogs();
      } catch (error) {
        statusBox.hidden = false;
        statusBox.innerHTML = '<div class="notice notice-error inline"><p>' + escapeHtml(error.message || 'Akce selhala.') + '</p></div>';
      } finally {
        button.disabled = false;
      }
    });
  });

  async function refreshLogs() {
    const logList = document.querySelector('.swio-log-list');
    if (!logList) return;

    const params = new URLSearchParams();
    params.append('action', 'swio_get_logs');
    params.append('nonce', nonce);

    const response = await fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: params.toString()
    });

    try {
      const payload = await parseJsonResponse(response);
      if (payload.success && payload.data && typeof payload.data.html === 'string') {
        logList.innerHTML = payload.data.html;
      }
    } catch (error) {
      console.warn('SWIO log refresh failed:', error);
    }
  }


  async function parseJsonResponse(response) {
    const raw = await response.text();
    try {
      return JSON.parse(raw);
    } catch (error) {
      const snippet = String(raw || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 220);
      throw new Error(snippet || 'Server vrátil neplatnou odpověď.');
    }
  }

  function mergeStats(base, incoming) {
    const merged = Object.assign({}, base);
    Object.keys(incoming || {}).forEach((key) => {
      const value = incoming[key];
      if (typeof value === 'number') {
        merged[key] = Number(merged[key] || 0) + value;
      } else if (/^\d+(\.\d+)?$/.test(String(value))) {
        merged[key] = Number(merged[key] || 0) + Number(value);
      } else {
        merged[key] = value;
      }
    });
    return merged;
  }

  function renderGlobalNotice(type, message) {
    if (!globalNoticeWrap) return;
    let cssClass = 'notice notice-info';
    if (type === 'success') cssClass = 'notice notice-success';
    if (type === 'warning') cssClass = 'notice notice-warning';
    if (type === 'error') cssClass = 'notice notice-error';
    globalNoticeWrap.innerHTML = '<div class="' + cssClass + '"><p>' + escapeHtml(message) + '</p></div>';
  }

  function formatBytes(bytes) {
    const numeric = Number(bytes || 0);
    if (!numeric) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = numeric;
    let unitIndex = 0;
    while (value >= 1024 && unitIndex < units.length - 1) {
      value /= 1024;
      unitIndex += 1;
    }
    return (Math.round(value * 100) / 100) + ' ' + units[unitIndex];
  }

  function renderSimpleStatus(box, message, details) {
    let html = '<div class="notice notice-success inline"><p><strong>' + escapeHtml(message) + '</strong></p></div>';
    if (details && details.length) {
      html += '<ul class="swio-result-list">';
      details.forEach((item) => { html += '<li>' + escapeHtml(item) + '</li>'; });
      html += '</ul>';
    }
    box.innerHTML = html;
  }

  function renderBatchStatus(box, current, aggregated) {
    const total = aggregated.stats.total ? Number(aggregated.stats.total) : 0;
    const processed = Number(aggregated.processed || 0);
    const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
    const doneText = current.finished ? 'Hotovo' : 'Probíhá zpracování';

    let html = '';
    html += '<div class="swio-progress"><div class="swio-progress-bar" style="width:' + percent + '%"></div></div>';
    html += '<p><strong>' + escapeHtml(doneText) + ':</strong> zkontrolováno ' + processed + (total ? ' z ' + total : '') + ', změněno/smazáno ' + Number(aggregated.changed || 0) + '.</p>';

    if (aggregated.stats.external || aggregated.stats.manual || aggregated.stats.saved_bytes) {
      html += '<div class="swio-inline-stats">';
      if (aggregated.stats.external) html += '<span>Externí varianty: <strong>' + Number(aggregated.stats.external) + '</strong></span>';
      if (aggregated.stats.manual) html += '<span>Ruční kontrola: <strong>' + Number(aggregated.stats.manual) + '</strong></span>';
      if (aggregated.stats.saved_bytes) html += '<span>Uvolněno: <strong>' + escapeHtml(formatBytes(aggregated.stats.saved_bytes)) + '</strong></span>';
      html += '</div>';
    }
    if (aggregated.stats.why) html += '<p class="description">' + escapeHtml(aggregated.stats.why) + '</p>';

    if (aggregated.details.length) {
      html += '<details open class="swio-details"><summary>Detail výsledku</summary><ul class="swio-result-list">';
      aggregated.details.forEach((item) => { html += '<li>' + escapeHtml(item) + '</li>'; });
      html += '</ul></details>';
    }
    if (aggregated.errors.length) {
      html += '<details class="swio-details"><summary>Chyby (' + aggregated.errors.length + ')</summary><ul class="swio-result-list swio-errors">';
      aggregated.errors.forEach((item) => { html += '<li>' + escapeHtml(item) + '</li>'; });
      html += '</ul></details>';
    }
    box.innerHTML = html;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
});
