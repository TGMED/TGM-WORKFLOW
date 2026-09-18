<?php

namespace App\Enums;

use App\Contracts\Approvable;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\OutOfOfficeRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * The request types that carry their own approver count.
 */
enum RequestModule: string
{
    case Leave = 'leave';
    case Lateness = 'lateness';
    case OutOfOffice = 'out_of_office';

    public function label(): string
    {
        return match ($this) {
            self::Leave => 'Leave requests',
            self::Lateness => 'Lateness requests',
            self::OutOfOffice => 'Out of office requests',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Leave => 'Time off booked against an allowance.',
            self::Lateness => 'Explanations for arriving after the start of a shift.',
            self::OutOfOffice => 'Days worked from home or out on company business.',
        };
    }

    /**
     * The module in the singular, for a sentence that names one request.
     */
    public function noun(): string
    {
        return match ($this) {
            self::Leave => 'leave request',
            self::Lateness => 'lateness request',
            self::OutOfOffice => 'out of office request',
        };
    }

    /**
     * @return class-string<Approvable&Model>
     */
    public function model(): string
    {
        return match ($this) {
            self::Leave => LeaveRequest::class,
            self::Lateness => LatenessRequest::class,
            self::OutOfOffice => OutOfOfficeRequest::class,
        };
    }
}
