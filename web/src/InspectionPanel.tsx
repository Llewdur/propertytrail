import { Button, Card } from '@zekini/ui';
import { useCallback, useEffect, useState } from 'react';
import ItemPhotos, { type ItemPhoto } from './ItemPhotos';

type Condition = 'excellent' | 'good' | 'fair' | 'poor' | 'damaged';

const CONDITIONS: { key: Condition; label: string; className: string }[] = [
    { key: 'excellent', label: 'Excellent', className: 'bg-emerald-800 text-white' },
    { key: 'good', label: 'Good', className: 'bg-emerald-600 text-white' },
    { key: 'fair', label: 'Fair', className: 'bg-amber-500 text-white' },
    { key: 'poor', label: 'Poor', className: 'bg-orange-600 text-white' },
    { key: 'damaged', label: 'Damaged', className: 'bg-red-600 text-white' },
];

type InspectionItem = {
    id: number | null;
    label: string;
    condition: Condition | null;
    note: string | null;
    photos?: ItemPhoto[];
};

type InspectionSection = { id: number; title: string; saved_at: string | null; items: InspectionItem[] };

type Progress = { sections_total: number; sections_saved: number; items_total: number; items_rated: number };

type Inspection = {
    id: number;
    phase: string;
    status: string;
    submitted_at: string | null;
    property: { id: number; name: string } | null;
    sections: InspectionSection[];
    progress: Progress;
};

type Property = { id: number; name: string };

type Props = {
    apiBase: string;
    organisationId: number | null;
};

