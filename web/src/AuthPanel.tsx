import {
    AuthPanel as PackageAuthPanel,
    buttonClass,
    Field,
    firstError,
    inputClass,
    twoFactorChallenge,
} from '@zekini/auth';
import { type FormEvent, useEffect, useState } from 'react';
import type { Bootstrap } from './auth';

const googleErrors = new Map([
    ['google', 'Google sign-in did not work. Please try again, or use your email and password.'],
    ['google_link', 'Please sign in with your email and password. Google cannot be linked to this account.'],
]);

/** The second step after Google sign-in, for an account with two-factor turned on. */
function GoogleTwoFactor({ bootstrap }: { bootstrap: Bootstrap }) {
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    async function submit(event: FormEvent): Promise<void> {
        event.preventDefault();
        setError(null);
        setSubmitting(true);

        try {
            const result = await twoFactorChallenge(bootstrap, code);

            if (result.ok) {
                window.location.assign('/app');

                return;
            }

            setError(firstError(result, 'That code did not work.'));
        } catch {
            setError('Something went wrong. Try again.');
        }

        setSubmitting(false);
    }

    return (
        <main className="mx-auto max-w-sm space-y-4 p-6">
            <h1 className="text-lg font-semibold text-slate-900">Two-factor sign-in</h1>
            <form onSubmit={submit} className="space-y-4">
                <Field label="Authentication or recovery code">
                    <input
                        value={code}
                        onChange={(event) => setCode(event.target.value)}
                        autoComplete="one-time-code"
                        required
                        className={inputClass}
                    />
                </Field>
                {error && (
                    <p role="alert" className="rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
                        {error}
                    </p>
                )}
                <button type="submit" disabled={submitting} className={`${buttonClass('primary')} w-full`}>
                    Verify
                </button>
                <a href="/app" className="block text-center text-xs text-slate-500 underline">
                    Back to sign in
                </a>
            </form>
        </main>
    );
}

export function AuthPanel({ bootstrap }: { bootstrap: Bootstrap }) {
    const [organisationName, setOrganisationName] = useState('');
    const [authError] = useState(() => new URLSearchParams(window.location.search).get('auth_error') ?? '');
    const googleError = googleErrors.get(authError);

    // Read once, then drop it from the address bar so it does not come back after a reload or sign-out.
    useEffect(() => {
        if (authError !== '') {
            window.history.replaceState(null, '', window.location.pathname);
        }
    }, [authError]);

    if (authError === 'google_two_factor') {
        return <GoogleTwoFactor bootstrap={bootstrap} />;
    }

    return (
        <PackageAuthPanel
            bootstrap={bootstrap}
            appName={bootstrap.appName}
            title={undefined}
            description="Sign in to manage properties and evidence."
            registerPayload={{ organisation_name: organisationName }}
            registerFields={
                <>
                    <Field label="Organisation (optional)">
                        <input
                            value={organisationName}
                            onChange={(event) => setOrganisationName(event.target.value)}
                            autoComplete="organization"
                            className={inputClass}
                        />
                        <span className="block text-xs font-normal text-slate-500">
                            Leave blank to add an organisation later.
                        </span>
                    </Field>
                    <p className="text-xs font-normal text-slate-500">
                        By registering you accept the{' '}
                        <a
                            href={bootstrap.termsUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-slate-900 underline"
                        >
                            Terms &amp; Conditions (v{bootstrap.termsVersion})
                        </a>
                        .
                    </p>
                </>
            }
            socialLogin={
                bootstrap.googleEnabled ? (
                    <>
                        {googleError && (
                            <p role="alert" className="rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
                                {googleError}
                            </p>
                        )}
                        <a
                            href="/auth/google/redirect"
                            className={`${buttonClass('secondary')} w-full`}
                        >
                            Continue with Google
                        </a>
                    </>
                ) : (
                    <p className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">
                        Google sign-in is not configured yet. Ask the owner to add the Google OAuth client details.
                    </p>
                )
            }
        />
    );
}
