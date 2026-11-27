import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import CameraScreen from './CameraScreen';
import CaptureSignOff from './CaptureSignOff';
import { loadFlow, NEW_FLOW, newRoomKey, resumeStep, saveFlow, type Flow, type IssueKind, type RoomRef, type Shot } from './captureFlow';
import { passkeysSupported, registerPasskey, unlockWithPasskey } from './capturePasskey';
import { compressImage } from './compressImage';
import { dropCapture, flushCaptures, queueCapture, queuedCaptures, releaseCaptures, type CaptureNote } from './offline';

type Area = { id: number; room_key?: string | null; name: string; photos: { id: number }[] };
type Capture = {
    capture_key: string;
    property_name: string;
    tenant_name: string;
    phase: string;
    expires_at: string;
    selfie_taken: boolean;
    passkey_registered: boolean;
    locked: boolean;
    notifications?: Notifications;
    areas: Area[];
};
type Notifications = { required: boolean; status: 'off' | 'pending' | 'subscribed' | 'fallback'; opt_in_url: string | null };

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
/** Matches ConditionVocabulary on the server. */
const CONDITIONS = ['Excellent', 'Good', 'Fair', 'Poor', 'Damaged'];
const ISSUE_KINDS: { kind: IssueKind; label: string }[] = [
    { kind: 'broken', label: 'Broken' },
    { kind: 'damaged', label: 'Damaged' },
    { kind: 'missing', label: 'Missing' },
];
const ROOM_STEPS = [
    'walked', 'corner', 'nearest', 'camera', 'taken', 'more', 'room-check',
    'rate', 'any-issue', 'issue-kind', 'issue-note', 'issue-photo', 'issue-more',
];

const PRIMARY = 'block w-full rounded-lg bg-slate-900 px-4 py-4 text-lg font-semibold text-white disabled:opacity-40';
const SECONDARY = 'block w-full rounded-lg border border-slate-300 bg-white px-4 py-3';
const LINK = 'block w-full py-2 text-sm underline';

