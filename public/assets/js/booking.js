'use strict';

(() => {
  const app = document.querySelector('#booking-app');
  if (!app) return;
  const choices = document.querySelector('#availability-form');
  const confirmation = document.querySelector('#confirm-booking-form');
  const room = document.querySelector('#booking-room');
  const roomStatus = document.querySelector('#room-status');
  const therapistChoices = document.querySelector('#therapist-choices');
  const therapistStatus = document.querySelector('#therapist-status');
  const guests = document.querySelector('#booking-guests');
  const date = document.querySelector('#booking-date');
  const grid = document.querySelector('#time-slots');
  const status = document.querySelector('#slot-status');
  const submit = document.querySelector('#confirm-booking');
  const currency = new Intl.NumberFormat('en-PH', {style:'currency',currency:'PHP',maximumFractionDigits:0});
  let controller;
  let sequence = 0;
  let slots = [];
  const summary = (key, value) => { document.querySelector(`#summary-${key}`).textContent = value; };
  const selectedSlot = () => slots.find(slot => slot.time === confirmation.querySelector('[name="time"]:checked')?.value);
  const selectedRoom = () => selectedSlot()?.rooms.find(item => item.available && String(item.id) === room.value);
  function updateTherapists() {
    const selected = confirmation.querySelector('[name="therapist_id"]:checked')?.value;
    const staff = selectedRoom() ? selectedSlot().therapists ?? [] : [];
    therapistChoices.replaceChildren();
    const any = confirmation.querySelector('[name="therapist_id"][value=""]');
    any.checked = true;
    summary('therapist', 'Any Available Therapist');
    for (const therapist of staff) {
      const label = document.createElement('label');
      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = 'therapist_id';
      radio.value = therapist.id;
      radio.dataset.therapistName = therapist.full_name;
      const name = document.createElement('span');
      name.textContent = therapist.full_name;
      const availability = document.createElement('small');
      availability.textContent = 'Available';
      name.append(availability);
      label.append(radio, name);
      therapistChoices.append(label);
      if (radio.value === selected) {
        radio.checked = true;
        summary('therapist', therapist.full_name);
      }
    }
    therapistStatus.textContent = staff.length
      ? 'You can choose a therapist or leave the assignment to us.'
      : 'Choose a time and room to see available therapists.';
  }
  function updateSubmit() { submit.disabled = !selectedRoom() || !choices.checkValidity(); }
  function resetRooms(message = 'Choose a start time first.') {
    room.replaceChildren(new Option(message, ''));
    room.disabled = true;
    roomStatus.textContent = message;
    summary('room', 'Choose a room');
    updateTherapists();
    updateSubmit();
  }
  function syncChoices(serviceChanged = false) {
    const selected = choices.querySelector('[name="service_id"]:checked');
    if (!selected) return;
    guests.max = selected.dataset.max;
    if (serviceChanged) guests.value = selected.dataset.included;
    const total = Number(selected.dataset.base) + Math.max(0, Number(guests.value) - Number(selected.dataset.included)) * Number(selected.dataset.extra);
    summary('service', selected.dataset.name);
    summary('duration', `${selected.dataset.duration} minutes`);
    summary('guests', guests.value || 'Choose guests');
    summary('date', date.value || 'Choose a date');
    summary('total', guests.validity.valid ? currency.format(total) : 'Check guest count');
    for (const key of ['service_id','guests','date']) confirmation.querySelector(`[name="${key}"]`).value = new FormData(choices).get(key) || '';
  }
  function invalidate() {
    controller?.abort();
    ++sequence;
    slots = [];
    grid.replaceChildren();
    grid.removeAttribute('aria-busy');
    resetRooms();
    summary('time', 'Choose a time');
    summary('end', 'Choose a time');
    status.dataset.state = 'idle';
    status.textContent = 'Refresh available times for your selection.';
  }
  function selectTime() {
    const slot = selectedSlot();
    if (!slot?.available) { resetRooms(slot?.reason || 'Choose an available time first.'); return; }
    const previous = room.value;
    const available = slot.rooms.filter(item => item.available);
    room.replaceChildren(new Option('Choose an available room', ''));
    available.forEach(item => room.add(new Option(`${item.code} · ${item.capacity} ${item.capacity === 1 ? 'person' : 'persons'}`, String(item.id))));
    room.disabled = false;
    room.value = available.some(item => String(item.id) === previous) ? previous : '';
    roomStatus.textContent = `${available.length} ${available.length === 1 ? 'room is' : 'rooms are'} available at ${slot.label}, including the cleaning gap. Availability is checked again when saving.`;
    summary('time', slot.label);
    const service = choices.querySelector('[name="service_id"]:checked');
    const endMinutes = Number(slot.time.slice(0,2))*60 + Number(slot.time.slice(3)) + Number(service.dataset.duration);
    const endHour = Math.floor(endMinutes/60);
    summary('end', `${endHour%12 || 12}:${String(endMinutes%60).padStart(2,'0')} ${endHour>=12?'PM':'AM'}`);
    summary('room', selectedRoom()?.code || 'Choose a room');
    updateTherapists();
    updateSubmit();
  }
  function renderAvailability(data) {
    if (!data || !Array.isArray(data.slots) || typeof data.message !== 'string' || !Number.isFinite(Number(data.total)) || data.slots.some(slot => !slot || typeof slot.time !== 'string' || typeof slot.available !== 'boolean' || !Array.isArray(slot.rooms))) throw new Error('We could not load appointment times. Please try again.');
    slots = data.slots;
    grid.replaceChildren();
    slots.forEach(slot => {
      const label = document.createElement('label');
      label.className = 'time-slot' + (slot.available ? '' : ' unavailable');
      const input = document.createElement('input');
      input.type = 'radio'; input.name = 'time'; input.value = slot.time;
      input.required = true; input.disabled = !slot.available;
      const text = document.createElement('span'); text.textContent = slot.label;
      const note = document.createElement('small');
      const count = slot.rooms.filter(item => item.available).length;
      note.textContent = slot.reason || `${count} ${count === 1 ? 'room' : 'rooms'} available`;
      text.append(note); label.append(input, text); grid.append(label);
    });
    status.dataset.state = data.status;
    status.textContent = data.message;
    summary('total', currency.format(data.total));
    resetRooms(slots.some(slot => slot.available) ? 'Choose a start time first.' : data.message);
  }
  async function refresh() {
    invalidate(); syncChoices();
    if (!choices.reportValidity()) {
      status.dataset.state = 'invalid_selection';
      status.textContent = guests.validity.valid ? 'Choose a valid treatment and a current or future date.' : `Enter a whole guest count from 1 to ${guests.max} for this treatment.`;
      return;
    }
    const current = ++sequence;
    controller = new AbortController();
    status.dataset.state = 'loading';
    status.textContent = 'Checking available rooms and therapists…';
    grid.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(`${app.dataset.availabilityUrl}?${new URLSearchParams(new FormData(choices))}`, {signal:controller.signal,headers:{Accept:'application/json'}});
      let data;
      try { data = await response.json(); } catch { throw new Error('We could not check availability right now. Please try again.'); }
      if (current !== sequence) return;
      if (!response.ok) throw new Error(data.error || 'We could not check availability right now. Please try again.');
      renderAvailability(data);
    } catch (error) {
      if (error.name !== 'AbortError' && current === sequence) {
        slots = []; grid.replaceChildren(); resetRooms('Room availability has not been verified.');
        status.dataset.state = 'error';
        status.textContent = error instanceof TypeError ? 'Could not connect to the availability service. Check your connection and try again.' : error.message;
      }
    } finally { if (current === sequence) grid.removeAttribute('aria-busy'); }
  }
  choices.addEventListener('submit', event => { event.preventDefault(); refresh(); });
  choices.addEventListener('change', event => {
    invalidate(); syncChoices(event.target.name === 'service_id');
    refresh();
  });
  guests.addEventListener('input', () => { invalidate(); syncChoices(); });
  confirmation.addEventListener('change', event => {
    if(event.target.name === 'payment_choice') summary('payment',event.target.value === 'downpayment'?`${app.dataset.demoPercentage}% downpayment`:'Full payment');
    if (event.target.name === 'time') selectTime();
    if (event.target.name === 'room_id') { summary('room', selectedRoom()?.code || 'Choose a room'); updateTherapists(); updateSubmit(); }
    if (event.target.name === 'therapist_id') summary('therapist', event.target.dataset.therapistName || 'Any Available Therapist');
  });
  confirmation.addEventListener('submit', event => {
    if (!selectedRoom() || !choices.checkValidity()) {
      event.preventDefault(); status.textContent = 'Choose a currently available start time and room before confirming.'; return;
    }
    if (confirmation.checkValidity()) { submit.disabled = true; submit.textContent = 'Saving your appointment…'; }
  });
  window.addEventListener('pageshow', () => { updateSubmit(); submit.textContent = app.dataset.submitLabel; });
  syncChoices(); resetRooms();
  try { const initial = JSON.parse(app.dataset.availability); if (initial) renderAvailability(initial); }
  catch { status.dataset.state = 'error'; status.textContent = 'Availability could not be loaded. Use Find available times to retry.'; }
})();
