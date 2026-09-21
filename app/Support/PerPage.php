<?php

namespace App\Support;

use Illuminate\Http\Request;

final class PerPage
{
    /** The page sizes a list offers. Anything else in the query is ignored. */
    public const OPTIONS = [10, 25, 50, 100];

    /**
     * The page size asked for in `per_page`, or the list's own default when
     * none was asked for or it is not one on offer.
     */
    public static function from(Request $request, int $default): int
    {
        $asked = $request->integer('per_page');

        return in_array($asked, self::OPTIONS, true) ? $asked : $default;
    }
}
