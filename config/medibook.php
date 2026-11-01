<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booking Slots
    |--------------------------------------------------------------------------
    |
    | Rules for the slot finder (FindAvailableSlots). These are fixed business
    | rules, not secrets, so they live here and not in .env.
    |
    | slot_step_minutes:   start times are offered every N minutes, counted
    |                      from the start of each working range.
    | lead_time_hours:     a slot must start at least this long from now.
    | booking_window_days: slots are offered only up to this many days ahead.
    |
    */

    'slot_step_minutes' => 15,

    'lead_time_hours' => 2,

    'booking_window_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Emergency Words
    |--------------------------------------------------------------------------
    |
    | The word list for DetectEmergency (PROJECT.md F5). If the intake text has one
    | of these phrases, the patient sees the 911 message, staff see a red flag and
    | the AI summary is skipped. This is plain PHP and never depends on the model.
    |
    | Matching ignores letter case, apostrophes and punctuation, so "Can't breathe"
    | and "cant breathe" are the same phrase. A phrase must start at a word start,
    | so "seizures" matches "seizure" but "keystroke" does not match "stroke". The
    | list errs on the side of a false alarm: a wrong flag costs staff a glance,
    | a missed one could cost a life.
    |
    | Filipino and Taglish phrases are a first pass. Have a native speaker review them.
    |
    */

    'emergency_words' => [
        // English
        'chest pain',
        'heart attack',
        "can't breathe",
        'cannot breathe',
        'can not breathe',
        'not breathing',
        'difficulty breathing',
        'trouble breathing',
        'unconscious',
        'passed out',
        'severe bleeding',
        'bleeding heavily',
        'coughing up blood',
        'stroke',
        'seizure',
        'overdose',
        'suicide',
        'suicidal',
        'kill myself',
        'want to die',

        // Filipino / Taglish
        'hindi makahinga',
        'hirap huminga',
        'hirap sa paghinga',
        'sakit ng dibdib',
        'sakit sa dibdib',
        'masakit ang dibdib',
        'inatake sa puso',
        'nahimatay',
        'walang malay',
        'nawalan ng malay',
        'matinding pagdurugo',
        'dumudugo nang malala',
        'kinukumbulsyon',
        'kombulsyon',
        'magpakamatay',
        'magpapakamatay',
        'gusto kong mamatay',
    ],

];
