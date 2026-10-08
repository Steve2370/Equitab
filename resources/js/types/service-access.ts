export interface ServiceCredentials {
    email: string | null;
    password: string | null;
    notes: string | null;
}

export type ServiceAccessStatus = 'payment_pending' | 'ready' | 'awaiting_owner' | 'unavailable';
export type ServiceAccessMode = 'credentials' | 'invitation';

/** Inertia may carry status metadata, but never access secrets in its history. */
export interface ServiceAccessSnapshot {
    status: ServiceAccessStatus;
    mode: ServiceAccessMode;
    credentials: null;
    invitation: null;
}

export type ServiceInvitation = {
    channel: 'link';
    url: string;
    provided_at: string;
    recipient_email: string | null;
} | {
    channel: 'provider_email';
    url: null;
    provided_at: string;
    recipient_email: string;
};

export type ServiceAccess = {
    status: Exclude<ServiceAccessStatus, 'ready'>;
    mode: ServiceAccessMode;
    credentials: null;
    invitation: null;
} | {
    status: 'ready';
    mode: 'credentials';
    credentials: ServiceCredentials;
    invitation: null;
} | {
    status: 'ready';
    mode: 'invitation';
    credentials: null;
    invitation: ServiceInvitation;
};
