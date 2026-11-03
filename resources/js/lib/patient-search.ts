import type { ScheduleAppointment, ScheduleDoctor } from '@/types';

/** One booking of the day and the doctor's column it sits in. */
export type PatientMatch = {
    appointment: ScheduleAppointment;
    doctor: ScheduleDoctor;
};

/** A piece of a patient's name, and whether it is the part the person typed. */
export type NameSegment = { text: string; match: boolean };

const COMBINING_MARKS = /\p{M}/gu;

/** Lower case with the accents taken off, so "José" and "jose" are the same word. */
export function fold(text: string): string {
    return text.normalize('NFD').replace(COMBINING_MARKS, '').toLowerCase();
}

/** What was typed, as folded words. An empty list means nothing was typed. */
export function searchTerms(query: string): string[] {
    return fold(query).split(/\s+/).filter(Boolean);
}

/**
 * The patient's name folded, and where each folded character came from in the original. Folding can change the
 * length (a lone accent mark is dropped, and a few letters fold to two), so the bold part is found in the folded
 * name and then mapped back to the name as it is written.
 */
function foldWithOrigin(name: string): { folded: string; origin: number[] } {
    let folded = '';
    const origin: number[] = [];
    let index = 0;

    for (const character of name) {
        const piece = fold(character);

        for (let unit = 0; unit < piece.length; unit++) {
            origin.push(index);
        }

        folded += piece;
        index += character.length;
    }

    // One more entry, so the end of a match at the very end of the name can be mapped too.
    origin.push(index);

    return { folded, origin };
}

/** A booking matches when every word that was typed is found somewhere in the patient's name. */
export function matchesPatient(name: string, terms: string[]): boolean {
    if (terms.length === 0) {
        return false;
    }

    const folded = fold(name);

    return terms.every((term) => folded.includes(term));
}

/**
 * The bookings of the day whose patient matches, earliest first. Only the patient's name is read: staff don't see
 * anything else about a patient to search on.
 */
export function findPatients(
    doctors: ScheduleDoctor[],
    terms: string[],
): PatientMatch[] {
    if (terms.length === 0) {
        return [];
    }

    return doctors
        .flatMap((doctor) =>
            doctor.appointments.map((appointment) => ({ appointment, doctor })),
        )
        .filter(({ appointment }) =>
            matchesPatient(appointment.patientName, terms),
        )
        .sort(
            (a, b) =>
                Date.parse(a.appointment.startsAt) -
                Date.parse(b.appointment.startsAt),
        );
}

/** The name in pieces, so the part that matched can be drawn in bold. The pieces joined give the name back. */
export function highlightName(name: string, terms: string[]): NameSegment[] {
    const { folded, origin } = foldWithOrigin(name);

    // Each word's places in the folded name, as [start, end) pairs.
    const ranges: Array<[number, number]> = [];

    for (const term of terms) {
        let from = folded.indexOf(term);

        while (from !== -1) {
            ranges.push([from, from + term.length]);
            from = folded.indexOf(term, from + term.length);
        }
    }

    if (ranges.length === 0) {
        return [{ text: name, match: false }];
    }

    // Overlapping and touching ranges become one bold piece.
    ranges.sort((a, b) => a[0] - b[0]);

    const merged: Array<[number, number]> = [];

    for (const [start, end] of ranges) {
        const last = merged[merged.length - 1];

        if (last && start <= last[1]) {
            last[1] = Math.max(last[1], end);
        } else {
            merged.push([start, end]);
        }
    }

    const segments: NameSegment[] = [];
    let cursor = 0;

    for (const [start, end] of merged) {
        const from = origin[start];
        const to = origin[end];

        if (from > cursor) {
            segments.push({ text: name.slice(cursor, from), match: false });
        }

        segments.push({ text: name.slice(from, to), match: true });
        cursor = to;
    }

    if (cursor < name.length) {
        segments.push({ text: name.slice(cursor), match: false });
    }

    return segments;
}
