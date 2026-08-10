/**
 * Human date for the last successful (or attempted) sync.
 */
export function lastSyncedLabel(lastSyncedAt: string | null | undefined): string | null {
    if (!lastSyncedAt) {
        return null;
    }

    const date = new Date(lastSyncedAt);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}
