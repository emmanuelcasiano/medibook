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

    /*
    |--------------------------------------------------------------------------
    | AI Intake Summary
    |--------------------------------------------------------------------------
    |
    | Settings for the AI intake summary (PROJECT.md F6). The provider and model
    | are config, not code, so switching Gemini, OpenAI or Anthropic needs no code
    | change. The API key itself is read in config/ai.php (GEMINI_API_KEY,
    | OPENAI_API_KEY or ANTHROPIC_API_KEY).
    |
    | After a switch: php artisan config:clear, php artisan queue:restart (a running
    | worker keeps the old config), run the Action once against the real API, and
    | re-run the evals.
    |
    | enabled:         off by default, so an app without a key never makes an AI
    |                  call. When off, new summaries are "skipped".
    | provider:        a provider name from config/ai.php: gemini, openai, anthropic.
    | model:           the provider's model id. Use a small, cheap model. No default,
    |                  because model ids change. Set MEDIBOOK_AI_MODEL in .env.
    | daily_token_cap: input + output tokens per day. When the total is reached, new
    |                  summaries are "skipped" and the raw answers are still shown.
    |
    */

    'ai' => [
        'enabled' => (bool) env('MEDIBOOK_AI_ENABLED', false),

        'provider' => (string) env('MEDIBOOK_AI_PROVIDER', 'gemini'),

        'model' => env('MEDIBOOK_AI_MODEL'),

        'daily_token_cap' => (int) env('MEDIBOOK_AI_DAILY_TOKEN_CAP', 200_000),
    ],

];
