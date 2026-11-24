import type { AuthBootstrap } from '@zekini/auth';

export type Bootstrap = AuthBootstrap & {
    appName: string;
    version: string;
    termsUrl: string;
    currentOrganisationId: number | null;
    googleEnabled: boolean;
};

export * from '@zekini/auth';
