/**
 * Human date for the last successful (or attempted) sync.
 */
import { formatAppDate } from '@/lib/dates';

export function lastSyncedLabel(lastSyncedAt: string | null | undefined): string | null {
    const formatted = formatAppDate(lastSyncedAt);

    if (!formatted) {
        return null;
    }

    return `Last synced ${formatted}`;
}
