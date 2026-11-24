import { Button, Card } from '@zekini/ui';
import { useCallback, useEffect, useState } from 'react';
import { useBlobUrl } from './ItemPhotos';

type Property = { id: number; name: string };
type Room = { id: number; name: string };
type Lease = {
    id: number;
    lease_reference: string;
    tenant_name: string;
    status: string;
    start_date: string;
    end_date: string | null;
};
type Photo = {
    id: number;
    label: string | null;
    phase: string | null;
    taken_at: string | null;
    record: {
        id: number;
        file_name: string;
        mime_type: string;
        file_size_bytes: number;
        captured_at: string | null;
    };
};
type Group = { room: Room | null; photos: Photo[] };

type Props = {
    apiBase: string;
    organisationId: number | null;
    refreshKey?: number;
};

function PhotoPreview({ apiBase, headers, photo }: { apiBase: string; headers: () => Record<string, string>; photo: Photo }) {
    const url = useBlobUrl(apiBase, headers, photo.record.id);

    return (
        <figure className="w-28 text-xs">
            {url !== null ? (
                <img src={url} alt={photo.label ?? photo.record.file_name} className="h-20 w-28 rounded object-cover" />
            ) : (
                <div className="h-20 w-28 rounded bg-slate-100" />
            )}
            <figcaption className="mt-1 truncate">{photo.record.file_name}</figcaption>
            {url !== null && (
                <a className="underline" href={url} download={photo.record.file_name}>Download</a>
            )}
        </figure>
    );
}

