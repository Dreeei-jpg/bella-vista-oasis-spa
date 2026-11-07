'use strict';

(() => {
  const key = 'bella-vista-theme';
  const system = matchMedia('(prefers-color-scheme: dark)');
  const root = document.documentElement;
  let choice;
  try { choice = localStorage.getItem(key); } catch { /* Storage may be disabled. */ }
  if (choice !== 'dark' && choice !== 'light') choice = null;
  function apply() {
    const theme = choice ?? (system.matches ? 'dark' : 'light');
    root.dataset.theme = theme;
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
      button.hidden = false;
      button.setAttribute('aria-pressed', String(theme === 'dark'));
      button.title = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
    });
  }
  apply();
  document.addEventListener('DOMContentLoaded', () => {
    apply();
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
      button.addEventListener('click', () => {
        choice = root.dataset.theme === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(key, choice); } catch { /* Keep the current page usable. */ }
        apply();
      });
    });
  });
  system.addEventListener('change', apply);
})();
