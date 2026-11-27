import { useCallback, useEffect, useState, type ReactNode } from 'react';
import CameraScreen from './CameraScreen';
import { unlockWithPasskey } from './capturePasskey';
import { compressImage } from './compressImage';
import SignaturePad from './SignaturePad';

type Room = { name: string; condition: string | null; photo_count: number; issues: { kind: string; note: string }[] };
type Review = {
    status: 'unsigned' | 'signed' | 'changed';
    content_hash: string;
    declaration_version: string;
    declarations: { key: string; text: string }[];
    tenant_name: string;
    rooms: Room[];
    signed: { version: number; reference: string; signed_at: string } | null;
};
type Step = 'room' | 'identity' | 'accuracy' | 'comment' | 'sign' | 'selfie' | 'unlock' | 'signed';

type Props = {
    apiBase: string;
    token: string;
    unlock: string | null;
    passkeyRegistered: boolean;
    onUnlock: (unlock: string) => void;
    /** Back to the capture to add a room or report a problem the summary missed. */
    onFix: () => void;
    onBack: () => void;
};

const PRIMARY = 'block w-full rounded-lg bg-slate-900 px-4 py-4 text-lg font-semibold text-white disabled:opacity-40';
const SECONDARY = 'block w-full rounded-lg border border-slate-300 bg-white px-4 py-3';
const LINK = 'block w-full py-2 text-sm underline';

