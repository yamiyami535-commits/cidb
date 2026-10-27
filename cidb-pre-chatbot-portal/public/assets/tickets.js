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
      toggle.querySelector('span').textContent = collapsed ? 'Show menu' : 'Hide menu';
    };
    setCollapsed(window.matchMedia('(max-width: 760px)').matches);
    toggle.addEventListener('click', () => setCollapsed(!menu.hidden));
  }
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
