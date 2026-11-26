import { useCallback, useEffect, useState } from 'react';

type Organisation = { id: number; name: string };

type Props = {
    apiBase: string;
    csrfToken: string;
    organisationId: number | null;
    onSwitched?: () => void;
};

export default function OrganisationSwitcher({ apiBase, csrfToken, organisationId, onSwitched }: Props) {
    const [organisations, setOrganisations] = useState<Organisation[]>([]);
    const [selected, setSelected] = useState<number | null>(organisationId);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        fetch(`${apiBase}/organisations`, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('load');
                }

                return response.json() as Promise<{ data: Organisation[]; current_organisation_id: number | null }>;
            })
            .then((payload) => {
                setOrganisations(payload.data);
                setSelected(payload.current_organisation_id ?? payload.data[0]?.id ?? null);
            })
            .catch(() => setError('Could not load organisations.'));
    }, [apiBase, csrfToken]);

    const switchTo = useCallback(async (id: number): Promise<void> => {
        setError(null);
        const response = await fetch(`${apiBase}/organisations/switch`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({ organisation_id: id }),
        });

        if (!response.ok) {
            setError('Could not switch organisation.');
            return;
        }

        onSwitched?.();
    }, [apiBase, csrfToken, onSwitched]);

    if (organisations.length === 0) {
        return error === null ? null : <p role="alert" className="mt-2 text-sm text-red-600">{error}</p>;
    }

    return (
        <div className="mt-3 text-sm">
            <label>
                Organisation
                <select
                    className="ml-2 rounded border border-slate-300 p-1"
                    value={selected ?? ''}
                    onChange={(event) => {
                        const id = Number(event.target.value);
                        setSelected(id);
                        void switchTo(id);
                    }}
                >
                    {organisations.map((organisation) => (
                        <option key={organisation.id} value={organisation.id}>{organisation.name}</option>
                    ))}
                </select>
            </label>
            {error !== null && <p role="alert" className="mt-2 text-red-600">{error}</p>}
        </div>
    );
}
