<?php

namespace App\Support;

/**
 * Reads config/countries.php back as a shape the rest of the app can rely on,
 * rather than leaving every caller to poke at raw config.
 */
final class Countries
{
    /**
     * @return array<string, array{name: string, dial: string}>
     */
    public static function all(): array
    {
        $countries = [];

        /** @var iterable<mixed, mixed> $configured */
        $configured = config('countries', []);

        foreach ($configured as $code => $country) {
            if (! is_array($country)) {
                continue;
            }

            $countries[(string) $code] = [
                'name' => (string) ($country['name'] ?? $code),
                'dial' => (string) ($country['dial'] ?? ''),
            ];
        }

        return $countries;
    }

    /**
     * ISO alpha-2 codes, for the validation rules.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function name(?string $code): ?string
    {
        return $code === null ? null : (self::all()[$code]['name'] ?? null);
    }

    /**
     * The dropdown payload, sorted by the name people will read.
     *
     * @return array<int, array{value: string, label: string, dial: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $code => $country) {
            $options[] = [
                'value' => $code,
                'label' => $country['name'],
                'dial' => $country['dial'],
            ];
        }

        usort($options, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * States for each country that has a list, keyed by country code. A country
     * missing from here gets a free-text field on the form instead.
     *
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    public static function stateOptions(): array
    {
        $states = [];

        /** @var iterable<mixed, mixed> $configured */
        $configured = config('profile.states', []);

        foreach ($configured as $code => $list) {
            if (! is_array($list)) {
                continue;
            }

            $states[(string) $code] = array_values(array_map(
                fn (mixed $state): array => [
                    'value' => (string) $state,
                    'label' => (string) $state,
                ],
                $list,
            ));
        }

        return $states;
    }
}
