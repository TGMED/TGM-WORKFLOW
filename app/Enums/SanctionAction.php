<?php

namespace App\Enums;

/**
 * What the company does about an offence. The list is fixed because each step
 * means something specific in an employment file; what varies between
 * companies is which step a given offence reaches, and how quickly, and that
 * is the ladder rather than this.
 */
enum SanctionAction: string
{
    case Counselling = 'counselling';
    case VerbalWarning = 'verbal_warning';
    case WrittenWarning = 'written_warning';
    case FinalWarning = 'final_warning';
    case Suspension = 'suspension';
    case Dismissal = 'dismissal';

    public function label(): string
    {
        return match ($this) {
            self::Counselling => 'A word about it',
            self::VerbalWarning => 'Verbal warning',
            self::WrittenWarning => 'Written warning',
            self::FinalWarning => 'Final written warning',
            self::Suspension => 'Suspension',
            self::Dismissal => 'Dismissal',
        };
    }

    /**
     * Whether this step ends the employment, which is the one outcome that
     * cannot be taken back and so is flagged wherever it appears.
     */
    public function endsEmployment(): bool
    {
        return $this === self::Dismissal;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Counselling, self::VerbalWarning => 'neutral',
            self::WrittenWarning, self::FinalWarning => 'brass',
            self::Suspension, self::Dismissal => 'alert',
        };
    }

    /**
     * @return array<int, array{value: string, label: string, ends_employment: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'ends_employment' => $case->endsEmployment(),
        ], self::cases());
    }
}
