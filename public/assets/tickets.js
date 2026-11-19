document.addEventListener('DOMContentLoaded', () => {
  const shell = document.querySelector('[data-ticket-shell]');
  const toggle = document.querySelector('[data-menu-toggle]');
  const menu = document.getElementById('ticket-menu');
  if (shell && toggle && menu) {
    toggle.hidden = false;
    const setCollapsed = collapsed => {
      shell.classList.toggle('tm-menu-hidden', collapsed);
      menu.hidden = collapsed;
      toggle.setAttribute('aria-expanded', String(!collapsed));
      toggle.querySelector('span').textContent = collapsed ? '›' : '‹';
      toggle.setAttribute('aria-label', collapsed ? 'Expand ticket menu' : 'Collapse ticket menu');
      toggle.title = toggle.getAttribute('aria-label');
    };
    setCollapsed(window.matchMedia('(max-width: 760px)').matches);
    toggle.addEventListener('click', () => setCollapsed(!menu.hidden));
    shell.addEventListener('keydown', event => { if (event.key === 'Escape') { setCollapsed(true); toggle.focus(); } });
  }
  document.querySelectorAll('[data-identity-url]').forEach(button => {
    const container = button.closest('.tm-identity');
    const value = container.querySelector('[data-identity-value]');
    const error = container.querySelector('[data-identity-error]');
    const masked = value.textContent;
    let controller;
    const hide = () => {
      controller?.abort();
      value.textContent = masked;
      button.setAttribute('aria-pressed', 'false');
      button.setAttribute('aria-label', 'Reveal ID number');
      error.textContent = '';
    };
    button.addEventListener('click', async () => {
      if (button.getAttribute('aria-pressed') === 'true') { hide(); return; }
      button.disabled = true;
      error.textContent = '';
      controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 15000);
      try {
        const response = await fetch(button.dataset.identityUrl, {credentials:'same-origin', cache:'no-store', signal:controller.signal, headers:{Accept:'application/json'}});
        if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Unable to reveal ID. Please try again.');
        const data = await response.json();
        if (typeof data.identity !== 'string' || !data.identity) throw new Error('No ID number is available.');
        if (controller.signal.aborted || document.hidden) return;
        value.textContent = data.identity;
        button.setAttribute('aria-pressed', 'true');
        button.setAttribute('aria-label', 'Hide ID number');
      } catch (failure) {
        if (!document.hidden) error.textContent = failure.name === 'AbortError' ? 'Request cancelled. Please try again.' : failure.message;
      } finally { clearTimeout(timeout); button.disabled = false; }
    });
    window.addEventListener('pagehide', hide);
    window.addEventListener('pageshow', hide);
    document.addEventListener('visibilitychange', () => { if (document.hidden) hide(); });
  });
  document.querySelectorAll('[data-ticket-form]').forEach(form => {
    form.addEventListener('submit', () => {
      form.setAttribute('aria-busy', 'true');
      const message = document.querySelector('[data-loading-message]');
      if (message) message.hidden = false;
    });
  });
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-ticket-form]').forEach(form => form.removeAttribute('aria-busy'));
    const message = document.querySelector('[data-loading-message]');
    if (message) message.hidden = true;
  });
});
