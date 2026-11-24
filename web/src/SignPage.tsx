import { Button, Card } from '@zekini/ui';
import { useEffect, useState } from 'react';
import SignaturePad from './SignaturePad';

type Summary = { party: string; phase: string; property_name: string; inspected_at: string | null; expires_at: string };

type Props = { apiBase: string; token: string };

/** Public, account-less signing page reached from an emailed or messaged link. */
export default function SignPage({ apiBase, token }: Props) {
    const [summary, setSummary] = useState<Summary | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);
    const [name, setName] = useState('');
    const [signature, setSignature] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [signedAt, setSignedAt] = useState<string | null>(null);

    useEffect(() => {
        fetch(`${apiBase}/sign/${encodeURIComponent(token)}`, { headers: { Accept: 'application/json' } })
            .then(async (response) => {
                const payload = (await response.json().catch(() => null)) as { data?: Summary; message?: string } | null;

                if (!response.ok || payload?.data === undefined) {
                    setRefusal(payload?.message ?? 'This signing link is not valid.');
                    return;
                }

                setSummary(payload.data);
            })
            .catch(() => setRefusal('We could not load this signing link.'));
    }, [apiBase, token]);

    async function submit(): Promise<void> {
        setError(null);

        if (name.trim() === '' || signature === null) {
            setError('Enter your name and draw your signature.');
            return;
        }

        const response = await fetch(`${apiBase}/sign/${encodeURIComponent(token)}`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ signer_name: name.trim(), signature }),
        });
        const payload = (await response.json().catch(() => null)) as { data?: { signed_at: string }; message?: string } | null;

        if (!response.ok || payload?.data === undefined) {
            setError(payload?.message ?? 'Could not record your signature.');
            return;
        }

        setSignedAt(payload.data.signed_at);
    }

    return (
        <main className="min-h-screen bg-slate-50 p-4 text-slate-900">
            <div className="mx-auto max-w-lg space-y-4">
                <Card>
                    <h1 className="text-xl font-bold">Sign inspection report</h1>
                    {refusal !== null && <p role="alert" className="mt-3 text-sm text-red-600">{refusal}</p>}
                    {summary === null && refusal === null && <p className="mt-3 text-sm text-slate-500">Loading…</p>}
                    {summary !== null && signedAt === null && (
                        <div className="mt-3 space-y-4">
                            <p className="text-sm text-slate-600">
                                {summary.property_name} · {summary.phase} inspection · signing as the {summary.party}
                            </p>
                            <label className="block text-sm">
                                Full name
                                <input
                                    className="mt-1 w-full rounded border border-slate-300 p-2"
                                    value={name}
                                    onChange={(event) => setName(event.target.value)}
                                />
                            </label>
                            <SignaturePad onChange={setSignature} />
                            {error !== null && <p role="alert" className="text-sm text-red-600">{error}</p>}
                            <Button onClick={() => void submit()}>Sign</Button>
                        </div>
                    )}
                    {signedAt !== null && (
                        <p className="mt-3 text-sm font-medium text-emerald-700">
                            Thank you. Signed on {new Date(signedAt).toLocaleString()}.
                        </p>
                    )}
                </Card>
            </div>
        </main>
    );
}
