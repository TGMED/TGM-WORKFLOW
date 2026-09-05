<?php

namespace App\Enums;

/**
 * What a report is about. The list is code rather than data: each entry is
 * quoted back to the reporter on the form and steers who the people team
 * routes the case to, and neither is something a settings page should be
 * able to change out from under an open investigation.
 */
enum ReportCategory: string
{
    case Harassment = 'harassment';
    case Discrimination = 'discrimination';
    case Bullying = 'bullying';
    case Safety = 'safety';
    case Fraud = 'fraud';
    case Misconduct = 'misconduct';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Harassment => 'Harassment',
            self::Discrimination => 'Discrimination',
            self::Bullying => 'Bullying or intimidation',
            self::Safety => 'Health and safety',
            self::Fraud => 'Fraud or theft',
            self::Misconduct => 'Other misconduct',
            self::Other => 'Something else',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Harassment => 'Unwanted conduct of any kind, sexual or otherwise.',
            self::Discrimination => 'Being treated differently for who you are.',
            self::Bullying => 'Repeated behaviour that humiliates or threatens.',
            self::Safety => 'A hazard, an injury, or an unsafe way of working.',
            self::Fraud => 'Money, stock or company property going missing.',
            self::Misconduct => 'A breach of company policy that fits nowhere above.',
            self::Other => 'Anything the categories above do not cover.',
        };
    }

    /**
     * Cases the people team should look at first whatever else is waiting.
     */
    public function isUrgent(): bool
    {
        return in_array($this, [self::Harassment, self::Discrimination, self::Safety], true);
    }

    /**
     * @return array<int, array{value: string, label: string, description: string, urgent: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
            'urgent' => $case->isUrgent(),
        ], self::cases());
    }
}
