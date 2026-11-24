import { Button, Card } from '@zekini/ui';
import { useCallback, useEffect, useState } from 'react';

type Room = {
    id: number;
    organisation_id: number;
    property_id: number;
    name: string;
    room_type: string;
    sort_order: number;
    notes: string | null;
};

type Property = {
    id: number;
    organisation_id: number;
    name: string;
    reference_code: string | null;
    property_type: string;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    province: string | null;
    postal_code: string | null;
    status: string;
    rooms: Room[];
};

type Lease = {
    id: number;
    organisation_id: number;
    property_id: number;
    lease_reference: string;
    tenant_name: string;
    start_date: string;
    end_date: string | null;
    status: string;
};

type Props = {
    apiBase: string;
    organisationId: number | null;
    csrfToken?: string;
    refreshKey?: number;
    onChanged?: () => void;
};

const PROPERTY_TYPES = ['residential_long', 'residential_short', 'commercial', 'industrial', 'land'] as const;
const PROPERTY_STATUSES = ['active', 'inactive', 'archived'] as const;
const ROOM_TYPES = ['bedroom', 'bathroom', 'kitchen', 'living', 'dining', 'garage', 'outdoor', 'other'] as const;
const LEASE_STATUSES = ['active', 'expired', 'terminated'] as const;

