import { Card } from '@zekini/ui';
import { useCallback, useEffect, useRef, useState } from 'react';
import { flushCaptures, queueCapture, queuedCaptures } from './offline';

type Photo = { id: number; taken_at: string | null };
type Area = { id: number; name: string; photos: Photo[] };
type Capture = {
    capture_key: string;
    property_name: string;
    tenant_name: string;
    phase: string;
    expires_at: string;
    areas: Area[];
};

type Props = { apiBase: string; token: string };

const RETRY_MILLISECONDS = 30_000;

function Thumbnail({ apiBase, token, photoId, label }: { apiBase: string; token: string; photoId: number; label: string }) {
    const [url, setUrl] = useState<string | null>(null);

    useEffect(() => {
        let created: string | null = null;
        let cancelled = false;

        fetch(`${apiBase}/capture/photos/${photoId}`, { credentials: 'omit', headers: { 'X-Capture-Token': token } })
            .then((response) => (response.ok ? response.blob() : Promise.reject(new Error('load'))))
            .then((blob) => {
                if (!cancelled) {
                    created = URL.createObjectURL(blob);
                    setUrl(created);
                }
            })
            .catch(() => undefined);

        return () => {
            cancelled = true;

            if (created !== null) {
                URL.revokeObjectURL(created);
            }
        };
    }, [apiBase, token, photoId]);

    return url === null ? (
        <div className="h-20 w-28 rounded bg-slate-100" />
    ) : (
        <img src={url} alt={label} className="h-20 w-28 rounded object-cover" />
    );
}

/** Public, account-less photo capture reached from a link the tenant was sent. */
export default function CapturePage({ apiBase, token }: Props) {
    const [capture, setCapture] = useState<Capture | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);
    const [waiting, setWaiting] = useState<number[]>([]);
    const [error, setError] = useState<string | null>(null);
    const syncing = useRef(false);

    const load = useCallback(async (): Promise<void> => {
        try {
            const response = await fetch(`${apiBase}/capture`, {
                credentials: 'omit',
                headers: { Accept: 'application/json', 'X-Capture-Token': token },
            });
            const payload = (await response.json().catch(() => null)) as { data?: Capture; message?: string } | null;

            if (!response.ok || payload?.data === undefined) {
                setRefusal(payload?.message ?? 'We could not load this link. Try again shortly.');
                return;
            }

            setRefusal(null);
            setCapture(payload.data);
        } catch {
            setRefusal('You are offline. This page loads again when you reconnect.');
        }
    }, [apiBase, token]);

    const captureKey = capture?.capture_key ?? null;

    const sync = useCallback(async (): Promise<void> => {
        if (captureKey === null || syncing.current) {
            return;
        }

        syncing.current = true;

        try {
            let uploaded = 0;
            let result;

            // Repeats so that a photo added while a pass was running is not left waiting.
            do {
                result = await flushCaptures(apiBase, token, captureKey);
                uploaded += result.uploaded;

                if (result.errors.length > 0) {
                    setError(result.errors.join(' '));
                }
            } while (result.uploaded > 0);

            setWaiting((await queuedCaptures(captureKey)).map((pending) => pending.areaId));

            if (uploaded > 0) {
                await load();
            }
        } finally {
            syncing.current = false;
        }
    }, [apiBase, token, captureKey, load]);

    useEffect(() => {
        void load();
    }, [load]);

    useEffect(() => {
        void sync();

        const retry = (): void => void sync();
        const timer = window.setInterval(retry, RETRY_MILLISECONDS);
        window.addEventListener('online', retry);

        return () => {
            window.clearInterval(timer);
            window.removeEventListener('online', retry);
        };
    }, [sync]);

    useEffect(() => {
        if (capture !== null) {
            return;
        }

        const reload = (): void => void load();
        window.addEventListener('online', reload);

        return () => window.removeEventListener('online', reload);
    }, [capture, load]);

    async function add(areaId: number, files: File[]): Promise<void> {
        if (captureKey === null) {
            return;
        }

        setError(null);

        try {
            // Saved on the phone first, so a dropped connection or a closed tab loses nothing.
            for (const file of files) {
                await queueCapture({ captureKey, areaId, file, fileName: file.name });
            }
        } catch {
            setError('This phone could not save the photo. Free some space and try again.');
            return;
        }

        setWaiting((await queuedCaptures(captureKey)).map((pending) => pending.areaId));
        await sync();
    }

    return (
        <main className="min-h-screen bg-slate-50 p-4 text-slate-900">
            <div className="mx-auto max-w-lg space-y-4">
                <Card>
                    <h1 className="text-xl font-bold">Photograph your property</h1>
                    {refusal !== null && <p role="alert" className="mt-3 text-sm text-red-600">{refusal}</p>}
                    {capture === null && refusal === null && <p className="mt-3 text-sm text-slate-500">Loading…</p>}
                    {capture !== null && (
                        <p className="mt-3 text-sm text-slate-600">
                            {capture.property_name} · {capture.tenant_name} · {capture.phase} photos. You can stop at any time
                            and continue with this link until {new Date(capture.expires_at).toLocaleDateString()}.
                        </p>
                    )}
                    {waiting.length > 0 && (
                        <p role="status" className="mt-2 text-sm text-amber-700">
                            {waiting.length} photo{waiting.length === 1 ? '' : 's'} saved on this phone, waiting to upload.
                        </p>
                    )}
                    {error !== null && <p role="alert" className="mt-2 text-sm text-red-600">{error}</p>}
                </Card>
                {capture?.areas.length === 0 && <Card><p className="text-sm">No rooms have been set up for this property yet.</p></Card>}
                {capture?.areas.map((area) => (
                    <Card key={area.id}>
                        <h2 className="font-semibold">{area.name}</h2>
                        <p className="text-sm text-slate-500">
                            {area.photos.length} uploaded
                            {waiting.includes(area.id) && `, ${waiting.filter((id) => id === area.id).length} waiting`}
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {area.photos.map((photo, index) => (
                                <Thumbnail key={photo.id} apiBase={apiBase} token={token} photoId={photo.id} label={`${area.name} photo ${index + 1}`} />
                            ))}
                        </div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <label className="cursor-pointer rounded bg-slate-900 px-3 py-2 text-sm text-white">
                                Take photo
                                <input
                                    type="file"
                                    accept="image/*"
                                    capture="environment"
                                    className="sr-only"
                                    aria-label={`Take photo of ${area.name}`}
                                    onChange={(event) => {
                                        const files = Array.from(event.target.files ?? []);
                                        event.target.value = '';
                                        void add(area.id, files);
                                    }}
                                />
                            </label>
                            <label className="cursor-pointer rounded border border-slate-300 px-3 py-2 text-sm">
                                Choose photos
                                <input
                                    type="file"
                                    accept="image/*"
                                    multiple
                                    className="sr-only"
                                    aria-label={`Choose photos of ${area.name}`}
                                    onChange={(event) => {
                                        const files = Array.from(event.target.files ?? []);
                                        event.target.value = '';
                                        void add(area.id, files);
                                    }}
                                />
                            </label>
                        </div>
                    </Card>
                ))}
            </div>
        </main>
    );
}
