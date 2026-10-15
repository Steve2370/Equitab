import type { Currency } from "../utils/money";

export interface CountryOption {
    code: string;
    name: string;
    currency: Currency;
}

export interface MarketState {
    country: string | null;
    currency: Currency | null;
    enabled: boolean;
    countries: CountryOption[];
}
