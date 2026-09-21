<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\Invitation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * How somebody new gets in.
 *
 * There is no sign-up. The people team adds a person, and this emails them a
 * link to choose their own password: nobody types a password on somebody
 * else's behalf, and nobody has to be told one. Only a hash of the link's
 * token is kept, so a copy of the users table is not a list of ways in.
 */
class Invitations
{
    /**
     * Send (or send again) the link. Sending again replaces the token, so an
     * older email stops working the moment a newer one is on its way.
     */
    public function invite(User $user): void
    {
        $token = Str::random(48);

        $user->forceFill([
            'invitation_token' => $this->hash($token),
            'invited_at' => Carbon::now(),
        ])->save();

        $user->notify(new Invitation($token));
    }

    /**
     * The active person a link belongs to, while it still works.
     */
    public function find(string $token): ?User
    {
        $user = User::query()
            ->active()
            ->where('invitation_token', $this->hash($token))
            ->first();

        return $user?->invitationState() === 'invited' ? $user : null;
    }

    /**
     * Take the invitation up: their password, chosen by them, and the link
     * spent. Following it proves the address is theirs, so it is verified in
     * the same breath.
     */
    public function accept(User $user, string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
            'invitation_token' => null,
            'email_verified_at' => $user->email_verified_at ?? Carbon::now(),
        ])->save();
    }

    /**
     * Somebody who got in another way, by resetting a forgotten password,
     * has no further use for the link.
     */
    public function spend(User $user): void
    {
        if ($user->invitation_token !== null) {
            $user->forceFill(['invitation_token' => null])->save();
        }
    }

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
