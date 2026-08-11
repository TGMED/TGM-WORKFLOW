<?php

namespace App\Enums;

/**
 * The three lists the Family tab keeps. They carry the same shape, so they
 * share one table and are told apart by this.
 */
enum RelationKind: string
{
    /** Who to call in an emergency. */
    case NextOfKin = 'next_of_kin';

    /** People the employee supports, which payroll and benefits care about. */
    case Dependant = 'dependant';

    /** Immediate family recorded for the record's sake. */
    case FamilyMember = 'family_member';

    public function label(): string
    {
        return match ($this) {
            self::NextOfKin => 'Next of kin',
            self::Dependant => 'Dependant',
            self::FamilyMember => 'Family member',
        };
    }

    /**
     * Heading for the card this list sits in.
     */
    public function plural(): string
    {
        return match ($this) {
            self::NextOfKin => 'Next of Kin',
            self::Dependant => 'Dependants',
            self::FamilyMember => 'Family Members',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
