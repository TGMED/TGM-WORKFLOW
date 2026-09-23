<?php

namespace App\Enums;

/**
 * Where the author of a performance review stands to the person it is about,
 * worked out when the review is written rather than chosen by the author.
 *
 * Only ever shown to the holders of the reviews permission. Telling the
 * subject a review came "from your team lead" would name the author in any
 * team with one lead, which is every team.
 */
enum ReviewStanding: string
{
    case Lead = 'lead';
    case Management = 'management';
    case Peer = 'peer';
    case Outside = 'outside';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Team lead',
            self::Management => 'Management',
            self::Peer => 'Colleague',
            self::Outside => 'From outside their group',
        };
    }

    /**
     * Whether a review from here may be shown to its subject at all. Somebody
     * with no working tie to the person can still say something, but it goes
     * to HR alone.
     */
    public function mayBePublic(): bool
    {
        return $this !== self::Outside;
    }
}
