'use strict';

const invitationLink = document.querySelector('[data-admin-invitation]');
if (invitationLink) {
  const copyInvitation = document.querySelector('[data-copy-admin-invitation]');
  const feedback = document.querySelector('[data-invitation-status]');
  invitationLink.value = new URL(invitationLink.value, window.location.origin).href;
  copyInvitation.hidden = false;
  copyInvitation.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(invitationLink.value);
      feedback.textContent = 'Invitation link copied.';
    } catch {
      invitationLink.select();
      feedback.textContent = 'Link selected. Use your device’s Copy command.';
    }
  });
}

const setupForm = document.querySelector('[data-admin-setup]');
if (setupForm) {
  const token = new URLSearchParams(window.location.hash.slice(1)).get('token');
  const feedback = document.querySelector('[data-admin-setup-message]');
  const showError = message => {
    feedback.textContent = message;
    feedback.hidden = false;
  };
  // Fragments do not enter the request URL. Remove this one from the address bar after reading it.
  if (window.location.hash) history.replaceState(null, '', window.location.pathname);
  if (!token || !/^[a-f0-9]{64}$/.test(token)) {
    showError('Open your complete invitation link to set your password.');
  } else {
    setupForm.hidden = false;
    const submit = setupForm.querySelector('[type="submit"]');
    setupForm.addEventListener('submit', async event => {
      if (event.defaultPrevented) return;
      event.preventDefault();
      if (submit.disabled || !setupForm.reportValidity()) return;
      feedback.hidden = true;
      submit.disabled = true;
      const data = new FormData(setupForm);
      data.set('token', token);
      try {
        const response = await fetch(setupForm.action, {method:'POST', body:data, headers:{Accept:'application/json'}});
        const result = await response.json();
        if (response.ok && result.redirect) {
          window.location.assign(result.redirect);
          return;
        }
        showError(result.error || 'We could not activate your account. Please try again.');
      } catch {
        showError('We could not reach the site. Check your connection and try again.');
      } finally {
        submit.disabled = false;
      }
    });
  }
}
