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

];
