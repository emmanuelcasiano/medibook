<?php

declare(strict_types=1);

namespace App\Actions\Intake;

final class DetectEmergency
{
    /**
     * Whether any of the intake texts has an emergency phrase from `medibook.emergency_words`.
     *
     * This runs before any AI call and never depends on the model. It returns a bool and not the matched words,
     * because the words are health data and must not end up in a log or an exception.
     *
     * Each text is checked on its own, so "chest" at the end of one field and "pain" at the start of the next do not
     * join into a false match.
     *
     * @param  array<array-key, string|null>  $texts  The free-text answers (reason, symptoms, allergies, medicines).
     */
    public function handle(array $texts): bool
    {
        $phrases = $this->phrases();

        if ($phrases === []) {
            return false;
        }

        foreach ($texts as $text) {
            if ($text === null) {
                continue;
            }

            // The leading space makes a phrase start at a word start: "seizures" matches, "keystroke" does not.
            $haystack = ' '.$this->normalize($text);

            foreach ($phrases as $phrase) {
                if (str_contains($haystack, ' '.$phrase)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The configured phrases in the same form as the text. A blank entry is dropped: it would match every text.
     *
     * @return list<string>
     */
    private function phrases(): array
    {
        /** @var list<string> $words */
        $words = config('medibook.emergency_words', []);

        return array_values(array_filter(
            array_map($this->normalize(...), $words),
            fn (string $phrase): bool => $phrase !== '',
        ));
    }

    /**
     * Lower case, no apostrophes ("can't", "can’t" and "cant" are the same), punctuation and runs of whitespace
     * become one space.
     */
    private function normalize(string $text): string
    {
        // Invalid UTF-8 would make preg_replace() return null and hide a real emergency, so replace bad bytes first.
        $text = mb_strtolower(mb_scrub($text));
        $text = str_replace(["'", '’', '‘', 'ʼ', '`'], '', $text);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }
}
