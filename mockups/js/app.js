/* MediBook mockup app: state, rules, router, views and actions.
   Plain JavaScript, no build step. Data lives in memory and in localStorage (if allowed). */
'use strict';
(function (MB) {
  const C = MB.CONFIG;
  const KEY = 'medibook-mockup';
  let S;
  const ui = { lastLogged: null, hoursDoc: null, hoursDraft: null, dlg: null, slot: null, menuOpen: false };

  /* ================= Utilities ================= */
  const $ = (sel, el) => (el || document).querySelector(sel);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const T = MB.fmtTime;
  const D = MB.fmtDate;
  const today = () => MB.now().date;

  const ICONS = {
    check: '<path d="M20 6 9 17l-5-5"/>',
    arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    left: '<path d="m15 18-6-6 6-6"/>',
    right: '<path d="m9 18 6-6-6-6"/>',
    up: '<path d="m18 15-6-6-6 6"/>',
    down: '<path d="m6 9 6 6 6-6"/>',
    cal: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
    lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2Z"/>',
    alert: '<path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    clip: '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/>',
    clipcheck: '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="m9 14 2 2 4-4"/>',
    cliplines: '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/>',
    steth: '<path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6 6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6 6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/>',
    repeat: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
    baby: '<circle cx="12" cy="8" r="5"/><path d="M9.5 8h.01M14.5 8h.01M10 10.5a3 3 0 0 0 4 0"/><path d="M6 21a6 6 0 0 1 12 0"/>',
    syringe: '<path d="m18 2 4 4M17 7l3-3M19 9 8.7 19.3a1 1 0 0 1-1.4 0l-2.6-2.6a1 1 0 0 1 0-1.4L15 5"/><path d="m9 11 4 4M5 19l-3 3M14 4l6 6"/>',
    moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/><path d="M19 3v4M21 5h-4"/>',
    sparkle: '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8Z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8Z"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
    list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M9 13h6M9 17h4"/>',
    chart: '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    bolt: '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8Z"/>',
    star: '<path d="M12 3l2.6 5.3 5.9.9-4.2 4.1 1 5.8L12 16.4 6.7 19.1l1-5.8L3.5 9.2l5.9-.9Z"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    calx: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M10 14l4 4M14 14l-4 4"/>'
  };
  const icon = (n, size, sw) => '<svg width="' + (size || 18) + '" height="' + (size || 18) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (sw || 2) + '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[n] || '') + '</svg>';
  const flagIcon = (size) => '<svg width="' + (size || 10) + '" height="' + (size || 10) + '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 22V4a1 1 0 0 1 1.4-.9C7 4 9 3 11 3s3.5 1.5 6 1.5c1.2 0 2.2-.3 3-.6V15c-.8.3-1.8.6-3 .6-2.5 0-4-1.6-6-1.6s-4 1-5 1.5V22Z"/></svg>';
  const LOGO = '<svg width="28" height="28" viewBox="0 0 28 28" aria-hidden="true"><rect width="28" height="28" rx="8" fill="#0E6B66"/><path d="M14 8v12M8 14h12" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/></svg>';

  /* ================= State ================= */
  function load() {
    try {
      const raw = localStorage.getItem(KEY);
      if (raw) {
        const s = JSON.parse(raw);
        if (s && s.v === MB.VERSION && s.seedDate === today()) return s;
      }
    } catch (e) { /* storage blocked: start fresh */ }
    return MB.seed();
  }
  function save() { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* ignore */ } }
  const nid = (p) => p + (S.nextId++).toString(36);

  const svc = (id) => S.services.find((s) => s.id === id);
  const doc = (id) => MB.DOCTORS.find((d) => d.id === id);
  const user = (id) => S.users.find((u) => u.id === id);
  const me = () => (S.session ? user(S.session.userId) : null);
  const role = () => (S.session ? S.session.role : null);
  const myDoc = () => MB.DOCTORS.find((d) => d.userId === (S.session && S.session.userId));
  const appt = (id) => S.appts.find((a) => a.id === id);
  const first = (name) => String(name).split(' ')[0];
  const initials = (name) => String(name).replace(/^Dr\.\s*/, '').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
  const ACTIVE = ['booked', 'checked_in'];
  const STATUS_LABEL = { booked: 'Booked', checked_in: 'Checked in', completed: 'Completed', cancelled: 'Cancelled', no_show: 'No-show' };
  const ROLE_HOME = { patient: '/me', staff: '/staff', doctor: '/doctor', admin: '/admin' };
  const blockEnd = (a) => { const s = svc(a.serviceId); return a.start + s.dur + s.buf; };
  const apptEnd = (a) => a.start + svc(a.serviceId).dur;
  const isFuture = (a) => { const n = MB.now(); return a.date > n.date || (a.date === n.date && a.start > n.min); };
  const isUpcoming = (a) => ACTIVE.includes(a.status) && isFuture(a);
  const hasStarted = (a) => { const n = MB.now(); return a.date < n.date || (a.date === n.date && n.min >= a.start); };
  const minsUntil = (a) => { const n = MB.now(); return MB.daysBetween(n.date, a.date) * 1440 + a.start - n.min; };
  function ageRange(u) {
    if (!u || !u.birthYear) return '';
    const age = MB.dayParts(today()).y - u.birthYear;
    if (age < 1) return 'Under 1';
    if (age < 10) return 'Age ' + age;
    const lo = Math.floor(age / 10) * 10;
    return lo + ' to ' + (lo + 9);
  }
  const statusBadge = (st) => '<span class="badge b-' + st + '"><span class="dot"></span>' + STATUS_LABEL[st] + '</span>';
  function docAvatar(d, cls) { return '<span class="avatar ' + (cls || '') + ' av-' + d.tone + '">' + d.initials + '</span>'; }
  function svcIcon(s, cls) { return '<span class="ic ' + (s.tone === 'amber' ? 'amber' : '') + ' ' + (cls || '') + '">' + icon(s.icon, 24, 1.8) + '</span>'; }

  function log(action, subject, meta) {
    const n = MB.now();
    const u = me();
    S.audit.unshift({ id: nid('l'), date: n.date, time: T(n.min), actor: u ? u.id : 'guest', actorName: u ? u.name : 'Guest', action, subject, meta: meta || '' });
  }

  /* ================= Rules ================= */
  function slotsFor(serviceId, doctorId, date, opts) {
    opts = opts || {};
    const s = svc(serviceId);
    const n = MB.now();
    if (!s || date < n.date || MB.daysBetween(n.date, date) > C.windowDays) return [];
    const docs = doctorId && doctorId !== 'any' ? [doc(doctorId)] : MB.DOCTORS.filter((d) => d.services.includes(serviceId));
    const out = [];
    docs.forEach((d) => {
      if (!d || !d.services.includes(serviceId)) return;
      const ranges = S.hours[d.id][MB.weekday(date)] || [];
      const busy = S.appts.filter((a) => a.doctorId === d.id && a.date === date && a.id !== opts.exclude && a.status !== 'cancelled' && a.status !== 'no_show').map((a) => [a.start, blockEnd(a)]);
      S.timeOff.filter((t) => t.doctorId === d.id && t.date === date).forEach((t) => busy.push([t.start, t.end]));
      ranges.forEach(([rs, re]) => {
        for (let t = rs; t + s.dur + s.buf <= re; t += C.step) {
          if (date === n.date && t < n.min + C.leadMin) continue;
          const e = t + s.dur + s.buf;
          if (busy.some(([bs, be]) => t < be && e > bs)) continue;
          out.push({ start: t, doctorId: d.id });
        }
      });
    });
    out.sort((x, y) => x.start - y.start);
    if (docs.length > 1) { const seen = new Set(); return out.filter((x) => (seen.has(x.start) ? false : seen.add(x.start))); }
    return out;
  }
  function nextOpen(serviceId, doctorId, fromDate) {
    let d = fromDate || today();
    for (let i = 0; i <= C.windowDays; i++) {
      const sl = slotsFor(serviceId, doctorId, d);
      if (sl.length) return { date: d, start: sl[0].start, doctorId: sl[0].doctorId };
      d = MB.addDays(d, 1);
      if (MB.daysBetween(today(), d) > C.windowDays) break;
    }
    return null;
  }
  const works = (docId, date) => (S.hours[docId][MB.weekday(date)] || []).length > 0;

  function book(o) {
    const p = user(o.patientId);
    if (!p || !p.active) return { error: 'This account is disabled.' };
    const upcoming = S.appts.filter((a) => a.patientId === o.patientId && isUpcoming(a));
    if (upcoming.length >= C.maxUpcoming) return { error: 'You already have 3 upcoming appointments. Cancel one to book another.', code: 'limit' };
    const s = svc(o.serviceId);
    const ns = o.start, ne = o.start + s.dur + s.buf;
    if (upcoming.some((a) => a.date === o.date && a.start < ne && blockEnd(a) > ns)) return { error: 'You already have an appointment at this time.', code: 'overlap' };
    const free = slotsFor(o.serviceId, o.doctorId, o.date).some((x) => x.start === o.start && x.doctorId === o.doctorId);
    if (!free) return { error: 'This time is no longer free. Please pick another slot.', code: 'taken' };
    const a = { id: nid('a'), patientId: o.patientId, doctorId: o.doctorId, serviceId: o.serviceId, date: o.date, start: o.start, status: 'booked', emergency: false, bookedOn: today(), followUpOf: o.followUpId || null, bookedBy: o.bookedBy || 'patient' };
    S.appts.push(a);
    if (o.followUpId) {
      const f = S.followUps.find((x) => x.id === o.followUpId);
      if (f) { f.status = 'booked'; f.bookedApptId = a.id; f.history.unshift({ when: D(today()) + ', ' + T(MB.now().min), what: o.bookedBy === 'staff' ? 'Booked by front desk' : 'Booked by patient', kind: 'blue' }); }
    }
    log('appointment.booked', 'Appointment ' + a.id, 'to booked');
    save();
    return { appt: a };
  }
  function cancelAppt(a, by, reason) {
    a.status = 'cancelled';
    a.cancelledBy = by;
    a.cancelReason = reason || '';
    log('appointment.cancelled', 'Appointment ' + a.id, 'booked to cancelled');
    if (a.followUpOf) {
      const f = S.followUps.find((x) => x.id === a.followUpOf);
      if (f && f.bookedApptId === a.id) { f.status = 'open'; f.bookedApptId = null; f.history.unshift({ when: D(today()), what: 'Follow-up booking cancelled', kind: 'hollow' }); }
    }
    save();
  }
  function moveAppt(a, date, start, doctorId) {
    const free = slotsFor(a.serviceId, doctorId, date, { exclude: a.id }).some((x) => x.start === start && x.doctorId === doctorId);
    if (!free) return { error: 'This time is no longer free. Please pick another slot.' };
    const b = Object.assign({}, a, { id: nid('a'), date, start, doctorId, status: 'booked', movedFrom: a.id });
    a.status = 'cancelled'; a.cancelReason = 'Moved by front desk'; a.cancelledBy = 'staff'; a.movedTo = b.id;
    S.appts.push(b);
    if (S.intakes[a.id]) { S.intakes[b.id] = S.intakes[a.id]; delete S.intakes[a.id]; }
    if (S.summaries[a.id]) { S.summaries[b.id] = S.summaries[a.id]; delete S.summaries[a.id]; }
    S.followUps.forEach((f) => { if (f.bookedApptId === a.id) f.bookedApptId = b.id; });
    log('appointment.moved', 'Appointment ' + b.id, D(a.date) + ' ' + T(a.start) + ' to ' + D(date) + ' ' + T(start));
    save();
    return { appt: b };
  }
  function fuState(f) {
    const t = today();
    if (f.status === 'booked') { const b = appt(f.bookedApptId); return { key: 'booked', label: b ? 'Booked · ' + D(b.date) + ', ' + T(b.start) : 'Booked', cls: 'b-checked_in' }; }
    if (f.status === 'dismissed') return { key: 'dismissed', label: 'Patient said not needed', cls: 'b-outline' };
    if (t > f.to) { const n = MB.daysBetween(f.to, t); return { key: 'call', label: 'Not booked · ended ' + n + (n === 1 ? ' day' : ' days') + ' ago', cls: 'b-red' }; }
    if (t >= f.from) {
      const left = MB.daysBetween(t, f.to);
      if (left <= 1) return { key: 'call', label: 'Not booked · ends ' + (left === 0 ? 'today' : 'tomorrow'), cls: 'b-fail' };
      return { key: 'open', label: 'Open now · ' + left + ' days left', cls: 'b-warn' };
    }
    const n = MB.daysBetween(t, f.from);
    return { key: 'waiting', label: 'Waiting · opens in ' + n + (n === 1 ? ' day' : ' days'), cls: 'b-grey' };
  }

  /* ================= Toast, dialog, menu ================= */
  function toast(msg, kind) {
    let box = $('#toasts');
    const el = document.createElement('div');
    el.className = 'toast' + (kind === 'err' ? ' err' : '');
    el.innerHTML = (kind === 'err' ? icon('alert', 16) : icon('check', 16, 2.6)) + '<span>' + esc(msg) + '</span>';
    box.appendChild(el);
    setTimeout(() => el.remove(), 3600);
  }
  function dialog(o) {
    closeDialog(true);
    const bd = document.createElement('div');
    bd.className = 'backdrop';
    bd.innerHTML = '<div class="modal ' + (o.wide ? 'wide' : '') + '" role="dialog" aria-modal="true" aria-labelledby="dlg-title"></div>';
    document.body.appendChild(bd);
    ui.dlg = { el: bd, prev: document.activeElement, render: o.render };
    paintDialog();
    bd.addEventListener('mousedown', (e) => { if (e.target === bd) closeDialog(); });
    const f = bd.querySelector('input:not([type=hidden]), textarea, select, .modal-foot .btn-primary, button');
    if (f) f.focus();
  }
  function paintDialog() {
    if (!ui.dlg) return;
    const o = ui.dlg.render();
    const m = ui.dlg.el.querySelector('.modal');
    m.innerHTML = '<div class="modal-head"><div><h2 id="dlg-title">' + o.title + '</h2>' + (o.sub ? '<p>' + o.sub + '</p>' : '') + '</div><button type="button" class="icon-btn plain" data-act="dlg-close" aria-label="Close">' + icon('x', 16, 2.2) + '</button></div>' +
      '<div class="modal-body">' + o.body + '</div>' + (o.foot ? '<div class="modal-foot">' + o.foot + '</div>' : '');
  }
  function closeDialog(silent) {
    if (!ui.dlg) return;
    const prev = ui.dlg.prev;
    ui.dlg.el.remove();
    ui.dlg = null;
    ui.slot = null;
    if (!silent && prev && document.body.contains(prev)) prev.focus();
  }

  /* ================= Router ================= */
  const ROUTES = [
    [/^\/$/, viewLanding],
    [/^\/login$/, viewLogin],
    [/^\/register$/, viewRegister],
    [/^\/book\/service$/, viewBookService],
    [/^\/book\/doctor$/, viewBookDoctor],
    [/^\/book\/time$/, viewBookTime],
    [/^\/book\/confirm$/, viewBookConfirm],
    [/^\/booked\/(\w+)$/, viewBooked, 'patient'],
    [/^\/me$/, viewMyAppts, 'patient'],
    [/^\/me\/appt\/(\w+)$/, viewMyAppt, 'patient'],
    [/^\/me\/intake\/(\w+)$/, viewIntake, 'patient'],
    [/^\/me\/followup\/(\w+)$/, startFollowUp, 'patient'],
    [/^\/staff$/, viewStaffSchedule, 'staff admin'],
    [/^\/staff\/followups$/, viewStaffFollowUps, 'staff'],
    [/^\/doctor$/, viewDoctorToday, 'doctor'],
    [/^\/doctor\/appt\/(\w+)$/, viewDoctorAppt, 'doctor'],
    [/^\/admin$/, viewAdminUsers, 'admin'],
    [/^\/admin\/services$/, viewAdminServices, 'admin'],
    [/^\/admin\/hours$/, viewAdminHours, 'admin'],
    [/^\/admin\/audit$/, viewAdminAudit, 'admin'],
    [/^\/settings$/, viewSettings, 'patient staff doctor admin']
  ];
  function go(path) { if (location.hash === '#' + path) render(); else location.hash = path; }
  function parseHash() {
    const h = location.hash.replace(/^#/, '') || '/';
    const i = h.indexOf('?');
    return { path: i < 0 ? h : h.slice(0, i), q: new URLSearchParams(i < 0 ? '' : h.slice(i + 1)) };
  }
  function qs(obj) {
    const p = new URLSearchParams();
    Object.keys(obj).forEach((k) => { if (obj[k] !== null && obj[k] !== undefined && obj[k] !== '') p.set(k, obj[k]); });
    const s = p.toString();
    return s ? '?' + s : '';
  }

  function render() {
    if (S.seedDate !== today()) { S = MB.seed(); save(); }
    const { path, q } = parseHash();
    let out = null;
    for (const [re, fn, roles] of ROUTES) {
      const m = path.match(re);
      if (!m) continue;
      if (roles) {
        if (!S.session) { S.returnTo = path; save(); go('/login'); return; }
        if (!roles.split(' ').includes(role())) { out = viewForbidden(); break; }
      }
      out = fn(m[1], q);
      break;
    }
    if (!out) out = viewNotFound();
    if (out.redirect) { go(out.redirect); return; }
    document.title = (out.title ? out.title + ' · ' : '') + 'MediBook';
    document.body.classList.toggle('is-landing', !!out.landing);
    const app = $('#app');
    if (out.layout === 'app') app.innerHTML = appLayout(out);
    else if (out.layout === 'bare') app.innerHTML = '<div class="demo-bar">Demo with fake data. Do not enter real health information.</div>' + out.html;
    else app.innerHTML = publicLayout(out);
    renderDemoSwitch();
    if (out.after) out.after();
    if (!out.keepScroll) window.scrollTo(0, 0);
    const h1 = app.querySelector('h1');
    if (h1 && !ui.dlg) { h1.setAttribute('tabindex', '-1'); h1.focus({ preventScroll: true }); }
  }

  /* ================= Layouts ================= */
  function publicLayout(out) {
    const u = me();
    const right = u
      ? '<a class="user-chip" href="#' + ROLE_HOME[u.role] + '"><span class="avatar">' + initials(u.name) + '</span><span>' + esc(u.name) + '</span></a><button class="btn btn-secondary btn-sm" data-act="logout">Log out</button>'
      : '<a class="btn btn-secondary btn-sm" href="#/login">Log in</a><a class="btn btn-primary btn-sm" href="#/book/service">Book a visit</a>';
    const nav = out.landing
      ? '<button data-act="scroll" data-to="how">How it works</button><button data-act="scroll" data-to="services">Services</button><button data-act="scroll" data-to="doctors">Doctors</button><button data-act="scroll" data-to="privacy">Privacy</button><button data-act="scroll" data-to="faq">Questions</button>'
      : (u && u.role === 'patient' ? '<a href="#/me">My appointments</a>' : '') + '<a href="#/book/service" ' + (out.nav === 'book' ? 'aria-current="page"' : '') + '>Book an appointment</a>';
    return '<div class="demo-bar">Demo clinic with fake data. Please don\'t enter real health information.</div>' +
      '<header class="pub-header"><div class="pub-header-in"><a class="brand" href="#/">' + LOGO + '<span>MediBook</span></a><nav class="pub-nav" aria-label="Main">' + nav + '</nav><div class="pub-actions">' + right + '</div></div></header>' +
      '<main id="main">' + out.html + '</main>';
  }
  function appLayout(out) {
    const u = me();
    const r = role();
    const items = {
      patient: [['/me', 'My appointments', 'cal', S.followUps.some((f) => f.patientId === u.id && f.status === 'open') ? '<span class="dot-alert" title="Follow-up to book"></span>' : ''], ['/book/service', 'Book an appointment', 'plus'], ['/settings', 'Profile and security', 'shield']],
      staff: [['/staff', 'Day schedule', 'cal'], ['/staff/followups', 'Follow-ups', 'repeat', (() => { const n = S.followUps.filter((f) => fuState(f).key === 'call').length; return n ? '<span class="count red">' + n + '</span>' : ''; })()], ['/settings', 'Profile and security', 'shield']],
      doctor: [['/doctor', 'Today', 'clock', (() => { const d = myDoc(); const n = d ? S.appts.filter((a) => a.doctorId === d.id && a.date === today() && a.status !== 'cancelled').length : 0; return '<span class="count">' + n + '</span>'; })()], ['/settings', 'Profile and security', 'shield']],
      admin: [['#Clinic setup'], ['/admin', 'Users', 'users'], ['/admin/services', 'Services', 'list'], ['/admin/hours', 'Working hours and time off', 'clock'], ['#Oversight'], ['/admin/audit', 'Audit log', 'file'], ['/staff', 'Schedule (view only)', 'cal'], ['/settings', 'Profile and security', 'shield']]
    }[r];
    const nav = items.map((it) => it[0][0] === '#' ? '<span class="group">' + it[0].slice(1) + '</span>' : '<a href="#' + it[0] + '" ' + (out.nav === it[0] ? 'aria-current="page"' : '') + '>' + icon(it[2]) + it[1] + (it[3] || '') + '</a>').join('');
    const roleName = { patient: 'Patient', staff: 'Staff', doctor: 'Doctor', admin: 'Admin' }[r];
    const sub = r === 'patient' ? u.email : r === 'doctor' ? (myDoc() || {}).spec : r === 'staff' ? 'Front desk' : 'Clinic admin';
    return '<div class="demo-bar">Demo with fake data. All times in Manila time.</div><div class="app"><aside class="sidebar"><div class="side-brand"><a class="brand" href="#/">' + LOGO + '<span>MediBook</span></a><span class="role-badge role-' + r + '">' + roleName + '</span></div>' +
      '<nav class="side-nav" aria-label="Main">' + nav + '</nav>' +
      '<div class="side-user"><span class="avatar ' + (r === 'doctor' ? 'av-violet' : r === 'staff' ? 'av-blue' : r === 'admin' ? 'av-dark' : '') + '">' + initials(u.name) + '</span><div class="who"><span>' + esc(u.name) + '</span><span>' + esc(sub) + '</span></div><button class="icon-btn plain" data-act="logout" aria-label="Log out" title="Log out">' + icon('logout', 16) + '</button></div></aside>' +
      '<main class="main" id="main"><div class="main-in">' + out.html + '</div></main></div>';
  }

  /* ================= Demo switcher ================= */
  function renderDemoSwitch() {
    let el = $('#demo-switch');
    if (!el) { el = document.createElement('div'); el.id = 'demo-switch'; el.className = 'demo-switch'; document.body.appendChild(el); }
    const r = role();
    const label = r ? 'Demo · ' + { patient: 'Patient', staff: 'Staff', doctor: 'Doctor', admin: 'Admin' }[r] : 'Demo · Logged out';
    el.innerHTML = '<button type="button" data-act="demo-menu" aria-expanded="' + ui.menuOpen + '" aria-controls="demo-menu"><i></i>' + label + '</button>' +
      (ui.menuOpen ? '<div class="demo-menu" id="demo-menu"><p>Switch role to see each part of the app</p>' +
        [['patient', 'Patient · Ana Ramos'], ['staff', 'Staff · Joanna Dizon'], ['doctor', 'Doctor · Dr. Carmela Lim'], ['admin', 'Admin · Elena Castro']].map(([k, l]) => '<button type="button" data-act="demo-login" data-role="' + k + '">' + l + (r === k ? icon('check', 14, 2.6) : '') + '</button>').join('') +
        '<button type="button" data-act="go" data-to="/">Landing page</button>' + (r ? '<button type="button" data-act="logout">Log out</button>' : '') + '<button type="button" class="reset" data-act="reset">Reset demo data</button></div>' : '');
  }
  function demoLogin(r) {
    const id = { patient: 'p1', staff: 's1', doctor: 'u-lim', admin: 'ad1' }[r];
    const u = user(id);
    if (!u.active) { toast('This demo account is disabled. Reset demo data to turn it back on.', 'err'); return; }
    S.session = { userId: id, role: r };
    ui.menuOpen = false;
    const back = S.returnTo;
    S.returnTo = null;
    save();
    go(back && canOpen(back, r) ? back : ROLE_HOME[r]);
  }
  function canOpen(path, r) {
    const hit = ROUTES.find(([re]) => re.test(path));
    if (!hit) return false;
    return !hit[2] || hit[2].split(' ').includes(r);
  }

  /* ================= Public views ================= */
  function photo(file, label, credit, cls, style) {
    return '<div class="photo ' + (cls || '') + '" style="' + style + '" role="img" aria-label="' + esc(label) + '">' + icon('eye', 34, 1.6) + '<span>' + esc(label) + '</span><small>' + esc(credit) + '<br>Add ' + file + '</small><img src="' + file + '" alt="" onerror="this.remove()"></div>';
  }
  function clinicOpenLabel() {
    const n = MB.now();
    let latest = 0, open = false;
    MB.DOCTORS.forEach((d) => (S.hours[d.id][MB.weekday(n.date)] || []).forEach(([a, b]) => { if (n.min >= a && n.min < b) open = true; if (b > latest) latest = b; }));
    if (open) return { open: true, text: 'Open today until ' + T(latest) };
    for (let i = 0; i < 7; i++) {
      const d = MB.addDays(n.date, i);
      let start = null;
      MB.DOCTORS.forEach((doc) => (S.hours[doc.id][MB.weekday(d)] || []).forEach(([a]) => { if ((i > 0 || a > n.min) && (start === null || a < start)) start = a; }));
      if (start !== null) return { open: false, text: 'Opens ' + (i === 0 ? 'today' : i === 1 ? 'tomorrow' : D(d)) + ' at ' + T(start) };
    }
    return { open: false, text: 'Closed' };
  }
  const nextLabel = (n) => (n ? MB.relDay(n.date) + ', ' + T(n.start) : 'No times in the next 30 days');

  function viewLanding() {
    const op = clinicOpenLabel();
    const heroNext = nextOpen('gen', 'any');
    const svcCards = S.services.filter((s) => s.active).map((s) => '<button type="button" class="svc-card" data-act="pick-service-go" data-id="' + s.id + '">' + svcIcon(s) + '<strong>' + s.name + '</strong><p>' + s.desc + '</p><small>' + s.dur + ' min · Book now</small></button>').join('');
    const docCards = MB.DOCTORS.map((d) => { const n = nextOpen(d.services[0], d.id); return '<div class="doc-card">' + docAvatar(d, 'xl') + '<div><strong>' + d.name + '</strong><div class="muted" style="font-size:15px;margin-top:4px">' + d.spec + '</div></div><span class="next">Next open: <strong>' + nextLabel(n) + '</strong></span></div>'; }).join('');
    const html =
      '<section class="l-wrap l-hero" aria-labelledby="hero-h"><div class="copy">' +
      '<span class="open-pill ' + (op.open ? '' : 'closed') + '"><i></i>' + op.text + '</span>' +
      '<h1 id="hero-h">See the right doctor, <em>at a time that suits you.</em></h1>' +
      '<p class="lead">Book a visit in a few taps, tell your doctor what\'s going on before you arrive, and spend your appointment on what matters to you.</p>' +
      '<div class="row"><a class="btn btn-primary btn-lg" href="#/book/service">Book a visit' + icon('arrow') + '</a><button class="btn btn-secondary btn-lg" data-act="scroll" data-to="how">How it works</button></div>' +
      '<ul class="ticks"><li>' + icon('check', 18, 2.4) + 'Free to cancel up to 2 hours before</li><li>' + icon('check', 18, 2.4) + 'Reminder the day before</li></ul></div>' +
      '<div class="art">' + photo('assets/photo-hero.jpg', 'Photo 1 · Doctor portrait', 'Justyn Abacajan, Pexels', '', 'width:100%;max-width:460px;aspect-ratio:3/4') +
      '<div class="float-card" style="left:0;bottom:40px"><span class="ic">' + icon('cal', 22) + '</span><div><small>Next open time</small><strong>' + (heroNext ? MB.relDay(heroNext.date) + ', ' + T(heroNext.start) : 'Fully booked') + '</strong>' + (heroNext ? '<small>' + doc(heroNext.doctorId).name + '</small>' : '') + '</div></div></div></section>' +
      '<section class="l-wrap" aria-label="Find a time" style="padding-bottom:72px"><form class="quick" data-form="quick">' +
      '<div class="field"><label for="q-svc">What do you need?</label><select class="select" id="q-svc" name="service">' + S.services.filter((s) => s.active).map((s) => '<option value="' + s.id + '">' + s.name + '</option>').join('') + '</select></div>' +
      '<div class="field"><label for="q-doc">Doctor</label><select class="select" id="q-doc" name="doctor"><option value="any">Any available doctor</option>' + MB.DOCTORS.map((d) => '<option value="' + d.id + '">' + d.name + '</option>').join('') + '</select></div>' +
      '<div class="field"><label for="q-when">When</label><select class="select" id="q-when" name="when"><option value="0">Soonest available</option><option value="1">Tomorrow or later</option><option value="7">Next week</option></select></div>' +
      '<button class="btn btn-dark" type="submit">' + icon('search') + 'See open times</button></form></section>' +
      '<section class="l-sec white" id="how" aria-labelledby="how-h"><div class="l-wrap l-two">' + photo('assets/photo-phone.jpg', 'Photo 2 · Booking on a phone', 'Ketut Subiyanto, Pexels', 'sand', 'aspect-ratio:4/5;max-width:480px') +
      '<div><span class="kicker">How it works</span><h2 id="how-h">Three steps, about five minutes.</h2><ol class="how" style="margin-top:36px">' +
      '<li><span class="n">1</span><div><h3>Pick a time</h3><p>Choose a service and a doctor, or let us find whoever is free first. You only see times that are really open.</p></div></li>' +
      '<li><span class="n">2</span><div><h3>Tell us why you\'re coming</h3><p>A short form about your symptoms, allergies and medicines. Write it in your own words, at home, with no one rushing you.</p></div></li>' +
      '<li><span class="n">3</span><div><h3>Arrive and check in</h3><p>Your doctor has already read your answers, so the visit starts where it should: with you.</p></div></li></ol></div></div></section>' +
      '<section class="l-sec" id="services" aria-labelledby="svc-h"><div class="l-wrap"><div class="page-head" style="margin-bottom:40px"><div style="max-width:560px"><span class="kicker">Services</span><h2 id="svc-h">Everyday care for the whole family.</h2></div><p class="intro" style="max-width:380px;margin:0">Every visit shows how long it takes, so you can plan your day around it.</p></div><div class="grid-3">' + svcCards + '</div></div></section>' +
      '<section class="l-sec sand" id="doctors" aria-labelledby="doc-h"><div class="l-wrap"><div style="max-width:620px;margin-bottom:40px"><span class="kicker">Our doctors</span><h2 id="doc-h">Choose who you see, or take the first free time.</h2></div><div class="grid-4">' + docCards + '</div></div></section>' +
      '<section class="l-sec" id="privacy" aria-labelledby="prv-h"><div class="l-wrap l-two"><div><span class="kicker">Before your visit</span><h2 id="prv-h">Your doctor reads your answers. Nobody else does.</h2><p class="intro">What you write before your visit is private. It goes to your doctor, not the front desk, and every time it\'s opened is recorded.</p>' +
      '<ul class="priv" style="margin-top:32px"><li><span class="ic">' + icon('lock', 20) + '</span><div><strong>Stored encrypted</strong><p>Your answers and your doctor\'s notes are locked when saved.</p></div></li>' +
      '<li><span class="ic">' + icon('users', 20) + '</span><div><strong>Front desk sees only what they need</strong><p>Staff can see that your form is filled in, never what it says.</p></div></li>' +
      '<li><span class="ic">' + icon('cliplines', 20) + '</span><div><strong>A short summary, checked by your doctor</strong><p>We prepare a brief summary from your answers alone, without your name or contact details. Your doctor reads your full answers and checks the summary before your visit.</p></div></li></ul></div>' +
      photo('assets/photo-consult.jpg', 'Photo 3 · Doctor with patient', 'Vitaly Gariev, Unsplash', '', 'aspect-ratio:5/4;max-width:520px') + '</div></section>' +
      '<section class="l-wrap" style="padding-bottom:88px" aria-label="Emergencies"><div class="notice notice-danger" style="padding:24px 28px;border-radius:20px;align-items:center;flex-wrap:wrap;gap:18px"><span class="avatar lg" style="background:var(--danger);color:#fff">' + icon('phone', 22, 2.2) + '</span><div style="flex:1 1 400px"><strong style="font-size:18px;display:block">This is not for emergencies.</strong><span style="font-size:16px;line-height:24px">If you have chest pain, trouble breathing or heavy bleeding, call 911 or go to the nearest emergency room now.</span></div><a class="btn btn-danger" href="tel:911">Call 911</a></div></section>' +
      '<section class="l-sec white" id="faq" aria-labelledby="faq-h" style="border-bottom:0"><div class="l-wrap l-two" style="align-items:flex-start"><div style="flex:1 1 300px"><span class="kicker">Questions</span><h2 id="faq-h">Good to know</h2><p class="intro">Still unsure about something? The front desk is happy to help when you arrive.</p></div><div class="faq" style="flex:2 1 520px">' +
      faq('Can I change or cancel my booking?', 'Yes. You can cancel from My appointments up to 2 hours before your visit, then book a new time. It\'s free.', true) +
      faq('Do I have to fill in the form before my visit?', 'It isn\'t required, but it helps. Your doctor can read it before you arrive, and you can edit it until you check in.') +
      faq('How many visits can I book at once?', 'Up to 3 upcoming appointments at a time, and two of your bookings can\'t overlap.') +
      faq('Will I get a reminder?', 'Yes. We send an email about 24 hours before your visit with the date, time, doctor and service. No health details are ever put in an email.') +
      faq('Can I book for my child?', 'Choose Child wellness and Dr. Bea Navarro. In this demo each account books for one person.') +
      '</div></div></section>' +
      '<section class="l-wrap" style="padding:88px 24px" aria-labelledby="cta-h"><div class="cta-band"><div class="copy"><h2 id="cta-h">Feeling off? <em>Let\'s get you seen.</em></h2><p>Open times for this week are ready to book. It takes a few minutes and you can cancel for free.</p><div class="row"><a class="btn btn-mint btn-lg" href="#/book/service">Book a visit</a><a class="btn btn-ghost-dark btn-lg" href="#/login">I have an account</a></div></div>' +
      photo('assets/photo-family.jpg', 'Photo 4 · Family moment', 'Grace, Pexels', 'dark', '') + '</div></section>' +
      '<footer class="l-foot"><div class="l-wrap"><div class="cols"><div style="max-width:340px"><a class="brand" href="#/">' + LOGO + '<span>MediBook</span></a><span>A portfolio demo of a clinic booking app. All names, doctors and appointments are made up.</span></div>' +
      '<div><strong>Clinic hours</strong><span>Mon to Fri, 8:00 to 17:00</span><span>Sat, 8:00 to 12:00</span><span>All times in Manila time</span></div>' +
      '<div><strong>For patients</strong><a href="#/book/service">Book a visit</a><a href="#/me">My appointments</a><button class="btn-link" style="text-align:left;color:#3E4A48;text-decoration:none;font-size:14px" data-act="scroll" data-to="privacy">Privacy</button></div>' +
      '<div><strong>Clinic team</strong><a href="#/login">Staff and doctor log in</a></div></div><p class="small">Photos from Pexels and Unsplash, used under their free licenses.</p></div></footer>';
    return { title: 'Book a clinic visit', html, landing: true };
  }
  const faq = (q, a, open) => '<details' + (open ? ' open' : '') + '><summary>' + q + '</summary><p>' + a + '</p></details>';

  function viewLogin() {
    if (S.session) return { redirect: ROLE_HOME[role()] };
    const html = '<div class="auth"><section class="brandside"><a class="brand" href="#/" style="color:#fff">' + LOGO + '<span>MediBook</span></a><div class="stack" style="gap:24px"><h2>Your clinic visit, booked and ready before you arrive.</h2><p>Pick a time with the doctor you need, tell us why you\'re coming in, and your doctor reads it before the visit.</p></div><p style="font-size:13px;color:#8FBCB6">One clinic · All times in Manila time · Portfolio demo</p></section>' +
      '<div class="formside"><form class="formbox" data-form="login" novalidate><div class="stack" style="gap:8px"><h1>Log in</h1><p class="muted">Patients book and manage visits. Staff, doctors and admins use their clinic account.</p></div>' +
      (S.returnTo === '/book/confirm' ? '<div class="notice notice-ok">' + icon('info') + '<span>Log in to confirm your booking. Your chosen time is saved.</span></div>' : '') +
      '<div class="field"><label for="l-email">Email</label><input class="input" id="l-email" name="email" type="email" autocomplete="email" placeholder="you@example.com"></div>' +
      '<div class="field"><label for="l-pass">Password</label><input class="input" id="l-pass" name="password" type="password" autocomplete="current-password"><span class="hint">Any password works in this demo.</span></div>' +
      '<p class="error-text" id="login-err" role="alert"></p><button class="btn btn-primary" type="submit">Log in</button>' +
      '<div class="or">or try a demo account</div><div class="demo-grid">' +
      '<button type="button" data-act="demo-login" data-role="patient"><strong>Patient</strong><small>Book, intake, cancel</small></button><button type="button" data-act="demo-login" data-role="staff"><strong>Staff</strong><small>Run the day schedule</small></button><button type="button" data-act="demo-login" data-role="doctor"><strong>Doctor</strong><small>Intake and AI draft</small></button><button type="button" data-act="demo-login" data-role="admin"><strong>Admin</strong><small>Setup and audit log</small></button></div>' +
      '<p class="muted" style="text-align:center;font-size:14px">New patient? <a href="#/register">Create an account</a></p></form></div></div>';
    return { title: 'Log in', html, layout: 'bare' };
  }
  function viewRegister() {
    if (S.session) return { redirect: ROLE_HOME[role()] };
    const html = '<div class="auth"><section class="brandside"><a class="brand" href="#/" style="color:#fff">' + LOGO + '<span>MediBook</span></a><div class="stack" style="gap:24px"><h2>Create your patient account.</h2><p>You\'ll use it to book visits, fill in your intake form and see your appointments.</p></div><p style="font-size:13px;color:#8FBCB6">Demo with fake data. Do not enter real health information.</p></section>' +
      '<div class="formside"><form class="formbox" data-form="register" novalidate><h1>Create an account</h1>' +
      '<div class="field"><label for="r-name">Full name</label><input class="input" id="r-name" name="name" autocomplete="name"></div>' +
      '<div class="field"><label for="r-email">Email</label><input class="input" id="r-email" name="email" type="email" autocomplete="email"></div>' +
      '<div class="field"><label for="r-birth">Birth date</label><input class="input" id="r-birth" name="birth" type="date"><span class="hint">Your doctor sees your age range, not your birth date.</span></div>' +
      '<div class="field"><label for="r-pass">Password</label><input class="input" id="r-pass" name="password" type="password" autocomplete="new-password"></div>' +
      '<p class="error-text" id="reg-err" role="alert"></p><button class="btn btn-primary" type="submit">Create account</button><p class="muted" style="text-align:center;font-size:14px">Already have an account? <a href="#/login">Log in</a></p></form></div></div>';
    return { title: 'Create an account', html, layout: 'bare' };
  }

  /* ================= Booking ================= */
  function draft() { if (!S.draft) S.draft = { serviceId: null, doctorId: null, date: null, start: null, slotDoctorId: null, followUpId: null, stripStart: null }; return S.draft; }
  function stepBar(now) {
    const steps = [['Service', '/book/service'], ['Doctor', '/book/doctor'], ['Time', '/book/time'], ['Confirm', '/book/confirm']];
    return '<ol class="steps" aria-label="Booking steps">' + steps.map(([l, p], i) => i < now ? '<li class="done"><span class="n">' + icon('check', 12, 3) + '</span><a href="#' + p + '">' + l + '</a></li>' : i === now ? '<li class="now" aria-current="step"><span class="n">' + (i + 1) + '</span>' + l + '</li>' : '<li><span class="n">' + (i + 1) + '</span>' + l + '</li>').join('') + '</ol>';
  }
  function bookingSummary(btn, note) {
    const d = draft();
    const s = d.serviceId && svc(d.serviceId);
    const docName = d.slotDoctorId ? doc(d.slotDoctorId).name : d.doctorId === 'any' ? 'Any available doctor' : d.doctorId ? doc(d.doctorId).name : null;
    const row = (k, v) => '<div><dt>' + k + '</dt><dd class="' + (v ? '' : 'pending') + '">' + (v || 'Next step') + '</dd></div>';
    const f = d.followUpId && S.followUps.find((x) => x.id === d.followUpId);
    return '<aside class="side"><div class="summary" aria-labelledby="sum-h"><div class="row" style="justify-content:space-between"><h2 id="sum-h">' + (f ? 'Your follow-up' : 'Your booking') + '</h2>' + (f ? '<span class="badge b-warn">Requested</span>' : '') + '</div><dl class="dl">' +
      row('Service', s && s.name) + row('Length', s && s.dur + ' min') + row('Doctor', docName) + row('Date', d.date && d.start != null ? D(d.date, 'full') : null) + row('Time', d.start != null ? '<span class="mono">' + T(d.start) + ' to ' + T(d.start + s.dur) + '</span>' : null) +
      '</dl><hr class="divider">' + btn + (note ? '<p class="small">' + note + '</p>' : '') + '</div></aside>';
  }

  function viewBookService(_, q) {
    const d = draft();
    if (q.get('fresh')) { S.draft = null; return { redirect: '/book/service' }; }
    const list = S.services.filter((s) => s.active).map((s) => {
      const docs = MB.DOCTORS.filter((x) => x.services.includes(s.id));
      const meta = docs.length === 1 ? docs[0].name : docs.length + ' doctors';
      return '<button type="button" class="opt-card" data-act="pick-service" data-id="' + s.id + '" data-search="' + esc((s.name + ' ' + s.desc).toLowerCase()) + '" aria-pressed="' + (d.serviceId === s.id) + '">' + svcIcon(s) +
        '<span class="body"><span class="row" style="justify-content:space-between"><span class="name">' + s.name + '</span>' + (s.id === 'gen' ? '<span class="badge b-ok" style="background:#fff">Most booked</span>' : '') + '</span><span class="desc">' + s.desc + '</span><span class="meta">' + s.dur + ' min · ' + meta + '</span></span></button>';
    }).join('');
    const html = '<div class="book-wrap">' + stepBar(0) + '<div class="split"><div class="grow">' +
      '<div class="book-title"><h1>What do you need help with?</h1><p>Pick the visit that fits best. Not sure? A general consultation covers most things.</p></div>' +
      '<div class="search">' + icon('search') + '<label class="sr-only" for="svc-search">Search services</label><input class="input" id="svc-search" type="search" placeholder="Search, e.g. rash, vaccine, check-up" data-filter=".opt-card"></div>' +
      '<div class="grid-2" id="svc-list" role="group" aria-label="Services">' + list + '</div><p class="muted" id="svc-none" hidden>No service matches that. Try "consultation".</p>' +
      '<div class="notice notice-danger">' + icon('phone') + '<span><strong>Chest pain, trouble breathing or heavy bleeding?</strong> Don\'t book. Call 911 or go to the nearest emergency room now.</span></div></div>' +
      bookingSummary('<a class="btn btn-primary btn-block" href="#/book/doctor" ' + (d.serviceId ? '' : 'aria-disabled="true"') + '>Choose a doctor' + icon('arrow', 16) + '</a>', 'No account needed yet. You\'ll log in or sign up just before you confirm.') + '</div></div>';
    return { title: 'Choose a service', html, nav: 'book' };
  }

  function viewBookDoctor() {
    const d = draft();
    if (!d.serviceId) return { redirect: '/book/service' };
    const s = svc(d.serviceId);
    const any = nextOpen(s.id, 'any');
    const offering = MB.DOCTORS.filter((x) => x.services.includes(s.id));
    const notOffering = MB.DOCTORS.filter((x) => !x.services.includes(s.id));
    const radio = (on) => '<span class="radio">' + (on ? icon('check', 12, 3.4) : '') + '</span>';
    let html = '<div class="book-wrap">' + stepBar(1) + '<div class="split"><div class="grow"><div class="book-title"><h1>Who would you like to see?</h1><div class="row" style="margin-top:10px"><span class="pill">' + s.name + ' · ' + s.dur + ' min <a href="#/book/service">Change</a></span></div></div><div class="stack" style="gap:12px" role="group" aria-label="Doctor">';
    if (offering.length > 1) {
      html += '<button type="button" class="opt-card doc" data-act="pick-doctor" data-id="any" aria-pressed="' + (d.doctorId === 'any') + '"><span class="avatar lg av-dark">' + icon('bolt', 22) + '</span><span class="body" style="flex:1 1 240px"><span class="name">Any available doctor</span><span class="desc">See the most open times. You\'ll see which doctor each time is with before you confirm.</span></span><span class="next"><span>Soonest</span><span>' + nextLabel(any) + '</span></span>' + radio(d.doctorId === 'any') + '</button><div class="or">or choose a doctor</div>';
    }
    offering.forEach((x) => {
      const n = nextOpen(s.id, x.id);
      const days = Object.keys(S.hours[x.id]).filter((k) => S.hours[x.id][k].length).map(Number);
      html += '<button type="button" class="opt-card doc" data-act="pick-doctor" data-id="' + x.id + '" aria-pressed="' + (d.doctorId === x.id) + '">' + docAvatar(x, 'lg') + '<span class="body" style="flex:1 1 240px"><span class="row" style="gap:8px"><span class="name">' + x.name + '</span><span class="muted" style="font-size:14px">' + x.spec + '</span></span><span class="desc">' + x.bio + '</span><span class="meta">' + workDays(days) + ' · ' + x.services.length + ' services</span></span><span class="next"><span>Next open</span><span>' + nextLabel(n) + '</span></span>' + radio(d.doctorId === x.id) + '</button>';
    });
    notOffering.forEach((x) => {
      const alt = S.services.find((v) => x.services.includes(v.id) && v.id !== 'fu');
      html += '<div class="opt-disabled">' + docAvatar(x, 'lg') + '<span class="body" style="flex:1 1 240px;display:flex;flex-direction:column;gap:4px"><span class="row" style="gap:8px"><strong style="font-size:17px">' + x.name + '</strong><span style="font-size:14px">' + x.spec + '</span></span><span style="font-size:14px">Doesn\'t offer ' + s.name.toLowerCase() + '. ' + (alt ? '<button class="btn-link" data-act="pick-service-go" data-id="' + alt.id + '" data-doc="' + x.id + '">Book ' + alt.name.toLowerCase() + '</button> instead.' : '') + '</span></span></div>';
    });
    html += '</div></div>' + bookingSummary('<a class="btn btn-primary btn-block" href="#/book/time" ' + (d.doctorId ? '' : 'aria-disabled="true"') + '>See open times' + icon('arrow', 16) + '</a><a class="btn btn-secondary btn-block" href="#/book/service">Back</a>') + '</div></div>';
    return { title: 'Choose a doctor', html, nav: 'book' };
  }
  function workDays(days) {
    const names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    days.sort((a, b) => a - b);
    const runs = [];
    days.forEach((x) => { const last = runs[runs.length - 1]; if (last && last[1] === x - 1) last[1] = x; else runs.push([x, x]); });
    return runs.map(([a, b]) => a === b ? names[a] : b - a === 1 ? names[a] + ', ' + names[b] : names[a] + ' to ' + names[b]).join(', ');
  }

  function dateStrip(o) {
    // o: { serviceId, doctorId, start (date), selected, exclude, range:{from,to}, act }
    const t = today();
    const days = [];
    for (let i = 0; i < 7; i++) days.push(MB.addDays(o.start, i));
    let band = '';
    if (o.range) {
      const idx = days.map((d, i) => (d >= o.range.from && d <= o.range.to ? i : -1)).filter((i) => i >= 0);
      if (idx.length) {
        band = '<div class="band-row" aria-hidden="true">' + (idx[0] > 0 ? '<span style="grid-column:1/' + (idx[0] + 1) + '"></span>' : '') + '<span class="band" style="grid-column:' + (idx[0] + 1) + '/' + (idx[idx.length - 1] + 2) + '">' + icon('star', 12, 2.4) + (o.rangeLabel || 'Suggested') + '</span></div>';
      }
    }
    const cells = days.map((d) => {
      const p = MB.dayParts(d);
      const out = d < t || MB.daysBetween(t, d) > C.windowDays;
      const open = o.doctorId && o.doctorId !== 'any' ? works(o.doctorId, d) : MB.DOCTORS.some((x) => x.services.includes(o.serviceId) && works(x.id, d));
      const n = out || !open ? 0 : slotsFor(o.serviceId, o.doctorId, d, { exclude: o.exclude }).length;
      const label = out ? (d < t ? 'Past' : 'Too far') : !open ? 'Closed' : n ? n + (n === 1 ? ' time' : ' times') : 'Full';
      const inR = o.range && d >= o.range.from && d <= o.range.to;
      return '<button type="button" class="day ' + (inR ? 'suggest' : '') + '" data-act="' + o.act + '" data-date="' + d + '" aria-pressed="' + (o.selected === d) + '" ' + (n ? '' : 'disabled') + ' aria-label="' + D(d, 'long') + ', ' + label + '"><span class="dw">' + p.dow + '</span><span class="dn">' + p.d + '</span><span class="dc">' + label + '</span></button>';
    }).join('');
    const p0 = MB.dayParts(days[0]), p6 = MB.dayParts(days[6]);
    const title = p0.monLong === p6.monLong ? p0.monLong + ' ' + p0.y : p0.monLong + ' to ' + p6.monLong + ' ' + p6.y;
    const canPrev = days[0] > t;
    const canNext = MB.daysBetween(t, days[6]) < C.windowDays;
    return '<div class="row" style="justify-content:space-between"><h2 style="font-size:16px;font-weight:600">' + title + '</h2><div class="row" style="gap:6px"><button type="button" class="icon-btn" data-act="' + o.act + '-page" data-dir="-1" aria-label="Earlier dates" ' + (canPrev ? '' : 'disabled') + '>' + icon('left') + '</button><button type="button" class="icon-btn" data-act="' + o.act + '-page" data-dir="1" aria-label="Later dates" ' + (canNext ? '' : 'disabled') + '>' + icon('right') + '</button></div></div>' + band + '<div class="datestrip">' + cells + '</div>';
  }
  function slotGrid(list, sel, act, showDoctor, selDoc) {
    if (!list.length) return '<div class="empty" style="padding:24px">' + icon('calx', 28, 1.6) + '<strong>No free times this day.</strong><span>Try another date.</span></div>';
    const btn = (x) => '<button type="button" class="slot" data-act="' + act + '" data-start="' + x.start + '" data-doc="' + x.doctorId + '" aria-pressed="' + (sel === x.start && (!selDoc || selDoc === x.doctorId)) + '"><span class="t">' + T(x.start) + '</span>' + (showDoctor ? '<span class="d">' + doc(x.doctorId).short + '</span>' : '') + '</button>';
    const am = list.filter((x) => x.start < 720), pm = list.filter((x) => x.start >= 720);
    return (am.length ? '<div class="slot-group"><h3>Morning</h3><div class="slots">' + am.map(btn).join('') + '</div></div>' : '') +
      (am.length && pm.length ? '<div class="lunch">Lunch break</div>' : '') +
      (pm.length ? '<div class="slot-group"><h3>Afternoon</h3><div class="slots">' + pm.map(btn).join('') + '</div></div>' : '');
  }

  function viewBookTime() {
    const d = draft();
    if (!d.serviceId) return { redirect: '/book/service' };
    if (!d.doctorId) return { redirect: '/book/doctor' };
    const s = svc(d.serviceId);
    const f = d.followUpId && S.followUps.find((x) => x.id === d.followUpId);
    const t = today();
    if (!d.stripStart || d.stripStart < t) d.stripStart = t;
    if (!d.date) {
      let pickDate = null;
      for (let i = 0; i < 7; i++) { const x = MB.addDays(d.stripStart, i); if (slotsFor(s.id, d.doctorId, x).length && (!f || (x >= f.from && x <= f.to))) { pickDate = x; break; } }
      if (!pickDate) for (let i = 0; i < 7; i++) { const x = MB.addDays(d.stripStart, i); if (slotsFor(s.id, d.doctorId, x).length) { pickDate = x; break; } }
      d.date = pickDate;
    }
    const list = d.date ? slotsFor(s.id, d.doctorId, d.date) : [];
    let head = '<div class="book-title"><h1>' + (f ? 'Book your follow-up' : 'Pick a time') + '</h1>';
    if (f) {
      const fd = doc(f.doctorId);
      head += '<div class="notice notice-info" style="margin-top:10px;align-items:center">' + docAvatar(fd) + '<span>' + fd.short + ' chose the visit type and asked to see you <strong style="color:var(--ink)">between ' + D(f.from) + ' and ' + D(f.to) + '</strong>. You only need to pick a time.</span></div>';
    } else {
      head += '<div class="row" style="margin-top:10px"><span class="pill">' + s.name + ' · ' + s.dur + ' min <a href="#/book/service">Change</a></span><span class="pill">' + (d.doctorId === 'any' ? 'Any available doctor' : doc(d.doctorId).name) + ' <a href="#/book/doctor">Change</a></span></div>';
    }
    head += '</div>';
    const strip = dateStrip({ serviceId: s.id, doctorId: d.doctorId, start: d.stripStart, selected: d.date, range: f ? { from: f.from, to: f.to } : null, rangeLabel: f ? 'Suggested by ' + doc(f.doctorId).short : '', act: 'pick-date' });
    let alt = '';
    if (f) {
      const fd = doc(f.doctorId);
      const after = nextOpen(f.serviceId, f.doctorId, MB.addDays(f.to, 1));
      alt = '<section class="card card-pad stack" style="border-style:dashed;gap:12px"><h2 style="font-size:15px;font-weight:600">Can\'t make that week?</h2><div class="grid-2">' +
        (after ? '<button type="button" class="opt-card" style="padding:14px 16px" data-act="fu-alt" data-mode="after" data-date="' + after.date + '"><span class="body"><span class="name" style="font-size:15px">' + fd.short + ', a later week</span><span class="meta">Next free: ' + D(after.date) + ', ' + T(after.start) + '</span></span></button>' : '') +
        (MB.DOCTORS.filter((x) => x.services.includes(f.serviceId)).length > 1 ? '<button type="button" class="opt-card" style="padding:14px 16px" data-act="fu-alt" data-mode="any"><span class="body"><span class="name" style="font-size:15px">Any doctor, same week</span><span class="meta">More open times that week</span></span></button>' : '') + '</div></section>';
    }
    const html = '<div class="book-wrap">' + stepBar(2) + '<div class="split"><div class="grow">' + head +
      '<section class="card card-pad stack" style="gap:16px" aria-label="Dates">' + strip + (f ? '<p class="hint">Days outside the suggested week are still open to you.</p>' : '') + '</section>' +
      '<section class="card card-pad stack" style="gap:18px" aria-labelledby="slots-h"><div class="row" style="justify-content:space-between"><h2 id="slots-h" style="font-size:16px;font-weight:600">' + (d.date ? D(d.date, 'long') + (d.doctorId !== 'any' ? ' · ' + doc(d.doctorId).short : '') : 'Pick a day') + '</h2><span class="hint">' + (d.doctorId === 'any' ? 'Each time shows the doctor it books · ' : '') + 'Manila time</span></div>' + slotGrid(list, d.start, 'pick-slot', d.doctorId === 'any', d.slotDoctorId) + '</section>' + alt +
      '</div>' + bookingSummary('<a class="btn btn-primary btn-block" href="#/book/confirm" ' + (d.start != null ? '' : 'aria-disabled="true"') + '>Continue' + icon('arrow', 16) + '</a>', 'Free to cancel up to 2 hours before. You can have up to 3 upcoming bookings.') + '</div></div>';
    return { title: 'Pick a time', html, nav: 'book', keepScroll: !!ui.keepScroll };
  }

  function viewBookConfirm() {
    const d = draft();
    if (!d.serviceId || !d.doctorId) return { redirect: '/book/service' };
    if (d.start == null || !d.date) return { redirect: '/book/time' };
    const s = svc(d.serviceId);
    const dc = doc(d.slotDoctorId);
    let body;
    if (!S.session) {
      S.returnTo = '/book/confirm'; save();
      body = '<section class="card card-pad stack"><h2 style="font-size:18px;font-weight:600">Log in to confirm</h2><p class="muted">Your time is held while you log in or create an account. It isn\'t booked until you confirm.</p><div class="row"><button class="btn btn-primary" data-act="demo-login" data-role="patient">Continue as Ana Ramos (demo patient)</button><a class="btn btn-secondary" href="#/login">Log in</a><a class="btn btn-secondary" href="#/register">Create an account</a></div></section>';
    } else if (role() !== 'patient') {
      body = '<div class="notice notice-warn">' + icon('info') + '<span>You\'re logged in as ' + role() + '. Only patients book here. Use the demo switcher to log in as the patient.</span></div>';
    } else {
      const err = ui.bookErr;
      body = (err ? '<div class="notice notice-danger" role="alert">' + icon('alert') + '<span><strong>' + esc(err) + '</strong> <a href="#/book/time">Pick another time</a></span></div>' : '') +
        '<section class="card" aria-label="Appointment"><div class="appt" style="border:0">' + dateBox(d.date, true) + '<div class="info"><span class="time">' + T(d.start) + ' to ' + T(d.start + s.dur) + '</span><span class="svc">' + s.name + '</span><span class="doc">' + dc.name + ' · ' + dc.spec + '</span></div></div>' +
        '<div style="border-top:1px solid var(--line);background:#FAFBFA;padding:12px 20px;font-size:13px;color:var(--muted);border-radius:0 0 16px 16px">Free to cancel until <strong style="color:var(--ink)">' + T((d.start - 120 + 1440) % 1440) + (d.start < 120 ? ', the day before' : ', ' + D(d.date)) + '</strong></div></section>' +
        '<div class="notice notice-info">' + icon('cliplines') + '<span>After you confirm, you can fill in a short intake form so your doctor can read it before your visit.</span></div>' +
        '<div class="row"><button class="btn btn-primary btn-lg" data-act="confirm-booking">Confirm booking</button><a class="btn btn-secondary btn-lg" href="#/book/time">Back</a></div>';
    }
    const html = '<div class="book-wrap">' + stepBar(3) + '<div class="split"><div class="grow"><div class="book-title"><h1>Check and confirm</h1><p>Booking for ' + (me() && role() === 'patient' ? esc(me().name) : 'you') + '.</p></div>' + body + '</div>' + bookingSummary('<p class="small">All times in Manila time.</p>') + '</div></div>';
    ui.bookErr = null;
    return { title: 'Confirm booking', html, nav: 'book' };
  }
  function dateBox(ds, dark) { const p = MB.dayParts(ds); return '<div class="datebox ' + (dark ? 'dark' : '') + '"><span>' + p.dow + '</span><strong>' + p.d + '</strong><span>' + p.mon + '</span></div>'; }

  function viewBooked(id) {
    const a = appt(id);
    if (!a || a.patientId !== me().id) return viewNotFound();
    const s = svc(a.serviceId), dc = doc(a.doctorId);
    const html = '<div class="book-wrap" style="max-width:680px"><div class="stack" style="align-items:center;text-align:center;gap:14px"><span class="avatar lg" style="background:var(--primary-soft);color:var(--primary)">' + icon('check', 28, 2.5) + '</span><h1 style="font-size:32px;line-height:38px;font-weight:600;letter-spacing:-.02em">You\'re booked</h1><p class="muted" style="font-size:16px">We sent a confirmation to <strong style="color:var(--ink)">' + esc(me().email) + '</strong>.</p></div>' +
      '<section class="card"><div class="appt" style="border:0">' + dateBox(a.date, true) + '<div class="info"><span class="time">' + T(a.start) + ' to ' + T(apptEnd(a)) + '</span><span class="svc">' + s.name + '</span><span class="doc">' + dc.name + ' · ' + dc.spec + '</span></div>' + statusBadge(a.status) + '</div><div style="border-top:1px solid var(--line);background:#FAFBFA;padding:12px 20px;font-size:13px;color:var(--muted);border-radius:0 0 16px 16px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"><span>Free to cancel up to 2 hours before</span><span class="mono">Ref ' + a.id.toUpperCase() + '</span></div></section>' +
      '<section class="fu-card" style="flex-direction:column;gap:16px"><div class="row" style="gap:14px;align-items:flex-start;flex-wrap:nowrap"><span class="avatar" style="width:40px;height:40px;border-radius:10px;background:var(--deep-2);color:var(--mint)">' + icon('cliplines', 20) + '</span><div class="stack" style="gap:6px"><h2>Next: tell your doctor why you\'re coming in</h2><p style="color:var(--mint-text);font-size:15px;line-height:22px">The intake form takes about 3 minutes. Only your doctor reads your answers. You can edit them until you check in.</p></div></div>' +
      '<div class="row"><a class="btn btn-mint" href="#/me/intake/' + a.id + '">Fill in your intake form' + icon('arrow', 16) + '</a><a class="btn btn-ghost-dark" href="#/me">Go to my appointments</a></div></section></div>';
    return { title: 'Booking confirmed', html };
  }

  /* ================= Patient ================= */
  function viewMyAppts(_, q) {
    const u = me();
    const tab = q.get('tab') === 'past' ? 'past' : 'upcoming';
    const mine = S.appts.filter((a) => a.patientId === u.id);
    const up = mine.filter(isUpcoming).sort((x, y) => (x.date + T(x.start)).localeCompare(y.date + T(y.start)));
    const past = mine.filter((a) => !isUpcoming(a)).sort((x, y) => (y.date + T(y.start)).localeCompare(x.date + T(x.start)));
    const fus = S.followUps.filter((f) => f.patientId === u.id && f.status === 'open');
    const fuCards = fus.map((f) => {
      const fd = doc(f.doctorId), fs = svc(f.serviceId);
      const n = slotsCount(f);
      const over = today() > f.to;
      const src = f.sourceApptId && appt(f.sourceApptId);
      return '<section class="fu-card" aria-labelledby="fu-' + f.id + '">' + docAvatar(fd, 'lg').replace('av-' + fd.tone, '" style="background:var(--mint);color:var(--deep)') +
        '<div class="stack" style="flex:1 1 360px;gap:12px"><div><span class="eyebrow">Follow-up to book</span><h2 id="fu-' + f.id + '">' + fd.name + ' would like to see you again</h2></div><div class="pills"><span>' + fs.name + ' · ' + fs.dur + ' min</span><span>Between <strong style="color:#fff">' + D(f.from) + '</strong> and <strong style="color:#fff">' + D(f.to) + '</strong></span>' + (f.sameDoctor ? '' : '<span>Any doctor</span>') + '</div>' +
        (f.note ? '<blockquote><span>"' + esc(f.note) + '"</span><small>' + fd.short + (src ? ', after your visit on ' + D(src.date) : '') + '</small></blockquote>' : '') + (over ? '<p style="font-size:14px;color:var(--mint-text)">The suggested week has passed. You can still book the next free time.</p>' : '') + '</div>' +
        '<div class="cta"><a class="btn btn-mint btn-lg" href="#/me/followup/' + f.id + '">Book follow-up' + icon('arrow', 16) + '</a><small>' + (n ? fd.short + ' has ' + n + ' open times then.' : 'No open times that week. You\'ll see other options.') + '</small><button class="btn-link" data-act="fu-dismiss" data-id="' + f.id + '">I don\'t need this visit</button></div></section>';
    }).join('');
    const rows = (tab === 'upcoming' ? up : past).map((a) => apptRow(a)).join('');
    const empty = tab === 'upcoming' ? '<div class="card empty">' + icon('cal', 28, 1.6) + '<strong>No upcoming appointments.</strong><a class="btn btn-primary" href="#/book/service?fresh=1">Book an appointment</a></div>' : '<div class="card empty"><strong>No past visits yet.</strong></div>';
    const full = up.length >= C.maxUpcoming;
    const html = '<div class="page-head"><div><h1>My appointments</h1><p>All times in Manila time</p></div><a class="btn ' + (fus.length ? 'btn-secondary' : 'btn-primary') + '" href="#/book/service?fresh=1" ' + (full ? 'aria-disabled="true"' : '') + '>' + icon('plus', 16, 2.4) + (fus.length ? 'Book something else' : 'Book an appointment') + '</a></div>' +
      fuCards +
      '<div class="tabs" role="tablist" aria-label="Appointments"><a role="tab" href="#/me" aria-selected="' + (tab === 'upcoming') + '">Upcoming <span class="n">' + up.length + '</span></a><a role="tab" href="#/me?tab=past" aria-selected="' + (tab === 'past') + '">Past <span class="n">' + past.length + '</span></a></div>' +
      (tab === 'upcoming' ? '<p class="usage"><span class="bars" aria-hidden="true">' + [0, 1, 2].map((i) => '<span class="' + (i < up.length ? 'on' : '') + '"></span>').join('') + '</span>' + up.length + ' of 3 upcoming bookings used' + (full ? '. Cancel one to book another.' : '') + '</p>' : '') +
      '<ul class="stack" style="gap:12px">' + (rows || '<li>' + empty + '</li>') + '</ul>';
    return { title: 'My appointments', html, layout: 'app', nav: '/me' };
  }
  function slotsCount(f) {
    let n = 0;
    for (let d = f.from < today() ? today() : f.from; d <= f.to; d = MB.addDays(d, 1)) n += slotsFor(f.serviceId, f.sameDoctor ? f.doctorId : 'any', d).length;
    return n;
  }
  function apptRow(a) {
    const s = svc(a.serviceId), dc = doc(a.doctorId);
    const intake = S.intakes[a.id];
    const up = isUpcoming(a);
    const canCancel = a.status === 'booked' && minsUntil(a) >= C.cancelCutoffMin;
    const intakeOpen = up && a.status === 'booked';
    const fuReq = S.followUps.find((f) => f.sourceApptId === a.id);
    let tag = '';
    if (up) tag = intake ? '<span class="badge tag b-ok">' + icon('check', 13, 2.6) + 'Intake done</span>' : '<span class="badge tag b-warn">' + icon('info', 13, 2.2) + 'Intake needed</span>';
    if (fuReq) tag += '<span class="badge tag b-warn">Follow-up ' + (fuReq.status === 'booked' ? 'booked' : 'requested') + '</span>';
    if (a.emergency && up) tag = '<span class="flag">' + flagIcon() + 'EMERGENCY</span>' + tag;
    return '<li class="appt">' + dateBox(a.date) + '<div class="info"><div class="row" style="gap:10px"><span class="time">' + T(a.start) + ' to ' + T(apptEnd(a)) + '</span>' + statusBadge(a.status) + (a.followUpOf ? '<span class="badge b-outline">Follow-up</span>' : '') + '</div><span class="svc">' + s.name + '</span><span class="doc">' + dc.name + ' · ' + dc.spec + '</span>' + (a.status === 'cancelled' && a.cancelReason ? '<span class="hint">' + esc(a.cancelReason) + '</span>' : '') + '</div>' +
      '<div class="acts"><div class="row" style="gap:6px;justify-content:flex-end">' + tag + '</div><div class="row" style="gap:8px">' +
      (intakeOpen && !intake ? '<a class="btn btn-primary btn-sm" href="#/me/intake/' + a.id + '">Fill intake</a>' : '') +
      '<a class="btn btn-secondary btn-sm" href="#/me/appt/' + a.id + '">View</a>' +
      (canCancel ? '<button class="btn btn-danger-ghost btn-sm" data-act="patient-cancel" data-id="' + a.id + '">Cancel</button>' : '') + '</div></div></li>';
  }
  function emergencyBlock() {
    return '<section class="emergency" role="alert"><span class="ic">' + icon('alert', 26, 2.4) + '</span><div style="flex:1 1 300px"><h2>This may be an emergency.</h2><p>Call 911 or go to the nearest emergency room now.</p></div><a class="btn" href="tel:911">' + icon('phone', 20, 2.2) + 'Call 911</a></section>';
  }
  function viewMyAppt(id) {
    const a = appt(id);
    if (!a || a.patientId !== me().id) return viewNotFound();
    const s = svc(a.serviceId), dc = doc(a.doctorId);
    const it = S.intakes[a.id];
    const locked = a.status !== 'booked' || !isFuture(a);
    const canCancel = a.status === 'booked' && minsUntil(a) >= C.cancelCutoffMin;
    const html = '<nav class="crumbs" aria-label="Breadcrumb"><a href="#/me">My appointments</a><span aria-hidden="true">/</span><span>' + D(a.date) + ', ' + T(a.start) + '</span></nav>' +
      (a.emergency && isFuture(a) ? emergencyBlock() : '') +
      '<div class="page-head"><div><h1>' + s.name + '</h1><p>' + dc.name + ' · ' + D(a.date, 'long') + ', ' + T(a.start) + ' to ' + T(apptEnd(a)) + '</p></div><div class="row">' + statusBadge(a.status) + (canCancel ? '<button class="btn btn-danger-ghost btn-sm" data-act="patient-cancel" data-id="' + a.id + '">Cancel</button>' : '') + '</div></div>' +
      (a.status === 'cancelled' ? '<div class="notice notice-warn">' + icon('info') + '<span>This appointment was cancelled' + (a.cancelledBy === 'staff' ? ' by the clinic' : '') + '. ' + esc(a.cancelReason || '') + (a.movedTo ? ' <a href="#/me/appt/' + a.movedTo + '">See the new time</a>.' : '') + '</span></div>' : '') +
      '<section class="card"><div class="card-head"><h2>Your intake answers</h2>' + (it ? (locked ? '<span class="hint">' + icon('lock', 13) + ' Can no longer be changed</span>' : '<a class="btn btn-secondary btn-sm" href="#/me/intake/' + a.id + '">Edit</a>') : '') + '</div>' +
      (it ? answersDl(it.answers) : '<div class="empty"><strong>Not filled in yet.</strong>' + (locked ? '' : '<a class="btn btn-primary" href="#/me/intake/' + a.id + '">Fill intake</a>') + '</div>') + '</section>' +
      (S.notes[a.id] ? '<p class="hint">' + icon('lock', 13) + ' Your doctor\'s visit notes are private to them.</p>' : '');
    return { title: s.name, html, layout: 'app', nav: '/me' };
  }
  function answersDl(x) {
    const r = (k, v) => '<div><dt>' + k + '</dt><dd>' + (v ? esc(v) : '<span class="subtle">Not given</span>') + '</dd></div>';
    return '<dl class="qa">' + r('Reason for visit', x.reason) + r('Symptoms', x.symptoms) + r('How long', x.duration) + '<div class="grid-2" style="gap:16px">' + r('Allergies', x.allergies) + r('Current medicines', x.meds) + '</div>' +
      '<div><dt>Checklist</dt><dd class="row" style="gap:8px">' + [['Fever', x.fever], ['Pregnant', x.pregnant], ['Recent surgery', x.surgery]].map(([k, v]) => '<span class="badge tag ' + (v === 'yes' ? 'b-skip' : 'b-grey') + '" style="font-size:13px;padding:5px 10px">' + k + ': ' + (v === 'yes' ? 'Yes' : v === 'no' ? 'No' : 'Not given') + '</span>').join('') + '</dd></div></dl>';
  }
  function viewIntake(id) {
    const a = appt(id);
    if (!a || a.patientId !== me().id) return viewNotFound();
    const s = svc(a.serviceId), dc = doc(a.doctorId);
    const it = S.intakes[a.id];
    const x = (it && it.answers) || {};
    const locked = a.status !== 'booked' || !isFuture(a);
    if (locked) return { redirect: '/me/appt/' + a.id };
    const yn = (name, label) => '<div class="yn"><span id="yn-' + name + '">' + label + '</span><div class="seg" role="radiogroup" aria-labelledby="yn-' + name + '"><label><input class="sr-only" type="radio" name="' + name + '" value="yes" ' + (x[name] === 'yes' ? 'checked' : '') + '>Yes</label><label><input class="sr-only" type="radio" name="' + name + '" value="no" ' + (x[name] === 'no' ? 'checked' : '') + '>No</label></div></div>';
    const ta = (name, label, rows, max, help, req) => '<div class="field"><label for="i-' + name + '">' + label + (req ? ' <span class="opt">(required)</span>' : '') + '</label>' + (help ? '<span class="hint">' + help + '</span>' : '') + '<textarea class="textarea" id="i-' + name + '" name="' + name + '" rows="' + rows + '" maxlength="' + max + '" data-count="' + max + '" ' + (req ? 'required aria-describedby="err-' + name + '"' : '') + '>' + esc(x[name] || '') + '</textarea><span class="counter" aria-live="polite">' + (x[name] || '').length + ' / ' + max.toLocaleString('en-US') + '</span>' + (req ? '<span class="error-text" id="err-' + name + '"></span>' : '') + '</div>';
    const html = '<nav class="crumbs" aria-label="Breadcrumb"><a href="#/me">My appointments</a><span aria-hidden="true">/</span><span>' + D(a.date) + ', ' + T(a.start) + '</span></nav>' +
      (a.emergency ? emergencyBlock() + '<div class="notice notice-ok">' + icon('check', 18, 2.4) + '<span><strong>Your intake was saved.</strong> Something you wrote may need urgent care, so the clinic team can see your appointment is flagged. Please don\'t wait for your booked time.</span></div>' : '') +
      '<div class="split"><form class="grow" data-form="intake" data-id="' + a.id + '" novalidate><div class="stack" style="gap:8px"><h1 style="font-size:30px;line-height:36px;font-weight:600;letter-spacing:-.02em">Intake form</h1><p class="muted">Your doctor reads this before your visit. Short, plain answers are fine.</p></div>' +
      (a.emergency ? '' : '<div class="notice notice-danger">' + icon('phone') + '<span><strong>Feeling very unwell right now?</strong> Don\'t wait for your appointment. Call 911 or go to the nearest emergency room.</span></div>') +
      '<section class="card card-pad stack" style="gap:20px"><h2 style="font-size:17px;font-weight:600">About this visit</h2>' + ta('reason', 'Reason for visit', 2, 500, '', true) + ta('symptoms', 'Symptoms', 4, 2000, 'What you feel, when it started, what makes it better or worse.') +
      '<div class="field" style="max-width:320px"><label for="i-duration">How long have you had this?</label><select class="select" id="i-duration" name="duration"><option value="">Choose one</option>' + MB.DURATIONS.map((v) => '<option ' + (x.duration === v ? 'selected' : '') + '>' + v + '</option>').join('') + '</select></div></section>' +
      '<section class="card card-pad stack" style="gap:20px"><h2 style="font-size:17px;font-weight:600">Allergies and medicines</h2><div class="grid-2" style="gap:16px">' + ta('allergies', 'Allergies', 3, 500) + ta('meds', 'Current medicines', 3, 500) + '</div></section>' +
      '<section class="card card-pad"><h2 style="font-size:17px;font-weight:600;margin-bottom:8px">Quick checklist</h2>' + yn('fever', 'Do you have a fever?') + yn('pregnant', 'Are you pregnant?') + yn('surgery', 'Surgery in the last 3 months?') + '</section>' +
      '<div class="row"><button class="btn btn-primary btn-lg" type="submit">Save intake</button><a class="btn btn-secondary btn-lg" href="#/me">Back</a><span class="hint">You can edit this until you check in.</span></div></form>' +
      '<aside class="side"><section class="card card-pad stack" style="gap:10px"><span class="eyebrow">Your appointment</span><span class="mono" style="font-size:16px;font-weight:500">' + D(a.date) + ' · ' + T(a.start) + '</span><strong style="font-weight:500">' + s.name + '</strong><span class="muted" style="font-size:14px">' + dc.name + ' · ' + dc.spec + '</span></section>' +
      '<section class="card card-pad stack" style="gap:14px"><h2 style="font-size:15px;font-weight:600;display:flex;gap:8px;align-items:center;color:var(--ink)"><span style="color:var(--primary)">' + icon('lock') + '</span>Who sees your answers</h2><ul class="stack" style="gap:10px;font-size:14px;line-height:20px;color:var(--muted)"><li><strong style="color:var(--ink)">Your doctor</strong> reads them, and every view is logged.</li><li><strong style="color:var(--ink)">Front desk staff</strong> only see that the form is filled in.</li><li>An <strong style="color:var(--ink)">AI draft summary</strong> is made from your answers only, never your name or contact details. Your doctor checks it.</li></ul></section></aside></div>';
    return { title: 'Intake form', html, layout: 'app', nav: '/me' };
  }
  function startFollowUp(id) {
    const f = S.followUps.find((x) => x.id === id && x.patientId === me().id);
    if (!f || f.status !== 'open') return { redirect: '/me' };
    const t = today();
    const from = f.from < t ? t : f.from;
    S.draft = { serviceId: f.serviceId, doctorId: f.sameDoctor ? f.doctorId : 'any', followUpId: f.id, date: null, start: null, slotDoctorId: null, stripStart: MB.addDays(from, -2) < t ? t : MB.addDays(from, -2) };
    save();
    return { redirect: '/book/time' };
  }

  /* ================= Staff ================= */
  const PX = 1.6;
  function viewStaffSchedule(_, q) {
    const date = q.get('date') || today();
    const sel = q.get('sel') ? appt(q.get('sel')) : null;
    const ro = role() === 'admin';
    const n = MB.now();
    const dayAppts = S.appts.filter((a) => a.date === date && a.status !== 'cancelled');
    const cnt = (st) => dayAppts.filter((a) => a.status === st).length;
    const head = '<div class="page-head"><div><h1>Day schedule' + (ro ? ' <span class="badge b-grey" style="vertical-align:middle">View only</span>' : '') + '</h1><p>' + dayAppts.length + ' appointments · ' + cnt('booked') + ' booked · ' + cnt('checked_in') + ' checked in · ' + cnt('completed') + ' done · ' + cnt('no_show') + ' no-show</p></div>' +
      '<div class="row" style="gap:8px"><a class="icon-btn" href="#/staff' + qs({ date: MB.addDays(date, -1) }) + '" aria-label="Previous day">' + icon('left') + '</a><label class="sr-only" for="sched-date">Date</label><input class="input" style="width:auto;min-height:40px;font-weight:600" type="date" id="sched-date" value="' + date + '" data-change="sched-date"><a class="icon-btn" href="#/staff' + qs({ date: MB.addDays(date, 1) }) + '" aria-label="Next day">' + icon('right') + '</a><a class="btn btn-dark btn-sm" href="#/staff">Today</a></div></div>' +
      '<div class="legend"><span><i style="background:#fff;border-color:var(--info-line)"></i>Booked</span><span><i style="background:var(--checked-soft);border-color:var(--checked-line)"></i>Checked in</span><span><i style="background:var(--done-soft);border-color:var(--done-line)"></i>Done</span><span><i style="background:var(--violet-soft);border-color:var(--violet-line)"></i>No-show</span><span style="color:var(--danger);font-weight:500"><span class="flag">' + flagIcon() + '</span>Emergency intake</span><span><span style="color:var(--amber-ink)">' + icon('clip', 14) + '</span>No intake yet</span></div>';

    const cols = MB.DOCTORS.map((d) => {
      const ranges = S.hours[d.id][MB.weekday(date)] || [];
      const list = dayAppts.filter((a) => a.doctorId === d.id).sort((x, y) => x.start - y.start);
      let shades = '';
      if (!ranges.length) shades = '<div class="shade" style="top:0;height:' + ((C.dayEnd - C.dayStart) * PX) + 'px">Not working</div>';
      else {
        let cur = C.dayStart;
        ranges.forEach(([a, b]) => { if (a > cur) shades += '<div class="shade" style="top:' + ((cur - C.dayStart) * PX) + 'px;height:' + ((a - cur) * PX) + 'px">' + (cur === C.dayStart ? '' : 'Lunch') + '</div>'; cur = b; });
        if (cur < C.dayEnd) shades += '<div class="shade" style="top:' + ((cur - C.dayStart) * PX) + 'px;height:' + ((C.dayEnd - cur) * PX) + 'px">Closed</div>';
      }
      const offs = S.timeOff.filter((t) => t.doctorId === d.id && t.date === date);
      offs.forEach((t) => { shades += '<div class="shade timeoff" style="top:' + ((t.start - C.dayStart) * PX) + 'px;height:' + ((t.end - t.start) * PX) + 'px"><strong>Time off</strong><span>' + esc(t.reason) + '</span></div>'; });
      const blocks = list.filter((a) => a.start >= C.dayStart).map((a) => {
        const s = svc(a.serviceId);
        const h = s.dur * PX - 2;
        const short = h < 40;
        const off = offs.some((t) => a.start < t.end && apptEnd(a) > t.start) && a.status === 'booked';
        const noIntake = !S.intakes[a.id] && a.status === 'booked';
        const flags = (a.emergency ? '<span class="flag">' + flagIcon() + (short ? '' : 'Emergency') + '</span>' : '') + (off ? '<span class="flag off">Doctor off</span>' : '') + (noIntake ? '<span style="color:var(--amber-ink)" title="No intake yet">' + icon('clip', 12, 2.2) + '</span>' : '');
        return '<button type="button" class="blk s-' + a.status + (short ? ' short' : '') + (off ? ' offwarn' : '') + (sel && sel.id === a.id ? ' sel' : '') + '" style="top:' + ((a.start - C.dayStart) * PX + 1) + 'px;height:' + h + 'px" data-act="go" data-to="/staff' + qs({ date: date === today() ? null : date, sel: a.id }) + '" aria-label="' + esc(user(a.patientId).name) + ', ' + T(a.start) + ', ' + s.name + ', ' + STATUS_LABEL[a.status] + (a.emergency ? ', emergency intake' : '') + '">' +
          (short ? '<span class="p">' + esc(user(a.patientId).name) + flags + '</span><span class="m">· ' + s.name + '</span>' : '<span class="p">' + esc(user(a.patientId).name) + flags + '</span><span class="m">' + s.name + ' · ' + STATUS_LABEL[a.status] + '</span>') + '</button>';
      }).join('');
      return { d, html: '<div class="sched-col ' + (ranges.length ? '' : 'off') + '" style="height:' + ((C.dayEnd - C.dayStart) * PX) + 'px">' + shades + blocks + '</div>', list, ranges, offs };
    });
    const tpl = '64px repeat(' + cols.length + ', minmax(0, 1fr))';
    let times = '';
    for (let m = C.dayStart; m <= C.dayEnd; m += 60) times += '<span style="top:' + ((m - C.dayStart) * PX) + 'px">' + T(m) + '</span>';
    const nowLine = date === n.date && n.min >= C.dayStart && n.min <= C.dayEnd ? '<div class="nowline" style="left:64px;top:' + (12 + (n.min - C.dayStart) * PX) + 'px"></div><span class="nowtag" style="left:4px;right:auto;top:' + (12 + (n.min - C.dayStart) * PX) + 'px">' + T(n.min) + '</span>' : '';
    const grid = '<section class="sched" aria-label="Schedule grid"><div class="sched-in"><div class="sched-head" style="grid-template-columns:' + tpl + '"><div>GMT+8</div>' + cols.map((c) => '<div><strong>' + c.d.name + '</strong><small>' + c.d.spec + ' · ' + c.list.length + '</small></div>').join('') + '</div>' +
      '<div class="sched-body" style="grid-template-columns:' + tpl + '"><div class="sched-times" style="height:' + ((C.dayEnd - C.dayStart) * PX) + 'px">' + times + '</div>' + cols.map((c) => c.html).join('') + nowLine + '</div></div></section>';
    const mobile = '<div class="sched-list">' + cols.map((c, i) => '<details class="doc-acc" ' + (i === 0 ? 'open' : '') + '><summary><span class="t"><strong>' + c.d.name + '</strong><small>' + (c.ranges.length ? c.list.length + ' appointments' + (c.list.some((a) => a.emergency) ? ' · emergency flag' : '') + (c.offs.length ? ' · time off' : '') : 'Not working') + '</small></span>' + icon('down', 18) + '</summary><ol>' +
      c.list.map((a) => '<li><button type="button" data-act="go" data-to="/staff' + qs({ date: date === today() ? null : date, sel: a.id }) + '"><span class="tm">' + T(a.start) + '</span><span class="nm">' + esc(user(a.patientId).name) + (a.emergency ? ' <span class="flag">EMERGENCY</span>' : '') + '<small>' + svc(a.serviceId).name + (!S.intakes[a.id] && a.status === 'booked' ? ' · no intake yet' : '') + '</small></span>' + statusBadge(a.status) + '</button></li>').join('') + '</ol></details>').join('') + '</div>';
    const html = head + '<div class="split"><div class="grow">' + grid + mobile + '</div><aside class="side">' + staffPanel(sel, ro, date) + '</aside></div>';
    return { title: 'Day schedule', html, layout: 'app', nav: '/staff', keepScroll: !!ui.keepScroll };
  }
  function staffPanel(a, ro, date) {
    if (!a) return '<div class="panel"><section class="empty">' + icon('cal', 28, 1.6) + '<strong>Pick an appointment</strong><span>Select a block on the schedule to check a patient in, cancel or move them.</span></section></div>';
    const u = user(a.patientId), s = svc(a.serviceId), dc = doc(a.doctorId);
    const it = S.intakes[a.id];
    const started = hasStarted(a);
    let acts = '';
    if (!ro && a.status === 'booked') {
      acts = '<section class="stack" style="gap:8px"><button class="btn btn-primary btn-block" data-act="checkin" data-id="' + a.id + '">' + icon('check', 16, 2.6) + 'Check in</button><div class="grid-2" style="gap:8px"><button class="btn btn-secondary" data-act="staff-move" data-id="' + a.id + '">Move</button><button class="btn btn-danger-ghost" data-act="staff-cancel" data-id="' + a.id + '">Cancel</button></div>' +
        '<button class="btn btn-secondary btn-block" data-act="noshow" data-id="' + a.id + '" ' + (started ? '' : 'disabled') + '>Mark no-show' + (started ? '' : ' · from ' + T(a.start)) + '</button></section>';
    } else if (!ro) acts = '<section class="hint">No actions for a ' + STATUS_LABEL[a.status].toLowerCase() + ' appointment.</section>';
    const hist = S.audit.filter((e) => e.subject === 'Appointment ' + a.id && !/viewed/.test(e.action)).slice(0, 5);
    return '<div class="panel" aria-labelledby="panel-h"><section class="ph"><div>' + statusBadge(a.status) + '<h2 id="panel-h">' + esc(u.name) + '</h2><span class="muted" style="font-size:14px">' + esc(u.email) + '</span></div><a class="icon-btn plain" href="#/staff' + qs({ date: date === today() ? null : date }) + '" aria-label="Close panel">' + icon('x', 16, 2.2) + '</a></section>' +
      '<section><dl class="dl"><div><dt>Time</dt><dd class="mono">' + D(a.date) + ', ' + T(a.start) + ' to ' + T(apptEnd(a)) + '</dd></div><div><dt>Doctor</dt><dd>' + dc.name + '</dd></div><div><dt>Service</dt><dd>' + s.name + '</dd></div><div><dt>Intake</dt><dd style="color:' + (it ? 'var(--primary-ink)' : 'var(--amber-ink)') + '">' + (it ? 'Filled in' : 'Not yet') + '</dd></div><div><dt>Emergency flag</dt><dd>' + (a.emergency ? '<span class="flag">' + flagIcon() + 'Yes</span>' : 'No') + '</dd></div>' + (a.followUpOf ? '<div><dt>Booked from</dt><dd>Doctor\'s follow-up request</dd></div>' : '') + '</dl>' + (a.emergency ? '<p class="hint" style="margin-top:10px">The patient was told to call 911. Consider calling them.</p>' : '') + '</section>' + acts +
      '<section class="stack" style="gap:12px"><h3 class="eyebrow">History</h3><ol class="timeline">' + (hist.length ? hist.map((e) => '<li><i class="' + (/booked/.test(e.action) ? 'blue' : '') + '"></i><div><span>' + histLabel(e) + '</span><small>' + D(e.date) + ', ' + e.time + '</small></div></li>').join('') : '<li><i class="blue"></i><div><span>Booked by patient</span><small>' + D(a.bookedOn || a.date) + '</small></div></li>') + '</ol></section></div>';
  }
  function histLabel(e) {
    const who = e.actorName || 'Someone';
    const map = { 'appointment.booked': 'Booked by ' + who, 'appointment.checked_in': 'Checked in by ' + who, 'appointment.cancelled': 'Cancelled by ' + who, 'appointment.no_show': 'Marked no-show by ' + who, 'appointment.moved': 'Moved by ' + who + ' (' + e.meta + ')', 'appointment.completed': 'Completed by ' + who, 'intake.saved': 'Intake saved by patient' };
    return esc(map[e.action] || e.action);
  }

  function viewStaffFollowUps(_, q) {
    const filter = q.get('f') || 'all';
    const sel = q.get('sel') ? S.followUps.find((f) => f.id === q.get('sel')) : null;
    const list = S.followUps.slice().map((f) => ({ f, st: fuState(f) }));
    const order = { call: 0, open: 1, waiting: 2, booked: 3, dismissed: 4 };
    list.sort((x, y) => order[x.st.key] - order[y.st.key] || x.f.from.localeCompare(y.f.from));
    const count = (k) => list.filter((x) => x.st.key === k).length;
    const shown = filter === 'all' ? list : list.filter((x) => x.st.key === filter);
    const stat = (k, label, sub, color) => '<a class="stat" href="#/staff/followups' + qs({ f: filter === k ? null : k }) + '" style="text-decoration:none;color:inherit;' + (filter === k ? 'border:2px solid var(--primary);padding:13px 15px' : '') + (k === 'call' ? ';border-color:' + (filter === k ? 'var(--primary)' : 'var(--danger-line)') : '') + '"><span class="k">' + label + '</span><span class="v" style="color:' + color + '">' + count(k) + '</span><span class="s">' + sub + '</span></a>';
    const rows = shown.map(({ f, st }) => {
      const u = user(f.patientId);
      return '<tr class="clickable ' + (sel && sel.id === f.id ? 'sel' : '') + '" data-act="go" data-to="/staff/followups' + qs({ f: filter === 'all' ? null : filter, sel: f.id }) + '" tabindex="0"><td><strong>' + esc(u.name) + '</strong><div class="hint">' + svc(f.serviceId).name + '</div></td><td>' + doc(f.doctorId).name + '</td><td style="font-size:13px">' + D(f.from, 'dm') + ' to ' + D(f.to, 'dm') + '</td><td><span class="badge ' + st.cls + '">' + st.label + '</span></td></tr>';
    }).join('');
    let panel = '<div class="panel"><section class="empty">' + icon('repeat', 28, 1.6) + '<strong>Pick a follow-up</strong><span>Select a row to book it for the patient or log a call.</span></section></div>';
    if (sel) {
      const u = user(sel.patientId), st = fuState(sel), fd = doc(sel.doctorId), src = sel.sourceApptId && appt(sel.sourceApptId);
      panel = '<div class="panel"><section class="ph"><div><span class="badge ' + st.cls + '">' + st.label + '</span><h2>' + esc(u.name) + '</h2><span class="muted" style="font-size:14px">' + esc(u.email) + '</span></div><a class="icon-btn plain" href="#/staff/followups' + qs({ f: filter === 'all' ? null : filter }) + '" aria-label="Close panel">' + icon('x', 16, 2.2) + '</a></section>' +
        '<section><dl class="dl"><div><dt>Visit type</dt><dd>' + svc(sel.serviceId).name + ' · ' + svc(sel.serviceId).dur + ' min</dd></div><div><dt>With</dt><dd>' + (sel.sameDoctor ? fd.name : 'Any doctor') + '</dd></div><div><dt>Between</dt><dd>' + D(sel.from) + ' and ' + D(sel.to) + '</dd></div>' + (src ? '<div><dt>From visit</dt><dd>' + D(src.date) + ', ' + T(src.start) + '</dd></div>' : '') + '</dl></section>' +
        '<section class="hint" style="background:#FAFBFA;display:flex;gap:8px">' + icon('lock', 14) + '<span>The doctor\'s note is private to the patient and ' + fd.short + '.</span></section>' +
        (sel.status === 'open' ? '<section class="stack" style="gap:8px"><button class="btn btn-primary btn-block" data-act="fu-staff-book" data-id="' + sel.id + '">Book for ' + esc(first(u.name)) + '</button><div class="grid-2" style="gap:8px"><button class="btn btn-secondary" data-act="fu-call" data-id="' + sel.id + '">Log a call</button><button class="btn btn-secondary" data-act="fu-remind" data-id="' + sel.id + '">Send reminder</button></div></section>' : '') +
        '<section class="stack" style="gap:12px"><h3 class="eyebrow">History</h3><ol class="timeline">' + sel.history.map((h) => '<li><i class="' + (h.kind || '') + '"></i><div><span>' + esc(h.what) + '</span><small>' + esc(h.when) + '</small></div></li>').join('') + '</ol></section></div>';
    }
    const html = '<div class="page-head"><div><h1>Follow-ups</h1><p>Patients a doctor asked to come back. Today is ' + D(today(), 'full') + '.</p></div>' + (filter !== 'all' ? '<a class="btn btn-secondary btn-sm" href="#/staff/followups">Show all</a>' : '') + '</div>' +
      '<div class="grid-4">' + stat('call', 'Needs a call', 'Range ended or ends soon', 'var(--danger)') + stat('open', 'Open now', 'Inside the range, not booked', 'var(--amber-ink)') + stat('waiting', 'Coming up', 'Range hasn\'t started', 'var(--ink)') + stat('booked', 'Booked', 'From a doctor\'s request', 'var(--primary-ink)') + '</div>' +
      '<div class="split"><div class="grow"><section class="card table-wrap" aria-label="Follow-up list"><table class="tbl" style="min-width:620px"><thead><tr><th scope="col">Patient</th><th scope="col">Asked by</th><th scope="col">Come back</th><th scope="col">Status</th></tr></thead><tbody>' + (rows || '<tr><td colspan="4"><div class="empty"><strong>Nothing here.</strong></div></td></tr>') + '</tbody></table></section></div><aside class="side">' + panel + '</aside></div>';
    return { title: 'Follow-ups', html, layout: 'app', nav: '/staff/followups', keepScroll: true };
  }

  /* ================= Slot dialog (move, staff booking) ================= */
  function openSlotDialog(ctx) {
    ui.slot = Object.assign({ date: null, start: null, slotDoc: null, err: null, stripStart: today() }, ctx);
    const c = ui.slot;
    if (c.range) c.stripStart = MB.addDays(c.range.from, -2) < today() ? today() : MB.addDays(c.range.from, -2);
    const docMode = () => c.doctorChoice === 'any' ? 'any' : c.doctorChoice;
    const pickFirst = () => {
      c.date = null;
      for (let i = 0; i < 7; i++) { const d = MB.addDays(c.stripStart, i); if (slotsFor(c.serviceId, docMode(), d, { exclude: c.exclude }).length) { c.date = d; break; } }
    };
    pickFirst();
    c.pickFirst = pickFirst;
    dialog({ wide: true, render: () => {
      const s = svc(c.serviceId);
      const list = c.date ? slotsFor(c.serviceId, docMode(), c.date, { exclude: c.exclude }) : [];
      const opts = MB.DOCTORS.filter((d) => d.services.includes(c.serviceId)).map((d) => '<option value="' + d.id + '" ' + (c.doctorChoice === d.id ? 'selected' : '') + '>' + d.name + (d.id === c.origDoctor ? ' (same)' : '') + '</option>').join('') + '<option value="any" ' + (c.doctorChoice === 'any' ? 'selected' : '') + '>Any doctor for this service</option>';
      const to = c.start != null ? D(c.date) + ' · ' + T(c.start) : 'Pick a time below';
      return {
        title: c.title, sub: c.sub,
        body: (c.from ? '<div class="fromto"><div><span class="eyebrow">From</span><span class="mono old">' + c.from + '</span><span class="hint">' + c.fromDoc + '</span></div><div class="to"><span class="eyebrow" style="color:var(--primary-ink)">To</span><span class="mono" style="font-weight:600">' + to + '</span><span class="hint" style="color:var(--primary-ink)">' + (c.slotDoc ? doc(c.slotDoc).name : '') + '</span></div></div>' : '') +
          '<div class="field" style="max-width:340px"><label for="slot-doc">Doctor</label><select class="select" id="slot-doc" data-change="slot-doc">' + opts + '</select></div>' +
          '<div class="stack" style="gap:12px">' + dateStrip({ serviceId: c.serviceId, doctorId: docMode(), start: c.stripStart, selected: c.date, exclude: c.exclude, range: c.range, rangeLabel: c.rangeLabel, act: 'slot-date' }) + '</div>' +
          (c.err ? '<div class="notice notice-danger" role="alert">' + icon('info') + '<span><strong>' + esc(c.err) + '</strong></span></div>' : '') +
          '<div class="stack" style="gap:14px">' + slotGrid(list, c.start, 'slot-pick', docMode() === 'any', c.slotDoc) + '</div>',
        foot: '<span class="hint">' + c.footNote + '</span><div class="right"><button class="btn btn-secondary" data-act="dlg-close">' + c.cancelLabel + '</button><button class="btn btn-primary" data-act="slot-confirm" ' + (c.start == null ? 'disabled' : '') + '>' + (c.start == null ? c.confirmLabel : c.confirmLabel + ' ' + T(c.start)) + '</button></div>'
      };
    } });
  }

  /* ================= Doctor ================= */
  function sumBadge(a) {
    const it = S.intakes[a.id], sm = S.summaries[a.id];
    if (!it) return '<span class="badge tag b-warn">No intake yet</span>';
    if (!sm) return '<span class="badge tag b-grey">Pending</span>';
    if (sm.status === 'pending') return '<span class="badge tag b-grey">Preparing summary</span>';
    if (sm.status === 'skipped') return '<span class="badge tag b-skip">Skipped · ' + (sm.reason === 'emergency' ? 'emergency' : sm.reason) + '</span>';
    if (sm.status === 'failed') return '<span class="badge tag b-fail">Summary failed</span>';
    return sm.reviewedBy ? '<span class="badge tag b-ok">Reviewed</span>' : '<span class="badge tag b-ai">Ready · not reviewed</span>';
  }
  function viewDoctorToday(_, q) {
    const d = myDoc();
    const date = q.get('date') || today();
    const n = MB.now();
    const list = S.appts.filter((a) => a.doctorId === d.id && a.date === date && a.status !== 'cancelled').sort((x, y) => x.start - y.start);
    const waiting = list.filter((a) => a.status === 'checked_in');
    const drafts = list.filter((a) => S.summaries[a.id] && S.summaries[a.id].status === 'ready' && !S.summaries[a.id].reviewedBy && ACTIVE.includes(a.status));
    const emer = list.filter((a) => a.emergency && ACTIVE.includes(a.status));
    const noIntake = list.filter((a) => !S.intakes[a.id] && a.status === 'booked');
    const done = list.filter((a) => a.status === 'completed' || a.status === 'no_show').length;
    const ranges = S.hours[d.id][MB.weekday(date)] || [];
    let rows = '';
    let nowDrawn = date !== n.date;
    list.forEach((a, i) => {
      if (!nowDrawn && a.start > n.min) { rows += '<li aria-hidden="true" style="position:relative;height:0"><span class="nowline" style="left:0;top:-1px"></span><span class="nowtag" style="left:14px;right:auto">' + T(n.min) + '</span></li>'; nowDrawn = true; }
      const prev = list[i - 1];
      if (prev && ranges.length > 1 && prev.start < ranges[0][1] && a.start >= ranges[1][0]) rows += '<li class="lunchrow"><span class="mono" style="flex:0 0 56px">' + T(ranges[0][1]) + '</span><span>Lunch break</span></li>';
      const u = user(a.patientId), s = svc(a.serviceId);
      const isDone = a.status === 'completed' || a.status === 'no_show';
      rows += '<li class="' + (isDone ? 'done' : a.status === 'checked_in' ? 'here' : '') + '"><a href="#/doctor/appt/' + a.id + '"><span class="tm">' + T(a.start) + '</span><span class="who"><strong>' + esc(first(u.name)) + ' <em>· ' + ageRange(u) + '</em>' + (a.emergency ? '<span class="flag">' + flagIcon() + 'Emergency</span>' : '') + (a.followUpOf ? '<span class="badge b-outline">Follow-up</span>' : '') + '</strong><small>' + s.name + (a.status === 'checked_in' ? ' · waiting since ' + T(a.checkedInAt || a.start) : '') + '</small></span>' + statusBadge(a.status) + '<span class="sum">' + (isDone ? '' : sumBadge(a)) + '</span>' + icon('right', 18) + '</a></li>';
    });
    const next = waiting[0] || list.find((a) => a.status === 'booked' && isFuture(a));
    const nu = next && user(next.patientId);
    const upnext = next ? '<section class="upnext"><span class="eyebrow">' + (next.status === 'checked_in' ? 'Waiting for you' : 'Up next') + '</span><div><strong>' + esc(first(nu.name)) + ' · ' + ageRange(nu) + '</strong><p>' + svc(next.serviceId).name + ' · ' + T(next.start) + '</p></div><p style="color:#EAF3F1">' + (next.status === 'checked_in' ? 'Checked in at ' + T(next.checkedInAt || next.start) + '. ' : '') + (S.intakes[next.id] ? 'Intake filled. ' : 'No intake yet. ') + 'Opening it is logged.</p><a class="btn btn-mint" href="#/doctor/appt/' + next.id + '">Open intake and AI draft</a></section>' : '';
    const attn = [];
    emer.forEach((a) => attn.push('<li class="red">' + icon('alert', 16, 2.2) + '<span><strong>' + esc(first(user(a.patientId).name)) + ', ' + T(a.start) + '</strong> wrote words on the emergency list. No AI summary was made; read the answers.</span></li>'));
    if (drafts.length) attn.push('<li class="ai">' + icon('sparkle', 16) + '<span><strong>' + drafts.length + ' AI draft' + (drafts.length > 1 ? 's' : '') + '</strong> waiting for review: ' + drafts.map((a) => esc(first(user(a.patientId).name))).join(', ') + '.</span></li>');
    noIntake.forEach((a) => attn.push('<li class="amber">' + icon('clip', 16) + '<span><strong>' + esc(first(user(a.patientId).name)) + ', ' + T(a.start) + '</strong> hasn\'t filled in an intake.</span></li>'));
    const html = '<div class="page-head"><div><p style="margin:0 0 4px">Good ' + (n.min < 720 ? 'morning' : n.min < 1080 ? 'afternoon' : 'evening') + ', ' + d.short + '</p><h1>' + (date === n.date ? 'Today, ' : '') + D(date) + '</h1></div><div class="row" style="gap:8px"><a class="icon-btn" href="#/doctor' + qs({ date: MB.addDays(date, -1) }) + '" aria-label="Previous day">' + icon('left') + '</a><a class="icon-btn" href="#/doctor' + qs({ date: MB.addDays(date, 1) }) + '" aria-label="Next day">' + icon('right') + '</a>' + (date !== n.date ? '<a class="btn btn-dark btn-sm" href="#/doctor">Today</a>' : '') + '</div></div>' +
      '<div class="grid-4"><div class="stat"><span class="k">Patients</span><span class="v">' + list.length + '</span><span class="s">' + done + ' done · ' + waiting.length + ' here · ' + (list.length - done - waiting.length) + ' to come</span></div><div class="stat"><span class="k">Waiting for you</span><span class="v" style="color:var(--primary-ink)">' + waiting.length + '</span><span class="s">' + (waiting[0] ? esc(first(user(waiting[0].patientId).name)) + ', checked in ' + T(waiting[0].checkedInAt || waiting[0].start) : 'Nobody yet') + '</span></div><div class="stat"><span class="k">AI drafts to review</span><span class="v" style="color:var(--ai-ink)">' + drafts.length + '</span><span class="s">Before each visit</span></div><div class="stat" style="border-color:' + (emer.length ? 'var(--danger-line)' : 'var(--border)') + '"><span class="k">Emergency flags</span><span class="v" style="color:' + (emer.length ? 'var(--danger)' : 'var(--ink)') + '">' + emer.length + '</span><span class="s">Read the answers directly</span></div></div>' +
      '<div class="split"><div class="grow"><section class="card" style="overflow:hidden"><div class="card-head"><h2>Appointments</h2><span class="hint">In time order · Manila time</span></div>' + (list.length ? '<ol class="dlist">' + rows + '</ol>' : '<div class="empty">' + icon('cal', 28, 1.6) + '<strong>No appointments on this day.</strong>' + (ranges.length ? '' : '<span>You don\'t work on ' + MB.dayParts(date).dowLong + 's.</span>') + '</div>') + '</section></div>' +
      '<aside class="side">' + upnext + (attn.length ? '<section class="card card-pad stack" style="gap:12px"><h2 style="font-size:15px;font-weight:600">Needs your attention</h2><ul class="attn">' + attn.join('') + '</ul></section>' : '') + '</aside></div>';
    return { title: 'Today', html, layout: 'app', nav: '/doctor' };
  }
  function viewDoctorAppt(id) {
    const a = appt(id);
    const d = myDoc();
    if (!a) return viewNotFound();
    if (a.doctorId !== d.id) return viewForbidden('This is another doctor\'s patient. Doctors can only open their own appointments.');
    const key = 'view:' + a.id;
    if (ui.lastLogged !== key) { ui.lastLogged = key; if (S.intakes[a.id]) { log('intake.viewed', 'Appointment ' + a.id, ''); if (S.summaries[a.id] && S.summaries[a.id].status === 'ready') log('summary.viewed', 'Appointment ' + a.id, ''); } if (S.notes[a.id]) log('notes.viewed', 'Appointment ' + a.id, ''); save(); }
    const u = user(a.patientId), s = svc(a.serviceId);
    const it = S.intakes[a.id], sm = S.summaries[a.id], note = S.notes[a.id];
    let ai;
    if (!it) ai = '<div class="ai-state">' + icon('clip', 22) + '<strong style="color:var(--ink)">No intake yet</strong><span>The patient hasn\'t filled in the form, so there\'s nothing to summarize.</span></div>';
    else if (!sm || sm.status === 'pending') ai = '<div class="ai-state"><span class="spinner"></span><strong style="color:var(--ink)">Preparing summary</strong><span>This usually takes a few seconds. The answers on the left are complete.</span></div>';
    else if (sm.status === 'skipped') ai = '<div class="ai-state">' + icon('alert', 22) + '<strong style="color:var(--ink)">Summary skipped' + (sm.reason === 'emergency' ? ' · emergency words found' : '') + '</strong><span>No answers were sent to the AI. Read the patient\'s answers directly.</span></div>';
    else if (sm.status === 'failed') ai = '<div class="ai-state">' + icon('info', 22) + '<strong style="color:var(--ink)">Summary unavailable</strong><span>Read the answers on the left.</span></div>';
    else {
      const x = sm.data;
      ai = '<div class="ai-body"><div><h3>Chief complaint</h3><p style="font-weight:500;font-size:16px">' + esc(x.chief) + '</p></div><div><h3>Key points</h3><ul>' + x.points.map((p) => '<li>' + esc(p) + '</li>').join('') + '</ul></div><div><h3>Allergies and medicines</h3><p>' + esc(x.am) + '</p></div><div><h3>Questions for the doctor</h3><ul>' + x.questions.map((p) => '<li>' + esc(p) + '</li>').join('') + '</ul></div>' +
        '<hr class="divider"><div class="row" style="justify-content:space-between"><span class="hint">Made from intake answers only · ' + esc(sm.model || 'model') + '</span>' + (sm.reviewedBy ? '<span class="badge b-ok">' + icon('check', 13, 2.6) + 'Reviewed by ' + esc(sm.reviewedBy) + ' at ' + sm.reviewedAt + '</span>' : '<button class="btn btn-ai btn-sm" data-act="mark-reviewed" data-id="' + a.id + '">' + icon('check', 16, 2.6) + 'Mark reviewed</button>') + '</div></div>';
    }
    const fu = S.followUps.find((f) => f.sourceApptId === a.id);
    const html = '<a href="#/doctor' + (a.date === today() ? '' : '?date=' + a.date) + '" class="row" style="gap:6px;font-size:14px">' + icon('left', 16) + (a.date === today() ? 'Today' : D(a.date)) + '</a>' +
      '<section class="card card-pad row" style="gap:20px;padding:20px 24px"><span class="avatar lg" style="background:var(--bg);border:1px solid var(--border);color:var(--muted)">' + esc(first(u.name)[0]) + '</span><div style="flex:1 1 280px" class="stack"><div class="row" style="gap:10px"><h1 style="font-size:26px;line-height:32px;font-weight:600;letter-spacing:-.015em">' + esc(first(u.name)) + '</h1><span class="muted">' + ageRange(u) + '</span>' + statusBadge(a.status) + (a.emergency ? '<span class="flag">' + flagIcon() + 'Emergency</span>' : '') + (a.followUpOf ? '<span class="badge b-outline">Follow-up visit</span>' : '') + '</div><span class="muted"><span class="mono" style="color:var(--ink)">' + T(a.start) + ' to ' + T(apptEnd(a)) + '</span> · ' + s.name + ' · ' + D(a.date) + '</span></div>' +
      '<div class="row">' + (a.status === 'checked_in' ? '<button class="btn btn-primary" data-act="finish" data-id="' + a.id + '">Finish visit</button>' : a.status === 'booked' ? '<span class="hint">Waiting for check-in at the front desk</span>' : fu ? '<span class="badge b-warn">Follow-up ' + (fu.status === 'booked' ? 'booked' : 'requested') + '</span>' : '') + '</div></section>' +
      '<p class="hint" style="display:flex;gap:6px;align-items:center;margin-top:-8px">' + icon('eye', 14) + 'Your view of this intake is recorded in the audit log.</p>' +
      '<div class="grid-2" style="gap:20px;align-items:start"><section class="card"><div class="card-head"><h2>Intake answers</h2>' + (it ? '<span class="hint">From the patient · ' + D(it.submittedOn || a.date) + '</span>' : '') + '</div>' + (it ? answersDl(it.answers) : '<div class="empty"><strong>Not filled in.</strong></div>') + '</section>' +
      '<section class="ai-card"><div class="ai-head"><h2>' + icon('sparkle') + 'AI summary</h2><span class="ai-label">AI draft. The doctor must review.</span></div>' + ai + '</section></div>' +
      '<section class="card card-pad stack" style="gap:12px"><div class="row" style="justify-content:space-between"><h2 style="font-size:16px;font-weight:600;display:flex;gap:8px;align-items:center"><span style="color:var(--primary)">' + icon('lock', 16) + '</span><label for="notes">Visit notes</label></h2><span class="hint" id="notes-status">Encrypted · only you can read these' + (note ? ' · saved ' + note.savedAt : '') + '</span></div><textarea class="textarea" id="notes" rows="5" placeholder="What you found and what you advised.">' + esc(note ? note.text : '') + '</textarea><div class="row" style="justify-content:flex-end"><button class="btn btn-primary btn-sm" data-act="save-notes" data-id="' + a.id + '">Save notes</button></div></section>';
    return { title: first(u.name), html, layout: 'app', nav: '/doctor', keepScroll: !!ui.keepScroll };
  }
  function openFinishDialog(a) {
    const u = user(a.patientId), d = doc(a.doctorId);
    const st = { on: false, svc: 'fu', when: 14, from: '', to: '', same: true, note: '' };
    const range = () => {
      if (st.when === 'custom') return st.from && st.to && st.to >= st.from ? { from: st.from, to: st.to } : null;
      const from = MB.addDays(today(), st.when - 2);
      return { from, to: MB.addDays(from, 4) };
    };
    dialog({ render: () => {
      const r = range();
      let open = 0;
      if (r) for (let x = r.from; x <= r.to; x = MB.addDays(x, 1)) open += slotsFor(st.svc, st.same ? d.id : 'any', x).length;
      const it = S.intakes[a.id], sm = S.summaries[a.id];
      const checks = [[!!S.notes[a.id], S.notes[a.id] ? 'Visit notes saved ' + S.notes[a.id].savedAt : 'No visit notes yet'], [!it || !sm || sm.status !== 'ready' || !!sm.reviewedBy, sm && sm.status === 'ready' ? (sm.reviewedBy ? 'AI summary reviewed' : 'AI summary not reviewed') : 'No AI summary to review']];
      ui.finish = { a, st, range };
      return {
        title: 'Finish this visit', sub: esc(first(u.name)) + ' · ' + ageRange(u) + ' · ' + svc(a.serviceId).name + ' · ' + D(a.date) + ', ' + T(a.start),
        body: '<div class="row" style="gap:8px">' + checks.map(([ok, l]) => '<span class="badge tag ' + (ok ? 'b-ok' : 'b-warn') + '" style="font-size:13px;padding:6px 10px">' + icon(ok ? 'check' : 'info', 13, 2.4) + l + '</span>').join('') + '</div>' +
          '<div class="row" style="justify-content:space-between;flex-wrap:nowrap;gap:16px"><div><strong id="fu-switch-l" style="font-size:17px">Ask ' + esc(first(u.name)) + ' to come back</strong><p class="hint">They get a request to book. Nothing is booked for them.</p></div><button type="button" class="switch" role="switch" aria-checked="' + st.on + '" aria-labelledby="fu-switch-l" data-act="fin-toggle"></button></div>' +
          (st.on ? '<div class="inset"><div class="field" style="max-width:320px"><label for="fin-svc">Visit type</label><select class="select" id="fin-svc" data-change="fin-svc">' + S.services.filter((s) => d.services.includes(s.id)).map((s) => '<option value="' + s.id + '" ' + (st.svc === s.id ? 'selected' : '') + '>' + s.name + ' · ' + s.dur + ' min</option>').join('') + '</select></div>' +
            '<div class="field"><span class="label" id="fin-when-l">When</span><div class="chips" role="group" aria-labelledby="fin-when-l">' + [[7, 'In 1 week'], [14, 'In 2 weeks'], [30, 'In 1 month'], ['custom', 'Pick dates']].map(([v, l]) => '<button type="button" class="chip-btn" data-act="fin-when" data-v="' + v + '" aria-pressed="' + (st.when === v) + '">' + l + '</button>').join('') + '</div>' +
            (st.when === 'custom' ? '<div class="row"><label class="sr-only" for="fin-from">From</label><input class="input" style="width:auto" type="date" id="fin-from" min="' + today() + '" value="' + st.from + '" data-change="fin-from"><span class="hint">to</span><label class="sr-only" for="fin-to">To</label><input class="input" style="width:auto" type="date" id="fin-to" min="' + today() + '" value="' + st.to + '" data-change="fin-to"></div>' : '') +
            (r ? '<span class="hint" style="color:var(--primary-ink);display:flex;gap:6px;align-items:center">' + icon('cal', 14) + 'Between <strong>' + D(r.from) + '</strong> and <strong>' + D(r.to) + '</strong> · ' + (st.same ? 'you have ' : 'the clinic has ') + open + ' open times then</span>' : '<span class="error-text">Pick a start and end date.</span>') + '</div>' +
            '<div class="field"><span class="label" id="fin-with-l">With</span><div class="grid-2" role="radiogroup" aria-labelledby="fin-with-l"><button type="button" class="opt-card" style="padding:12px 14px" role="radio" aria-checked="' + st.same + '" aria-pressed="' + st.same + '" data-act="fin-with" data-v="same"><span class="body"><span class="name" style="font-size:15px">Me, ' + d.short + '</span><span class="meta">Best for checking progress</span></span></button><button type="button" class="opt-card" style="padding:12px 14px" role="radio" aria-checked="' + !st.same + '" aria-pressed="' + !st.same + '" data-act="fin-with" data-v="any"><span class="body"><span class="name" style="font-size:15px">Any doctor</span><span class="meta">More times to choose from</span></span></button></div></div>' +
            '<div class="field"><label for="fin-note">Note for ' + esc(first(u.name)) + ' <span class="opt">(optional)</span></label><textarea class="textarea" id="fin-note" rows="2" maxlength="200" data-count="200" data-change="fin-note">' + esc(st.note) + '</textarea><div class="row" style="justify-content:space-between"><span class="hint">' + icon('lock', 12) + ' Shown only in ' + esc(first(u.name)) + '\'s account. Never emailed, never shown to staff.</span><span class="counter">' + st.note.length + ' / 200</span></div></div></div>' : ''),
        foot: '<span class="hint">' + (st.on ? esc(first(u.name)) + ' gets an email to book, with no health details.' : 'The visit will be marked completed.') + '</span><div class="right"><button class="btn btn-secondary" data-act="dlg-close">Cancel</button><button class="btn btn-primary" data-act="fin-submit" ' + (st.on && !r ? 'disabled' : '') + '>' + (st.on ? 'Complete and send request' : 'Mark completed') + '</button></div>'
      };
    } });
  }

  /* ================= Admin ================= */
  function viewAdminUsers(_, q) {
    const r = q.get('role') || '';
    const term = (q.get('q') || '').toLowerCase();
    const list = S.users.filter((u) => (!r || u.role === r) && (!term || (u.name + ' ' + u.email).toLowerCase().includes(term))).sort((a, b) => ['admin', 'doctor', 'staff', 'patient'].indexOf(a.role) - ['admin', 'doctor', 'staff', 'patient'].indexOf(b.role) || a.name.localeCompare(b.name));
    const rows = list.map((u) => '<tr><td><div class="row" style="gap:10px;flex-wrap:nowrap"><span class="avatar ' + ({ doctor: 'av-violet', staff: 'av-blue', admin: 'av-dark' }[u.role] || '') + '">' + initials(u.name) + '</span><div><strong>' + esc(u.name) + '</strong><div class="hint">' + esc(u.email) + '</div></div></div></td><td><span class="role-badge role-' + u.role + '">' + u.role + '</span></td><td>' + (u.active ? '<span class="badge b-ok">Active</span>' : '<span class="badge b-outline">Inactive</span>') + '</td><td>' + (u.twoFA ? 'On' : '<span class="subtle">Off</span>') + '</td><td class="hint">' + D(u.created) + '</td><td style="text-align:right">' + (u.id === me().id ? '<span class="hint">You</span>' : '<button class="btn btn-secondary btn-sm" data-act="toggle-user" data-id="' + u.id + '">' + (u.active ? 'Deactivate' : 'Reactivate') + '</button>') + '</td></tr>').join('');
    const html = '<div class="page-head"><div><h1>Users</h1><p>' + S.users.length + ' accounts. Patients sign up themselves; you create staff, doctor and admin accounts.</p></div><button class="btn btn-primary" data-act="create-user">' + icon('plus', 16, 2.4) + 'Create user</button></div>' +
      '<form class="card filters" data-form="user-filter" style="grid-template-columns:2fr 1fr auto"><div class="field"><label for="uf-q">Search</label><input class="input" id="uf-q" name="q" value="' + esc(q.get('q') || '') + '" placeholder="Name or email"></div><div class="field"><label for="uf-r">Role</label><select class="select" id="uf-r" name="role"><option value="">All roles</option>' + ['patient', 'staff', 'doctor', 'admin'].map((x) => '<option ' + (r === x ? 'selected' : '') + ' value="' + x + '">' + x[0].toUpperCase() + x.slice(1) + '</option>').join('') + '</select></div><button class="btn btn-dark" type="submit">Apply</button></form>' +
      '<section class="card table-wrap"><table class="tbl" style="min-width:760px"><thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">2FA</th><th scope="col">Created</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>' + (rows || '<tr><td colspan="6"><div class="empty"><strong>No users match these filters.</strong></div></td></tr>') + '</tbody></table></section>';
    return { title: 'Users', html, layout: 'app', nav: '/admin' };
  }
  function viewAdminServices() {
    const rows = S.services.map((s) => '<tr><td><div class="row" style="gap:12px;flex-wrap:nowrap">' + svcIcon(s).replace('class="ic', 'style="width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:' + (s.tone === 'amber' ? '#FBEFE4;color:#A4561B' : 'var(--primary-soft);color:var(--primary)') + '" class="x') + '<div><strong>' + s.name + '</strong><div class="hint">' + s.desc + '</div></div></div></td><td class="mono">' + s.dur + ' min</td><td class="mono">' + s.buf + ' min</td><td>' + MB.DOCTORS.filter((d) => d.services.includes(s.id)).map((d) => d.short).join(', ') + '</td><td><button type="button" class="switch" role="switch" aria-checked="' + s.active + '" aria-label="' + s.name + ' bookable" data-act="toggle-service" data-id="' + s.id + '"></button></td></tr>').join('');
    const html = '<div class="page-head"><div><h1>Services</h1><p>Length sets the slot size. The buffer is the gap kept free after each visit.</p></div></div><section class="card table-wrap"><table class="tbl" style="min-width:760px"><thead><tr><th scope="col">Service</th><th scope="col">Length</th><th scope="col">Buffer</th><th scope="col">Doctors</th><th scope="col">Bookable</th></tr></thead><tbody>' + rows + '</tbody></table></section>';
    return { title: 'Services', html, layout: 'app', nav: '/admin/services' };
  }
  function viewAdminHours(_, q) {
    const docId = q.get('doc') || 'lim';
    const d = doc(docId) || doc('lim');
    if (ui.hoursDoc !== d.id || !ui.hoursDraft) { ui.hoursDoc = d.id; ui.hoursDraft = JSON.parse(JSON.stringify(S.hours[d.id])); ui.hoursErr = null; }
    const H = ui.hoursDraft;
    const days = [1, 2, 3, 4, 5, 6, 0];
    const names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const changed = JSON.stringify(H) !== JSON.stringify(S.hours[d.id]);
    const rows = days.map((wd) => {
      const rs = H[wd] || [];
      return '<div class="hours-row"><span class="wd">' + names[wd] + '</span><div class="ranges">' + (rs.length ? rs.map(([a, b], i) => '<div class="rg"><label class="sr-only" for="h-' + wd + '-' + i + '-0">' + names[wd] + ' range ' + (i + 1) + ' start</label><input type="time" step="900" id="h-' + wd + '-' + i + '-0" value="' + T(a) + '" data-change="hour" data-wd="' + wd + '" data-i="' + i + '" data-k="0"><span class="hint">to</span><label class="sr-only" for="h-' + wd + '-' + i + '-1">' + names[wd] + ' range ' + (i + 1) + ' end</label><input type="time" step="900" id="h-' + wd + '-' + i + '-1" value="' + T(b) + '" data-change="hour" data-wd="' + wd + '" data-i="' + i + '" data-k="1"><button type="button" class="icon-btn plain" data-act="hour-del" data-wd="' + wd + '" data-i="' + i + '" aria-label="Remove ' + names[wd] + ' range ' + (i + 1) + '">' + icon('x', 16) + '</button></div>').join('') : '<span class="closed">Not working</span>') + '</div><button type="button" class="btn btn-secondary btn-sm" style="border-style:dashed;color:var(--primary-ink)" data-act="hour-add" data-wd="' + wd + '">' + icon('plus', 14, 2.4) + 'Add range</button></div>';
    }).join('');
    const total = Object.values(H).reduce((t, rs) => t + rs.reduce((x, [a, b]) => x + (b - a), 0), 0) / 60;
    const bars = days.map((wd) => '<div class="r"><span>' + names[wd].slice(0, 3) + '</span><div class="track">' + (H[wd] || []).map(([a, b]) => '<i style="left:' + ((a - 420) / 720 * 100) + '%;width:' + ((b - a) / 720 * 100) + '%"></i>').join('') + '</div></div>').join('');
    const offs = S.timeOff.filter((t) => t.doctorId === d.id && t.date >= today()).sort((a, b) => a.date.localeCompare(b.date));
    const offRows = offs.map((t) => { const hit = S.appts.filter((a) => a.doctorId === d.id && a.date === t.date && a.status === 'booked' && a.start < t.end && apptEnd(a) > t.start).length; return '<tr><td>' + D(t.date) + '</td><td class="mono">' + T(t.start) + ' to ' + T(t.end) + '</td><td>' + esc(t.reason) + '</td><td>' + (hit ? '<span class="badge b-skip">' + hit + ' booking' + (hit > 1 ? 's' : '') + ' inside</span>' : '<span class="hint">None</span>') + '</td><td style="text-align:right"><button class="btn btn-secondary btn-sm" data-act="timeoff-del" data-id="' + t.id + '">Remove</button></td></tr>'; }).join('');
    const html = '<div class="page-head"><div><h1>Working hours and time off</h1><p>Manila time. Add a second range for a lunch break.</p></div><div class="row"><label class="sr-only" for="h-doc">Doctor</label><select class="select" style="width:auto" id="h-doc" data-change="hours-doc">' + MB.DOCTORS.map((x) => '<option value="' + x.id + '" ' + (x.id === d.id ? 'selected' : '') + '>' + x.name + '</option>').join('') + '</select></div></div>' +
      (ui.hoursErr ? '<div class="notice notice-danger" role="alert">' + icon('alert') + '<span>' + esc(ui.hoursErr) + '</span></div>' : '') +
      '<div class="split"><div class="grow"><section class="card"><div class="card-head"><h2>' + d.name + ' · weekly hours</h2><div class="row"><button class="btn btn-secondary btn-sm" data-act="hours-discard" ' + (changed ? '' : 'disabled') + '>Discard</button><button class="btn btn-primary btn-sm" data-act="hours-save" ' + (changed ? '' : 'disabled') + '>Save hours</button></div></div>' + rows + '</section>' +
      '<section class="card"><div class="card-head"><h2>Upcoming time off</h2></div>' + (offRows ? '<div class="table-wrap"><table class="tbl"><thead><tr><th scope="col">Date</th><th scope="col">Time</th><th scope="col">Reason</th><th scope="col">Bookings affected</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>' + offRows + '</tbody></table></div>' : '<div class="empty" style="padding:24px"><strong>No time off planned.</strong></div>') +
      '<form class="filters" data-form="timeoff" style="border-top:1px solid var(--line);grid-template-columns:1.2fr 1fr 1fr 1.6fr auto"><div class="field"><label for="to-date">Date</label><input class="input" type="date" id="to-date" name="date" min="' + today() + '" required></div><div class="field"><label for="to-s">From</label><input class="input" type="time" step="900" id="to-s" name="start" value="08:00"></div><div class="field"><label for="to-e">To</label><input class="input" type="time" step="900" id="to-e" name="end" value="17:00"></div><div class="field"><label for="to-r">Reason</label><input class="input" id="to-r" name="reason" placeholder="e.g. Conference"></div><button class="btn btn-dark" type="submit">Add time off</button></form></section></div>' +
      '<aside class="side"><section class="card card-pad stack"><div class="row" style="justify-content:space-between"><h2 style="font-size:16px;font-weight:600">Week at a glance</h2><strong>' + total + ' h</strong></div><div class="week-bars"><div class="axis"><span>07</span><span>10</span><span>13</span><span>16</span><span>19</span></div>' + bars + '</div>' + (changed ? '<p class="hint" style="color:var(--amber-ink)">Unsaved changes</p>' : '') + '</section></aside></div>';
    return { title: 'Working hours', html, layout: 'app', nav: '/admin/hours', keepScroll: !!ui.keepScroll };
  }
  function viewAdminAudit(_, q) {
    const fa = q.get('actor') || '', fx = q.get('action') || '', fd = q.get('date') || '';
    const page = Math.max(1, Number(q.get('p') || 1));
    const all = S.audit.filter((e) => (!fa || e.actor === fa) && (!fx || e.action === fx) && (!fd || e.date === fd));
    const per = 50, pages = Math.max(1, Math.ceil(all.length / per));
    const list = all.slice((page - 1) * per, page * per);
    const actors = [...new Set(S.audit.map((e) => e.actor))].map((id) => [id, (S.audit.find((e) => e.actor === id) || {}).actorName || id]).sort((a, b) => a[1].localeCompare(b[1]));
    const actions = [...new Set(S.audit.map((e) => e.action))].sort();
    const roleOf = (id) => (user(id) || {}).role;
    const rows = list.map((e) => '<tr><td class="mono" style="font-size:13px;color:var(--muted);white-space:nowrap">' + D(e.date, 'dm') + ' ' + e.time + '</td><td>' + esc(e.actorName) + (roleOf(e.actor) ? ' <span class="role-badge role-' + roleOf(e.actor) + '" style="margin-left:4px">' + roleOf(e.actor) + '</span>' : '') + '</td><td><code class="code ' + (/viewed/.test(e.action) ? 'view' : '') + '">' + e.action + '</code></td><td class="mono" style="font-size:13px">' + esc(e.subject) + '</td><td class="mono" style="font-size:13px">' + (e.meta ? esc(e.meta) : '<span class="subtle">-</span>') + '</td></tr>').join('');
    const html = '<div class="page-head"><div><h1>Audit log</h1><p style="display:flex;gap:6px;align-items:center">' + icon('lock', 14) + 'Append-only. Entries can\'t be edited or deleted. No health text is stored here.</p></div></div>' +
      '<form class="card filters" data-form="audit-filter"><div class="field"><label for="af-a">Actor</label><select class="select" id="af-a" name="actor"><option value="">Anyone</option>' + actors.map(([id, n]) => '<option value="' + id + '" ' + (fa === id ? 'selected' : '') + '>' + esc(n) + '</option>').join('') + '</select></div><div class="field"><label for="af-x">Action</label><select class="select" id="af-x" name="action"><option value="">All actions</option><optgroup label="Views">' + actions.filter((a) => /viewed/.test(a)).map((a) => '<option ' + (fx === a ? 'selected' : '') + '>' + a + '</option>').join('') + '</optgroup><optgroup label="Changes">' + actions.filter((a) => !/viewed/.test(a)).map((a) => '<option ' + (fx === a ? 'selected' : '') + '>' + a + '</option>').join('') + '</optgroup></select></div><div class="field"><label for="af-d">Date</label><input class="input" type="date" id="af-d" name="date" value="' + fd + '"></div><div></div><div class="row" style="gap:8px"><button class="btn btn-primary" type="submit">Apply</button><a class="btn btn-secondary" href="#/admin/audit">Clear</a></div></form>' +
      '<section class="card table-wrap"><table class="tbl" style="min-width:820px"><thead><tr><th scope="col">Time</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Subject</th><th scope="col">Details</th></tr></thead><tbody>' + (rows || '<tr><td colspan="5"><div class="empty"><strong>No events match these filters.</strong></div></td></tr>') + '</tbody></table></section>' +
      '<div class="pager"><span>Showing ' + (all.length ? (page - 1) * per + 1 : 0) + ' to ' + Math.min(page * per, all.length) + ' of ' + all.length + ' events · <span class="code view" style="padding:0 6px">amber</span> = health-data views</span><div class="row" style="gap:6px"><a class="btn btn-secondary btn-sm" href="#/admin/audit' + qs({ actor: fa, action: fx, date: fd, p: page - 1 }) + '" ' + (page > 1 ? '' : 'aria-disabled="true"') + '>Previous</a><span class="hint">Page ' + page + ' of ' + pages + '</span><a class="btn btn-secondary btn-sm" href="#/admin/audit' + qs({ actor: fa, action: fx, date: fd, p: page + 1 }) + '" ' + (page < pages ? '' : 'aria-disabled="true"') + '>Next</a></div></div>';
    return { title: 'Audit log', html, layout: 'app', nav: '/admin/audit' };
  }
  function viewSettings() {
    const u = me();
    const need2fa = u.role !== 'patient';
    const html = '<div class="page-head"><div><h1>Profile and security</h1><p>Your account details.</p></div></div><div class="split"><div class="grow"><section class="card card-pad stack"><h2 style="font-size:17px;font-weight:600">Profile</h2><dl class="dl" style="font-size:15px"><div><dt>Name</dt><dd>' + esc(u.name) + '</dd></div><div><dt>Email</dt><dd>' + esc(u.email) + '</dd></div><div><dt>Role</dt><dd><span class="role-badge role-' + u.role + '">' + u.role + '</span></dd></div></dl></section>' +
      '<section class="card card-pad stack"><div class="row" style="justify-content:space-between"><h2 style="font-size:17px;font-weight:600">Two-factor sign-in</h2>' + (u.twoFA ? '<span class="badge b-ok">On</span>' : '<span class="badge b-grey">Off</span>') + '</div><p class="muted">' + (need2fa ? 'Required for staff, doctor and admin accounts.' : 'Optional for patients. Adds a 6-digit code from an app when you log in.') + '</p>' + (need2fa ? '' : '<div><button class="btn btn-secondary" data-act="toggle-2fa">' + (u.twoFA ? 'Turn off' : 'Turn on') + '</button></div>') + '</section></div></div>';
    return { title: 'Profile and security', html, layout: 'app', nav: '/settings' };
  }
  function viewForbidden(msg) {
    const html = '<div class="book-wrap" style="max-width:560px;text-align:center"><div class="card empty" style="padding:48px 24px">' + icon('lock', 32, 1.6) + '<h1 style="font-size:24px;font-weight:600">You don\'t have access to this page</h1><p class="muted">' + esc(msg || 'Your role can\'t open this part of MediBook.') + '</p><a class="btn btn-primary" href="#' + (role() ? ROLE_HOME[role()] : '/') + '">Go to your home page</a></div></div>';
    return { title: 'No access', html, layout: role() ? 'app' : undefined };
  }
  function viewNotFound() {
    return { title: 'Not found', html: '<div class="book-wrap" style="max-width:560px;text-align:center"><div class="card empty" style="padding:48px 24px"><h1 style="font-size:24px;font-weight:600">Page not found</h1><p class="muted">The link may be old, or the item was removed.</p><a class="btn btn-primary" href="#' + (role() ? ROLE_HOME[role()] : '/') + '">Go home</a></div></div>', layout: role() ? 'app' : undefined };
  }

  /* ================= Actions ================= */
  function rerender(keep) { ui.keepScroll = keep !== false; render(); ui.keepScroll = false; }
  function refocus(sel) { const el = sel && document.querySelector(sel); if (el) el.focus(); }

  const ACT = {
    'go': (el) => go(el.dataset.to),
    'skip': () => { const h = document.querySelector('#main h1') || document.querySelector('#app h1'); if (h) { h.setAttribute('tabindex', '-1'); h.focus(); } },
    'scroll': (el) => { const t = document.getElementById(el.dataset.to); if (t) t.scrollIntoView({ behavior: 'smooth' }); },
    'logout': () => { S.session = null; S.returnTo = null; ui.menuOpen = false; save(); go('/'); toast('Logged out'); },
    'demo-menu': () => { ui.menuOpen = !ui.menuOpen; renderDemoSwitch(); if (ui.menuOpen) refocus('#demo-menu button'); },
    'demo-login': (el) => demoLogin(el.dataset.role),
    'reset': () => {
      ui.menuOpen = false; renderDemoSwitch();
      dialog({ render: () => ({ title: 'Reset demo data?', body: '<p class="muted">This puts back the original made-up patients, bookings and follow-ups. Anything you changed will be lost.</p>', foot: '<div class="right"><button class="btn btn-secondary" data-act="dlg-close">Keep my changes</button><button class="btn btn-danger" data-act="reset-yes">Reset</button></div>' }) });
    },
    'reset-yes': () => { const sess = S.session; S = MB.seed(); S.session = sess; save(); closeDialog(); ui.hoursDraft = null; render(); toast('Demo data reset'); },
    'dlg-close': () => closeDialog(),

    // booking
    'pick-service': (el) => { const d = draft(); d.serviceId = el.dataset.id; d.followUpId = null; if (d.doctorId && d.doctorId !== 'any' && !doc(d.doctorId).services.includes(d.serviceId)) d.doctorId = null; d.date = null; d.start = null; d.slotDoctorId = null; save(); rerender(); refocus('[data-act="pick-service"][data-id="' + el.dataset.id + '"]'); },
    'pick-service-go': (el) => { S.draft = { serviceId: el.dataset.id, doctorId: el.dataset.doc || null, date: null, start: null, slotDoctorId: null, followUpId: null, stripStart: null }; save(); closeDialog(true); go(el.dataset.doc ? '/book/time' : '/book/doctor'); },
    'pick-doctor': (el) => { const d = draft(); d.doctorId = el.dataset.id; d.date = null; d.start = null; d.slotDoctorId = null; save(); rerender(); refocus('[data-act="pick-doctor"][data-id="' + el.dataset.id + '"]'); },
    'pick-date': (el) => { const d = draft(); d.date = el.dataset.date; d.start = null; d.slotDoctorId = null; save(); rerender(); refocus('[data-act="pick-date"][data-date="' + el.dataset.date + '"]'); },
    'pick-date-page': (el) => { const d = draft(); const t = today(); let s = MB.addDays(d.stripStart || t, 7 * Number(el.dataset.dir)); if (s < t) s = t; d.stripStart = s; d.date = null; d.start = null; save(); rerender(); },
    'pick-slot': (el) => { const d = draft(); d.start = Number(el.dataset.start); d.slotDoctorId = el.dataset.doc; save(); rerender(); refocus('[data-act="pick-slot"][aria-pressed="true"]'); },
    'fu-alt': (el) => { const d = draft(); if (el.dataset.mode === 'any') { d.doctorId = 'any'; } else { d.stripStart = el.dataset.date; } d.date = el.dataset.mode === 'after' ? el.dataset.date : null; d.start = null; d.slotDoctorId = null; save(); rerender(); },
    'confirm-booking': () => {
      const d = draft();
      const res = book({ patientId: me().id, serviceId: d.serviceId, doctorId: d.slotDoctorId, date: d.date, start: d.start, followUpId: d.followUpId });
      if (res.error) { ui.bookErr = res.error; if (res.code === 'taken') { d.start = null; d.slotDoctorId = null; } save(); rerender(false); return; }
      S.draft = null; save(); go('/booked/' + res.appt.id);
    },

    // patient
    'patient-cancel': (el) => {
      const a = appt(el.dataset.id);
      dialog({ render: () => ({ title: 'Cancel this appointment?', body: '<p class="muted">' + svc(a.serviceId).name + ' with ' + doc(a.doctorId).name + ' on <strong style="color:var(--ink)">' + D(a.date) + ', ' + T(a.start) + '</strong>. The time will open up for other patients.</p><div class="field"><label for="pc-reason">Reason <span class="opt">(optional)</span></label><input class="input" id="pc-reason" placeholder="e.g. Feeling better"></div>', foot: '<div class="right"><button class="btn btn-secondary" data-act="dlg-close">Keep appointment</button><button class="btn btn-danger" data-act="patient-cancel-yes" data-id="' + a.id + '">Cancel appointment</button></div>' }) });
    },
    'patient-cancel-yes': (el) => {
      const a = appt(el.dataset.id);
      if (minsUntil(a) < C.cancelCutoffMin) { closeDialog(); toast('It\'s less than 2 hours before your visit. Please call the clinic.', 'err'); return; }
      cancelAppt(a, 'patient', ($('#pc-reason') || {}).value || 'Cancelled by patient');
      closeDialog(); rerender(); toast('Appointment cancelled');
    },
    'fu-dismiss': (el) => {
      dialog({ render: () => ({ title: 'Skip this follow-up?', body: '<p class="muted">Your doctor asked to see you again. If you skip it, the clinic may still call to check in. You can always book a visit yourself.</p>', foot: '<div class="right"><button class="btn btn-secondary" data-act="dlg-close">Keep it</button><button class="btn btn-primary" data-act="fu-dismiss-yes" data-id="' + el.dataset.id + '">I don\'t need this visit</button></div>' }) });
    },
    'fu-dismiss-yes': (el) => { const f = S.followUps.find((x) => x.id === el.dataset.id); f.status = 'dismissed'; f.history.unshift({ when: D(today()) + ', ' + T(MB.now().min), what: 'Patient said not needed', kind: 'hollow' }); log('follow_up.dismissed', 'Follow-up ' + f.id, 'open to dismissed'); save(); closeDialog(); rerender(); toast('Follow-up removed'); },

    // staff
    'checkin': (el) => { const a = appt(el.dataset.id); a.status = 'checked_in'; a.checkedInAt = MB.now().min; log('appointment.checked_in', 'Appointment ' + a.id, 'booked to checked_in'); save(); rerender(); toast(first(user(a.patientId).name) + ' is checked in'); },
    'noshow': (el) => {
      const a = appt(el.dataset.id);
      dialog({ render: () => ({ title: 'Mark as no-show?', body: '<p class="muted">' + esc(user(a.patientId).name) + ' didn\'t arrive for ' + T(a.start) + '. This can\'t be undone in the demo.</p>', foot: '<div class="right"><button class="btn btn-secondary" data-act="dlg-close">Go back</button><button class="btn btn-danger" data-act="noshow-yes" data-id="' + a.id + '">Mark no-show</button></div>' }) });
    },
    'noshow-yes': (el) => { const a = appt(el.dataset.id); a.status = 'no_show'; log('appointment.no_show', 'Appointment ' + a.id, 'booked to no_show'); save(); closeDialog(); rerender(); toast('Marked as no-show'); },
    'staff-cancel': (el) => {
      const a = appt(el.dataset.id);
      dialog({ render: () => ({ title: 'Cancel this appointment?', sub: esc(user(a.patientId).name) + ' · ' + D(a.date) + ', ' + T(a.start), body: '<div class="field"><label for="sc-reason">Reason <span class="opt">(required, sent to the patient)</span></label><input class="input" id="sc-reason" placeholder="e.g. Doctor unavailable"><span class="error-text" id="sc-err"></span></div><p class="hint">The patient gets an email with the date, time and doctor. No health details.</p>', foot: '<div class="right"><button class="btn btn-secondary" data-act="dlg-close">Keep appointment</button><button class="btn btn-danger" data-act="staff-cancel-yes" data-id="' + a.id + '">Cancel appointment</button></div>' }) });
    },
    'staff-cancel-yes': (el) => {
      const r = ($('#sc-reason') || {}).value.trim();
      if (!r) { $('#sc-err').textContent = 'Add a reason so the patient knows why.'; $('#sc-reason').classList.add('invalid'); $('#sc-reason').focus(); return; }
      cancelAppt(appt(el.dataset.id), 'staff', r); closeDialog(); rerender(); toast('Appointment cancelled. Patient emailed.');
    },
    'staff-move': (el) => {
      const a = appt(el.dataset.id);
      openSlotDialog({ mode: 'move', apptId: a.id, serviceId: a.serviceId, doctorChoice: a.doctorId, origDoctor: a.doctorId, exclude: a.id, title: 'Move appointment', sub: esc(user(a.patientId).name) + ' · ' + svc(a.serviceId).name + ' · ' + svc(a.serviceId).dur + ' min', from: D(a.date) + ' · ' + T(a.start), fromDoc: doc(a.doctorId).name, footNote: 'The old time is freed in the same step.', cancelLabel: 'Keep current time', confirmLabel: 'Move to' });
    },
    'fu-staff-book': (el) => {
      const f = S.followUps.find((x) => x.id === el.dataset.id);
      openSlotDialog({ mode: 'fu', fuId: f.id, serviceId: f.serviceId, doctorChoice: f.sameDoctor ? f.doctorId : 'any', origDoctor: f.doctorId, title: 'Book follow-up for ' + esc(first(user(f.patientId).name)), sub: svc(f.serviceId).name + ' · ' + doc(f.doctorId).short + ' asked for ' + D(f.from) + ' to ' + D(f.to), range: { from: f.from, to: f.to }, rangeLabel: 'Suggested by ' + doc(f.doctorId).short, footNote: 'The patient gets a confirmation email.', cancelLabel: 'Cancel', confirmLabel: 'Book' });
    },
    'slot-date': (el) => { ui.slot.date = el.dataset.date; ui.slot.start = null; ui.slot.slotDoc = null; ui.slot.err = null; paintDialog(); refocus('[data-act="slot-date"][data-date="' + el.dataset.date + '"]'); },
    'slot-date-page': (el) => { const c = ui.slot; const t = today(); let s = MB.addDays(c.stripStart, 7 * Number(el.dataset.dir)); if (s < t) s = t; c.stripStart = s; c.start = null; c.pickFirst(); paintDialog(); },
    'slot-pick': (el) => { ui.slot.start = Number(el.dataset.start); ui.slot.slotDoc = el.dataset.doc; ui.slot.err = null; paintDialog(); refocus('[data-act="slot-pick"][aria-pressed="true"]'); },
    'slot-confirm': () => {
      const c = ui.slot;
      if (c.mode === 'move') {
        const res = moveAppt(appt(c.apptId), c.date, c.start, c.slotDoc);
        if (res.error) { c.err = res.error; c.start = null; paintDialog(); return; }
        const date = res.appt.date;
        closeDialog(); go('/staff' + qs({ date: date === today() ? null : date, sel: res.appt.id })); toast('Moved to ' + D(date) + ', ' + T(res.appt.start));
      } else {
        const f = S.followUps.find((x) => x.id === c.fuId);
        const res = book({ patientId: f.patientId, serviceId: f.serviceId, doctorId: c.slotDoc, date: c.date, start: c.start, followUpId: f.id, bookedBy: 'staff' });
        if (res.error) { c.err = res.error.replace('You already have', 'The patient already has').replace('You ', 'The patient '); c.start = null; paintDialog(); return; }
        closeDialog(); rerender(); toast('Booked for ' + D(c.date) + ', ' + T(res.appt.start));
      }
    },
    'fu-call': (el) => {
      dialog({ render: () => ({ title: 'Log a call', body: '<div class="field"><label for="call-out">Outcome</label><select class="select" id="call-out"><option>No answer</option><option>Will book online</option><option>Booked by phone</option><option>Doesn\'t need the visit</option></select></div><p class="hint">Don\'t write health details here. Staff notes are not encrypted.</p>', foot: '<div class="right"><button class="btn btn-secondary" data-act="dlg-close">Cancel</button><button class="btn btn-primary" data-act="fu-call-yes" data-id="' + el.dataset.id + '">Save</button></div>' }) });
    },
    'fu-call-yes': (el) => {
      const f = S.followUps.find((x) => x.id === el.dataset.id);
      const out = $('#call-out').value;
      f.history.unshift({ when: D(today()) + ', ' + T(MB.now().min), what: 'Called by ' + me().name + ': ' + out, kind: 'blue' });
      if (out === 'Doesn\'t need the visit') f.status = 'dismissed';
      log('follow_up.called', 'Follow-up ' + f.id, out);
      save(); closeDialog(); rerender(); toast('Call logged');
    },
    'fu-remind': (el) => { const f = S.followUps.find((x) => x.id === el.dataset.id); f.history.unshift({ when: D(today()) + ', ' + T(MB.now().min), what: 'Reminder email sent by ' + me().name, kind: 'blue' }); log('follow_up.reminded', 'Follow-up ' + f.id, ''); save(); rerender(); toast('Reminder sent to ' + first(user(f.patientId).name)); },

    // doctor
    'mark-reviewed': (el) => { const sm = S.summaries[el.dataset.id]; sm.reviewedBy = me().name; sm.reviewedAt = T(MB.now().min); log('summary.reviewed', 'Appointment ' + el.dataset.id, ''); save(); rerender(); toast('Summary marked reviewed'); },
    'save-notes': (el) => { const txt = $('#notes').value; S.notes[el.dataset.id] = { text: txt, savedAt: T(MB.now().min) }; log('notes.saved', 'Appointment ' + el.dataset.id, ''); save(); $('#notes-status').textContent = 'Encrypted · only you can read these · saved ' + S.notes[el.dataset.id].savedAt; toast('Notes saved'); },
    'finish': (el) => openFinishDialog(appt(el.dataset.id)),
    'fin-toggle': () => { ui.finish.st.on = !ui.finish.st.on; paintDialog(); refocus('.switch'); },
    'fin-when': (el) => { const v = el.dataset.v; ui.finish.st.when = v === 'custom' ? 'custom' : Number(v); paintDialog(); refocus('[data-act="fin-when"][aria-pressed="true"]'); },
    'fin-with': (el) => { ui.finish.st.same = el.dataset.v === 'same'; paintDialog(); refocus('[data-act="fin-with"][aria-pressed="true"]'); },
    'fin-submit': () => {
      const { a, st, range } = ui.finish;
      const noteEl = $('#fin-note');
      if (noteEl) st.note = noteEl.value;
      a.status = 'completed';
      log('appointment.completed', 'Appointment ' + a.id, 'checked_in to completed');
      if (st.on) {
        const r = range();
        const d = doc(a.doctorId);
        const f = { id: nid('f'), patientId: a.patientId, doctorId: a.doctorId, serviceId: st.svc, from: r.from, to: r.to, sameDoctor: st.same, note: st.note.trim(), sourceApptId: a.id, createdOn: today(), status: 'open', history: [{ when: D(today()) + ', ' + T(MB.now().min), what: 'Asked to come back by ' + d.short, kind: 'teal' }, { when: D(today()) + ', ' + T(MB.now().min), what: 'Request email sent', kind: 'blue' }] };
        S.followUps.push(f);
        log('follow_up.recommended', 'Follow-up ' + f.id, D(r.from) + ' to ' + D(r.to));
      }
      save(); closeDialog(); rerender(); toast(st.on ? 'Visit completed. Follow-up request sent.' : 'Visit completed');
    },

    // admin
    'toggle-user': (el) => { const u = user(el.dataset.id); u.active = !u.active; log(u.active ? 'user.reactivated' : 'user.deactivated', 'User ' + u.id, u.active ? 'inactive to active' : 'active to inactive'); save(); rerender(); toast(u.name + (u.active ? ' reactivated' : ' deactivated')); },
    'create-user': () => {
      dialog({ render: () => ({ title: 'Create user', sub: 'Staff, doctor and admin accounts. They get an email to set a password.', body: '<form class="stack" data-form="create-user" id="cu-form" novalidate><div class="field"><label for="cu-name">Full name</label><input class="input" id="cu-name" name="name"></div><div class="field"><label for="cu-email">Email</label><input class="input" id="cu-email" name="email" type="email"></div><div class="field"><label for="cu-role">Role</label><select class="select" id="cu-role" name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select><span class="hint">Doctor accounts also need hours and services. Not part of this mockup.</span></div><p class="error-text" id="cu-err" role="alert"></p></form>', foot: '<div class="right"><button class="btn btn-secondary" data-act="dlg-close">Cancel</button><button class="btn btn-primary" type="submit" form="cu-form">Create user</button></div>' }) });
    },
    'toggle-service': (el) => { const s = svc(el.dataset.id); s.active = !s.active; log('service.updated', 'Service ' + s.id, s.active ? 'bookable' : 'not bookable'); save(); rerender(); },
    'hour-add': (el) => { const H = ui.hoursDraft; const wd = el.dataset.wd; H[wd] = H[wd] || []; const last = H[wd][H[wd].length - 1]; const s = last ? Math.min(last[1] + 60, 1320) : 480; H[wd].push([s, Math.min(s + 240, 1380)]); ui.hoursErr = null; rerender(); },
    'hour-del': (el) => { const H = ui.hoursDraft; H[el.dataset.wd].splice(Number(el.dataset.i), 1); ui.hoursErr = null; rerender(); },
    'hours-discard': () => { ui.hoursDraft = null; ui.hoursErr = null; rerender(); },
    'hours-save': () => {
      const H = ui.hoursDraft;
      for (const wd of Object.keys(H)) {
        const rs = H[wd].slice().sort((a, b) => a[0] - b[0]);
        for (let i = 0; i < rs.length; i++) {
          if (rs[i][1] <= rs[i][0]) { ui.hoursErr = 'Each range must end after it starts.'; rerender(); return; }
          if (i && rs[i][0] < rs[i - 1][1]) { ui.hoursErr = 'Ranges on the same day can\'t overlap.'; rerender(); return; }
        }
        H[wd] = rs;
      }
      const docId = ui.hoursDoc;
      S.hours[docId] = JSON.parse(JSON.stringify(H));
      const outside = S.appts.filter((a) => a.doctorId === docId && a.status === 'booked' && isFuture(a) && !(S.hours[docId][MB.weekday(a.date)] || []).some(([x, y]) => a.start >= x && apptEnd(a) <= y));
      log('working_hours.updated', 'Doctor ' + docId, '');
      save(); ui.hoursDraft = null; rerender();
      toast(outside.length ? 'Hours saved. ' + outside.length + ' future booking' + (outside.length > 1 ? 's are' : ' is') + ' now outside them; staff can move ' + (outside.length > 1 ? 'them' : 'it') + '.' : 'Hours saved');
    },
    'timeoff-del': (el) => { S.timeOff = S.timeOff.filter((t) => t.id !== el.dataset.id); log('time_off.deleted', 'Time off ' + el.dataset.id, ''); save(); rerender(); toast('Time off removed'); },
    'toggle-2fa': () => { const u = me(); u.twoFA = !u.twoFA; save(); rerender(); toast('Two-factor sign-in turned ' + (u.twoFA ? 'on' : 'off')); }
  };

  const FORMS = {
    'quick': (f) => {
      const s = f.service.value, d = f.doctor.value, w = Number(f.when.value);
      const offers = d === 'any' || doc(d).services.includes(s);
      S.draft = { serviceId: s, doctorId: offers ? d : null, date: null, start: null, slotDoctorId: null, followUpId: null, stripStart: w ? MB.addDays(today(), w) : today() };
      save();
      if (!offers) toast(doc(d).name + ' doesn\'t offer that. Pick another doctor.', 'err');
      go(offers ? '/book/time' : '/book/doctor');
    },
    'login': (f) => {
      const email = f.email.value.trim().toLowerCase();
      const err = $('#login-err');
      if (!email || !f.password.value) { err.textContent = 'Enter your email and password.'; return; }
      const u = S.users.find((x) => x.email.toLowerCase() === email);
      if (!u) { err.textContent = 'These details don\'t match an account. Try a demo account below.'; return; }
      if (!u.active) { err.textContent = 'This account is disabled.'; return; }
      S.session = { userId: u.id, role: u.role };
      const back = S.returnTo; S.returnTo = null; save();
      go(back && canOpen(back, u.role) ? back : ROLE_HOME[u.role]);
    },
    'register': (f) => {
      const name = f.name.value.trim(), email = f.email.value.trim(), birth = f.birth.value, pass = f.password.value;
      const err = $('#reg-err');
      if (!name || !email || !birth || !pass) { err.textContent = 'Please fill in every field.'; return; }
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { err.textContent = 'Enter a valid email address.'; return; }
      if (S.users.some((u) => u.email.toLowerCase() === email.toLowerCase())) { err.textContent = 'An account with this email already exists. Log in instead.'; return; }
      const u = { id: nid('p'), name, email, role: 'patient', active: true, birthYear: Number(birth.slice(0, 4)), twoFA: false, created: today() };
      S.users.push(u);
      S.session = { userId: u.id, role: 'patient' };
      const back = S.returnTo; S.returnTo = null;
      log('user.registered', 'User ' + u.id, '');
      save(); go(back && canOpen(back, 'patient') ? back : '/me');
      toast('Account created. In the real app you\'d verify your email first.');
    },
    'intake': (f) => {
      const a = appt(f.dataset.id);
      const ans = { reason: f.reason.value.trim(), symptoms: f.symptoms.value.trim(), duration: f.duration.value, allergies: f.allergies.value.trim(), meds: f.meds.value.trim(), fever: (f.querySelector('[name=fever]:checked') || {}).value || '', pregnant: (f.querySelector('[name=pregnant]:checked') || {}).value || '', surgery: (f.querySelector('[name=surgery]:checked') || {}).value || '' };
      if (!ans.reason) { $('#err-reason').textContent = 'Tell us the reason for your visit.'; $('#i-reason').classList.add('invalid'); $('#i-reason').focus(); return; }
      S.intakes[a.id] = { answers: ans, submittedOn: today() };
      log('intake.saved', 'Appointment ' + a.id, '');
      if (MB.isEmergency(ans)) {
        a.emergency = true;
        S.summaries[a.id] = { status: 'skipped', reason: 'emergency' };
        save(); rerender(false);
        return;
      }
      a.emergency = false;
      S.summaries[a.id] = { status: 'pending' };
      save();
      setTimeout(() => {
        const sm = S.summaries[a.id];
        if (!sm || sm.status !== 'pending') return;
        S.summaries[a.id] = { status: 'ready', data: MB.makeSummary(ans), model: 'claude-haiku (demo)', reviewedBy: null, reviewedAt: null };
        save();
        if (parseHash().path === '/doctor/appt/' + a.id) rerender();
      }, 2500);
      go('/me/appt/' + a.id); toast('Intake saved');
    },
    'audit-filter': (f) => go('/admin/audit' + qs({ actor: f.actor.value, action: f.action.value, date: f.date.value })),
    'user-filter': (f) => go('/admin' + qs({ q: f.q.value.trim(), role: f.role.value })),
    'create-user': (f) => {
      const name = f.name.value.trim(), email = f.email.value.trim();
      if (!name || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { $('#cu-err').textContent = 'Add a name and a valid email.'; return; }
      if (S.users.some((u) => u.email.toLowerCase() === email.toLowerCase())) { $('#cu-err').textContent = 'That email is already used.'; return; }
      const u = { id: nid('u'), name, email, role: f.role.value, active: true, twoFA: false, created: today() };
      S.users.push(u); log('user.created', 'User ' + u.id, u.role); save(); closeDialog(); rerender(); toast(name + ' created. Set-password email sent.');
    },
    'timeoff': (f) => {
      const date = f.date.value, s = f.start.value, e = f.end.value;
      const toMin = (v) => Number(v.slice(0, 2)) * 60 + Number(v.slice(3, 5));
      if (!date || !s || !e || toMin(e) <= toMin(s)) { toast('Pick a date and an end time after the start.', 'err'); return; }
      const t = { id: nid('t'), doctorId: ui.hoursDoc, date, start: toMin(s), end: toMin(e), reason: f.reason.value.trim() || 'Time off' };
      S.timeOff.push(t);
      const hit = S.appts.filter((a) => a.doctorId === t.doctorId && a.date === date && a.status === 'booked' && a.start < t.end && apptEnd(a) > t.start).length;
      log('time_off.created', 'Doctor ' + t.doctorId, D(date) + ' ' + s + ' to ' + e);
      save(); rerender(); toast(hit ? 'Time off added. ' + hit + ' booking' + (hit > 1 ? 's' : '') + ' inside it; staff will see a warning.' : 'Time off added');
    }
  };

  const CHANGES = {
    'sched-date': (el) => { if (el.value) go('/staff' + qs({ date: el.value === today() ? null : el.value })); },
    'slot-doc': (el) => { const c = ui.slot; c.doctorChoice = el.value; c.start = null; c.slotDoc = null; c.err = null; c.pickFirst(); paintDialog(); refocus('#slot-doc'); },
    'fin-svc': (el) => { ui.finish.st.svc = el.value; paintDialog(); refocus('#fin-svc'); },
    'fin-from': (el) => { ui.finish.st.from = el.value; paintDialog(); refocus('#fin-to'); },
    'fin-to': (el) => { ui.finish.st.to = el.value; paintDialog(); refocus('[data-act="fin-submit"]'); },
    'fin-note': (el) => { ui.finish.st.note = el.value; },
    'hours-doc': (el) => { ui.hoursDraft = null; go('/admin/hours?doc=' + el.value); },
    'hour': (el) => {
      if (!el.value) return;
      const m = Number(el.value.slice(0, 2)) * 60 + Number(el.value.slice(3, 5));
      ui.hoursDraft[el.dataset.wd][Number(el.dataset.i)][Number(el.dataset.k)] = m;
      ui.hoursErr = null; rerender(); refocus('#' + el.id);
    }
  };

  /* ================= Wiring ================= */
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-act]');
    if (ui.menuOpen && !e.target.closest('#demo-switch')) { ui.menuOpen = false; renderDemoSwitch(); }
    if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') return;
    const fn = ACT[el.dataset.act];
    if (fn) { e.preventDefault(); fn(el, e); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { if (ui.dlg) closeDialog(); else if (ui.menuOpen) { ui.menuOpen = false; renderDemoSwitch(); refocus('#demo-switch > button'); } }
    if (e.key === 'Enter' && e.target.matches('tr[data-act]')) ACT[e.target.dataset.act](e.target, e);
    if (e.key === 'Tab' && ui.dlg) {
      const f = ui.dlg.el.querySelectorAll('button:not([disabled]), [href], input, select, textarea');
      if (!f.length) return;
      const firstEl = f[0], lastEl = f[f.length - 1];
      if (e.shiftKey && document.activeElement === firstEl) { e.preventDefault(); lastEl.focus(); }
      else if (!e.shiftKey && document.activeElement === lastEl) { e.preventDefault(); firstEl.focus(); }
    }
  });
  document.addEventListener('submit', (e) => {
    const f = e.target.closest('form[data-form]');
    if (!f) return;
    e.preventDefault();
    const fn = FORMS[f.dataset.form];
    if (fn) fn(f);
  });
  document.addEventListener('change', (e) => {
    const el = e.target.closest('[data-change]');
    if (el && CHANGES[el.dataset.change] && el.dataset.change !== 'fin-note') CHANGES[el.dataset.change](el);
  });
  document.addEventListener('input', (e) => {
    const el = e.target;
    if (el.dataset.count) { const c = el.parentElement.querySelector('.counter'); if (c) c.textContent = el.value.length.toLocaleString('en-US') + ' / ' + Number(el.dataset.count).toLocaleString('en-US'); }
    if (el.dataset.change === 'fin-note') CHANGES['fin-note'](el);
    if (el.classList.contains('invalid') && el.value) el.classList.remove('invalid');
    if (el.dataset.filter) {
      const term = el.value.trim().toLowerCase();
      let shown = 0;
      document.querySelectorAll(el.dataset.filter).forEach((c) => { const hit = !term || (c.dataset.search || '').includes(term); c.hidden = !hit; if (hit) shown++; });
      const none = $('#svc-none'); if (none) none.hidden = shown > 0;
    }
  });
  window.addEventListener('hashchange', () => { closeDialog(true); ui.menuOpen = false; render(); });

  S = load();
  save();
  render();
})(window.MB);
