import { Button } from '@zekini/ui';
import { useState } from 'react';

type Status = { phase: string; expires_at: string; last_used_at: string | null; photo_count?: number; url?: string; queued?: string[] };

type Props = {
    url: string;
    leaseReference: string;
    headers: () => Record<string, string>;
};

const fieldClass = 'mt-1 w-full rounded border border-slate-300 p-2';

/** The private link a tenant uses to photograph their unit without an account. */
export default function LeaseCaptureLink({ url, leaseReference, headers }: Props) {
    const [open, setOpen] = useState(false);
    const [link, setLink] = useState<Status | null>(null);
    const [phase, setPhase] = useState('incoming');
    const [email, setEmail] = useState('');
    const [phone, setPhone] = useState('');
    const [message, setMessage] = useState<string | null>(null);

    async function request(target: string, init: RequestInit): Promise<unknown> {
        const response = await fetch(target, { credentials: 'same-origin', ...init });
        const payload = (await response.json().catch(() => null)) as { data?: unknown; message?: string } | null;

        if (!response.ok) {
            setMessage(payload?.message ?? 'The capture link request failed.');
            return undefined;
        }

        return payload?.data;
    }

    async function show(): Promise<void> {
        setMessage(null);
        const data = (await request(url, { headers: headers() })) as Status | null | undefined;

        if (data !== undefined) {
            setLink(data);
            setOpen(true);
        }
    }

    async function post(target: string, body: Record<string, string>, success: (data: Status) => string): Promise<void> {
        setMessage(null);
        const data = (await request(target, {
            method: 'POST',
            headers: { ...headers(), 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        })) as Status | undefined;

        if (data !== undefined) {
            setLink(data);
            setMessage(success(data));
        }
    }

    async function withdraw(): Promise<void> {
        if ((await request(url, { method: 'DELETE', headers: headers() })) !== undefined) {
            setLink(null);
            setMessage('Link withdrawn.');
        }
    }

    function send(): void {
        const body: Record<string, string> = { phase };

        if (email.trim() !== '') {
            body.email = email.trim();
        }

        if (phone.trim() !== '') {
            body.phone = phone.trim();
        }

        void post(`${url}/send`, body, (data) =>
            (data.queued ?? []).length === 0 ? 'Could not send. Copy the link instead.' : `Sending by ${(data.queued ?? []).join(' and ')}.`,
        );
    }

    if (!open) {
        return (
            <span className="block">
                <button type="button" className="underline" aria-label={`Tenant capture link for ${leaseReference}`} onClick={() => void show()}>
                    Tenant capture link
                </button>
                {message !== null && <span role="status" className="ml-2">{message}</span>}
            </span>
        );
    }

    return (
        <fieldset className="mt-2 space-y-2 rounded border border-slate-200 p-2">
            <legend>Tenant capture link</legend>
            {link === null ? (
                <p className="text-slate-500">No live link.</p>
            ) : (
                <p className="text-slate-500">
                    Live {link.phase} link until {new Date(link.expires_at).toLocaleDateString()}
                    {link.photo_count !== undefined && `, ${link.photo_count} photos captured`}. A new link replaces it.
                </p>
            )}
            {link?.url !== undefined && (
                <div className="space-y-1">
                    <label className="block">
                        Link (shown once)
                        <input readOnly className={fieldClass} value={link.url} onFocus={(event) => event.target.select()} />
                    </label>
                    <button type="button" className="mr-3 underline" onClick={() => void navigator.clipboard?.writeText(link.url ?? '')}>
                        Copy
                    </button>
                    <a className="underline" href={`sms:${phone.trim()}?&body=${encodeURIComponent(`Please photograph your property: ${link.url}`)}`}>
                        Open in SMS app
                    </a>
                </div>
            )}
            <label className="block">
                Photos for
                <select className={fieldClass} value={phase} onChange={(event) => setPhase(event.target.value)}>
                    <option value="incoming">Moving in</option>
                    <option value="interim">During the lease</option>
                    <option value="outgoing">Moving out</option>
                </select>
            </label>
            <label className="block">
                Tenant email
                <input type="email" className={fieldClass} value={email} onChange={(event) => setEmail(event.target.value)} />
            </label>
            <label className="block">
                Tenant phone (international format)
                <input type="tel" className={fieldClass} value={phone} onChange={(event) => setPhone(event.target.value)} />
            </label>
            <div className="flex flex-wrap gap-2">
                <Button onClick={() => void post(url, { phase }, () => 'Link created. Copy it now.')}>Create link</Button>
                <Button variant="secondary" onClick={send}>Create and send</Button>
                {link !== null && <Button variant="secondary" onClick={() => void withdraw()}>Withdraw</Button>}
            </div>
            {message !== null && <p role="status">{message}</p>}
        </fieldset>
    );
}
