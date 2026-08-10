/**
 * Format ledger pence as GBP. Supports hundredths of a penny (e.g. 0.01 → £0.0001).
 */
export function formatPenceAsGbp(pence: number, options: { signed?: boolean } = {}): string {
    const abs = Math.abs(pence);
    const centipence = Math.round(abs * 100);

    let maximumFractionDigits: number;
    let minimumFractionDigits: number;

    if (abs === 0 || centipence % 10_000 === 0) {
        maximumFractionDigits = 0;
        minimumFractionDigits = 0;
    } else if (centipence % 100 === 0) {
        maximumFractionDigits = 2;
        minimumFractionDigits = 2;
    } else {
        maximumFractionDigits = 4;
        minimumFractionDigits = 4;
    }

    const formatted = new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        maximumFractionDigits,
        minimumFractionDigits,
    }).format(pence / 100);

    if (options.signed && pence > 0) {
        return `+${formatted}`;
    }

    return formatted;
}
