export type SyncScheduleAccount = {
    sync_due?: boolean;
    next_sync_at?: string | null;
    last_synced_at?: string | null;
};

/**
 * Human countdown until the next automatic scheduled sync, or "Due now".
 */
export function nextSyncLabel(
    account: SyncScheduleAccount,
    nowMs: number = Date.now(),
): string {
    if (account.sync_due || !account.next_sync_at) {
        return 'Due now';
    }

    const target = new Date(account.next_sync_at).getTime();

    if (Number.isNaN(target)) {
        return 'Due now';
    }

    const remainingMs = target - nowMs;

    if (remainingMs <= 0) {
        return 'Due now';
    }

    const totalMinutes = Math.ceil(remainingMs / 60_000);
    const days = Math.floor(totalMinutes / (60 * 24));
    const hours = Math.floor((totalMinutes % (60 * 24)) / 60);
    const minutes = totalMinutes % 60;

    if (days > 0) {
        return hours > 0 ? `in ${days}d ${hours}h` : `in ${days}d`;
    }

    if (hours > 0) {
        return minutes > 0 ? `in ${hours}h ${minutes}m` : `in ${hours}h`;
    }

    return `in ${Math.max(1, minutes)}m`;
}

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
