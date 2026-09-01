/**
 * Every timestamp arriving from the server is already shifted into the
 * company's configured timezone, so these helpers format in place and never
 * re-interpret the offset.
 */

export function timeOfDay(iso: string | null | undefined): string {
    if (!iso) {
        return '-';
    }

    return new Date(iso).toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}

export function shortDate(iso: string | null | undefined): string {
    if (!iso) {
        return '-';
    }

    return new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

export function fullDate(iso: string | null | undefined): string {
    if (!iso) {
        return '-';
    }

    return new Date(iso).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export function dateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '-';
    }

    return `${shortDate(iso)} · ${timeOfDay(iso)}`;
}

/** 95 -> "1h 35m". Used for worked hours and lateness alike. */
export function duration(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) {
        return '-';
    }

    if (minutes < 60) {
        return `${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

/** Signed offset from the 9:00 baseline, e.g. "-12m early" / "+24m late". */
export function offsetLabel(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) {
        return 'No record';
    }

    if (minutes === 0) {
        return 'On the hour';
    }

    return minutes > 0
        ? `${duration(minutes)} after start`
        : `${duration(Math.abs(minutes))} before start`;
}

export function distance(meters: number | null | undefined): string {
    if (meters === null || meters === undefined) {
        return '-';
    }

    return meters >= 1000 ? `${(meters / 1000).toFixed(1)}km` : `${meters}m`;
}

/** "3 minutes ago" for the activity feeds. */
export function relative(iso: string | null | undefined): string {
    if (!iso) {
        return '-';
    }

    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 60) {
        return 'just now';
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m ago`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h ago`;
    }

    if (seconds < 604800) {
        return `${Math.floor(seconds / 86400)}d ago`;
    }

    return shortDate(iso);
}

/** Minutes since midnight for "09:00" style strings. */
export function clockToMinutes(value: string): number {
    const [hours, minutes] = value.split(':').map(Number);

    return hours * 60 + (minutes || 0);
}

export function minutesToClock(minutes: number): string {
    const normalized = ((minutes % 1440) + 1440) % 1440;
    const hours = Math.floor(normalized / 60);
    const rest = normalized % 60;
    const suffix = hours >= 12 ? 'PM' : 'AM';
    const display = hours % 12 === 0 ? 12 : hours % 12;

    return `${display}:${String(rest).padStart(2, '0')} ${suffix}`;
}

/**
 * Attendance has three states, not two: on time, inside the site's grace
 * window, and late. Grace is amber and explicitly not late; only a genuinely
 * late arrival goes red.
 */
export function attendanceTone(
    status: string | null | undefined,
): 'signal' | 'brass' | 'alert' {
    if (status === 'late') {
        return 'alert';
    }

    return status === 'grace' ? 'brass' : 'signal';
}

export function attendanceLabel(status: string | null | undefined): string {
    if (status === 'late') {
        return 'Late';
    }

    return status === 'grace' ? 'Within grace' : 'On time';
}
