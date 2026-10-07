export type GroupTier = "standard" | "premium" | "famille";
export type GroupVisibility = "public" | "private";

export interface OwnerSubscription {
    id: number;
    name: string;
    slug: string;
    max_members: number;
    monthly_price: number;
    category: string;
    currency: string;
    tier: GroupTier;
}

export interface DraftData {
    subscription_id: number | null;
    name: string;
    description: string;
    tier: GroupTier;
    max_members: number;
    total_price: number;
    split_type: "equal";
    visibility: GroupVisibility;
    renewal_date: string;
    auto_renew: boolean;
}

// Drafts may contain blanks. Only publication requires complete data.
export type DraftInput = Partial<{ [K in keyof DraftData]: DraftData[K] | null }>;

export interface DraftPreview {
    full_group_share: number;
    total_price: number;
    currency: string;
    max_members: number;
}

export interface GroupDraft {
    id: string;
    version: number;
    status: "draft" | "publishing" | "published";
    data: DraftInput;
    updated_at: string;
    published_group_id: number | null;
    preview?: DraftPreview | null;
}

export interface OwnerReadiness {
    identityVerified: boolean;
    connectActive: boolean;
    ready: boolean;
    identityStatus: string;
    connectStatus: string;
}

export interface ServiceCredentials {
    credential_email: string;
    credential_password: string;
    credential_notes: string;
}

export type DraftErrors = Record<string, string>;
export type OwnerActivation = "identity" | "connect";

export interface PublishResult {
    redirect: string;
    group_id: number;
}

export interface GroupDraftTransport {
    create(id: string, data: DraftInput): Promise<GroupDraft>;
    update(id: string, version: number, data: DraftInput): Promise<GroupDraft>;
    reopen(id: string, version: number): Promise<GroupDraft>;
    publish(id: string, version: number, credentials: ServiceCredentials): Promise<PublishResult>;
    activate(kind: OwnerActivation, draftId: string): Promise<string>;
}
