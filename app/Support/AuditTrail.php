<?php

namespace App\Support;

use App\Models\Audit;
use Illuminate\Support\Str;

/**
 * Turns a stored audit row into something a person can read: what kind of
 * record it was, which one, and which fields moved.
 */
class AuditTrail
{
    /**
     * Fields nobody needs to see move. Timestamps are noise on every row, and
     * the secrets are already stripped by the global exclude in config.
     *
     * @var array<int, string>
     */
    protected const HIDDEN = [
        'created_at',
        'updated_at',
        'password',
        'remember_token',
        'email_verified_at',
    ];

    /**
     * Columns worth naming a record by, in the order they are tried.
     *
     * @var array<int, string>
     */
    protected const NAMES = ['name', 'title', 'slug', 'email'];

    /**
     * @return array<string, mixed>
     */
    public static function payload(Audit $audit): array
    {
        /** @var array<string, mixed> $old */
        $old = $audit->old_values ?? [];
        /** @var array<string, mixed> $new */
        $new = $audit->new_values ?? [];

        return [
            'id' => $audit->id,
            'event' => $audit->event,
            'event_label' => self::eventLabel($audit->event),
            'event_tone' => self::eventTone($audit->event),
            'type' => $audit->auditable_type,
            'type_label' => self::typeLabel($audit->auditable_type),
            'subject' => self::subject($old, $new, $audit->auditable_id),
            'actor' => $audit->user?->getAttribute('name'),
            'actor_id' => $audit->user_id,
            'ip_address' => $audit->ip_address,
            'url' => $audit->url,
            'created_at' => $audit->created_at?->toIso8601String(),
            'changes' => self::changes($old, $new),
        ];
    }

    /**
     * The fields that moved, each with what it was and what it became.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<int, array{field: string, label: string, from: string|null, to: string|null}>
     */
    public static function changes(array $old, array $new): array
    {
        $fields = collect(array_keys($old + $new))
            ->reject(fn (string $field): bool => in_array($field, self::HIDDEN, true))
            ->values();

        return $fields
            ->map(fn (string $field): array => [
                'field' => $field,
                'label' => self::fieldLabel($field),
                'from' => array_key_exists($field, $old) ? self::value($old[$field]) : null,
                'to' => array_key_exists($field, $new) ? self::value($new[$field]) : null,
            ])
            // A row where both sides read the same carries no information.
            ->reject(fn (array $change): bool => $change['from'] === $change['to'])
            ->values()
            ->all();
    }

    /**
     * Model classes that actually appear in the trail, ready for the filter.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function recordedTypes(): array
    {
        return Audit::query()
            ->distinct()
            ->orderBy('auditable_type')
            ->pluck('auditable_type')
            ->map(fn (string $type): array => [
                'value' => $type,
                'label' => self::typeLabel($type),
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function events(): array
    {
        return [
            ['value' => 'created', 'label' => 'Created'],
            ['value' => 'updated', 'label' => 'Updated'],
            ['value' => 'deleted', 'label' => 'Deleted'],
            ['value' => 'restored', 'label' => 'Restored'],
        ];
    }

    public static function typeLabel(?string $type): string
    {
        if ($type === null) {
            return 'Record';
        }

        return Str::of(class_basename($type))->headline()->toString();
    }

    /**
     * Matches the tones understood by the StatusPill component.
     */
    protected static function eventTone(?string $event): string
    {
        return match ($event) {
            'created' => 'signal',
            'deleted' => 'alert',
            'restored' => 'brass',
            default => 'neutral',
        };
    }

    protected static function eventLabel(?string $event): string
    {
        return $event === null ? 'Changed' : Str::of($event)->headline()->toString();
    }

    /**
     * Which record this was. Read off the values rather than the row itself,
     * so a deleted record still says what it was called.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected static function subject(array $old, array $new, int|string|null $id): string
    {
        foreach (self::NAMES as $field) {
            $value = $new[$field] ?? $old[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '#'.($id ?? '?');
    }

    protected static function fieldLabel(string $field): string
    {
        return Str::of($field)
            ->replaceEnd('_id', '')
            ->headline()
            ->toString();
    }

    /**
     * A stored value as a line of text. Arrays are the awkward case: a JSON
     * column reads back as one, and a raw dump helps nobody.
     */
    protected static function value(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => $value === [] ? 'None' : implode(', ', array_map(
                fn (mixed $item): string => is_scalar($item) ? (string) $item : self::encode($item),
                $value,
            )),
            is_scalar($value) => (string) $value,
            default => self::encode($value),
        };
    }

    /**
     * A value with no plainer reading, as JSON. Encoding can fail on a
     * resource or a malformed string, and a row that will not render is worse
     * than one that says so.
     */
    protected static function encode(mixed $value): string
    {
        $encoded = json_encode($value);

        return $encoded === false ? '(unreadable)' : $encoded;
    }
}
