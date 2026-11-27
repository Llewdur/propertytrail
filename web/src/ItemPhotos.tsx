import { Button } from '@zekini/ui';
import { useEffect, useRef, useState } from 'react';
import { compressImage } from './compressImage';
import { queuePhoto, queuedPhotos, type PendingPhoto } from './offline';

export type ItemPhoto = {
    link_id: number;
    evidence_id: number;
    caption: string | null;
    file_name: string;
    mime_type: string;
    taken_at: string | null;
    replaces_link_id: number | null;
    superseded: boolean;
};

type Props = {
    apiBase: string;
    headers: () => Record<string, string>;
    inspectionId: number;
    itemId: number;
    photos: ItemPhoto[];
    editable: boolean;
    onChanged: () => void;
    syncKey?: number;
    onSyncChanged?: () => void;
};

export function useBlobUrl(apiBase: string, headers: () => Record<string, string>, evidenceId: number): string | null {
    const [url, setUrl] = useState<string | null>(null);

    useEffect(() => {
        let created: string | null = null;
        let cancelled = false;

        fetch(`${apiBase}/evidence/${evidenceId}/download`, { headers: headers() })
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
    }, [apiBase, headers, evidenceId]);

    return url;
}

function Thumbnail({ apiBase, headers, photo }: { apiBase: string; headers: () => Record<string, string>; photo: ItemPhoto }) {
    const url = useBlobUrl(apiBase, headers, photo.evidence_id);

    return (
        <figure className="w-28 text-xs">
            {url !== null ? (
                <img src={url} alt={photo.caption ?? photo.file_name} className="h-20 w-28 rounded object-cover" />
            ) : (
                <div className="h-20 w-28 rounded bg-slate-100" />
            )}
            <figcaption className="mt-1 truncate">{photo.caption ?? photo.file_name}</figcaption>
            {url !== null && (
                <a className="underline" href={url} download={photo.file_name}>Download</a>
            )}
        </figure>
    );
}

