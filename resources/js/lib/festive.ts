/**
 * Seasonal touches on the app, read off the Lagos calendar.
 *
 * - Christmas (lights round the logo, a cap on it, snow on the sign-in page)
 *   runs from whenever this ships until the end of 31 December 2026.
 * - New Year (fireworks and a greeting on the sign-in page) runs from
 *   1 to 5 January 2027.
 *
 * Outside those dates nothing shows. To run them again another year, move the
 * dates below.
 *
 * On a local copy, `?season=christmas` or `?season=new-year` on any page shows
 * that season whatever the date, for previewing. It does nothing on the live
 * site.
 */
export type Season = 'christmas' | 'new-year';

const CHRISTMAS_UNTIL = new Date('2027-01-01T00:00:00+01:00');
const NEW_YEAR_FROM = new Date('2027-01-01T00:00:00+01:00');
const NEW_YEAR_UNTIL = new Date('2027-01-06T00:00:00+01:00');

export const NEW_YEAR = 2027;

function preview(): Season | null {
    if (!import.meta.env.DEV || typeof window === 'undefined') {
        return null;
    }

    const asked = new URLSearchParams(window.location.search).get('season');

    return asked === 'christmas' || asked === 'new-year' ? asked : null;
}

export function season(now: Date = new Date()): Season | null {
    const previewing = preview();

    if (previewing !== null) {
        return previewing;
    }

    if (now < CHRISTMAS_UNTIL) {
        return 'christmas';
    }

    if (now >= NEW_YEAR_FROM && now < NEW_YEAR_UNTIL) {
        return 'new-year';
    }

    return null;
}

export function isFestive(now: Date = new Date()): boolean {
    return season(now) === 'christmas';
}

export function isNewYear(now: Date = new Date()): boolean {
    return season(now) === 'new-year';
}
