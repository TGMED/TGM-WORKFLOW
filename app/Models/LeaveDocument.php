<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A document added to a leave request after it was booked. Kept on the
 * private disk and only served through a controller that checks who is asking.
 *
 * @property int $id
 * @property int $leave_request_id
 * @property string $path
 * @property string $name
 * @property int|null $size
 * @property int|null $uploaded_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LeaveRequest $leaveRequest
 * @property-read User|null $uploadedBy
 */
#[Fillable(['path', 'name', 'size', 'uploaded_by_id'])]
class LeaveDocument extends Model
{
    /**
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
