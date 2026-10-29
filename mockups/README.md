# MediBook mockup

A clickable mockup of the MediBook clinic booking app, in plain HTML, CSS and JavaScript.
No build step, no server, no packages. All patients, doctors and appointments are made up.

## Open it

Double-click `index.html`, or open it in any modern browser (Chrome, Edge, Firefox, Safari).

If your browser blocks local files from saving data, serve the folder instead:

```
python -m http.server 8000
```

then open http://localhost:8000.

## Try it

Use the **Demo** button in the bottom-right corner to switch role at any time.

| Role | Logs in as | Try this |
| --- | --- | --- |
| Patient | Ana Ramos | Book a visit, fill in the intake form, type "chest pain" to see the emergency state, cancel, book the follow-up Dr. Lim asked for |
| Staff | Joanna Dizon | Day schedule: check in, mark no-show, cancel with a reason, move a booking. Follow-ups: book for a patient, log a call |
| Doctor | Dr. Carmela Lim | Today's list, open an appointment, mark the AI draft reviewed, save notes, finish a visit and ask the patient to come back |
| Admin | Elena Castro | Users, services, working hours and time off, audit log with filters |

The rules from the spec are live: 15-minute slots, 2-hour lead time, 30-day window, buffers after each visit,
lunch breaks and time off, at most 3 upcoming bookings per patient, no overlapping bookings, cancel up to
2 hours before, and "This time is no longer free" when a slot is gone.

## How it works

| File | What it holds |
| --- | --- |
| `index.html` | The page shell. Loads the fonts, the styles and the two scripts. |
| `css/styles.css` | All styles. Colours and sizes are CSS variables at the top. |
| `js/data.js` | Settings, services, doctors, working hours, date helpers and the demo data generator. |
| `js/app.js` | State, booking rules, the router (`#/path` links) and every screen. |
| `assets/` | Drop the landing page photos here (see below). |

- **Data** is generated fresh each day around today's date in Manila time, so the schedule always looks current.
  Changes you make are kept in your browser's local storage. Use **Demo > Reset demo data** to start over.
- **Time** is clinic time (Asia/Manila), whatever your computer's time zone is.
- **The AI summary** is simulated: saving an intake makes a draft summary after about 2.5 seconds, built only
  from the answers. Emergency words skip the summary, as in the spec.
- **Nothing is sent anywhere.** The only network request is Google Fonts; without internet the app falls back to system fonts.

## Landing page photos

The landing page has four photo slots. Save the photos into `assets/` with these names and they appear automatically:

| File name | Slot | Suggested photo |
| --- | --- | --- |
| `photo-hero.jpg` | Hero, tall portrait | Smiling female doctor in Davao, Justyn Abacajan (Pexels) |
| `photo-phone.jpg` | How it works, 4:5 | Young woman with smartphone in a cafe, Ketut Subiyanto (Pexels) |
| `photo-consult.jpg` | Privacy section, 5:4 | Doctor consults with patient, Vitaly Gariev (Unsplash) |
| `photo-family.jpg` | Final call to action | Mother and child walking in the Philippines, Grace (Pexels) |

## Not included

Email sending, real logins and two-factor codes, password reset, and the AI usage page are shown as notes or
left out. This is a front-end mockup only; the real app is planned in Laravel with React.