function Screen({ progress, error, children, actions }: { progress: string; error: string | null; children: ReactNode; actions: ReactNode }) {
    return (
        <main className="mx-auto flex min-h-[100dvh] max-w-lg flex-col gap-3 bg-slate-50 p-4 text-slate-900">
            <div className="text-sm text-slate-500">{progress}</div>
            <div className="flex flex-1 flex-col items-center justify-center gap-3 text-center">{children}</div>
            {error !== null && <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-700">{error}</p>}
            <div className="space-y-2">{actions}</div>
        </main>
    );
}

const Request = ({ children }: { children: ReactNode }) => <h1 className="text-2xl font-bold">{children}</h1>;

/** Review, declare, sign and confirm with a selfie: one thing per screen. */
export default function CaptureSignOff({ apiBase, token, unlock, passkeyRegistered, onUnlock, onFix, onBack }: Props) {
    const [review, setReview] = useState<Review | null>(null);
    const [step, setStep] = useState<Step>('room');
    const [roomIndex, setRoomIndex] = useState(0);
    const [comment, setComment] = useState('');
    const [signerName, setSignerName] = useState('');
    const [signature, setSignature] = useState<string | null>(null);
    const [selfie, setSelfie] = useState<File | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const headers = useCallback(
        (current: string | null): Record<string, string> => ({
            Accept: 'application/json',
            'X-Capture-Token': token,
            ...(current === null ? {} : { 'X-Capture-Unlock': current }),
        }),
        [token],
    );

    const load = useCallback(async (): Promise<void> => {
        try {
            const response = await fetch(`${apiBase}/capture/sign-off`, { credentials: 'omit', headers: headers(unlock) });
            const payload = (await response.json().catch(() => null)) as { data?: Review; message?: string } | null;

            if (!response.ok || payload?.data === undefined) {
                setError(payload?.message ?? 'We could not load your summary. Try again.');
                return;
            }

            setError(null);
            setReview(payload.data);
            setSignerName((current) => (current === '' ? payload.data!.tenant_name : current));
            setStep(payload.data.status === 'signed' ? 'signed' : 'room');
            setRoomIndex(0);
        } catch {
            setError('You are offline. Reconnect and try again.');
        }
    }, [apiBase, headers, unlock]);

    useEffect(() => {
        void load();
    }, [load]);

    async function submit(withUnlock: string | null, photo: File): Promise<void> {
        if (review === null || signature === null) {
            return;
        }

        setBusy(true);

        try {
            const file = await compressImage(photo);
            const body = new FormData();
            body.append('signer_name', signerName.trim());
            body.append('signature', signature);
            body.append('content_hash', review.content_hash);
            body.append('declaration_version', review.declaration_version);
            body.append('confirm_identity', '1');
            body.append('confirm_accuracy', '1');
            body.append('comment', comment.trim());
            body.append('selfie', file, file.name);
            body.append('selfie_taken_at', new Date().toISOString());
            const response = await fetch(`${apiBase}/capture/sign-off`, {
                method: 'POST',
                credentials: 'omit',
                headers: headers(withUnlock),
                body,
            });
            const payload = (await response.json().catch(() => null)) as { message?: string } | null;

            if (!response.ok) {
                setError(payload?.message ?? 'Your signature was not saved. Try again.');
                return;
            }

            await load();
        } catch {
            setError('Your signature was not saved. Check your connection and try again.');
        } finally {
            setBusy(false);
        }
    }

    async function confirmWithPhone(): Promise<void> {
        if (selfie === null) {
            return;
        }

        setBusy(true);

        try {
            const fresh = await unlockWithPasskey(apiBase, token);
            onUnlock(fresh);
            await submit(fresh, selfie);
        } catch (failure) {
            setError(failure instanceof DOMException ? 'The phone did not confirm it was you. Try again.' : (failure as Error).message);
            setBusy(false);
        }
    }

    async function download(): Promise<void> {
        try {
            const response = await fetch(`${apiBase}/capture/sign-off/copy`, { credentials: 'omit', headers: headers(unlock) });

            if (!response.ok) {
                setError('Your copy could not be downloaded. Try again.');
                return;
            }

            const url = URL.createObjectURL(await response.blob());
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = `${review?.signed?.reference ?? 'sign-off'}.pdf`;
            anchor.click();
            URL.revokeObjectURL(url);
        } catch {
            setError('Your copy could not be downloaded. Check your connection.');
        }
    }

    if (review === null) {
        return (
            <Screen progress="Sign off" error={error} actions={<button type="button" className={LINK} onClick={onBack}>Back</button>}>
                <Request>Loading your summary…</Request>
            </Screen>
        );
    }

    if (step === 'signed' && review.signed !== null) {
        return (
            <Screen
                progress="Signed"
                error={error}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => void download()}>Download your copy</button>
                        <button type="button" className={LINK} onClick={onBack}>Back</button>
                    </>
                }
            >
                <Request>Signed. Thank you.</Request>
                <p>Reference {review.signed.reference}</p>
                <p className="text-sm text-slate-500">Signed {new Date(review.signed.signed_at).toLocaleString()}.</p>
            </Screen>
        );
    }

    const room = review.rooms[roomIndex];

    if (step === 'room' && room !== undefined) {
        const last = roomIndex + 1 >= review.rooms.length;

        return (
            <Screen
                progress={`Check ${roomIndex + 1} of ${review.rooms.length}`}
                error={error}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => (last ? setStep('identity') : setRoomIndex(roomIndex + 1))}>
                            This is right
                        </button>
                        <button type="button" className={SECONDARY} onClick={onFix}>Something is wrong or missing</button>
                        <button type="button" className={LINK} onClick={() => (roomIndex === 0 ? onBack() : setRoomIndex(roomIndex - 1))}>Back</button>
                    </>
                }
            >
                {review.status === 'changed' && <p className="text-sm text-amber-700">Your record changed after you signed. Check it and sign again.</p>}
                <Request>{room.name}</Request>
                <p>{room.condition === null ? 'Not rated' : `Condition: ${room.condition}`} · {room.photo_count} photos</p>
                {room.issues.length === 0 ? (
                    <p className="text-sm text-slate-500">No problems reported.</p>
                ) : (
                    room.issues.slice(0, 3).map((issue, index) => (
                        <p key={index} className="text-sm">{issue.kind}: {issue.note}</p>
                    ))
                )}
                {room.issues.length > 3 && <p className="text-sm text-slate-500">and {room.issues.length - 3} more</p>}
            </Screen>
        );
    }

    if (step === 'room' || step === 'identity' || step === 'accuracy') {
        const declaration = review.declarations.find((entry) => entry.key === (step === 'accuracy' ? 'accuracy' : 'identity'));
        const next: Step = step === 'accuracy' ? 'comment' : 'accuracy';

        return (
            <Screen
                progress="Confirm"
                error={error}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => setStep(next)}>I confirm</button>
                        <button type="button" className={LINK} onClick={step === 'accuracy' ? () => setStep('identity') : onBack}>Back</button>
                    </>
                }
            >
                <Request>{step === 'accuracy' ? 'Is this record accurate?' : 'Is this you?'}</Request>
                <p>{declaration?.text}</p>
                {step !== 'accuracy' && <p className="text-sm text-slate-500">Not you? Stop here and tell the property&apos;s staff.</p>}
            </Screen>
        );
    }

    if (step === 'comment') {
        return (
            <Screen
                progress="Anything to add?"
                error={error}
                actions={
                    <>
                        <button type="button" className={PRIMARY} onClick={() => setStep('sign')}>{comment.trim() === '' ? 'Nothing to add' : 'Next'}</button>
                        <button type="button" className={LINK} onClick={() => setStep('accuracy')}>Back</button>
                    </>
                }
            >
                <Request>Anything to add or disagree with?</Request>
                <textarea
                    aria-label="Comment"
                    maxLength={2000}
                    rows={4}
                    className="w-full rounded border border-slate-300 p-2"
                    value={comment}
                    onChange={(event) => setComment(event.target.value)}
                />
                <p className="text-sm text-slate-500">It is signed with your record.</p>
            </Screen>
        );
    }

    if (step === 'sign') {
        return (
            <Screen
                progress="Sign"
                error={error}
                actions={
                    <>
                        <button type="button" className={PRIMARY} disabled={signature === null || signerName.trim() === ''} onClick={() => setStep('selfie')}>
                            Next
                        </button>
                        <button type="button" className={LINK} onClick={() => setStep('comment')}>Back</button>
                    </>
                }
            >
                <Request>Sign with your finger</Request>
                <input
                    aria-label="Your full name"
                    className="w-full rounded border border-slate-300 p-2"
                    maxLength={120}
                    value={signerName}
                    onChange={(event) => setSignerName(event.target.value)}
                />
                <div className="w-full"><SignaturePad onChange={setSignature} /></div>
            </Screen>
        );
    }

    if (step === 'selfie') {
        return (
            <CameraScreen
                facing="user"
                instruction="One last selfie to show it is you signing."
                onPhoto={(file) => {
                    setSelfie(file);
                    setStep('unlock');
                }}
                onCancel={() => setStep('sign')}
            />
        );
    }

    return (
        <Screen
            progress="Confirm it is you"
            error={error}
            actions={
                <>
                    <button
                        type="button"
                        className={PRIMARY}
                        disabled={busy || selfie === null}
                        onClick={() => (passkeyRegistered ? void confirmWithPhone() : selfie !== null && void submit(unlock, selfie))}
                    >
                        {busy ? 'Signing…' : passkeyRegistered ? 'Unlock with your phone to sign' : 'Sign now'}
                    </button>
                    <button type="button" className={LINK} onClick={() => setStep('selfie')}>Retake selfie</button>
                </>
            }
        >
            <Request>{passkeyRegistered ? 'Confirm with your phone' : 'Ready to sign'}</Request>
            <p>
                {passkeyRegistered
                    ? 'Use the face, fingerprint or PIN unlock you set up for this link.'
                    : 'Your signature, selfie and answers are saved with your record.'}
            </p>
        </Screen>
    );
}
