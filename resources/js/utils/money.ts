export const supportedCurrencies = ["CAD", "EUR"] as const;
export type Currency = typeof supportedCurrencies[number];

export function isSupportedCurrency(value: unknown): value is Currency {
    return value === "CAD" || value === "EUR";
}

const formatters = Object.fromEntries(supportedCurrencies.map((currency) => [currency,
    new Intl.NumberFormat("fr-CA", {
        style: "currency", currency, currencyDisplay: "code",
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    }),
])) as Record<Currency, Intl.NumberFormat>;

/** Native minor units only; an unknown amount/currency must never look like CAD. */
export function formatMoney(cents: number, currency: string): string {
    if (!Number.isSafeInteger(cents) || !isSupportedCurrency(currency)) return "Montant indisponible";
    // Preserve the last cent even at the safe-integer boundary, without float division.
    const whole = BigInt(cents) / 100n;
    const fraction = String(Math.abs(cents % 100)).padStart(2, "0");
    return formatters[currency].formatToParts(cents < 0 && whole === 0n ? -0 : whole)
        .map((part) => part.type === "fraction" ? fraction : part.value).join("");
}
