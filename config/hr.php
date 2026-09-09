<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Self-registration
    |--------------------------------------------------------------------------
    |
    | Whether people who sign up themselves can use the app straight away.
    | Set this to false to create them deactivated instead, so an administrator
    | has to approve each account on the staff page before it can clock in.
    |
    */

    'activate_signups_immediately' => env('HR_ACTIVATE_SIGNUPS', true),

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