function label(value: string): string {
    return value
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

async function failureMessages(response: Response, fallback: string): Promise<string[]> {
    const payload = (await response.json().catch(() => null)) as
        | { errors?: Record<string, string[]> | string[]; message?: string }
        | null;

    if (Array.isArray(payload?.errors)) {
        return payload.errors;
    }

    if (payload?.errors !== undefined) {
        return Object.values(payload.errors).flat();
    }

    return [payload?.message ?? fallback];
}

export default function SetupPanel({ apiBase, organisationId, csrfToken = '', refreshKey = 0, onChanged }: Props) {
    const [properties, setProperties] = useState<Property[]>([]);
    const [propertyId, setPropertyId] = useState<number | null>(null);
    const [rooms, setRooms] = useState<Room[]>([]);
    const [leases, setLeases] = useState<Lease[]>([]);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [propertyErrors, setPropertyErrors] = useState<string[]>([]);
    const [roomErrors, setRoomErrors] = useState<string[]>([]);
    const [leaseErrors, setLeaseErrors] = useState<string[]>([]);

    const [name, setName] = useState('');
    const [propertyType, setPropertyType] = useState<string>(PROPERTY_TYPES[0]);
    const [referenceCode, setReferenceCode] = useState('');
    const [addressLine1, setAddressLine1] = useState('');
    const [city, setCity] = useState('');
    const [province, setProvince] = useState('');
    const [postalCode, setPostalCode] = useState('');
    const [propertyStatus, setPropertyStatus] = useState<string>(PROPERTY_STATUSES[0]);

    const [roomName, setRoomName] = useState('');
    const [roomType, setRoomType] = useState<string>(ROOM_TYPES[0]);
    const [sortOrder, setSortOrder] = useState('0');

    const [leaseReference, setLeaseReference] = useState('');
    const [tenantName, setTenantName] = useState('');
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [leaseStatus, setLeaseStatus] = useState<string>(LEASE_STATUSES[0]);

    const headers = useCallback((): Record<string, string> => {
        const result: Record<string, string> = { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken };
        if (organisationId !== null) {
            result['X-Organisation-Id'] = String(organisationId);
        }

        return result;
    }, [csrfToken, organisationId]);

    function send(method: 'POST', url: string, body: unknown): Promise<Response> {
        return fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { ...headers(), 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
    }

    useEffect(() => {
        fetch(`${apiBase}/properties`, { headers: headers(), credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('load');
                }

                return response.json() as Promise<{ data: Property[] }>;
            })
            .then((payload) => {
                setProperties(payload.data);
                setPropertyId((current) =>
                    current !== null && payload.data.some((property) => property.id === current)
                        ? current
                        : payload.data[0]?.id ?? null,
                );
                setLoadError(null);
            })
            .catch(() => setLoadError('Could not load properties.'));
    }, [apiBase, headers, refreshKey]);

    useEffect(() => {
        if (propertyId === null) {
            setRooms([]);
            setLeases([]);
            return;
        }

        fetch(`${apiBase}/properties/${propertyId}/rooms`, { headers: headers(), credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('load');
                }

                return response.json() as Promise<{ data: Room[] }>;
            })
            .then((payload) => setRooms(payload.data))
            .catch(() => setLoadError('Could not load rooms.'));

        fetch(`${apiBase}/properties/${propertyId}/leases`, { headers: headers(), credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('load');
                }

                return response.json() as Promise<{ data: Lease[] }>;
            })
            .then((payload) => setLeases(payload.data))
            .catch(() => setLoadError('Could not load leases.'));
    }, [apiBase, headers, propertyId, refreshKey]);

    async function createProperty(): Promise<void> {
        setPropertyErrors([]);
        const response = await send('POST', `${apiBase}/properties`, {
            name,
            property_type: propertyType,
            reference_code: referenceCode === '' ? null : referenceCode,
            address_line_1: addressLine1 === '' ? null : addressLine1,
            address_line_2: null,
            city: city === '' ? null : city,
            province: province === '' ? null : province,
            postal_code: postalCode === '' ? null : postalCode,
            status: propertyStatus,
        });

        if (!response.ok) {
            setPropertyErrors(await failureMessages(response, 'Could not create the property.'));
            return;
        }

        const payload = (await response.json()) as { data: Property };
        setProperties((current) => [payload.data, ...current]);
        setPropertyId(payload.data.id);
        setName('');
        setReferenceCode('');
        setAddressLine1('');
        setCity('');
        setProvince('');
        setPostalCode('');
        onChanged?.();
    }

    async function createRoom(): Promise<void> {
        if (propertyId === null) {
            setRoomErrors(['Choose a property first.']);
            return;
        }

        setRoomErrors([]);
        const response = await send('POST', `${apiBase}/properties/${propertyId}/rooms`, {
            name: roomName,
            room_type: roomType,
            sort_order: Number(sortOrder),
        });

        if (!response.ok) {
            setRoomErrors(await failureMessages(response, 'Could not add the room.'));
            return;
        }

        const payload = (await response.json()) as { data: Room };
        setRooms((current) => [...current, payload.data]);
        setRoomName('');
        onChanged?.();
    }

    async function createLease(): Promise<void> {
        if (propertyId === null) {
            setLeaseErrors(['Choose a property first.']);
            return;
        }

        setLeaseErrors([]);
        const response = await send('POST', `${apiBase}/properties/${propertyId}/leases`, {
            lease_reference: leaseReference,
            tenant_name: tenantName,
            start_date: startDate,
            end_date: endDate === '' ? null : endDate,
            status: leaseStatus,
        });

        if (!response.ok) {
            setLeaseErrors(await failureMessages(response, 'Could not add the lease.'));
            return;
        }

        const payload = (await response.json()) as { data: Lease };
        setLeases((current) => [payload.data, ...current]);
        setLeaseReference('');
        setTenantName('');
        setStartDate('');
        setEndDate('');
        onChanged?.();
    }

    const fieldClass = 'mt-1 w-full rounded border border-slate-300 p-2 text-sm';

    return (
        <Card>
            <h2 id="setup" className="text-lg font-semibold">Property setup</h2>
            <p className="mt-1 text-sm text-slate-500">
                Create the property, its rooms and its lease here. Each record belongs to the organisation shown beside it.
            </p>
            {loadError !== null && <p role="alert" className="mt-3 text-sm text-red-600">{loadError}</p>}

            <div className="mt-5 grid gap-6 lg:grid-cols-3">
                <section className="space-y-3">
                    <h3 className="font-medium">New property</h3>
                    {propertyErrors.length > 0 && (
                        <ul role="alert" className="space-y-1 text-sm text-red-600">
                            {propertyErrors.map((message) => <li key={message}>{message}</li>)}
                        </ul>
                    )}
                    <label className="block text-sm">
                        Name
                        <input id="setup-property-name" className={fieldClass} value={name} onChange={(event) => setName(event.target.value)} />
                    </label>
                    <label className="block text-sm">
                        Type
                        <select className={fieldClass} value={propertyType} onChange={(event) => setPropertyType(event.target.value)}>
                            {PROPERTY_TYPES.map((type) => <option key={type} value={type}>{label(type)}</option>)}
                        </select>
                    </label>
                    <label className="block text-sm">
                        Reference
                        <input className={fieldClass} value={referenceCode} onChange={(event) => setReferenceCode(event.target.value)} />
                    </label>
                    <label className="block text-sm">
                        Address
                        <input className={fieldClass} value={addressLine1} onChange={(event) => setAddressLine1(event.target.value)} />
                    </label>
                    <div className="grid grid-cols-2 gap-2">
                        <label className="block text-sm">
                            City
                            <input className={fieldClass} value={city} onChange={(event) => setCity(event.target.value)} />
                        </label>
                        <label className="block text-sm">
                            Province
                            <input className={fieldClass} value={province} onChange={(event) => setProvince(event.target.value)} />
                        </label>
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <label className="block text-sm">
                            Postal code
                            <input className={fieldClass} value={postalCode} onChange={(event) => setPostalCode(event.target.value)} />
                        </label>
                        <label className="block text-sm">
                            Status
                            <select className={fieldClass} value={propertyStatus} onChange={(event) => setPropertyStatus(event.target.value)}>
                                {PROPERTY_STATUSES.map((status) => <option key={status} value={status}>{label(status)}</option>)}
                            </select>
                        </label>
                    </div>
                    <Button onClick={() => void createProperty()}>Create property</Button>
                </section>

                <section className="space-y-3">
                    <h3 className="font-medium">Rooms</h3>
                    {roomErrors.length > 0 && (
                        <ul role="alert" className="space-y-1 text-sm text-red-600">
                            {roomErrors.map((message) => <li key={message}>{message}</li>)}
                        </ul>
                    )}
                    <label className="block text-sm">
                        Property
                        <select
                            className={fieldClass}
                            value={propertyId ?? ''}
                            onChange={(event) => setPropertyId(event.target.value === '' ? null : Number(event.target.value))}
                        >
                            <option value="">Select…</option>
                            {properties.map((property) => (
                                <option key={property.id} value={property.id}>{property.name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="block text-sm">
                        Room name
                        <input className={fieldClass} value={roomName} onChange={(event) => setRoomName(event.target.value)} />
                    </label>
                    <label className="block text-sm">
                        Room type
                        <select className={fieldClass} value={roomType} onChange={(event) => setRoomType(event.target.value)}>
                            {ROOM_TYPES.map((type) => <option key={type} value={type}>{label(type)}</option>)}
                        </select>
                    </label>
                    <label className="block text-sm">
                        Sort order
                        <input type="number" min="0" className={fieldClass} value={sortOrder} onChange={(event) => setSortOrder(event.target.value)} />
                    </label>
                    <Button onClick={() => void createRoom()}>Add room</Button>
                    <ul className="space-y-1 text-sm">
                        {rooms.map((room) => (
                            <li key={room.id}>
                                {room.name} · {label(room.room_type)} · <span className="text-slate-500">Org #{room.organisation_id}</span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="space-y-3">
                    <h3 className="font-medium">Leases</h3>
                    {leaseErrors.length > 0 && (
                        <ul role="alert" className="space-y-1 text-sm text-red-600">
                            {leaseErrors.map((message) => <li key={message}>{message}</li>)}
                        </ul>
                    )}
                    <label className="block text-sm">
                        Reference
                        <input className={fieldClass} value={leaseReference} onChange={(event) => setLeaseReference(event.target.value)} />
                    </label>
                    <label className="block text-sm">
                        Tenant
                        <input className={fieldClass} value={tenantName} onChange={(event) => setTenantName(event.target.value)} />
                    </label>
                    <div className="grid grid-cols-2 gap-2">
                        <label className="block text-sm">
                            Start date
                            <input type="date" className={fieldClass} value={startDate} onChange={(event) => setStartDate(event.target.value)} />
                        </label>
                        <label className="block text-sm">
                            End date
                            <input type="date" className={fieldClass} value={endDate} onChange={(event) => setEndDate(event.target.value)} />
                        </label>
                    </div>
                    <label className="block text-sm">
                        Status
                        <select className={fieldClass} value={leaseStatus} onChange={(event) => setLeaseStatus(event.target.value)}>
                            {LEASE_STATUSES.map((status) => <option key={status} value={status}>{label(status)}</option>)}
                        </select>
                    </label>
                    <Button onClick={() => void createLease()}>Add lease</Button>
                    <ul className="space-y-1 text-sm">
                        {leases.map((lease) => (
                            <li key={lease.id}>
                                {lease.lease_reference} · {lease.tenant_name} · {label(lease.status)} ·{' '}
                                <span className="text-slate-500">Org #{lease.organisation_id}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>

            <h3 className="mt-6 font-medium">Properties</h3>
            {properties.length === 0 ? (
                <p className="mt-2 text-sm text-slate-500">No properties yet. Create one above to get started.</p>
            ) : (
                <ul className="mt-2 space-y-2 text-sm">
                    {properties.map((property) => (
                        <li key={property.id} className="rounded border border-slate-200 p-3">
                            <div className="font-medium">
                                {property.name} <span className="font-normal text-slate-500">Org #{property.organisation_id}</span>
                            </div>
                            <div className="text-slate-600">
                                {label(property.property_type)} · {property.status}
                                {property.address_line_1 !== null && ` · ${property.address_line_1}`}
                                {property.city !== null && `, ${property.city}`}
                            </div>
                            <div className="text-slate-500">
                                {property.rooms?.length ?? 0} room(s)
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
