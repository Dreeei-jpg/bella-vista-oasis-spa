'use strict';

// Quote changes refresh the authoritative server summary; this never completes a payment.
const demoQuoteForm = document.querySelector('.payment-options');
demoQuoteForm?.addEventListener('change', event => {
  if (event.target.name !== 'payment_choice') return;
  const method = document.querySelector('#demo-method')?.value;
  if (method) {
    let field = demoQuoteForm.querySelector('[name="method"]');
    if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = 'method'; demoQuoteForm.append(field); }
    field.value = method;
  }
  demoQuoteForm.requestSubmit();
});
