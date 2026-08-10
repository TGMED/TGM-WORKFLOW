<?php

namespace App\Enums;

use App\Contracts\Approvable;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * The request types that carry their own approver count.
 */
enum RequestModule: string
{
    case Leave = 'leave';
    case Lateness = 'lateness';

    public function label(): string
    {
        return match ($this) {
            self::Leave => 'Leave requests',
            self::Lateness => 'Lateness requests',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Leave => 'Time off booked against an allowance.',
            self::Lateness => 'Explanations for arriving after the start of a shift.',
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
        };
    }
}
