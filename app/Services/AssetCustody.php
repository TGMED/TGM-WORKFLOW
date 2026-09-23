<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Handing an asset to somebody and taking it back. The asset row and the
 * history move together, so the list never says one thing and the trail
 * another.
 */
class AssetCustody
{
    public function assign(Asset $asset, User $to, User $by, ?string $note = null): void
    {
        DB::transaction(function () use ($asset, $to, $by, $note): void {
            $now = Carbon::now();

            // Straight from one person to another is a return and a hand-over.
            $this->closeOpenSpell($asset, $now);

            AssetAssignment::query()->create([
                'asset_id' => $asset->id,
                'user_id' => $to->id,
                'assigned_by_id' => $by->id,
                'assigned_at' => $now,
                'note' => $note,
            ]);

            $asset->update([
                'assigned_user_id' => $to->id,
                'assigned_at' => $now,
                'status' => AssetStatus::Assigned,
            ]);
        });
    }

    public function takeBack(Asset $asset, AssetStatus $status = AssetStatus::Available): void
    {
        DB::transaction(function () use ($asset, $status): void {
            $this->closeOpenSpell($asset, Carbon::now());

            $asset->update([
                'assigned_user_id' => null,
                'assigned_at' => null,
                'status' => $status,
            ]);
        });
    }

    protected function closeOpenSpell(Asset $asset, Carbon $at): void
    {
        AssetAssignment::query()
            ->where('asset_id', $asset->id)
            ->whereNull('returned_at')
            ->update(['returned_at' => $at]);
    }
}
