import { Button } from '@zekini/ui';
import { useState } from 'react';

type Room = { id: number; name: string };
type Props = {
    apiBase: string;
    headers: () => Record<string, string>;
    room: Room;
    rooms: Room[];
    photos: { id: number; label: string | null; taken_at: string | null }[];
    onChanged: () => void;
};

const FIELD = 'rounded border border-slate-300 p-1 text-sm';

/** Staff tidying after a capture: rename a room, merge it into another, or split photos out of it. */
export default function RoomTools({ apiBase, headers, room, rooms, photos, onChanged }: Props) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState(room.name);
    const [target, setTarget] = useState('');
    const [splitName, setSplitName] = useState('');
    const [chosen, setChosen] = useState<number[]>([]);
    const [error, setError] = useState<string | null>(null);

    async function send(method: string, path: string, body: object): Promise<void> {
        setError(null);

        try {
            const response = await fetch(`${apiBase}/rooms/${room.id}${path}`, {
                method,
                headers: { ...headers(), 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });

            if (!response.ok) {
                const failure = (await response.json().catch(() => null)) as { message?: string } | null;
                setError(failure?.message ?? 'The room could not be changed.');
                return;
            }
        } catch {
            setError('No connection. Try again.');
            return;
        }

        setChosen([]);
        setSplitName('');
        setOpen(false);
        onChanged();
    }

    if (!open) {
        return (
            <button type="button" className="text-xs underline" onClick={() => setOpen(true)}>
                Edit room
            </button>
        );
    }

    return (
        <div className="mt-2 space-y-2 rounded bg-slate-50 p-2 text-sm">
            {error !== null && <p role="alert" className="text-red-600">{error}</p>}
            <div className="flex flex-wrap gap-2">
                <input className={FIELD} aria-label="Room name" value={name} onChange={(event) => setName(event.target.value)} />
                <Button variant="secondary" disabled={name.trim() === ''} onClick={() => void send('PUT', '', { name: name.trim() })}>
                    Rename
                </Button>
            </div>
            <div className="flex flex-wrap gap-2">
                <select className={FIELD} aria-label="Merge into" value={target} onChange={(event) => setTarget(event.target.value)}>
                    <option value="">Merge into…</option>
                    {rooms.filter((other) => other.id !== room.id).map((other) => (
                        <option key={other.id} value={other.id}>{other.name}</option>
                    ))}
                </select>
                <Button
                    variant="secondary"
                    disabled={target === ''}
                    onClick={() => void send('POST', '/merge', { into_room_id: Number(target) })}
                >
                    Merge
                </Button>
            </div>
            <fieldset>
                <legend>Split: choose the photos that belong to another room</legend>
                {photos.map((photo, index) => (
                    <label key={photo.id} className="mr-3 inline-block">
                        <input
                            type="checkbox"
                            className="mr-1"
                            checked={chosen.includes(photo.id)}
                            onChange={(event) =>
                                setChosen((ids) => (event.target.checked ? [...ids, photo.id] : ids.filter((id) => id !== photo.id)))
                            }
                        />
                        {index + 1}. {photo.label ?? 'Photo'}
                    </label>
                ))}
                <div className="mt-1 flex flex-wrap gap-2">
                    <input
                        className={FIELD}
                        aria-label="New room name"
                        placeholder="New room name"
                        value={splitName}
                        onChange={(event) => setSplitName(event.target.value)}
                    />
                    <Button
                        variant="secondary"
                        disabled={chosen.length === 0 || splitName.trim() === ''}
                        onClick={() => void send('POST', '/split', { name: splitName.trim(), photo_ids: chosen })}
                    >
                        Split
                    </Button>
                </div>
            </fieldset>
            <button type="button" className="text-xs underline" onClick={() => setOpen(false)}>Close</button>
        </div>
    );
}
