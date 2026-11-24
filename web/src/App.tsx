import { Button, Card } from '@zekini/ui';
import { useEffect, useState } from 'react';
import { AuthPanel } from './AuthPanel';
import InspectionPanel from './InspectionPanel';
import LeasePhotosPanel from './LeasePhotosPanel';
import OrganisationSwitcher from './OrganisationSwitcher';
import PlanPanel from './PlanPanel';
import { ProfileMenu } from './ProfileMenu';
import SetupPanel from './SetupPanel';
import { fetchMe, firstError, logout, type AuthUser, type Bootstrap } from './auth';

type Health = {
    status: string;
    services: Record<string, string>;
};

declare global {
    interface Window {
        __PROPERTYTRAIL_BOOTSTRAP__?: Bootstrap;
    }
}

export default function App() {
    const bootstrap = window.__PROPERTYTRAIL_BOOTSTRAP__;
    const [health, setHealth] = useState<Health | null>(null);
    const [user, setUser] = useState<AuthUser | null>(null);
    const [accountError, setAccountError] = useState<string | null>(null);
    const [signingOut, setSigningOut] = useState(false);
    const [logoutError, setLogoutError] = useState<string | null>(null);
    const [setupKey, setSetupKey] = useState(0);

    useEffect(() => {
        fetch(`${bootstrap?.apiBase ?? '/api/v1'}/health`)
            .then((response) => response.json() as Promise<Health>)
            .then(setHealth)
            .catch(() => setHealth(null));
    }, [bootstrap?.apiBase]);

    useEffect(() => {
        if (bootstrap?.authenticated !== true) {
            return;
        }

        fetchMe(bootstrap)
            .then((result) => {
                if (!result.ok) {
                    setAccountError(firstError(result, 'We could not load your account details.'));
                    return;
                }

                setUser(result.data);
            })
            .catch(() => setAccountError('We could not load your account details.'));
    }, [bootstrap]);

    async function handleLogout(): Promise<void> {
        if (bootstrap === undefined) {
            return;
        }

        setSigningOut(true);
        setLogoutError(null);

        try {
            const result = await logout(bootstrap);

            if (!result.ok) {
                setLogoutError(firstError(result, 'We could not sign you out. Please try again.'));
                return;
            }

            window.location.reload();
        } catch {
            setLogoutError('We could not sign you out. Please try again.');
        } finally {
            setSigningOut(false);
        }
    }

    if (bootstrap === undefined) {
        return <main className="min-h-screen bg-slate-50 p-8 text-slate-900">Loading…</main>;
    }

    return (
        <main className="min-h-screen bg-slate-50 p-8 text-slate-900">
            <div className="mx-auto max-w-4xl space-y-6">
                <header className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold">{bootstrap.appName}</h1>
                        <p className="text-sm text-slate-500">Property condition and evidence platform</p>
                    </div>
                    <div className="flex items-center gap-3">
                        {bootstrap.authenticated && user !== null && (
                            <ProfileMenu
                                bootstrap={bootstrap}
                                user={user}
                                onUserUpdated={setUser}
                                onSignedOut={() => void handleLogout()}
                            />
                        )}
                        {bootstrap.authenticated && user === null && (
                            <span className="text-sm text-slate-500">Loading account…</span>
                        )}
                        {signingOut && <span className="text-sm text-slate-500">Signing out…</span>}
                        <span className="rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold text-white">
                            v{bootstrap.version}
                        </span>
                    </div>
                </header>

                {accountError !== null && (
                    <p role="alert" className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {accountError}
                    </p>
                )}
                {logoutError !== null && (
                    <p role="alert" className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {logoutError}
                    </p>
                )}

                {!bootstrap.authenticated ? (
                    <AuthPanel bootstrap={bootstrap} />
                ) : (
                    <>
                        <Card>
                            <h2 className="text-lg font-semibold">System status</h2>
                            <p className="mt-2 text-sm text-slate-600">
                                {health === null ? 'Checking services…' : `Status: ${health.status}`}
                            </p>
                            {health !== null && (
                                <dl className="mt-4 grid grid-cols-3 gap-3 text-sm">
                                    {Object.entries(health.services).map(([service, status]) => (
                                        <div key={service} className="rounded border border-slate-200 p-3">
                                            <dt className="font-medium capitalize">{service}</dt>
                                            <dd className="text-slate-500">{status}</dd>
                                        </div>
                                    ))}
                                </dl>
                            )}
                        </Card>

                        <Card>
                            <h2 className="text-lg font-semibold">Tenant context</h2>
                            <p className="mt-2 text-sm text-slate-600">
                                {bootstrap.currentOrganisationId === null
                                    ? 'No organisation selected'
                                    : `Organisation #${bootstrap.currentOrganisationId}`}
                            </p>
                            <OrganisationSwitcher
                                apiBase={bootstrap.apiBase ?? '/api/v1'}
                                csrfToken={bootstrap.csrfToken}
                                organisationId={bootstrap.currentOrganisationId ?? null}
                                onSwitched={() => window.location.reload()}
                            />
                            <div className="mt-4 flex gap-3">
                                <Button onClick={() => document.getElementById('setup')?.scrollIntoView({ behavior: 'smooth' })}>
                                    New property
                                </Button>
                                <Button
                                    variant="secondary"
                                    onClick={() => document.getElementById('lease-photos')?.scrollIntoView({ behavior: 'smooth' })}
                                >
                                    Upload evidence
                                </Button>
                                <Button
                                    variant="secondary"
                                    onClick={() => document.getElementById('plan')?.scrollIntoView({ behavior: 'smooth' })}
                                >
                                    Plan and usage
                                </Button>
                            </div>
                        </Card>

                        <PlanPanel
                            apiBase={bootstrap.apiBase ?? '/api/v1'}
                            organisationId={bootstrap.currentOrganisationId ?? null}
                        />

                        <SetupPanel
                            apiBase={bootstrap.apiBase ?? '/api/v1'}
                            organisationId={bootstrap.currentOrganisationId ?? null}
                            csrfToken={bootstrap.csrfToken}
                            onChanged={() => setSetupKey((key) => key + 1)}
                        />

                        <LeasePhotosPanel
                            apiBase={bootstrap.apiBase ?? '/api/v1'}
                            organisationId={bootstrap.currentOrganisationId ?? null}
                            refreshKey={setupKey}
                        />

                        <InspectionPanel
                            apiBase={bootstrap.apiBase ?? '/api/v1'}
                            organisationId={bootstrap.currentOrganisationId ?? null}
                        />
                    </>
                )}
            </div>
        </main>
    );
}
