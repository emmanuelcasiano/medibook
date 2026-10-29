/* MediBook mockup: config, reference data, date helpers and demo seed.
   Everything here is fake. Times are clinic time (Asia/Manila). */
'use strict';
window.MB = window.MB || {};

(function (MB) {
  MB.VERSION = 3;
  MB.TZ = 'Asia/Manila';
  MB.CONFIG = { leadMin: 120, step: 15, windowDays: 30, maxUpcoming: 3, cancelCutoffMin: 120, dayStart: 480, dayEnd: 1020 };

  /* ---------- Date helpers (dates are 'YYYY-MM-DD', times are minutes from midnight) ---------- */
  MB.now = function () {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: MB.TZ, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
    }).formatToParts(new Date());
    const g = (t) => parts.find((p) => p.type === t).value;
    return { date: g('year') + '-' + g('month') + '-' + g('day'), min: Number(g('hour')) * 60 + Number(g('minute')) };
  };
  MB.addDays = function (ds, n) {
    const d = new Date(ds + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  };
  MB.weekday = (ds) => new Date(ds + 'T00:00:00Z').getUTCDay();
  MB.daysBetween = (a, b) => Math.round((Date.parse(b + 'T00:00:00Z') - Date.parse(a + 'T00:00:00Z')) / 864e5);
  MB.fmtTime = (m) => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
  const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const DOW_LONG = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const MON_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  MB.dayParts = function (ds) {
    const d = new Date(ds + 'T00:00:00Z');
    return { dow: DOW[d.getUTCDay()], dowLong: DOW_LONG[d.getUTCDay()], d: d.getUTCDate(), mon: MON[d.getUTCMonth()], monLong: MON_LONG[d.getUTCMonth()], y: d.getUTCFullYear() };
  };
  MB.fmtDate = function (ds, style) {
    const p = MB.dayParts(ds);
    if (style === 'long') return p.dowLong + ', ' + p.d + ' ' + p.monLong;
    if (style === 'full') return p.dow + ', ' + p.d + ' ' + p.mon + ' ' + p.y;
    if (style === 'dm') return p.d + ' ' + p.mon;
    return p.dow + ' ' + p.d + ' ' + p.mon;
  };
  MB.relDay = function (ds) {
    const t = MB.now().date;
    const n = MB.daysBetween(t, ds);
    if (n === 0) return 'Today';
    if (n === 1) return 'Tomorrow';
    if (n === -1) return 'Yesterday';
    return MB.fmtDate(ds);
  };

  /* ---------- Reference data ---------- */
  MB.SERVICES = [
    { id: 'gen', name: 'General consultation', desc: "Coughs, fevers, aches and anything you're unsure about.", dur: 30, buf: 5, icon: 'steth', tone: 'teal', active: true },
    { id: 'fu', name: 'Follow-up visit', desc: "Check how you're doing after a recent visit or new medicine.", dur: 15, buf: 5, icon: 'repeat', tone: 'teal', active: true },
    { id: 'phys', name: 'Annual physical', desc: 'A yearly check of your overall health, including for work clearance.', dur: 45, buf: 10, icon: 'clipcheck', tone: 'teal', active: true },
    { id: 'child', name: 'Child wellness', desc: 'Growth checks and advice for babies, kids and teens.', dur: 30, buf: 5, icon: 'baby', tone: 'amber', active: true },
    { id: 'vax', name: 'Vaccination', desc: 'Flu shots and routine vaccines for adults and children.', dur: 15, buf: 5, icon: 'syringe', tone: 'amber', active: true },
    { id: 'skin', name: 'Skin consultation', desc: 'Rashes, acne, moles and other skin concerns.', dur: 30, buf: 5, icon: 'moon', tone: 'amber', active: true }
  ];

  const MF = [[480, 720], [780, 1020]];
  MB.DOCTORS = [
    { id: 'lim', userId: 'u-lim', name: 'Dr. Carmela Lim', short: 'Dr. Lim', initials: 'CL', spec: 'Family Medicine', tone: 'teal',
      bio: 'Cares for adults and children. Speaks English, Filipino and Cebuano.', services: ['gen', 'fu', 'phys', 'vax'],
      hours: { 1: MF, 2: MF, 3: MF, 4: MF, 5: MF, 6: [[480, 720]] } },
    { id: 'tan', userId: 'u-tan', name: 'Dr. Rafael Tan', short: 'Dr. Tan', initials: 'RT', spec: 'Internal Medicine', tone: 'blue',
      bio: 'Adults only. Long-term conditions like blood pressure and diabetes.', services: ['gen', 'fu', 'phys', 'vax'],
      hours: { 1: MF, 2: MF, 3: MF, 4: MF, 5: MF } },
    { id: 'nav', userId: 'u-nav', name: 'Dr. Bea Navarro', short: 'Dr. Navarro', initials: 'BN', spec: 'Pediatrics', tone: 'amber',
      bio: 'Babies, kids and teens up to 18.', services: ['child', 'vax', 'fu'],
      hours: { 1: MF, 3: MF, 5: MF, 6: [[480, 720]] } },
    { id: 'dc', userId: 'u-dc', name: 'Dr. Miguel Dela Cruz', short: 'Dr. Dela Cruz', initials: 'MD', spec: 'Dermatology', tone: 'violet',
      bio: 'Skin, hair and nail concerns for all ages.', services: ['skin', 'fu'],
      hours: { 1: MF, 2: MF, 4: MF, 5: MF, 6: [[480, 720]] } }
  ];

  MB.EMERGENCY_WORDS = [
    'chest pain', "can't breathe", 'cannot breathe', 'difficulty breathing', 'trouble breathing', 'short of breath', 'unconscious',
    'severe bleeding', 'heavy bleeding', 'stroke', 'suicide', 'overdose', 'seizure',
    'masakit ang dibdib', 'hirap huminga', 'hindi makahinga', 'nahimatay', 'nag-seizure'
  ];

  MB.DURATIONS = ['Less than 1 day', '1 to 3 days', '4 to 7 days', '1 to 4 weeks', 'More than a month'];

  const PATIENTS = [
    ['p1', 'Ana Ramos', 1994], ['p2', 'Paolo Garcia', 1991], ['p3', 'Liza Mendoza', 1971], ['p4', 'Maria Santos', 1982],
    ['p5', 'Jose Reyes', 1960], ['p6', 'Andrea Cruz', 1999], ['p7', 'Ramon Aquino', 1950], ['p8', 'Grace Ocampo', 1989],
    ['p9', 'Mark Villanueva', 1986], ['p10', 'Kristine Bautista', 1993], ['p11', 'Dennis Flores', 1978], ['p12', 'Rosa Villareal', 1968],
    ['p13', 'Carlo Mercado', 1984], ['p14', 'Joy Castillo', 1997], ['p15', 'Lucas Santos', 2019], ['p16', 'Mia Reyes', 2021],
    ['p17', 'Ethan Cruz', 2016], ['p18', 'Sofia Garcia', 2012], ['p19', 'Hannah Lim', 2001], ['p20', 'Daniel Torres', 1975],
    ['p21', 'Bianca Ramos', 1996], ['p22', 'Paolo Diaz', 1980], ['p23', 'Teresa Gomez', 1958], ['p24', 'Miguel Ramos', 2009]
  ];
  const KIDS = ['p15', 'p16', 'p17', 'p18', 'p24'];

  const INTAKES = [
    { reason: 'Cough and sore throat for about 5 days, worse at night.', symptoms: 'Dry cough, sore throat, mild headache. Feel tired in the afternoon. Took paracetamol twice.', duration: '4 to 7 days', allergies: 'Penicillin (rash)', meds: 'Losartan 50 mg, once a day', fever: 'yes', pregnant: 'no', surgery: 'no' },
    { reason: 'Back pain after lifting boxes at work.', symptoms: 'Lower back pain on the right side. Worse when bending. No numbness in the legs.', duration: '1 to 3 days', allergies: '', meds: 'Ibuprofen when needed', fever: 'no', pregnant: 'no', surgery: 'no' },
    { reason: 'Need a medical certificate for a new job.', symptoms: 'No symptoms. Feeling well.', duration: '', allergies: 'Shrimp', meds: '', fever: 'no', pregnant: 'no', surgery: 'no' },
    { reason: 'Itchy rash on both arms.', symptoms: 'Red, itchy patches on both forearms. Started after using a new soap. Getting a little worse.', duration: '4 to 7 days', allergies: '', meds: 'Cetirizine at night', fever: 'no', pregnant: 'no', surgery: 'no' },
    { reason: 'Follow-up on blood pressure readings.', symptoms: 'Home readings around 140 over 90 in the mornings. Mild headaches sometimes.', duration: '1 to 4 weeks', allergies: 'Aspirin', meds: 'Amlodipine 5 mg daily', fever: 'no', pregnant: 'no', surgery: 'no' },
    { reason: 'Child has a runny nose and low appetite.', symptoms: 'Runny nose, sneezing, eating less than usual. Still playful.', duration: '1 to 3 days', allergies: '', meds: '', fever: 'yes', pregnant: '', surgery: 'no' },
    { reason: 'Stomach ache and loose stools.', symptoms: 'Cramping after meals, three loose stools a day. Drinking water and oral rehydration salts.', duration: '1 to 3 days', allergies: '', meds: '', fever: 'no', pregnant: 'no', surgery: 'no' },
    { reason: 'Flu shot before travel.', symptoms: 'No symptoms.', duration: '', allergies: 'Eggs (mild)', meds: '', fever: 'no', pregnant: 'no', surgery: 'no' }
  ];
  MB.INTAKE_SAMPLES = INTAKES;

  /* ---------- Small deterministic random ---------- */
  function rng(seed) {
    return function () {
      seed |= 0; seed = (seed + 0x6D2B79F5) | 0;
      let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  MB.makeSummary = function (a) {
    const pts = String(a.symptoms || '').split(/[.;]\s*/).map((s) => s.trim()).filter(Boolean).slice(0, 4);
    if (a.fever === 'yes') pts.push('Reports fever');
    if (a.pregnant === 'yes') pts.push('Reports being pregnant');
    if (a.surgery === 'yes') pts.push('Had surgery in the last 3 months');
    if (a.duration) pts.push('Duration: ' + a.duration.toLowerCase());
    const am = [];
    am.push(a.allergies ? 'Allergies: ' + a.allergies + '.' : 'Allergies: not given.');
    am.push(a.meds ? 'Medicines: ' + a.meds + '.' : 'Medicines: not given.');
    const qs = [];
    if (a.fever === 'yes') qs.push('Fever reported, but no temperature reading given.');
    if (a.meds && !/\d/.test(a.meds)) qs.push('Medicine dose not given.');
    if (!a.symptoms) qs.push('No symptoms described.');
    if (!qs.length) qs.push('Nothing unclear in the answers.');
    return { chief: a.reason || 'Not given', points: pts.length ? pts : ['Not given'], am: am.join(' '), questions: qs };
  };

  MB.isEmergency = function (answers) {
    const text = [answers.reason, answers.symptoms].join(' ').toLowerCase().replace(/[‘’]/g, "'");
    return MB.EMERGENCY_WORDS.some((w) => text.includes(w));
  };

  /* ---------- Seed ---------- */
  MB.seed = function () {
    const now = MB.now();
    const today = now.date;
    const R = rng(20261029);
    const pick = (arr) => arr[Math.floor(R() * arr.length)];
    let n = 1;
    const nid = (p) => p + (n++).toString(36);

    const S = {
      v: MB.VERSION, seedDate: today, users: [], appts: [], intakes: {}, summaries: {}, notes: {}, followUps: [],
      audit: [], timeOff: [], hours: {}, services: JSON.parse(JSON.stringify(MB.SERVICES)), session: null, draft: null, returnTo: null
    };

    PATIENTS.forEach(([id, name, by]) => S.users.push({ id, name, email: name.toLowerCase().replace(/[^a-z]+/g, '.') + '@example.com', role: 'patient', active: true, birthYear: by, twoFA: false, created: MB.addDays(today, -60 - by % 50) }));
    S.users.push({ id: 's1', name: 'Joanna Dizon', email: 'joanna.dizon@medibook.test', role: 'staff', active: true, twoFA: true, created: MB.addDays(today, -200) });
    S.users.push({ id: 's2', name: 'Arnel Bautista', email: 'arnel.bautista@medibook.test', role: 'staff', active: false, twoFA: true, created: MB.addDays(today, -320) });
    MB.DOCTORS.forEach((d) => S.users.push({ id: d.userId, name: d.name, email: d.short.replace('Dr. ', '').toLowerCase().replace(/[^a-z]+/g, '') + '@medibook.test', role: 'doctor', active: true, twoFA: true, created: MB.addDays(today, -400) }));
    S.users.push({ id: 'ad1', name: 'Elena Castro', email: 'elena.castro@medibook.test', role: 'admin', active: true, twoFA: true, created: MB.addDays(today, -500) });
    MB.DOCTORS.forEach((d) => { S.hours[d.id] = JSON.parse(JSON.stringify(d.hours)); });

    const svc = (id) => S.services.find((s) => s.id === id);
    const docById = (id) => MB.DOCTORS.find((d) => d.id === id);
    const works = (docId, date) => (S.hours[docId][MB.weekday(date)] || []).length > 0;
    const nextWorking = (docId, from) => { let d = from; for (let i = 0; i < 14 && !works(docId, d); i++) d = MB.addDays(d, 1); return d; };
    const prevWorking = (docId, from) => { let d = from; for (let i = 0; i < 14 && !works(docId, d); i++) d = MB.addDays(d, -1); return d; };

    function mk(doctorId, serviceId, date, start, patientId, extra) {
      const a = Object.assign({ id: nid('a'), doctorId, serviceId, date, start, patientId, status: null, emergency: false, bookedOn: MB.addDays(date, -1 - Math.floor(R() * 6)) }, extra || {});
      S.appts.push(a);
      return a;
    }
    function clearRange(doctorId, date, start, end) {
      S.appts = S.appts.filter((a) => {
        if (a.doctorId !== doctorId || a.date !== date) return true;
        const s = svc(a.serviceId);
        return a.start + s.dur + s.buf <= start || a.start >= end;
      });
    }

    const adults = PATIENTS.map((p) => p[0]).filter((id) => id !== 'p1' && !KIDS.includes(id));

    // Generic schedule
    MB.DOCTORS.forEach((d) => {
      for (let off = -10; off <= 14; off++) {
        const date = MB.addDays(today, off);
        if (d.id === 'lim' && off === 0) continue;
        const ranges = S.hours[d.id][MB.weekday(date)] || [];
        const p = off <= 2 ? 0.5 : Math.max(0.18, 0.5 - off * 0.03);
        ranges.forEach(([a, b]) => {
          let t = a;
          while (t < b) {
            if (R() < p) {
              const s = svc(pick(d.services));
              const end = t + s.dur + s.buf;
              if (end <= b) {
                const pool = (d.id === 'nav' || s.id === 'child') ? KIDS : adults;
                mk(d.id, s.id, date, t, pick(pool));
                t = Math.ceil(end / 15) * 15;
                continue;
              }
            }
            t += 15 * (1 + Math.floor(R() * 2));
          }
        });
      }
    });

    // Dr. Lim today: a hand-made day so the doctor view has every state
    const specials = [];
    if (works('lim', today)) {
      [[480, 'p4', 'gen', 1], [525, 'p5', 'fu', 4], [570, 'p6', 'phys', 2], [630, 'p2', 'gen', 0], [675, 'p3', 'gen', -1], [780, 'p7', 'fu', null], [840, 'p8', 'phys', 2], [915, 'p13', 'gen', 6]]
        .filter(([t]) => (S.hours.lim[MB.weekday(today)] || []).some(([a, b]) => t >= a && t + 30 <= b))
        .forEach(([t, pid, sid, intakeIdx]) => { const a = mk('lim', sid, today, t, pid); a._intake = intakeIdx; specials.push(a); });
    }

    // Ana Ramos (the demo patient)
    const pastDate = prevWorking('lim', MB.addDays(today, -8));
    clearRange('lim', pastDate, 630, 665);
    const anaPast = mk('lim', 'gen', pastDate, 630, 'p1', { status: 'completed', bookedOn: MB.addDays(pastDate, -3) });
    anaPast._intake = 0;
    const up1Date = nextWorking('tan', MB.addDays(today, 1));
    clearRange('tan', up1Date, 555, 590);
    mk('tan', 'gen', up1Date, 555, 'p1', { status: 'booked', bookedOn: today });
    const up2Date = nextWorking('tan', MB.addDays(today, 5));
    clearRange('tan', up2Date, 540, 560);
    const up2 = mk('tan', 'vax', up2Date, 540, 'p1', { status: 'booked', bookedOn: MB.addDays(today, -2) });
    up2._intake = 7;
    const skinDate = prevWorking('dc', MB.addDays(today, -21));
    const anaSkin = mk('dc', 'skin', skinDate, 600, 'p1', { status: 'completed', bookedOn: MB.addDays(skinDate, -4) });
    anaSkin._intake = 3;

    // Statuses, intakes and summaries
    S.appts.forEach((a) => {
      const s = svc(a.serviceId);
      const end = a.start + s.dur;
      const isPast = a.date < today || (a.date === today && end <= now.min);
      const inProgress = a.date === today && a.start <= now.min + 15 && now.min < end;
      if (!a.status) {
        const r = R();
        if (isPast) a.status = r < 0.86 ? 'completed' : r < 0.95 ? 'no_show' : 'cancelled';
        else if (inProgress) a.status = 'checked_in';
        else a.status = r < 0.05 ? 'cancelled' : 'booked';
        if (a.status === 'cancelled') { a.cancelReason = r < 0.97 ? 'Cancelled by patient' : 'Doctor unavailable'; a.cancelledBy = 'patient'; }
      }
      if (a.status === 'checked_in') a.checkedInAt = Math.max(a.start - 10, 480);
      if (a.status === 'completed') a.checkedInAt = a.start - 5;

      let idx = a._intake;
      if (idx === undefined) idx = (a.status !== 'cancelled' && R() < 0.8) ? Math.floor(R() * INTAKES.length) : null;
      if (a.serviceId === 'child' && idx !== null && idx !== undefined && a._intake === undefined) idx = 5;
      if (a.serviceId === 'skin' && idx !== null && idx !== undefined && a._intake === undefined) idx = 3;
      if (a.serviceId === 'vax' && idx !== null && idx !== undefined && a._intake === undefined) idx = 7;
      if (idx === -1) {
        const ans = { reason: 'Sudden chest pain since this morning.', symptoms: 'Chest pain that spreads to my left arm. A little short of breath when walking.', duration: 'Less than 1 day', allergies: '', meds: 'Metformin 500 mg twice a day', fever: 'no', pregnant: 'no', surgery: 'no' };
        S.intakes[a.id] = { answers: ans, submittedOn: MB.addDays(a.date, -1) };
        a.emergency = true;
        S.summaries[a.id] = { status: 'skipped', reason: 'emergency' };
      } else if (idx !== null && idx !== undefined) {
        const ans = Object.assign({}, INTAKES[idx]);
        S.intakes[a.id] = { answers: ans, submittedOn: MB.addDays(a.date, -1) };
        const reviewed = a.status === 'completed';
        S.summaries[a.id] = { status: 'ready', data: MB.makeSummary(ans), model: 'claude-haiku (demo)', reviewedBy: reviewed ? docById(a.doctorId).name : null, reviewedAt: reviewed ? MB.fmtTime(a.start + 2) : null };
      }
      delete a._intake;
    });

    // Notes for completed visits of Ana
    S.notes[anaPast.id] = { text: 'Temp 37.9 C at check-in. Throat red, no exudate. Lungs clear.', savedAt: MB.fmtTime(anaPast.start + 11) };

    // Follow-ups
    const fu = (o) => { const f = Object.assign({ id: nid('f'), status: 'open', history: [] }, o); S.followUps.push(f); return f; };
    const anaFrom = nextWorking('lim', MB.addDays(today, 9));
    fu({ patientId: 'p1', doctorId: 'lim', serviceId: 'fu', from: anaFrom, to: MB.addDays(anaFrom, 4), sameDoctor: true,
      note: "Let's check how your cough and throat are doing.", sourceApptId: anaPast.id, createdOn: pastDate,
      history: [{ when: MB.fmtDate(pastDate) + ', ' + MB.fmtTime(anaPast.start + 22), what: 'Asked to come back by Dr. Lim', kind: 'teal' }, { when: MB.fmtDate(pastDate) + ', ' + MB.fmtTime(anaPast.start + 22), what: 'Request email sent', kind: 'blue' }] });
    fu({ patientId: 'p10', doctorId: 'tan', serviceId: 'fu', from: MB.addDays(today, -10), to: MB.addDays(today, -6), sameDoctor: true, note: 'Recheck blood pressure.', createdOn: MB.addDays(today, -24),
      history: [{ when: MB.fmtDate(MB.addDays(today, -24)), what: 'Asked to come back by Dr. Tan', kind: 'teal' }, { when: MB.fmtDate(MB.addDays(today, -10)) + ', 08:00', what: 'Reminder email sent', kind: 'blue' }] });
    fu({ patientId: 'p5', doctorId: 'lim', serviceId: 'fu', from: MB.addDays(today, -3), to: MB.addDays(today, 1), sameDoctor: true, note: 'Bring your home sugar readings.', createdOn: MB.addDays(today, -17),
      history: [{ when: MB.fmtDate(MB.addDays(today, -17)), what: 'Asked to come back by Dr. Lim', kind: 'teal' }] });
    fu({ patientId: 'p17', doctorId: 'nav', serviceId: 'child', from: nextWorking('nav', MB.addDays(today, 18)), to: MB.addDays(today, 29), sameDoctor: true, note: 'Growth check.', createdOn: MB.addDays(today, -2),
      history: [{ when: MB.fmtDate(MB.addDays(today, -2)), what: 'Asked to come back by Dr. Navarro', kind: 'teal' }] });
    const lizaDate = nextWorking('lim', MB.addDays(today, 5));
    clearRange('lim', lizaDate, 600, 635);
    const lizaAppt = mk('lim', 'gen', lizaDate, 600, 'p3', { status: 'booked', bookedOn: MB.addDays(today, -1) });
    fu({ patientId: 'p3', doctorId: 'lim', serviceId: 'gen', from: MB.addDays(today, 3), to: MB.addDays(today, 7), sameDoctor: true, note: 'Check results together.', createdOn: MB.addDays(today, -6), status: 'booked', bookedApptId: lizaAppt.id,
      history: [{ when: MB.fmtDate(MB.addDays(today, -6)), what: 'Asked to come back by Dr. Lim', kind: 'teal' }, { when: MB.fmtDate(MB.addDays(today, -1)), what: 'Booked by patient', kind: 'blue' }] });
    fu({ patientId: 'p9', doctorId: 'tan', serviceId: 'fu', from: MB.addDays(today, -17), to: MB.addDays(today, -13), sameDoctor: true, note: '', createdOn: MB.addDays(today, -30), status: 'dismissed',
      history: [{ when: MB.fmtDate(MB.addDays(today, -30)), what: 'Asked to come back by Dr. Tan', kind: 'teal' }, { when: MB.fmtDate(MB.addDays(today, -20)), what: 'Patient said not needed', kind: 'hollow' }] });

    // Time off
    const navOff = nextWorking('nav', MB.addDays(today, 2));
    S.timeOff.push({ id: nid('t'), doctorId: 'nav', date: navOff, start: 780, end: 1020, reason: 'Medical conference' });
    S.timeOff.push({ id: nid('t'), doctorId: 'tan', date: nextWorking('tan', MB.addDays(today, 12)), start: 480, end: 1020, reason: 'Leave' });

    // Audit log seed (most recent first)
    const name = (uid) => (S.users.find((u) => u.id === uid) || {}).name;
    const docUser = (docId) => docById(docId).userId;
    const todays = S.appts.filter((a) => a.date === today).sort((x, y) => y.start - x.start);
    let order = 0;
    todays.forEach((a) => {
      if (a.status === 'completed' || a.status === 'checked_in') {
        if (S.intakes[a.id] && a.status === 'completed') S.audit.push({ id: nid('l'), date: today, time: MB.fmtTime(Math.min(a.start + 3, now.min)), actor: docUser(a.doctorId), action: 'intake.viewed', subject: 'Appointment ' + a.id, meta: '', o: order++ });
        S.audit.push({ id: nid('l'), date: today, time: MB.fmtTime(a.checkedInAt), actor: 's1', action: 'appointment.checked_in', subject: 'Appointment ' + a.id, meta: 'booked to checked_in', o: order++ });
      }
      if (a.status === 'no_show') S.audit.push({ id: nid('l'), date: today, time: MB.fmtTime(a.start + 20), actor: 's1', action: 'appointment.no_show', subject: 'Appointment ' + a.id, meta: 'booked to no_show', o: order++ });
    });
    S.audit.push({ id: nid('l'), date: today, time: '07:52', actor: 'ad1', action: 'time_off.created', subject: 'Doctor ' + 'nav', meta: MB.fmtDate(navOff) + ' 13:00 to 17:00', o: order++ });
    S.appts.filter((a) => a.bookedOn && a.bookedOn >= MB.addDays(today, -2) && a.status !== 'cancelled').slice(0, 14).forEach((a) => {
      S.audit.push({ id: nid('l'), date: a.bookedOn > today ? today : a.bookedOn, time: MB.fmtTime(480 + Math.floor(R() * 600)), actor: a.patientId, action: 'appointment.booked', subject: 'Appointment ' + a.id, meta: 'to booked', o: order++ });
    });
    S.audit.push({ id: nid('l'), date: MB.addDays(today, -3), time: '16:40', actor: 'ad1', action: 'user.deactivated', subject: 'User s2', meta: 'active to inactive', o: order++ });
    S.audit.sort((x, y) => (y.date + y.time).localeCompare(x.date + x.time));
    S.audit.forEach((e) => { e.actorName = name(e.actor); delete e.o; });

    S.nextId = n;
    return S;
  };
})(window.MB);
