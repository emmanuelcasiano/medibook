/**
 * The fixed outcomes of a logged call. The value is what the form sends (the server checks it against its own list),
 * the label is what staff read. There is no free-text outcome on purpose: no health detail can be typed in.
 */
export const callOutcomes = [
    { value: 'no_answer', label: 'No answer' },
    { value: 'will_book_online', label: 'Will book online' },
    { value: 'booked_by_phone', label: 'Booked by phone' },
    { value: 'not_needed', label: "Doesn't need the visit" },
] as const;