export default function LeasePhotosPanel({ apiBase, organisationId, refreshKey = 0 }: Props) {
    const [properties, setProperties] = useState<Property[]>([]);
    const [propertyId, setPropertyId] = useState<number | null>(null);
    const [rooms, setRooms] = useState<Room[]>([]);
    const [leases, setLeases] = useState<Lease[]>([]);
    const [leaseId, setLeaseId] = useState<number | null>(null);
    const [groups, setGroups] = useState<Group[]>([]);
    const [tab, setTab] = useState<'documents' | 'leases'>('documents');
    const [file, setFile] = useState<File | null>(null);
    const [phase, setPhase] = useState<'incoming' | 'outgoing'>('incoming');
    const [roomId, setRoomId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    const headers = useCallback((): Record<string, string> => {
        const result: Record<string, string> = { Accept: 'application/json' };
        if (organisationId !== null) {
            result['X-Organisation-Id'] = String(organisationId);
        }

        return result;
    }, [organisationId]);

    const loadPhotos = useCallback(async (): Promise<void> => {
        if (propertyId === null) {
            setGroups([]);
            return;
        }

        const query = new URLSearchParams();
        if (leaseId !== null) {
            query.set('lease_id', String(leaseId));
        }

        const response = await fetch(`${apiBase}/properties/${propertyId}/lease-photos?${query.toString()}`, {
            headers: headers(),
        });

        if (!response.ok) {
            setError('Could not load lease photos.');
            return;
        }

        const payload = (await response.json()) as { data: { property_id: number; groups: Group[] } };
        setGroups(payload.data.groups);
        setError(null);
    }, [apiBase, headers, leaseId, propertyId]);

    useEffect(() => {
        fetch(`${apiBase}/properties`, { headers: headers() })
            .then((response) => response.json() as Promise<{ data: Property[] }>)
            .then((payload) => {
                setProperties(payload.data);
                setPropertyId(payload.data[0]?.id ?? null);
            })
            .catch(() => setError('Could not load properties.'));
    }, [apiBase, headers, refreshKey]);

    useEffect(() => {
        if (propertyId === null) {
            setRooms([]);
            setLeases([]);
            setLeaseId(null);
            return;
        }

        fetch(`${apiBase}/properties/${propertyId}/rooms`, { headers: headers() })
            .then((response) => response.json() as Promise<{ data: Room[] }>)
            .then((payload) => setRooms(payload.data))
            .catch(() => setError('Could not load rooms.'));

        fetch(`${apiBase}/properties/${propertyId}/leases`, { headers: headers() })
            .then((response) => response.json() as Promise<{ data: Lease[] }>)
            .then((payload) => {
                setLeases(payload.data);
                setLeaseId(payload.data[0]?.id ?? null);
            })
            .catch(() => setError('Could not load leases.'));
    }, [apiBase, headers, propertyId]);

    useEffect(() => {
        void loadPhotos();
    }, [loadPhotos]);

    const upload = async (): Promise<void> => {
        if (file === null || leaseId === null) {
            setError('Choose a file and a lease first.');
            return;
        }

        const body = new FormData();
        body.append('file', file);
        body.append('attachable_type', 'lease');
        body.append('attachable_id', String(leaseId));
        body.append('phase', phase);
        body.append('taken_at', new Date().toISOString().slice(0, 19).replace('T', ' '));
        if (roomId !== null) {
            body.append('area_room_id', String(roomId));
        }

        const response = await fetch(`${apiBase}/evidence/upload`, {
            method: 'POST',
            headers: headers(),
            body,
        });

        if (!response.ok) {
            setError('Upload failed.');
            return;
        }

        setFile(null);
        await loadPhotos();
    };

    return (
        <Card>
            <h2 id="lease-photos" className="text-lg font-semibold">Lease photos</h2>
            <div className="mt-4 flex flex-wrap gap-3">
                <label className="text-sm">
                    Property
                    <select
                        className="ml-2 rounded border border-slate-300 p-1"
                        value={propertyId ?? ''}
                        onChange={(event) => setPropertyId(event.target.value === '' ? null : Number(event.target.value))}
                    >
                        <option value="">Select…</option>
                        {properties.map((property) => (
                            <option key={property.id} value={property.id}>{property.name}</option>
                        ))}
                    </select>
                </label>
                <div className="flex gap-2">
                    <Button variant={tab === 'documents' ? 'primary' : 'secondary'} onClick={() => setTab('documents')}>Documents</Button>
                    <Button variant={tab === 'leases' ? 'primary' : 'secondary'} onClick={() => setTab('leases')}>Leases</Button>
                </div>
            </div>
            {error !== null && <p className="mt-3 text-sm text-red-600">{error}</p>}
            {tab === 'leases' && (
                <ul className="mt-4 space-y-1 text-sm">
                    {leases.map((lease) => (
                        <li key={lease.id}>
                            {lease.lease_reference} · {lease.tenant_name} · {lease.status}
                        </li>
                    ))}
                </ul>
            )}
            <div className="mt-4 flex flex-wrap items-end gap-3">
                <label className="text-sm">
                    Lease
                    <select
                        className="ml-2 rounded border border-slate-300 p-1"
                        value={leaseId ?? ''}
                        onChange={(event) => setLeaseId(event.target.value === '' ? null : Number(event.target.value))}
                    >
                        <option value="">Select…</option>
                        {leases.map((lease) => (
                            <option key={lease.id} value={lease.id}>{lease.lease_reference}</option>
                        ))}
                    </select>
                </label>
                <label className="text-sm">
                    Room
                    <select
                        className="ml-2 rounded border border-slate-300 p-1"
                        value={roomId ?? ''}
                        onChange={(event) => setRoomId(event.target.value === '' ? null : Number(event.target.value))}
                    >
                        <option value="">Property level</option>
                        {rooms.map((room) => (
                            <option key={room.id} value={room.id}>{room.name}</option>
                        ))}
                    </select>
                </label>
                <label className="text-sm">
                    Phase
                    <select
                        className="ml-2 rounded border border-slate-300 p-1"
                        value={phase}
                        onChange={(event) => setPhase(event.target.value === 'outgoing' ? 'outgoing' : 'incoming')}
                    >
                        <option value="incoming">Incoming</option>
                        <option value="outgoing">Outgoing</option>
                    </select>
                </label>
                <input
                    type="file"
                    accept="image/*"
                    onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                />
                <Button onClick={() => void upload()}>Upload photo</Button>
            </div>
            <div className="mt-6 space-y-4">
                {groups.map((group, index) => (
                    <div key={`${group.room?.id ?? 'property'}-${index}`} className="rounded border border-slate-200 p-3">
                        <h3 className="font-medium">{group.room?.name ?? 'Property level'}</h3>
                        <div className="mt-2 grid grid-cols-2 gap-3">
                            {(['incoming', 'outgoing'] as const).map((lens) => (
                                <div key={lens}>
                                    <p className="text-xs uppercase text-slate-500">{lens}</p>
                                    {group.photos
                                        .filter((photo) => photo.phase === lens)
                                        .map((photo) => (
                                            <div key={photo.id} className="mt-2 flex items-start gap-2">
                                                <PhotoPreview apiBase={apiBase} headers={headers} photo={photo} />
                                                <p className="text-sm text-slate-500">{photo.taken_at ?? 'undated'}</p>
                                            </div>
                                        ))}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </Card>
    );
}
