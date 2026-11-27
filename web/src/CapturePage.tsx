import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import CameraScreen from './CameraScreen';
import { loadFlow, NEW_FLOW, newRoomKey, resumeStep, saveFlow, type Flow, type RoomRef, type Shot } from './captureFlow';
import { compressImage } from './compressImage';
import { dropCapture, flushCaptures, queueCapture, queuedCaptures, releaseCaptures } from './offline';

type Area = { id: number; name: string; photos: { id: number }[] };
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
const COMMON_ROOMS = [
    { name: 'Kitchen', outdoor: false },
    { name: 'Bedroom', outdoor: false },
    { name: 'Bathroom', outdoor: false },
    { name: 'Lounge', outdoor: false },
    { name: 'Garden', outdoor: true },
    { name: 'Garage', outdoor: false },
];
const SUGGESTIONS = 6;

const PRIMARY = 'block w-full rounded-lg bg-slate-900 px-4 py-4 text-lg font-semibold text-white disabled:opacity-40';
const SECONDARY = 'block w-full rounded-lg border border-slate-300 bg-white px-4 py-3';
const LINK = 'block w-full py-2 text-sm underline';

const sameRoom = (name: string, other: string): boolean => name.trim().toLowerCase() === other.trim().toLowerCase();
const roomMark = (room: RoomRef): string => room.key ?? `id:${room.id}`;

/** One short request and one action, sized so a phone never has to scroll. */
function Screen({ progress, onStop, notice, children, actions }: {
    progress?: string;
    onStop?: () => void;
    notice?: ReactNode;
    children: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <main className="mx-auto flex min-h-[100dvh] max-w-lg flex-col gap-3 bg-slate-50 p-4 text-slate-900">
            <div className="flex items-center justify-between gap-2 text-sm text-slate-500">
                <span className="truncate">{progress}</span>
                {onStop !== undefined && <button type="button" className="shrink-0 underline" onClick={onStop}>Stop</button>}
            </div>
            <div className="flex flex-1 flex-col items-center justify-center gap-3 text-center">{children}</div>
            {notice}
            <div className="space-y-2">{actions}</div>
        </main>
    );
}

const Request = ({ children }: { children: ReactNode }) => <h1 className="text-2xl font-bold">{children}</h1>;

/** Where to stand and which way to aim. Hidden when the screen is too short for it. */
function CornerPicture() {
    return (
        <svg viewBox="0 0 100 80" className="h-24 text-slate-700 [@media(max-height:600px)]:hidden" aria-hidden="true">
            <rect x="4" y="4" width="92" height="72" fill="none" stroke="currentColor" strokeWidth="2" />
            <circle cx="14" cy="66" r="6" fill="currentColor" />
            <path d="M22 60 L80 18 M80 18 L66 20 M80 18 L74 31" fill="none" stroke="currentColor" strokeWidth="3" />
        </svg>
    );
}

