import { Button } from '@zekini/ui';
import { useState } from 'react';
import SignaturePad from './SignaturePad';

export type InspectionSignature = {
    party: 'landlord' | 'tenant';
    signer_name: string;
    signed_at: string;
    signed_via: string;
    signature_image: string;
};

type Props = {
    apiBase: string;
    headers: () => Record<string, string>;
    inspectionId: number;
    signatures: InspectionSignature[];
    onChanged: () => void;
};

const PARTIES: Array<'landlord' | 'tenant'> = ['landlord', 'tenant'];

export default function SignaturesPanel({ apiBase, headers, inspectionId, signatures, onChanged }: Props) {
    const [signing, setSigning] = useState<'landlord' | 'tenant' | null>(null);
    const [name, setName] = useState('');
    const [image, setImage] = useState<string | null>(null);
    const [link, setLink] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    async function post(path: string, body: unknown): Promise<Response> {
        return fetch(`${apiBase}/inspections/${inspectionId}/${path}`, {
            method: 'POST',
            headers: { ...headers(), 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
    }

    async function saveSignature(): Promise<void> {
        setError(null);

        if (signing === null || name.trim() === '' || image === null) {
            setError('Enter the signer name and draw a signature.');
            return;
        }

        const response = await post('signatures', { party: signing, signer_name: name.trim(), signature: image });

        if (!response.ok) {
            const payload = (await response.json().catch(() => null)) as { message?: string } | null;
            setError(payload?.message ?? 'Could not save the signature.');
            return;
        }

        setSigning(null);
        setName('');
        setImage(null);
        onChanged();
    }

    async function createLink(party: 'landlord' | 'tenant'): Promise<void> {
        setError(null);
        const response = await post('signing-links', { party });
        const payload = (await response.json().catch(() => null)) as { data?: { url: string }; message?: string } | null;

        if (!response.ok || payload?.data === undefined) {
            setError(payload?.message ?? 'Could not create a signing link.');
            return;
        }

        setLink(payload.data.url);
    }

    return (
        <div className="space-y-3 rounded border border-slate-200 p-3">
            <h3 className="font-semibold">Signatures</h3>
            {error !== null && <p role="alert" className="text-sm text-red-600">{error}</p>}
            {PARTIES.map((party) => {
                const signed = signatures.find((candidate) => candidate.party === party);

                return (
                    <div key={party} className="text-sm">
                        <span className="font-medium capitalize">{party}</span>
                        {signed !== undefined ? (
                            <div className="mt-1">
                                <img src={signed.signature_image} alt={`${party} signature`} className="h-16 rounded border border-slate-200 bg-white" />
                                <p className="text-slate-600">
                                    {signed.signer_name} · {new Date(signed.signed_at).toLocaleString()}
                                </p>
                            </div>
                        ) : (
                            <span className="ml-3 inline-flex gap-2">
                                <Button variant="secondary" onClick={() => setSigning(party)}>Sign here</Button>
                                <Button variant="secondary" onClick={() => void createLink(party)}>Get signing link</Button>
                            </span>
                        )}
                    </div>
                );
            })}
            {link !== null && (
                <p className="break-all text-sm">
                    Share this single-use link (valid 7 days): <code>{link}</code>
                </p>
            )}
            {signing !== null && (
                <div className="space-y-2">
                    <label className="block text-sm">
                        Signer name
                        <input
                            className="mt-1 w-full rounded border border-slate-300 p-2"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                        />
                    </label>
                    <SignaturePad onChange={setImage} />
                    <div className="flex gap-2">
                        <Button onClick={() => void saveSignature()}>Save signature</Button>
                        <Button variant="secondary" onClick={() => setSigning(null)}>Cancel</Button>
                    </div>
                </div>
            )}
        </div>
    );
}
