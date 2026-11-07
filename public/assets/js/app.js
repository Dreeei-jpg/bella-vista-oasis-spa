'use strict';

const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('#main-nav');
menuButton?.addEventListener('click', () => {
  const open = menuButton.getAttribute('aria-expanded') !== 'true';
  menuButton.setAttribute('aria-expanded', String(open));
  navigation.classList.toggle('is-open', open);
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && navigation?.classList.contains('is-open')) {
    navigation.classList.remove('is-open');
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.focus();
  }
});

document.querySelectorAll('[data-toggle-password]').forEach(button => {
  const label = (button.getAttribute('aria-label') || 'Show password').replace(/^Show\s+/i, '');
  button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.togglePassword);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.textContent = show ? 'Hide' : 'Show';
    button.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + label);
  });
});

document.querySelectorAll('form[data-validate]').forEach(form => {
  const password = form.querySelector('[name="password"]');
  const confirmation = form.querySelector('[name="password_confirmation"]');
  const validate = () => {
    if (confirmation) confirmation.setCustomValidity(confirmation.value !== password.value ? 'The passwords do not match.' : '');
    if (password) password.setCustomValidity(new TextEncoder().encode(password.value).length > 72 ? 'This password exceeds the 72-byte limit. Please shorten it.' : '');
  };
  form.addEventListener('input', validate);
  form.addEventListener('submit', event => {
    validate();
    if (!form.reportValidity()) event.preventDefault();
  });
});
document.querySelector('.notice.error')?.focus();

document.querySelectorAll('.price-estimator').forEach(estimator => {
  const select = estimator.querySelector('select');
  const update = () => {
    const total = Number(estimator.dataset.base) + Math.max(0, Number(select.value) - Number(estimator.dataset.included)) * Number(estimator.dataset.extra);
    estimator.querySelector('output').textContent = new Intl.NumberFormat('en-PH', {style:'currency', currency:'PHP', maximumFractionDigits:0}).format(total);
  };
  select.addEventListener('change', update);
  update();
});

document.querySelectorAll('[data-filter]').forEach(button => {
  button.addEventListener('click', () => {
    document.querySelectorAll('[data-filter]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
    document.querySelectorAll('[data-category]').forEach(item => {
      item.hidden = button.dataset.filter !== 'all' && item.dataset.category !== button.dataset.filter;
    });
  });
});
const lightbox = document.querySelector('#lightbox');
document.querySelectorAll('.gallery-image').forEach(button => {
  button.addEventListener('click', () => {
    const original = button.querySelector('img');
    lightbox.querySelector('img').src = original.src;
    lightbox.querySelector('img').alt = original.alt;
    lightbox.querySelector('p').textContent = button.dataset.caption;
    lightbox.showModal();
    document.body.classList.add('modal-open');
  });
});
lightbox?.querySelector('.lightbox-close').addEventListener('click', () => lightbox.close());
lightbox?.addEventListener('close', () => document.body.classList.remove('modal-open'));
lightbox?.addEventListener('click', event => { if (event.target === lightbox) lightbox.close(); });

document.querySelectorAll('[data-copy-reference]').forEach(button => {
  button.hidden = false;
  button.addEventListener('click', async () => {
    const panel = button.closest('.reference-panel');
    const status = panel.querySelector('.copy-status');
    try {
      await navigator.clipboard.writeText(button.dataset.copyReference);
      status.textContent = 'Reference copied.';
    } catch {
      const range = document.createRange();
      range.selectNodeContents(panel.querySelector('.booking-reference'));
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      status.textContent = 'Reference selected. Use your device’s Copy command.';
    }
  });
});

// Keep content visible without JavaScript; motion is an optional enhancement.
if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && 'IntersectionObserver' in window) {
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.animate([{opacity:0.65,transform:'translateY(18px)'},{opacity:1,transform:'translateY(0)'}],{duration:650,easing:'ease-out'});
        observer.unobserve(entry.target);
      }
    });
  }, {threshold:0.12});
  document.querySelectorAll('.intro-section, .service-feature, .history-preview, .story-section').forEach(section => observer.observe(section));
}
