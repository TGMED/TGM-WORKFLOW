<?php

namespace App\Enums;

/**
 * How far the people team has got with a report. A report is never deleted
 * and never silently dropped: it closes as resolved or dismissed, and either
 * way the reporter sees that somebody looked.
 */
enum ReportStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Resolved => 'Resolved',
            self::Dismissed => 'Closed with no action',
        };
    }

    /**
     * What the reporter is told, which is deliberately gentler than the
     * internal label: 'dismissed' is accurate to the file and cold to read.
     */
    public function reporterLabel(): string
    {
        return match ($this) {
            self::Submitted => 'Received',
            self::UnderReview => 'Being looked into',
            self::Resolved => 'Resolved',
            self::Dismissed => 'Closed',
        };
    }

    /**
     * Matches the tones understood by the StatusPill component.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Submitted => 'beacon',
            self::UnderReview => 'brass',
            self::Resolved => 'signal',
            self::Dismissed => 'neutral',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Submitted || $this === self::UnderReview;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
