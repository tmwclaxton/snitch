/**
 * Format ledger pence as GBP. Supports tenths of a penny (e.g. 0.2 → £0.002).
 */
export function formatPenceAsGbp(pence: number, options: { signed?: boolean } = {}): string {
    const abs = Math.abs(pence);
    const hasTenth = Math.round(abs * 10) % 10 !== 0;
    const maximumFractionDigits = abs === 0 || abs % 100 === 0 ? 0 : hasTenth ? 3 : 2;

    const formatted = new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP',
        maximumFractionDigits,
        minimumFractionDigits: maximumFractionDigits === 0 ? 0 : Math.min(2, maximumFractionDigits),
    }).format(pence / 100);

    if (options.signed && pence > 0) {
        return `+${formatted}`;
    }

    return formatted;
}
