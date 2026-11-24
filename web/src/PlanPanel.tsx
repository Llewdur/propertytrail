import { Card } from '@zekini/ui';
import { useCallback, useEffect, useState } from 'react';

type Plan = {
    code: string;
    name: string;
    price_monthly_cents: number;
    property_limit: number | null;
    inspection_allowance: number | null;
    signing_link_limit: number | null;
    watermark: boolean;
};

type Entitlement = {
    plan: { code: string; name: string };
    status: string;
    limits: {
        inspections: number | null;
        properties: number | null;
        signing_links_per_month: number | null;
        watermark: boolean;
    };
    usage: { inspections: number; properties: number; signing_links_this_month: number };
    plans: Plan[];
};

type Props = {
    apiBase: string;
    organisationId: number | null;
};

export default function PlanPanel({ apiBase, organisationId }: Props): React.JSX.Element {
    const [entitlement, setEntitlement] = useState<Entitlement | null>(null);
    const [error, setError] = useState<string | null>(null);
    const headers = useCallback((): Record<string, string> => {
        const result: Record<string, string> = { Accept: 'application/json' };
        if (organisationId !== null) {
            result['X-Organisation-Id'] = String(organisationId);
        }

        return result;
    }, [organisationId]);

    useEffect(() => {
        if (organisationId === null) {
            setEntitlement(null);
            return;
        }

        let active = true;
        fetch(`${apiBase}/billing/entitlement`, { headers: headers() })
            .then(async (response) => {
                if (!response.ok) {
                    const body = (await response.json().catch(() => null)) as { message?: string } | null;
                    throw new Error(body?.message ?? 'Could not load your plan.');
                }

                return (await response.json()) as { data: Entitlement };
            })
            .then((payload) => {
                if (active) {
                    setEntitlement(payload.data);
                    setError(null);
                }
            })
            .catch((reason: unknown) => {
                if (active) {
                    setError(reason instanceof Error ? reason.message : 'Could not load your plan.');
                }
            });

        return () => {
            active = false;
        };
    }, [apiBase, organisationId, headers]);

    function describeLimit(value: number | null): string {
        return value === null ? 'Unlimited' : String(value);
    }

    function price(plan: Plan): string {
        if (plan.price_monthly_cents === 0) {
            return 'Free';
        }

        return `R${(plan.price_monthly_cents / 100).toFixed(0)}/month`;
    }

    return (
        <Card>
            <div id="plan" className="scroll-mt-6">
                <h2 className="text-lg font-semibold">Plan and usage</h2>
            </div>
            {error !== null && <p role="alert" className="mt-2 text-sm text-red-600">{error}</p>}
            {entitlement === null && error === null && (
                <p className="mt-2 text-sm text-slate-500">Loading your plan…</p>
            )}
            {entitlement !== null && (
                <>
                    <p className="mt-2 text-sm text-slate-600">
                        Current plan: <strong>{entitlement.plan.name}</strong> · {entitlement.status}
                    </p>
                    <dl className="mt-3 grid grid-cols-3 gap-3 text-sm">
                        <div className="rounded border border-slate-200 p-3">
                            <dt className="text-slate-500">Inspections</dt>
                            <dd>{entitlement.usage.inspections} / {describeLimit(entitlement.limits.inspections)}</dd>
                        </div>
                        <div className="rounded border border-slate-200 p-3">
                            <dt className="text-slate-500">Properties</dt>
                            <dd>{entitlement.usage.properties} / {describeLimit(entitlement.limits.properties)}</dd>
                        </div>
                        <div className="rounded border border-slate-200 p-3">
                            <dt className="text-slate-500">Signing links this month</dt>
                            <dd>
                                {entitlement.usage.signing_links_this_month} /{' '}
                                {describeLimit(entitlement.limits.signing_links_per_month)}
                            </dd>
                        </div>
                    </dl>
                    <table className="mt-4 w-full text-left text-sm">
                        <thead className="text-slate-500">
                            <tr>
                                <th className="py-2">Plan</th>
                                <th className="py-2">Price</th>
                                <th className="py-2">Properties</th>
                                <th className="py-2">Inspections</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entitlement.plans.map((plan) => (
                                <tr key={plan.code} className="border-t border-slate-200">
                                    <td className="py-2">
                                        {plan.name}
                                        {plan.code === entitlement.plan.code && (
                                            <span className="ml-2 rounded bg-slate-900 px-2 py-0.5 text-xs text-white">
                                                Current
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2">{price(plan)}</td>
                                    <td className="py-2">{describeLimit(plan.property_limit)}</td>
                                    <td className="py-2">{describeLimit(plan.inspection_allowance)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="mt-3 text-xs text-slate-500">
                        Billing is handled by Fleet Accounting. Contact us to change plan.
                    </p>
                </>
            )}
        </Card>
    );
}
