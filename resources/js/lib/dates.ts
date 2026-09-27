/**
 * Locale-aware date helpers. Product users are UK-based; default to en-GB.
 */

const APP_LOCALE = 'en-GB';

export function formatAppDate(
    value: string | Date | null | undefined,
    options: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    },
): string | null {
    if (value == null || value === '') {
        return null;
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return date.toLocaleDateString(APP_LOCALE, options);
}

export function formatAppDateTime(
    value: string | Date | null | undefined,
): string | null {
    return formatAppDate(value, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
