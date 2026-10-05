// How the reports print a number: every quantity, amount and percentage with four
// decimals whatever its value (5 -> 5.0000, 0.1 -> 0.1000), so a page reads the same as
// its PDF and its Excel export. A count of records, items or ranks stays a whole number.

const fourDecimals = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 4,
    maximumFractionDigits: 4,
});

const toNumber = (value) => {
    const number = Number(value);

    return Number.isFinite(number) ? number : 0;
};

// A value that rounds to nothing is 0.0000, never -0.0000.
const rounded = (value) => {
    const number = Math.round(toNumber(value) * 10000) / 10000;

    return number === 0 ? 0 : number;
};

export const formatReportNumber = (value) => fourDecimals.format(rounded(value));

export const formatReportCurrency = (value) => {
    const number = rounded(value);

    return `${number < 0 ? '-' : ''}₱${fourDecimals.format(Math.abs(number))}`;
};

export const formatReportPercent = (value) => `${formatReportNumber(value)}%`;

export const formatReportCount = (value) => Math.round(toNumber(value)).toLocaleString('en-US');