export default function ItemPhotos({ apiBase, headers, inspectionId, itemId, photos, editable, onChanged, syncKey, onSyncChanged }: Props) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const [pending, setPending] = useState<File | null>(null);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [caption, setCaption] = useState('');
    const [cameraOn, setCameraOn] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [pendingUploads, setPendingUploads] = useState<PendingPhoto[]>([]);
    const [replacing, setReplacing] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);

    const current = photos.filter((photo) => !photo.superseded);

    useEffect(() => () => stopCamera(), []);

    useEffect(() => {
        let cancelled = false;

        void queuedPhotos(inspectionId).then((queued) => {
            if (!cancelled) {
                setPendingUploads(queued.filter((photo) => photo.itemId === itemId));
            }
        });

        return () => {
            cancelled = true;
        };
    }, [inspectionId, itemId, syncKey]);

    useEffect(() => {
        if (pending === null) {
            setPreviewUrl(null);
            return;
        }

        const url = URL.createObjectURL(pending);
        setPreviewUrl(url);

        return () => URL.revokeObjectURL(url);
    }, [pending]);

    function stopCamera(): void {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        setCameraOn(false);
    }

    async function startCamera(replaceLinkId: number | null = null): Promise<void> {
        stopCamera();
        setError(null);
        setReplacing(replaceLinkId);

        if (!navigator.mediaDevices?.getUserMedia) {
            setReplacing(null);
            setError('Live camera is not available here; use Take photo instead.');
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
            streamRef.current = stream;
            setCameraOn(true);
            window.setTimeout(() => {
                if (videoRef.current !== null) {
                    videoRef.current.srcObject = stream;
                    void videoRef.current.play();
                }
            }, 0);
        } catch {
            setReplacing(null);
            setError('Camera permission was denied; use Take photo instead.');
        }
    }

    function snap(): void {
        const video = videoRef.current;

        if (video === null || video.videoWidth === 0) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d')?.drawImage(video, 0, 0);
        canvas.toBlob((blob) => {
            if (blob !== null) {
                setPending(new File([blob], `item-${itemId}-${Date.now()}.jpg`, { type: 'image/jpeg' }));
                stopCamera();
            }
        }, 'image/jpeg', 0.9);
    }

    function discard(): void {
        setPending(null);
        setCaption('');
        setReplacing(null);
    }

    async function accept(): Promise<void> {
        if (pending === null || saving) {
            return;
        }

        setSaving(true);
        setError(null);
        setNotice(null);

        try {
            await upload(await compressImage(pending));
        } catch {
            setError('Could not save the photo. Check your connection and try again.');
        } finally {
            setSaving(false);
        }
    }

    async function upload(file: File): Promise<void> {
        const form = new FormData();
        form.append('file', file);
        form.append('attachable_type', 'inspection_item');
        form.append('attachable_id', String(itemId));

        if (caption.trim() !== '') {
            form.append('label', caption.trim());
        }

        if (replacing !== null) {
            form.append('replaces_link_id', String(replacing));
        }

        try {
            const response = await fetch(`${apiBase}/evidence/upload`, { method: 'POST', headers: headers(), body: form });

            if (!response.ok) {
                const failure = (await response.json().catch(() => null)) as { message?: string } | null;
                setError(failure?.message ?? 'Could not save the photo.');
                return;
            }
        } catch {
            await queuePhoto({
                inspectionId,
                itemId,
                file,
                fileName: file.name,
                label: caption.trim() === '' ? null : caption.trim(),
                replacesLinkId: replacing,
            });
            const queued = await queuedPhotos(inspectionId);
            setPendingUploads(queued.filter((photo) => photo.itemId === itemId));
            setNotice('Photo saved on this device. It will sync when you are back online.');
            discard();
            onSyncChanged?.();
            return;
        }

        discard();
        setError(null);
        onChanged();
    }

    return (
        <div className="mt-3">
            {error !== null && <p role="alert" className="text-xs text-red-600">{error}</p>}
            {notice !== null && <p role="status" className="text-xs text-emerald-700">{notice}</p>}
            {pendingUploads.length > 0 && (
                <p role="status" className="text-xs text-amber-700">
                    {pendingUploads.length} photo{pendingUploads.length === 1 ? '' : 's'} waiting to sync.
                </p>
            )}
            {current.length > 0 && (
                <div className="flex flex-wrap gap-3">
                    {current.map((photo) => (
                        <div key={photo.link_id}>
                            <Thumbnail apiBase={apiBase} headers={headers} photo={photo} />
                            {editable && !cameraOn && pending === null && (
                                <button
                                    type="button"
                                    className="text-xs underline"
                                    onClick={() => {
                                        setCaption(photo.caption ?? '');
                                        void startCamera(photo.link_id);
                                    }}
                                >
                                    Retake
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            )}
            {editable && pending === null && !cameraOn && (
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    <Button variant="secondary" onClick={() => void startCamera()}>Open camera</Button>
                    <label className="cursor-pointer rounded border border-slate-300 px-3 py-1 text-sm">
                        Take photo
                        <input
                            type="file"
                            accept="image/*"
                            capture="environment"
                            className="sr-only"
                            onChange={(event) => {
                                const file = event.target.files?.[0] ?? null;
                                event.target.value = '';

                                if (file !== null) {
                                    setPending(file);
                                }
                            }}
                        />
                    </label>
                </div>
            )}
            {cameraOn && (
                <div className="mt-2 space-y-2">
                    <video ref={videoRef} playsInline muted className="w-full max-w-sm rounded bg-black" />
                    <div className="flex gap-2">
                        <Button onClick={snap}>Capture</Button>
                        <Button variant="secondary" onClick={() => { stopCamera(); setReplacing(null); }}>Cancel</Button>
                    </div>
                </div>
            )}
            {pending !== null && previewUrl !== null && (
                <div className="mt-2 space-y-2">
                    <img src={previewUrl} alt="Photo preview" className="max-h-48 rounded" />
                    <input
                        className="w-full rounded border border-slate-300 p-2 text-sm"
                        placeholder="Caption (e.g. Cupboard door hinge loose)"
                        value={caption}
                        onChange={(event) => setCaption(event.target.value)}
                    />
                    <div className="flex gap-2">
                        <Button disabled={saving} onClick={() => void accept()}>Use photo</Button>
                        <Button variant="secondary" onClick={discard}>Retake</Button>
                    </div>
                </div>
            )}
        </div>
    );
}