/** Browser storage can be missing or blocked; the page then simply asks again. */
function stored(store: () => Storage, key: string, value?: string): string | null {
    try {
        if (value !== undefined) {
            store().setItem(key, value);
        }

        return store().getItem(key);
    } catch {
        return value ?? null;
    }
}

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
    const [note, setNote] = useState('');
    const [selfieCamera, setSelfieCamera] = useState(false);
    const [signing, setSigning] = useState(false);
    // This visit's phone unlock, kept only for this tab.
    const unlockKey = `capture-unlock:${token}`;
    const [unlock, setUnlock] = useState<string | null>(() => stored(() => window.sessionStorage, unlockKey));
    const [passkeyDeclined, setPasskeyDeclined] = useState(false);
    const [notifyStep, setNotifyStep] = useState<'ask' | 'whatsapp'>('ask');
    const [phone, setPhone] = useState('');
    const syncing = useRef(false);

    const capturing = (extra: Record<string, string> = {}): Record<string, string> => ({
        Accept: 'application/json',
        'X-Capture-Token': token,
        ...(unlock === null ? {} : { 'X-Capture-Unlock': unlock }),
        ...extra,
    });

    const load = useCallback(async (): Promise<void> => {
        try {
            const response = await fetch(`${apiBase}/capture`, {
                credentials: 'omit',
                headers: { Accept: 'application/json', 'X-Capture-Token': token, ...(unlock === null ? {} : { 'X-Capture-Unlock': unlock }) },
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
    }, [apiBase, token, unlock]);

    // Photos wait on the phone until the link is unlocked.
    const captureKey = capture === null || capture.locked ? null : capture.capture_key;

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
                result = await flushCaptures(apiBase, token, captureKey, unlock);
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
    }, [apiBase, token, captureKey, unlock, load]);

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

    const roomFields = (target: RoomRef) => ({
        areaId: target.id,
        roomKey: target.key,
        roomName: target.name,
        roomOutdoor: target.outdoor,
        adjoinsId: target.adjoinsId,
        adjoinsKey: target.adjoinsKey,
    });

    /** Saved on the phone first, like a photo, so it survives being offline. */
    async function answer(entry: CaptureNote, patch: Partial<Flow>): Promise<void> {
        if (captureKey === null || flow.room === null || saving) {
            return;
        }

        setSaving(true);

        try {
            await queueCapture({ captureKey, ...roomFields(flow.room), note: entry });
            setError(null);
            go(patch);
            void sync();
        } catch {
            setError('This phone could not save your answer. Free some space and try again.');
        } finally {
            setSaving(false);
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
                ...roomFields(room),
                corner: flow.shot === 'extra' || flow.shot === 'closeup' ? null : flow.corner,
                issueKey: flow.shot === 'closeup' ? flow.issue?.key : null,
                shot: flow.shot,
                held: true,
                file,
                fileName: file.name,
            });
            setTaken({ id, url: URL.createObjectURL(file) });
            setError(null);
            go({ step: flow.shot === 'closeup' ? 'issue-more' : 'taken' });
        } catch {
            setError('This phone could not save the photo. Free some space and try again.');
            go({ step: flow.shot === 'closeup' ? 'issue-photo' : 'corner' });
        } finally {
            setSaving(false);
        }
    }

    /** Sent straight up rather than queued: the page only loads online, and the rooms wait for it. */
    async function sendSelfie(raw: File): Promise<void> {
        if (saving) {
            return;
        }

        setSaving(true);
        setSelfieCamera(false);

        try {
            const file = await compressImage(raw);
            const body = new FormData();
            body.append('file', file, file.name);
            body.append('taken_at', new Date().toISOString());
            const response = await fetch(`${apiBase}/capture/selfie`, {
                method: 'POST',
                credentials: 'omit',
                headers: capturing(),
                body,
            });
            const payload = (await response.json().catch(() => null)) as { message?: string } | null;

            if (!response.ok) {
                setError(payload?.message ?? 'Your selfie did not upload. Try again.');
                return;
            }

            setError(null);
            setCapture((current) => (current === null ? current : { ...current, selfie_taken: true }));
        } catch {
            setError('Your selfie did not upload. Check your connection and try again.');
        } finally {
            setSaving(false);
        }
    }

    /** The phone cannot take notifications, so reminders go to WhatsApp. */
    async function remindOnWhatsApp(): Promise<void> {
        if (saving) {
            return;
        }

        setSaving(true);

        try {
            const response = await fetch(`${apiBase}/capture/notifications/fallback`, {
                method: 'POST',
                credentials: 'omit',
                headers: capturing({ 'Content-Type': 'application/json' }),
                body: JSON.stringify({ phone: phone.trim() }),
            });
            const payload = (await response.json().catch(() => null)) as { data?: Notifications; message?: string } | null;

            if (!response.ok || payload?.data === undefined) {
                setError(payload?.message ?? 'That number was not saved. Try again.');
                return;
            }

            setError(null);
            setCapture((current) => (current === null ? current : { ...current, notifications: payload.data }));
        } catch {
            setError('That number was not saved. Check your connection and try again.');
        } finally {
            setSaving(false);
        }
    }

    function remember(next: string): void {
        stored(() => window.sessionStorage, unlockKey, next);
        setUnlock(next);
    }

    /** Asked for by the phone itself; the face or fingerprint never reaches this page. */
    async function passkey(action: 'register' | 'unlock'): Promise<void> {
        if (saving) {
            return;
        }

        setSaving(true);

        try {
            remember(action === 'register' ? await registerPasskey(apiBase, token, unlock) : await unlockWithPasskey(apiBase, token));
            setError(null);

            if (action === 'register') {
                setCapture((current) => (current === null ? current : { ...current, passkey_registered: true }));
            }
        } catch (failure) {
            const cancelled = failure instanceof DOMException;
            setError(cancelled ? 'The phone did not confirm it was you. Try again.' : (failure as Error).message);
        } finally {
            setSaving(false);
        }
    }

    const declineKey = `capture-passkey-declined:${capture?.capture_key ?? ''}`;

    function declinePasskey(): void {
        stored(() => window.localStorage, declineKey, '1');
        setPasskeyDeclined(true);
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
        void keep({
            step: 'next-room',
            previous: room,
            room: null,
            issue: null,
            finished: room === null ? flow.finished : [...flow.finished, roomMark(room)],
            rooms: room === null ? flow.rooms : [...flow.rooms, room],
        });
    }

    /** After the last problem in a room: on to the next room, or back to "anything else?". */
    function noMoreIssues(): void {
        if (flow.later) {
            void keep({ step: 'anything-else', room: null, issue: null, later: false });
        } else {
            finishRoom();
        }
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
                    {waiting} item{waiting === 1 ? '' : 's'} saved on this phone, waiting to upload.
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

    if (capture.locked) {
        return (
            <Screen
                notice={status}
                actions={
                    <button type="button" className={PRIMARY} disabled={saving} onClick={() => void passkey('unlock')}>
                        {saving ? 'Checking…' : 'Unlock with this phone'}
                    </button>
                }
            >
                <Request>Unlock to continue</Request>
                <p>{capture.property_name} · {capture.phase} photos</p>
                <p className="text-sm text-slate-500">
                    This link is locked to the phone that started it. Use its face, fingerprint or PIN unlock. Lost that phone? Ask the
                    property&apos;s staff to reset the phone unlock.
                </p>
            </Screen>
        );
    }

    const photographed = capture.areas.filter((area) => area.photos.length > 0);
    const roomNumber = Math.max(flow.finished.length, resumeAt?.finished.length ?? 0) + 1;
    const room = flow.room;
    const inCorners = ['walked', 'corner', 'nearest', 'taken', 'more', 'room-check'].includes(flow.step);
    const progress = room === null
        ? `Room ${roomNumber}`
        : [flow.later ? null : `Room ${roomNumber}`, room.name, inCorners ? `Corner ${flow.corner}` : null]
              .filter((part) => part !== null)
              .join(' · ');
    const stop = (): void => {
        setResumeAt(flow);
        setFlow(NEW_FLOW);
        setTaken(null);
        setNote('');

        if (captureKey !== null) {
            void releaseCaptures(captureKey).then(sync);
        }
    };
    const shared = { progress, onStop: stop, notice: status };

    if (flow.step === 'intro') {
        const returning = resumeAt !== null || photographed.length > 0;
        const start = (): void => {
            if (resumeAt !== null) {
                setFlow({ ...resumeAt, step: resumeStep(resumeAt.step, resumeAt.shot) });
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
                {!capture.selfie_taken && (
                    <p className="text-sm text-slate-500">
                        First, a quick selfie to show who took the photos. Only the property&apos;s staff can see it.
                    </p>
                )}
            </Screen>
        );
    }

    if (capture.notifications?.status === 'pending') {
        if (notifyStep === 'whatsapp') {
            return (
                <Screen
                    onStop={stop}
                    notice={status}
                    actions={
                        <>
                            <button
                                type="button"
                                className={PRIMARY}
                                disabled={saving || phone.trim() === ''}
                                onClick={() => void remindOnWhatsApp()}
                            >
                                {saving ? 'Saving…' : 'Remind me on WhatsApp'}
                            </button>
                            <button type="button" className={LINK} onClick={() => setNotifyStep('ask')}>Back</button>
                        </>
                    }
                >
                    <Request>Your WhatsApp number</Request>
                    <p>We will remind you there until your photos are done.</p>
                    <input
                        className="w-full rounded-lg border border-slate-300 p-3 text-lg"
                        aria-label="WhatsApp number"
                        inputMode="tel"
                        maxLength={16}
                        placeholder="+27821234567"
                        value={phone}
                        onChange={(event) => setPhone(event.target.value)}
                    />
                </Screen>
            );
        }

        return (
            <Screen
                onStop={stop}
                notice={status}
                actions={
                    <>
                        {capture.notifications.opt_in_url !== null && (
                            <a className={`${PRIMARY} text-center`} href={capture.notifications.opt_in_url} target="_blank" rel="noopener noreferrer">
                                Turn on notifications
                            </a>
                        )}
                        <button type="button" className={SECONDARY} onClick={() => void load()}>I have turned them on</button>
                        <button type="button" className={LINK} onClick={() => setNotifyStep('whatsapp')}>I can&apos;t turn them on</button>
                    </>
                }
            >
                <Request>Turn on notifications</Request>
                <p>We remind you until every room is photographed. Keep this page open and come back here after.</p>
                <p className="text-sm text-slate-500">
                    On an iPhone, the next page shows you how to add it to your Home Screen first. Said no by mistake? Turn
                    notifications back on in your browser&apos;s settings.
                </p>
            </Screen>
        );
    }

    if (!capture.selfie_taken) {
        if (selfieCamera) {
            return (
                <CameraScreen
                    facing="user"
                    instruction="Hold the phone at arm's length and look at the screen."
                    onPhoto={(file) => void sendSelfie(file)}
                    onCancel={() => setSelfieCamera(false)}
                />
            );
        }

        return (
            <Screen
                onStop={stop}
                notice={status}
                actions={
                    <button type="button" className={PRIMARY} disabled={saving} onClick={() => setSelfieCamera(true)}>
                        {saving ? 'Uploading…' : 'Take selfie'}
                    </button>
                }
            >
                <Request>Take a selfie</Request>
                <p>It shows who took these photos. Only the property&apos;s staff can see it.</p>
            </Screen>
        );
    }

    const offerPasskey =
        !capture.passkey_registered && !passkeyDeclined && passkeysSupported() && stored(() => window.localStorage, declineKey) === null;

    if (offerPasskey) {
        return (
            <Screen
                onStop={stop}
                notice={status}
                actions={
                    <>
                        <button type="button" className={PRIMARY} disabled={saving} onClick={() => void passkey('register')}>
                            {saving ? 'Setting up…' : 'Lock it to this phone'}
                        </button>
                        <button type="button" className={SECONDARY} disabled={saving} onClick={declinePasskey}>Not now</button>
                    </>
                }
            >
                <Request>Lock this link to your phone?</Request>
                <p>
                    Next time, the link asks for this phone&apos;s face, fingerprint or PIN unlock before photos can be sent. The face or
                    fingerprint stays on the phone.
                </p>
            </Screen>
        );
    }

    if (flow.step === 'name' || room === null && ROOM_STEPS.includes(flow.step)) {
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
                        <button type="button" className={PRIMARY} onClick={() => go({ step: 'anything-else' })}>No, that is everything</button>
                        <button type="button" className={SECONDARY} onClick={() => go({ step: 'name' })}>Yes, there is another</button>
                    </>
                }
            >
                <Request>Is there any room or outside area not done yet?</Request>
            </Screen>
        );
    }

    if (flow.step === 'anything-else') {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => go({ step: 'done' })}>No, finish</button>
                        <button type="button" className={SECONDARY} onClick={() => go({ step: 'issue-room' })}>Yes, report it</button>
                    </>
                }
            >
                <Request>Anything else broken, damaged or missing?</Request>
            </Screen>
        );
    }

    if (flow.step === 'issue-room') {
        // This phone's rooms, then any photographed on another phone.
        const elsewhere = photographed.filter(
            (area) => !flow.rooms.some((known) => known.id === area.id || (known.key !== null && known.key === area.room_key)),
        );
        const choices: RoomRef[] = [
            ...flow.rooms,
            ...elsewhere.map((area) => ({ id: area.id, key: null, name: area.name, outdoor: false, adjoinsId: null, adjoinsKey: null })),
        ];

        return (
            <Screen {...shared} actions={<button type="button" className={LINK} onClick={() => go({ step: 'anything-else' })}>Back</button>}>
                <Request>Which room?</Request>
                <div className="flex flex-wrap justify-center gap-2">
                    {choices.map((choice) => (
                        <button
                            key={roomMark(choice)}
                            type="button"
                            className="rounded-full border border-slate-300 bg-white px-4 py-2"
                            onClick={() => go({ step: 'issue-kind', room: choice, later: true })}
                        >
                            {choice.name}
                        </button>
                    ))}
                </div>
            </Screen>
        );
    }

    if (flow.step === 'done' || room === null) {
        if (signing) {
            return (
                <CaptureSignOff
                    apiBase={apiBase}
                    token={token}
                    unlock={unlock}
                    passkeyRegistered={capture.passkey_registered}
                    onUnlock={remember}
                    onFix={() => {
                        setSigning(false);
                        go({ step: 'anything-else' });
                    }}
                    onBack={() => setSigning(false)}
                />
            );
        }

        return (
            <Screen
                notice={status}
                actions={
                    <>
                        <button type="button" className={PRIMARY} disabled={waiting > 0} onClick={() => setSigning(true)}>
                            Review and sign
                        </button>
                        <button type="button" className={SECONDARY} onClick={() => go({ step: 'name' })}>Add another room</button>
                        <button type="button" className={SECONDARY} onClick={() => go({ step: 'issue-room' })}>Report a problem</button>
                    </>
                }
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
                instruction={
                    flow.shot === 'closeup'
                        ? flow.issue?.kind === 'missing' ? 'Photograph where it should be.' : 'Take a close-up of the problem.'
                        : flow.shot === 'extra' ? 'Photograph the ceiling, the floor or a close-up.' : `Corner ${flow.corner}: aim at the opposite corner.`
                }
                onPhoto={(file) => void save(file)}
                onCancel={() => go(flow.shot === 'closeup' ? { step: 'issue-photo' } : { step: 'corner', shot: 'corner' })}
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
                        <button type="button" className={PRIMARY} onClick={() => go({ step: 'rate' })}>Yes, this room is done</button>
                        <button type="button" className={SECONDARY} onClick={() => shoot('extra')}>No, add a photo</button>
                    </>
                }
            >
                <Request>Is everything in {room.name} photographed?</Request>
            </Screen>
        );
    }

    if (flow.step === 'rate') {
        return (
            <Screen
                {...shared}
                actions={CONDITIONS.map((condition) => (
                    <button
                        key={condition}
                        type="button"
                        className={SECONDARY}
                        onClick={() => void answer({ condition: condition.toLowerCase() }, { step: 'any-issue' })}
                    >
                        {condition}
                    </button>
                ))}
            >
                <Request>What condition is {room.name} in?</Request>
            </Screen>
        );
    }

    if (flow.step === 'any-issue') {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={noMoreIssues}>No</button>
                        <button type="button" className={SECONDARY} onClick={() => go({ step: 'issue-kind' })}>Yes</button>
                    </>
                }
            >
                <Request>Is anything broken, damaged or missing?</Request>
            </Screen>
        );
    }

    if (flow.step === 'issue-kind') {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        {ISSUE_KINDS.map(({ kind, label }) => (
                            <button
                                key={kind}
                                type="button"
                                className={SECONDARY}
                                onClick={() => {
                                    setNote('');
                                    go({ step: 'issue-note', issue: { key: newRoomKey(), kind } });
                                }}
                            >
                                {label}
                            </button>
                        ))}
                        <button
                            type="button"
                            className={LINK}
                            onClick={() => go(flow.later ? { step: 'issue-room', room: null, later: false } : { step: 'any-issue' })}
                        >
                            Back
                        </button>
                    </>
                }
            >
                <Request>What is wrong?</Request>
            </Screen>
        );
    }

    const issue = flow.issue;

    if (flow.step === 'issue-note' && issue !== null) {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button
                            type="button"
                            className={PRIMARY}
                            disabled={note.trim() === '' || saving}
                            onClick={() => void answer({ issueKey: issue.key, issueKind: issue.kind, issueNote: note.trim() }, { step: 'issue-photo' })}
                        >
                            Next
                        </button>
                        <button type="button" className={LINK} onClick={() => go({ step: 'issue-kind' })}>Back</button>
                    </>
                }
            >
                <Request>What is {issue.kind}?</Request>
                <textarea
                    className="w-full rounded-lg border border-slate-300 p-3 text-lg"
                    aria-label="Describe the problem"
                    maxLength={500}
                    rows={3}
                    placeholder="A few words"
                    value={note}
                    onChange={(event) => setNote(event.target.value)}
                />
            </Screen>
        );
    }

    if (flow.step === 'issue-photo') {
        const missing = issue?.kind === 'missing';

        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => shoot('closeup')}>Take photo</button>
                        {missing && <button type="button" className={SECONDARY} onClick={() => go({ step: 'issue-more' })}>Skip</button>}
                    </>
                }
            >
                <Request>{missing ? 'Take a photo of where it should be.' : 'Take a close-up.'}</Request>
            </Screen>
        );
    }

    if (flow.step === 'issue-more') {
        return (
            <Screen
                {...shared}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={noMoreIssues}>No, that is all</button>
                        <button type="button" className={SECONDARY} onClick={() => void keep({ step: 'issue-kind', issue: null })}>Yes, another</button>
                        {taken !== null && <button type="button" className={LINK} onClick={() => void retake()}>Retake the photo</button>}
                    </>
                }
            >
                <Request>Another one in {room.name}?</Request>
                {taken !== null && <img src={taken.url} alt="The photo you took" className="max-h-[30dvh] rounded" />}
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
