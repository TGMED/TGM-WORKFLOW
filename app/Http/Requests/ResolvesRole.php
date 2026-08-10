<?php

namespace App\Http\Requests;

use App\Models\Role;

/**
 * Roles come off the wire as slugs. This swaps the slug for the foreign key
 * the users table actually stores.
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

        /** @var string $slug */
        $slug = $data['role'];
        unset($data['role']);

        $data['role_id'] = Role::idFor($slug);

        return $data;
    }
}
