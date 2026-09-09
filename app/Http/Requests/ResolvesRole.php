<?php

namespace App\Http\Requests;

use App\Models\Role;

/**
 * Roles come off the wire as slugs and live on a pivot, so they are not part
 * of what the users table itself stores. This splits the two apart: `payload()`
 * is what gets written to the row, `roleIds()` is what gets synced after.
 */
trait ResolvesRole
{
    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        unset($data['roles']);

        return $data;
    }

    /**
     * The roles to sync onto the person, in catalogue order.
     *
     * @return array<int, int>
     */
    public function roleIds(): array
    {
        /** @var array<int, string> $slugs */
        $slugs = $this->validated()['roles'] ?? [];

        return Role::query()
            ->whereIn('slug', $slugs)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
