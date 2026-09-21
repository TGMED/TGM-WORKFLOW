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

];
