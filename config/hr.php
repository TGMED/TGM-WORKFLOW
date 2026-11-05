<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    |
    | There is no sign-up: the people team adds somebody and the app emails
    | them a link to choose their own password. This is how many days that
    | link works for before it has to be sent again from the staff list.
    |
    */

    'invitation_days' => (int) env('HR_INVITATION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Probation
    |--------------------------------------------------------------------------
    |
    | How many months a new starter serves before confirmation is due. Read by
    | the admin console to work out who is coming up for confirmation and who
    | is already overdue; nothing is confirmed automatically, since that is a
    | decision somebody makes rather than a date passing.
    |
    */

    'probation_months' => (int) env('HR_PROBATION_MONTHS', 6),

    /*
    |--------------------------------------------------------------------------
    | Approval reminders
    |--------------------------------------------------------------------------
    |
    | Each working morning, approvers get one email listing the leave,
    | lateness and out of office requests waiting on them, and super admins
    | get the list of everything still pending. A request is only included
    | once it has waited `after_hours`, so something filed at 7am is not
    | chased an hour later. `at` is read in `timezone`.
    |
    */

    'approval_reminders' => [
        'enabled' => (bool) env('HR_APPROVAL_REMINDERS', true),
        'after_hours' => (int) env('HR_APPROVAL_REMINDER_AFTER_HOURS', 24),
        'at' => env('HR_APPROVAL_REMINDER_AT', '08:00'),
        'timezone' => env('HR_APPROVAL_REMINDER_TIMEZONE', 'Africa/Lagos'),
    ],

];