/** Public, account-less guided capture reached from a link the tenant was sent. */
export default function CapturePage({ apiBase, token }: Props) {
    const [capture, setCapture] = useState<Capture | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);
    const [waiting, setWaiting] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const [flow, setFlow] = useState<Flow>(NEW_FLOW);
    const [resumeAt, setResumeAt] = useState<Flow | null>(null);
    const [name, setName] = useState('');
    const [outdoor, setOutdoor] = useState(false);
    const [taken, setTaken] = useState<{ id: string; url: string } | null>(null);
    const [saving, setSaving] = useState(false);
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
            let errors: string[] = [];

            // Repeats so that a photo added while a pass was running is not left waiting.
            do {
                result = await flushCaptures(apiBase, token, captureKey);
                uploaded += result.uploaded;
                errors = result.errors;
            } while (result.uploaded > 0);

            setError(errors.length > 0 ? errors[0] : null);
            setWaiting((await queuedCaptures(captureKey)).filter((pending) => pending.held !== true).length);

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
        if (captureKey === null) {
            return;
        }

        const saved = loadFlow(captureKey);
        setResumeAt(saved === null || saved.step === 'intro' ? null : saved);
        // A photo left held by a closed tab was taken, so it goes up.
        void releaseCaptures(captureKey).then(sync);
    }, [captureKey, sync]);

    useEffect(() => {
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

    useEffect(() => () => {
        if (taken !== null) {
            URL.revokeObjectURL(taken.url);
        }
    }, [taken]);

    function go(patch: Partial<Flow>): void {
        const next = { ...flow, ...patch };
        setFlow(next);

        if (captureKey !== null && next.step !== 'intro') {
            saveFlow(captureKey, next);
        }
    }

    /** Any choice other than a retake keeps the photo just taken. */
    async function keep(patch: Partial<Flow>): Promise<void> {
        go(patch);
        setTaken(null);

        if (captureKey !== null) {
            await releaseCaptures(captureKey);
            await sync();
        }
    }

    async function retake(): Promise<void> {
        if (taken !== null) {
            await dropCapture(taken.id);
            setTaken(null);
        }

        go({ step: 'camera' });
    }

    async function save(raw: File): Promise<void> {
        const room = flow.room;

        if (captureKey === null || room === null || saving) {
            return;
        }

        setSaving(true);

        try {
            const file = await compressImage(raw);
            // Saved on the phone first, so a dropped connection or a closed tab loses nothing.
            const id = await queueCapture({
                captureKey,
                areaId: room.id,
                roomKey: room.key,
                roomName: room.name,
                roomOutdoor: room.outdoor,
                adjoinsId: room.adjoinsId,
                adjoinsKey: room.adjoinsKey,
                corner: flow.shot === 'extra' ? null : flow.corner,
                shot: flow.shot,
                held: true,
                file,
                fileName: file.name,
            });
            setTaken({ id, url: URL.createObjectURL(file) });
            setError(null);
            go({ step: 'taken' });
        } catch {
            setError('This phone could not save the photo. Free some space and try again.');
            go({ step: 'corner' });
        } finally {
            setSaving(false);
        }
    }

    const shoot = (shot: Shot): void => go({ step: 'camera', shot });

    function chooseRoom(chosenName: string, isOutdoor: boolean): void {
        // The name of a room still waiting for photos means that room. A second "Bedroom" is a new one.
        const known = capture?.areas.find(
            (area) => area.photos.length === 0 && sameRoom(area.name, chosenName) && !flow.finished.includes(`id:${area.id}`),
        );
        const room: RoomRef = {
            id: known?.id ?? null,
            key: known === undefined ? newRoomKey() : null,
            name: known?.name ?? chosenName.trim(),
            outdoor: isOutdoor,
            adjoinsId: null,
            adjoinsKey: null,
        };

        setName('');
        setOutdoor(false);
        go({ room, corner: 1, shot: 'corner', step: flow.previous !== null ? 'walked' : 'corner' });
    }

    function walkedStraight(yes: boolean): void {
        const { room, previous } = flow;

        if (room !== null) {
            go({
                step: 'corner',
                room: yes && previous !== null ? { ...room, adjoinsId: previous.id, adjoinsKey: previous.key } : room,
            });
        }
    }

    function finishRoom(): void {
        const room = flow.room;
        go({
            step: 'next-room',
            previous: room,
            room: null,
            finished: room === null ? flow.finished : [...flow.finished, roomMark(room)],
        });
    }

    const status = (
        <>
            {error !== null && (
                <p role="alert" className="text-center text-sm text-red-600">
                    {error} <button type="button" className="underline" onClick={() => void sync()}>Try again</button>
                </p>
            )}
            {waiting > 0 && (
                <p role="status" className="text-center text-sm text-amber-700">
                    {waiting} photo{waiting === 1 ? '' : 's'} saved on this phone, waiting to upload.
                </p>
            )}
        </>
    );

    if (capture === null || refusal !== null) {
        return (
            <Screen notice={status}>
                {refusal === null ? <p>Loading…</p> : <p role="alert" className="text-red-600">{refusal}</p>}
            </Screen>
        );
    }

    const photographed = capture.areas.filter((area) => area.photos.length > 0);
    const roomNumber = Math.max(flow.finished.length, resumeAt?.finished.length ?? 0) + 1;
    const room = flow.room;
    const progress = room === null ? `Room ${roomNumber}` : `Room ${roomNumber} · ${room.name} · Corner ${flow.corner}`;
    const stop = (): void => {
        setResumeAt(flow);
        setFlow(NEW_FLOW);
        setTaken(null);

        if (captureKey !== null) {
            void releaseCaptures(captureKey).then(sync);
        }
    };
    const shared = { progress, onStop: stop, notice: status };

    if (flow.step === 'intro') {
        const returning = resumeAt !== null || photographed.length > 0;
        const start = (): void => {
            if (resumeAt !== null) {
                setFlow({ ...resumeAt, step: resumeStep(resumeAt.step) });
            } else {
                // A new phone has no saved step, so the rooms already photographed count as done.
                go({ step: 'name', finished: photographed.map((area) => `id:${area.id}`) });
            }
        };

        return (
            <Screen notice={status} actions={<button type="button" className={PRIMARY} onClick={start}>{returning ? 'Continue' : 'Start'}</button>}>
                <Request>{returning ? 'Welcome back' : 'Photograph your property'}</Request>
                <p>{capture.property_name} · {capture.tenant_name} · {capture.phase} photos</p>
                <p className="text-sm text-slate-500">
                    Every room, inside and outside. Stop at any time and continue with this link until{' '}
                    {new Date(capture.expires_at).toLocaleDateString()}.
                </p>
            </Screen>
        );
    }

    if (flow.step === 'name' || room === null && ['walked', 'corner', 'nearest', 'camera', 'taken', 'more', 'room-check'].includes(flow.step)) {
        const unfinished = capture.areas.filter(
            (area) => area.photos.length === 0 && !flow.finished.includes(`id:${area.id}`),
        );
        const suggestions = [
            ...unfinished.map((area) => ({ name: area.name, outdoor: false })),
            ...COMMON_ROOMS.filter((common) => !unfinished.some((area) => sameRoom(area.name, common.name))),
        ].slice(0, SUGGESTIONS);

        return (
            <Screen
                {...shared}
                actions={
                    <button type="button" className={PRIMARY} disabled={name.trim() === ''} onClick={() => chooseRoom(name, outdoor)}>
                        Next
                    </button>
                }
            >
                <Request>Go to room {roomNumber}. What is it called?</Request>
                <input
                    className="w-full rounded-lg border border-slate-300 p-3 text-lg"
                    aria-label="Room name"
                    maxLength={80}
                    placeholder="Room name"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                />
                <label className="text-sm">
                    <input type="checkbox" className="mr-2" checked={outdoor} onChange={(event) => setOutdoor(event.target.checked)} />
                    This is outside
                </label>
                <div className="flex flex-wrap justify-center gap-2">
                    {suggestions.map((suggestion) => (
                        <button
                            key={suggestion.name}
                            type="button"
                            className="rounded-full border border-slate-300 bg-white px-3 py-1 text-sm"
                            onClick={() => {
                                setName(suggestion.name);
                                setOutdoor(suggestion.outdoor);
                            }}
                        >
                            {suggestion.name}
                        </button>
                    ))}
                </div>
            </Screen>
        );
    }

    if (flow.step === 'next-room') {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => go({ step: 'name' })}>I am in the next room</button>
                        <button type="button" className={SECONDARY} onClick={() => go({ step: 'last-check' })}>No more rooms</button>
                    </>
                }
            >
                <Request>Please go to the very next room.</Request>
            </Screen>
        );
    }

    if (flow.step === 'last-check') {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => go({ step: 'done' })}>No, that is everything</button>
                        <button type="button" className={SECONDARY} onClick={() => go({ step: 'name' })}>Yes, there is another</button>
                    </>
                }
            >
                <Request>Is there any room or outside area not done yet?</Request>
            </Screen>
        );
    }

    if (flow.step === 'done' || room === null) {
        return (
            <Screen
                notice={status}
                actions={<button type="button" className={SECONDARY} onClick={() => go({ step: 'name' })}>Add another room</button>}
            >
                <Request>Thank you. All done.</Request>
                <p>{waiting > 0 ? 'Keep this page open until your photos have uploaded.' : 'Your photos have uploaded.'}</p>
            </Screen>
        );
    }

    if (flow.step === 'walked') {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => walkedStraight(true)}>Yes</button>
                        <button type="button" className={SECONDARY} onClick={() => walkedStraight(false)}>No</button>
                    </>
                }
            >
                <Request>Did you walk straight here from {flow.previous?.name}?</Request>
            </Screen>
        );
    }

    if (flow.step === 'camera') {
        return (
            <CameraScreen
                instruction={flow.shot === 'extra' ? 'Photograph the ceiling, the floor or a close-up.' : `Corner ${flow.corner}: aim at the opposite corner.`}
                onPhoto={(file) => void save(file)}
                onCancel={() => go({ step: 'corner', shot: 'corner' })}
            />
        );
    }

    if (flow.step === 'nearest') {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => shoot('nearest')}>Take photo</button>
                        <button type="button" className={SECONDARY} onClick={() => go({ step: 'corner', corner: flow.corner + 1 })}>
                            Skip this corner
                        </button>
                        <button type="button" className={LINK} onClick={() => go({ step: 'room-check' })}>No more corners</button>
                    </>
                }
            >
                <Request>Take it from the doorway or the nearest spot.</Request>
            </Screen>
        );
    }

    if (flow.step === 'taken') {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => void keep({ step: 'corner', corner: flow.corner + 1, shot: 'corner' })}>
                            Next corner
                        </button>
                        <button type="button" className={SECONDARY} onClick={() => void keep({ step: 'room-check' })}>No more corners</button>
                        <button type="button" className={LINK} onClick={() => go({ step: 'more' })}>More choices</button>
                    </>
                }
            >
                <Request>Thank you. Go to the next corner.</Request>
                {taken !== null && <img src={taken.url} alt="The photo you took" className="max-h-[30dvh] rounded" />}
            </Screen>
        );
    }

    if (flow.step === 'more') {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => void keep({ step: 'camera', shot: 'extra' })}>Add another photo</button>
                        {taken !== null && <button type="button" className={SECONDARY} onClick={() => void retake()}>Retake</button>}
                        <button type="button" className={LINK} onClick={() => go({ step: 'taken' })}>Back</button>
                    </>
                }
            >
                <Request>What else do you need?</Request>
            </Screen>
        );
    }

    if (flow.step === 'room-check') {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={finishRoom}>Yes, this room is done</button>
                        <button type="button" className={SECONDARY} onClick={() => shoot('extra')}>No, add a photo</button>
                    </>
                }
            >
                <Request>Is everything in {room.name} photographed?</Request>
            </Screen>
        );
    }

    return (
        <Screen
            {...shared}
            actions={
                <>
                    <button type="button" className={PRIMARY} onClick={() => shoot('corner')}>Take photo</button>
                    <button type="button" className={LINK} onClick={() => go({ step: 'nearest' })}>I can&apos;t stand there</button>
                    {flow.corner > 1 && (
                        <button type="button" className={LINK} onClick={() => go({ step: 'room-check' })}>No more corners</button>
                    )}
                </>
            }
        >
            <Request>Stand in corner {flow.corner} and take a photo of the opposite corner.</Request>
            <CornerPicture />
        </Screen>
    );
}
