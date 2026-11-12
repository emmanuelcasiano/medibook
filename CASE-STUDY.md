# MediBook case study

MediBook is a personal build with fake data, not a real clinic system. It is a clinic booking app where an AI turns a patient's intake form into a draft summary for the doctor.

## The problem

Two people can want the same slot. Health data must reach the doctor and nobody else. And an AI summary can be wrong, so the app can't depend on it.

## Three decisions

**1. Three guards against a double booking.** A row lock makes bookings for one doctor run one at a time. An overlap check rejects any clash. A unique index on `(doctor, start time, active_key)` stops a duplicate even if the first two fail. Cancelled bookings get a `NULL` key, so they free the slot.

**2. Least privilege for health data.** Staff and admins run the clinic but cannot read intake, summaries or notes. A doctor reads only their own patients. The nurse reads and writes vitals, nothing else. The data is encrypted, and every view is written to an append-only audit log.

**3. AI with a non-AI safety net.** An emergency word list runs in plain PHP before any AI. A match shows the 911 message, flags staff and makes no AI call. The raw answers always show next to the draft, a daily token cap limits spend, and the doctor must review it.

## What was hard

My first summary restated the patient's answers. A model restating an allergy can only add risk, so I cut that and kept gaps and points to check. My checks had rewarded repeating answers, so the new version first scored 8/15. After I rewrote them it scored 12/15, below my 13/15 target. The misses are facts the model left out.

## Numbers

- 2,634 tests and 11,650 assertions, all passing, on MySQL.
- Intake eval: 12/15. All 3 injection cases, both diagnosis cases and all 3 emergency cases held.
- About 570 to 600 input and 80 to 290 output tokens per summary.
