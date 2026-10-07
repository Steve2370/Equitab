export interface AdminPage<T> {
    data: T[];
    total: number;
    current_page?: number;
    last_page?: number;
    prev_page_url?: string | null;
    next_page_url?: string | null;
}
const labels: Record<string, string> = {
    open: "Ouvert",
    closed: "Fermé",
    active: "Actif",
    inactive: "Inactif",
    verified: "Vérifiée",
    pending: "En attente",
    unverified: "Non vérifiée",
    rejected: "Refusée",
    not_started: "Non configuré",
    not_connected: "Non connecté",
    pending_payment: "Paiement en attente",
    suspended: "Suspendu",
    left: "A quitté",
    under_review: "En examen",
    resolved_refund: "Remboursé",
    resolved_rejected: "Rejeté",
    public: "Public",
    private: "Privé — sur invitation",
    invite_only: "Privé — sur invitation",
};
export function adminLabel(value: string | null | undefined) {
    return value
        ? Object.prototype.hasOwnProperty.call(labels, value)
            ? labels[value]
            : value
        : "Non disponible";
}
