document.addEventListener('DOMContentLoaded', function () {
  const accordions = document.querySelectorAll('[data-swio-accordion]');

  accordions.forEach((accordion) => {
    const items = accordion.querySelectorAll('.swio-accordion-item');

    items.forEach((item) => {
      const toggle = item.querySelector('.swio-accordion-toggle');
      const panel = item.querySelector('.swio-accordion-panel');

      if (!toggle || !panel) return;

      toggle.addEventListener('click', () => {
        const isOpen = item.classList.contains('is-open');

        items.forEach((other) => {
          other.classList.remove('is-open');
          const otherToggle = other.querySelector('.swio-accordion-toggle');
          const otherPanel = other.querySelector('.swio-accordion-panel');
          if (otherToggle) otherToggle.setAttribute('aria-expanded', 'false');
          if (otherPanel) otherPanel.hidden = true;
        });

        if (!isOpen) {
          item.classList.add('is-open');
          toggle.setAttribute('aria-expanded', 'true');
          panel.hidden = false;
        }
      });
    });
  });

  const actionButtons = document.querySelectorAll('.swio-run-action');
  actionButtons.forEach((button) => {
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
      let aggregated = {
        processed: 0,
        changed: 0,
        details: [],
        errors: [],
        stats: {},
        message: ''
      };

      try {
        while (true) {
          const formData = new FormData();
          formData.append('action', 'swio_run_action');
          formData.append('nonce', swioAdmin.nonce);
          formData.append('swio_action', action);
          formData.append('swio_offset', String(offset));

          const response = await fetch(swioAdmin.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
          });

          const payload = await response.json();
          if (!payload.success) {
            throw new Error((payload.data && payload.data.message) ? payload.data.message : 'Akce selhala.');
          }

          const data = payload.data || {};

          if (isBatch) {
            aggregated.processed += Number(data.processed || 0);
            aggregated.changed += Number(data.changed || 0);
            aggregated.details = aggregated.details.concat(data.details || []).slice(0, 40);
            aggregated.errors = aggregated.errors.concat(data.errors || []).slice(0, 25);
            aggregated.stats = Object.assign({}, aggregated.stats, data.stats || {});
            renderBatchStatus(statusBox, data, aggregated);

            if (data.finished) {
              break;
            }

            offset = Number(data.next_offset || 0);
          } else {
            aggregated.message = data.message || 'Akce byla dokončena.';
            aggregated.details = data.details || [];
            renderSimpleStatus(statusBox, aggregated.message, aggregated.details);
            break;
          }
        }
      } catch (error) {
        statusBox.innerHTML = '<div class="notice notice-error inline"><p>' + escapeHtml(error.message || 'Akce selhala.') + '</p></div>';
      } finally {
        button.disabled = false;
      }
    });
  });

  function renderSimpleStatus(box, message, details) {
    let html = '<div class="notice notice-success inline"><p><strong>' + escapeHtml(message) + '</strong></p></div>';
    if (details && details.length) {
      html += '<ul class="swio-result-list">';
      details.forEach((item) => {
        html += '<li>' + escapeHtml(item) + '</li>';
      });
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
    html += '<div class="swio-progress">';
    html += '<div class="swio-progress-bar" style="width:' + percent + '%"></div>';
    html += '</div>';
    html += '<p><strong>' + escapeHtml(doneText) + ':</strong> zkontrolováno ' + processed + (total ? ' z ' + total : '') + ', změněno/smazáno ' + Number(aggregated.changed || 0) + '.</p>';

    if (aggregated.stats.why) {
      html += '<p class="description">' + escapeHtml(aggregated.stats.why) + '</p>';
    }

    if (aggregated.details.length) {
      html += '<details open class="swio-details"><summary>Detail výsledku</summary><ul class="swio-result-list">';
      aggregated.details.forEach((item) => {
        html += '<li>' + escapeHtml(item) + '</li>';
      });
      html += '</ul></details>';
    }

    if (aggregated.errors.length) {
      html += '<details class="swio-details"><summary>Chyby (' + aggregated.errors.length + ')</summary><ul class="swio-result-list swio-errors">';
      aggregated.errors.forEach((item) => {
        html += '<li>' + escapeHtml(item) + '</li>';
      });
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
