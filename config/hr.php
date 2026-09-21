<?php

return [

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