export default function InspectionPanel({ apiBase, organisationId }: Props) {
    const [inspections, setInspections] = useState<Inspection[]>([]);
    const [properties, setProperties] = useState<Property[]>([]);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [activeSectionId, setActiveSectionId] = useState<number | null>(null);
    const [newPropertyId, setNewPropertyId] = useState<number | null>(null);
    const [newPhase, setNewPhase] = useState<'incoming' | 'outgoing' | 'interim'>('incoming');
    const [drafts, setDrafts] = useState<InspectionItem[]>([]);
    const [photoMap, setPhotoMap] = useState<Record<number, ItemPhoto[]>>({});
    const [error, setError] = useState<string | null>(null);
    const [submitErrors, setSubmitErrors] = useState<string[]>([]);

    const headers = useCallback((): Record<string, string> => {
        const result: Record<string, string> = { Accept: 'application/json' };

        if (organisationId !== null) {
            result['X-Organisation-Id'] = String(organisationId);
        }

        return result;
    }, [organisationId]);

    useEffect(() => {
        fetch(`${apiBase}/inspections`, { headers: headers() })
            .then((response) => (response.ok ? (response.json() as Promise<{ data: Inspection[] }>) : Promise.reject(new Error('load'))))
            .then((payload) => {
                setInspections(payload.data);
                setSelectedId((current) => current ?? payload.data[0]?.id ?? null);
                setActiveSectionId((current) => current ?? payload.data[0]?.sections[0]?.id ?? null);
            })
            .catch(() => setError('Could not load inspections.'));
    }, [apiBase, headers]);

    useEffect(() => {
        fetch(`${apiBase}/properties`, { headers: headers() })
            .then((response) => (response.ok ? (response.json() as Promise<{ data: Property[] }>) : Promise.reject(new Error('load'))))
            .then((payload) => {
                setProperties(payload.data);
                setNewPropertyId((current) => current ?? payload.data[0]?.id ?? null);
            })
            .catch(() => setError('Could not load properties.'));
    }, [apiBase, headers]);

    const selected = inspections.find((inspection) => inspection.id === selectedId) ?? null;

    useEffect(() => {
        const section = selected?.sections.find((candidate) => candidate.id === activeSectionId) ?? null;
        setDrafts(section === null ? [] : section.items.map((item) => ({ ...item })));
    }, [selected, activeSectionId]);

    function send(method: 'POST' | 'PUT', url: string, body?: unknown): Promise<Response> {
        return fetch(url, {
            method,
            headers: { ...headers(), 'Content-Type': 'application/json' },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    }

    async function createInspection(): Promise<void> {
        if (newPropertyId === null) {
            setError('Choose a property first.');
            return;
        }

        setError(null);
        const response = await send('POST', `${apiBase}/inspections`, { property_id: newPropertyId, phase: newPhase });

        if (!response.ok) {
            setError('Could not start the inspection.');
            return;
        }

        const payload = (await response.json()) as { data: Inspection };
        setInspections((current) => [payload.data, ...current]);
        setSelectedId(payload.data.id);
        setActiveSectionId(payload.data.sections[0]?.id ?? null);
    }

    function updateItem(index: number, patch: Partial<InspectionItem>): void {
        setDrafts((current) => current.map((item, position) => (position === index ? { ...item, ...patch } : item)));
    }

    function addItem(): void {
        setDrafts((current) => [...current, { id: null, label: 'New item', condition: null, note: null }]);
    }

    async function saveRoom(): Promise<void> {
        if (selected === null || activeSectionId === null) {
            return;
        }

        setError(null);
        const response = await send('PUT', `${apiBase}/inspections/${selected.id}/sections/${activeSectionId}`, {
            items: drafts.map((item, index) => ({
                id: item.id,
                label: item.label,
                condition: item.condition,
                note: item.note === '' ? null : item.note,
                sort_order: index,
            })),
        });

        if (!response.ok) {
            const failure = (await response.json().catch(() => null)) as { message?: string } | null;
            setError(failure?.message ?? 'Could not save this room.');
            return;
        }

        const payload = (await response.json()) as { data: Inspection };
        setInspections((current) => current.map((inspection) => (inspection.id === payload.data.id ? payload.data : inspection)));
    }

    async function reloadSelected(): Promise<void> {
        if (selectedId === null) {
            return;
        }

        const response = await fetch(`${apiBase}/inspections/${selectedId}`, { headers: headers() });

        if (!response.ok) {
            return;
        }

        // Only photos are refreshed; replacing the inspection would reset unsaved room edits.
        const payload = (await response.json()) as { data: Inspection };
        const fresh: Record<number, ItemPhoto[]> = {};

        for (const section of payload.data.sections) {
            for (const item of section.items) {
                if (item.id !== null) {
                    fresh[item.id] = item.photos ?? [];
                }
            }
        }

        setPhotoMap((current) => ({ ...current, ...fresh }));
    }

    async function submitInspection(): Promise<void> {
        if (selected === null) {
            return;
        }

        setSubmitErrors([]);
        const response = await send('POST', `${apiBase}/inspections/${selected.id}/submit`);
        const payload = (await response.json().catch(() => null)) as
            | { data?: Inspection; errors?: string[]; message?: string }
            | null;

        if (!response.ok) {
            setSubmitErrors(payload?.errors ?? [payload?.message ?? 'Could not submit the inspection.']);
            return;
        }

        if (payload?.data !== undefined) {
            const fresh = payload.data;
            setInspections((current) => current.map((inspection) => (inspection.id === fresh.id ? fresh : inspection)));
        }
    }

    const editable = selected !== null && selected.status === 'draft';

    return (
        <Card>
            <h2 className="text-lg font-semibold">Inspections</h2>
            {error !== null && <p role="alert" className="mt-2 text-sm text-red-600">{error}</p>}
            {submitErrors.length > 0 && (
                <ul role="alert" className="mt-2 space-y-1 text-sm text-red-600">
                    {submitErrors.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            )}
            <div className="mt-4 flex flex-wrap items-end gap-3">
                <label className="text-sm">
                    Property
                    <select
                        className="ml-2 rounded border border-slate-300 p-1"
                        value={newPropertyId ?? ''}
                        onChange={(event) => setNewPropertyId(event.target.value === '' ? null : Number(event.target.value))}
                    >
                        <option value="">Select…</option>
                        {properties.map((property) => (
                            <option key={property.id} value={property.id}>{property.name}</option>
                        ))}
                    </select>
                </label>
                <label className="text-sm">
                    Phase
                    <select
                        className="ml-2 rounded border border-slate-300 p-1"
                        value={newPhase}
                        onChange={(event) => setNewPhase(event.target.value as 'incoming' | 'outgoing' | 'interim')}
                    >
                        <option value="incoming">Incoming</option>
                        <option value="outgoing">Outgoing</option>
                        <option value="interim">Interim</option>
                    </select>
                </label>
                <Button onClick={() => void createInspection()}>New inspection</Button>
            </div>
            <ul className="mt-4 space-y-1 text-sm">
                {inspections.map((inspection) => (
                    <li key={inspection.id}>
                        <button
                            type="button"
                            className="text-left underline-offset-2 hover:underline"
                            onClick={() => {
                                setSelectedId(inspection.id);
                                setActiveSectionId(inspection.sections[0]?.id ?? null);
                                setSubmitErrors([]);
                            }}
                        >
                            {inspection.property?.name ?? 'Property'} · {inspection.phase} · {inspection.status}
                            {' '}({inspection.progress.items_rated}/{inspection.progress.items_total} rated)
                        </button>
                    </li>
                ))}
            </ul>
            {selected !== null && (
                <div className="mt-4 space-y-4">
                    <p className="text-sm text-slate-600">
                        {selected.progress.sections_saved}/{selected.progress.sections_total} rooms saved · {selected.progress.items_rated}/{selected.progress.items_total} items rated
                    </p>
                    {selected.status !== 'draft' && selected.submitted_at !== null && (
                        <p className="text-sm font-medium text-emerald-700">
                            Submitted on {new Date(selected.submitted_at).toLocaleString()}
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {selected.sections.map((section) => (
                            <button
                                key={section.id}
                                type="button"
                                onClick={() => setActiveSectionId(section.id)}
                                className={`rounded px-3 py-2 text-sm font-medium ${section.id === activeSectionId ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white text-slate-700'}`}
                            >
                                {section.title}
                                {section.saved_at !== null ? ' ✓' : ''}
                            </button>
                        ))}
                    </div>
                    {drafts.map((item, index) => (
                        <div key={item.id ?? `new-${index}`} className="rounded border border-slate-200 p-3">
                            <input
                                className="w-full rounded border border-slate-300 p-2 font-medium"
                                value={item.label}
                                disabled={!editable}
                                onChange={(event) => updateItem(index, { label: event.target.value })}
                            />
                            <div className="mt-2 flex flex-wrap gap-2">
                                {CONDITIONS.map((condition) => (
                                    <button
                                        key={condition.key}
                                        type="button"
                                        disabled={!editable}
                                        onClick={() => updateItem(index, { condition: condition.key })}
                                        className={`rounded-full px-3 py-1 text-xs font-semibold ${item.condition === condition.key ? condition.className : 'border border-slate-300 bg-white text-slate-600'}`}
                                    >
                                        {condition.label}
                                    </button>
                                ))}
                            </div>
                            <input
                                className="mt-2 w-full rounded border border-slate-300 p-2 text-sm"
                                placeholder="Note (e.g. Scuff marks on wall behind door)"
                                value={item.note ?? ''}
                                disabled={!editable}
                                onChange={(event) => updateItem(index, { note: event.target.value })}
                            />
                            {item.id !== null && (
                                <ItemPhotos
                                    apiBase={apiBase}
                                    headers={headers}
                                    itemId={item.id}
                                    photos={photoMap[item.id] ?? item.photos ?? []}
                                    editable={editable}
                                    onChanged={() => void reloadSelected()}
                                />
                            )}
                        </div>
                    ))}
                    {editable && (
                        <div className="flex flex-wrap gap-3">
                            <Button variant="secondary" onClick={addItem}>Add item</Button>
                            <Button onClick={() => void saveRoom()}>Save room</Button>
                            <Button onClick={() => void submitInspection()}>Submit inspection</Button>
                        </div>
                    )}
                </div>
            )}
        </Card>
    );
}
