import { Button } from '@zekini/ui';
import { useState } from 'react';
import type { Area } from './SitesSection';

type Coverage = { available: Area[]; covered_ids: number[]; is_default: boolean };

type Props = {
    url: string;
    leaseReference: string;
    headers: () => Record<string, string>;
};

/** Which areas "the property" covers for one tenant. */
export default function LeaseCoverage({ url, leaseReference, headers }: Props) {
    const [coverage, setCoverage] = useState<Coverage | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [message, setMessage] = useState<string | null>(null);

    async function request(init: RequestInit, success: string | null): Promise<void> {
        const response = await fetch(url, { credentials: 'same-origin', ...init });

        if (!response.ok) {
            setMessage('Could not load or save the covered areas.');
            return;
        }

        const payload = (await response.json()) as { data: Coverage };
        setCoverage(payload.data);
        setSelected(payload.data.covered_ids);
        setMessage(success);
    }

    if (coverage === null) {
        return (
            <span className="block">
                <button
                    type="button"
                    className="underline"
                    aria-label={`Covered areas for ${leaseReference}`}
                    onClick={() => void request({ headers: headers() }, null)}
                >
                    Covered areas
                </button>
                {message !== null && <span role="status" className="ml-2">{message}</span>}
            </span>
        );
    }

    return (
        <fieldset className="mt-2 space-y-1 rounded border border-slate-200 p-2">
            <legend>Covered areas</legend>
            {coverage.is_default && <p className="text-slate-500">Nothing chosen yet: the unit's own rooms are covered.</p>}
            {coverage.available.map((area) => (
                <label key={area.id} className="block">
                    <input
                        type="checkbox"
                        className="mr-2"
                        checked={selected.includes(area.id)}
                        onChange={(event) => setSelected((current) =>
                            event.target.checked ? [...current, area.id] : current.filter((id) => id !== area.id),
                        )}
                    />
                    {area.name}
                </label>
            ))}
            <Button onClick={() => void request({
                method: 'PUT',
                headers: { ...headers(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ area_ids: selected }),
            }, 'Saved.')}>Save covered areas</Button>
            {message !== null && <p role="status">{message}</p>}
        </fieldset>
    );
}
