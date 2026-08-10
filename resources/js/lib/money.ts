type FormatPenceOptions = {
    signed?: boolean;
    /**
     * `auto` (default): only as many decimals as required, up to 4
     * (e.g. £6.30, £0.0103). Use for balance, ledger lines, spend chart.
     * `2`: catalog / subscription / top-up prices - whole pounds or 2dp
     * (e.g. £19, £10.00). Never 4dp.
     */
    decimals?: 2 | 'auto';
};

/**
 * Format ledger pence as GBP. Default is smart precision (not forced 4dp).
 */
export function formatPenceAsGbp(pence: number, options: FormatPenceOptions = {}): string {
    const mode = options.decimals ?? 'auto';
    const centipence = Math.round(Math.abs(pence) * 100);

    let maximumFractionDigits: number;
    let minimumFractionDigits: number;

    if (mode === 2) {
        if (centipence === 0 || centipence % 10_000 === 0) {
            maximumFractionDigits = 0;
            minimumFractionDigits = 0;
        } else {
            maximumFractionDigits = 2;
            minimumFractionDigits = 2;
        }
    } else if (centipence === 0 || centipence % 10_000 === 0) {
        maximumFractionDigits = 0;
        minimumFractionDigits = 0;
    } else if (centipence % 100 === 0) {
        maximumFractionDigits = 2;
        minimumFractionDigits = 2;
    } else if (centipence % 10 === 0) {
        maximumFractionDigits = 3;
        minimumFractionDigits = 3;
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
