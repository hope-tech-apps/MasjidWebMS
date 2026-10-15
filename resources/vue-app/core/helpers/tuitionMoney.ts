/** Amounts remain integers, including display and decimal form parsing. */
export function tuitionScale(currency: string): number {
    const c = currency.toLowerCase();
    if (['bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf'].includes(c)) return 0;
    return ['bhd','jod','kwd','omr','tnd'].includes(c) ? 3 : 2;
}
export function tuitionDecimal(minor: number, currency: string): string {
    if (!Number.isSafeInteger(minor)) throw new Error('Invalid amount');
    const scale = tuitionScale(currency), digits = String(Math.abs(minor)).padStart(scale + 1, '0');
    return (minor < 0 ? '-' : '') + (scale ? digits.slice(0, -scale) + '.' + digits.slice(-scale) : digits);
}
export function tuitionMoney(minor: number, currency: string): string { return currency.toUpperCase() + ' ' + tuitionDecimal(minor, currency); }
export function tuitionMinor(input: string, currency: string): number | null {
    const scale = tuitionScale(currency);
    if (!(scale ? new RegExp('^\\d+(?:\\.\\d{1,' + scale + '})?$') : /^\d+$/).test(input.trim())) return null;
    const [whole, fraction = ''] = input.trim().split('.');
    const amount = Number(whole + fraction.padEnd(scale, '0'));
    return Number.isSafeInteger(amount) && amount > 0 && amount <= 999999999 ? amount : null;
}
