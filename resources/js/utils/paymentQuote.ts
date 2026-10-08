import { isSupportedCurrency, type Currency } from "./money.ts";

export interface PaymentQuote {
    amount_today: number;
    amount_recurring: number;
    currency: Currency;
    next_billing_date?: string;
}

export function isPaymentQuote(value: unknown): value is PaymentQuote {
    if (typeof value !== "object" || value === null) return false;
    const quote = value as Partial<PaymentQuote>;
    return typeof quote.amount_today === "number" && Number.isSafeInteger(quote.amount_today) && quote.amount_today >= 0
        && typeof quote.amount_recurring === "number" && Number.isSafeInteger(quote.amount_recurring) && quote.amount_recurring >= 0
        && isSupportedCurrency(quote.currency)
        && (quote.next_billing_date === undefined || typeof quote.next_billing_date === "string");
}
