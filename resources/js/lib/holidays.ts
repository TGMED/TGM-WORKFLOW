/**
 * Public holidays as the request forms see them: `Y-m-d` => name, the same
 * dates App\Support\Workdays leaves out of every working-day count.
 */
export type Holidays = Record<string, string>;

/** A date's own calendar day, read locally rather than through UTC. */
export function dayKey(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/** The holidays falling between two `Y-m-d` dates, both ends included. */
export function holidaysBetween(
    start: string,
    end: string,
    holidays: Holidays,
): Array<{ date: string; name: string }> {
    if (!start || !end || end < start) {
        return [];
    }

    return Object.entries(holidays)
        .filter(([date]) => date >= start && date <= end)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([date, name]) => ({ date, name }));
}

/** "Christmas Day and Boxing Day are public holidays, so not counted." */
export function holidayNote(
    inRange: Array<{ date: string; name: string }>,
): string | null {
    if (inRange.length === 0) {
        return null;
    }

    const names = inRange.map((holiday) => holiday.name);
    const list =
        names.length === 1
            ? names[0]
            : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;

    return names.length === 1
        ? `${list} is a public holiday, so it is not counted.`
        : `${list} are public holidays, so they are not counted.`;
}
