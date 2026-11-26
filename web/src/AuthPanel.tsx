import { AuthPanel as PackageAuthPanel, buttonClass, Field, inputClass } from '@zekini/auth';
import { useState } from 'react';
import type { Bootstrap } from './auth';

const googleErrors: Record<string, string> = {
    google: 'Google sign-in did not work. Please try again, or use your email and password.',
    google_link: 'Please sign in with your email and password. Google cannot be linked to this account yet.',
    google_two_factor: 'This account uses two-factor sign-in. Please sign in with your email and password.',
};

export function AuthPanel({ bootstrap }: { bootstrap: Bootstrap }) {
    const [organisationName, setOrganisationName] = useState('');
    const googleError = googleErrors[new URLSearchParams(window.location.search).get('auth_error') ?? ''];

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
