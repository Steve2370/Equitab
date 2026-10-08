import type { OwnerCountryState } from "../types/group-draft";

export class OwnerCountryRequestError extends Error {
    readonly status: number;

    constructor(message: string, status = 0) {
        super(message);
        this.name = "OwnerCountryRequestError";
        this.status = status;
    }
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isCountryState(value: unknown): value is OwnerCountryState {
    if (!isRecord(value) || typeof value.locked !== "boolean" || typeof value.canStart !== "boolean"
        || !(value.country === null || (typeof value.country === "string" && /^[A-Z]{2}$/.test(value.country)))
        || !Array.isArray(value.countries) || value.countries.length === 0) return false;

    return value.countries.every((option) => isRecord(option)
        && typeof option.code === "string" && /^[A-Z]{2}$/.test(option.code)
        && typeof option.name === "string" && option.name.trim().length > 0
        && typeof option.enabled === "boolean");
}

export function ownerCountryCsrfToken(cookie: string): string {
    const value = cookie.split(";").map((item) => item.trim()).find((item) => item.startsWith("XSRF-TOKEN="));
    try {
        return value ? decodeURIComponent(value.slice("XSRF-TOKEN=".length)) : "";
    } catch {
        return "";
    }
}

/** Transport only: eligibility and the country lock always come from the server. */
export async function saveOwnerCountry(
    country: string,
    fetcher: typeof fetch = fetch,
    cookie: string = document.cookie,
): Promise<OwnerCountryState> {
    let response: Response;
    try {
        response = await fetcher("/api/owner/country", {
            method: "PATCH",
            credentials: "same-origin",
            redirect: "error",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-XSRF-TOKEN": ownerCountryCsrfToken(cookie),
            },
            body: JSON.stringify({ country }),
        });
    } catch {
        throw new OwnerCountryRequestError("Impossible d’enregistrer votre pays. Vérifiez votre connexion et réessayez.");
    }

    const data: unknown = await response.json().catch(() => null);
    if (!response.ok) {
        let message = "Le pays n’a pas pu être enregistré. Veuillez réessayer.";
        if (response.status === 419 || response.status === 401) message = "Votre session a expiré. Rechargez la page pour vous reconnecter.";
        else if (response.status === 403) message = "Cette modification n’est pas autorisée. Vérifiez votre compte ou contactez le soutien.";
        else if (response.status === 429) message = "Trop de tentatives. Patientez un instant avant de réessayer.";
        else if (response.status === 422 && isRecord(data) && isRecord(data.errors)) {
            const field = data.errors.country;
            const error = Array.isArray(field) ? field[0] : field;
            if (typeof error === "string" && error.trim()) message = error;
        }
        throw new OwnerCountryRequestError(message, response.status);
    }
    if (!isCountryState(data)) {
        throw new OwnerCountryRequestError("La confirmation du pays est indisponible. Rechargez la page pour vérifier son enregistrement.", response.status);
    }
    return data;
}
