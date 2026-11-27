<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $reference }}</title>
<style>
    @page { margin: 20mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2933; }
    h1 { font-size: 16px; margin: 0 0 4px 0; }
    h2 { font-size: 12px; margin: 14px 0 4px 0; border-bottom: 1px solid #e4e7eb; padding-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; vertical-align: top; padding: 3px 4px; border-bottom: 1px solid #e4e7eb; }
    th { background: #f5f7fa; font-size: 9px; }
    .muted { color: #52606d; }
    .warning { border: 1px solid #d97706; padding: 6px; color: #92400e; }
    .sig { border: 1px solid #cbd2d9; padding: 6px; width: 60%; }
    .sig img { max-height: 70px; max-width: 100%; }
    .hash { font-family: 'DejaVu Sans Mono', monospace; font-size: 8px; word-break: break-all; }
</style>
</head>
<body>
<h1>Tenant sign-off</h1>
<p class="muted">
    {{ $signOff->content['property_name'] ?? '' }} &middot; {{ ucfirst($signOff->phase) }} &middot;
    Reference {{ $reference }} &middot; Version {{ $signOff->version }}
</p>

@if ($superseded)
    <p class="warning">A later version of this record has been signed. This copy shows what was signed at the time.</p>
@endif

<h2>Declarations</h2>
<table>
    @foreach ($signOff->declarations as $declaration)
        <tr><td>&#10003; {{ $declaration['text'] }}</td></tr>
    @endforeach
</table>
<p class="muted">Wording version {{ $signOff->declaration_version }}</p>

<h2>Signature</h2>
<div class="sig">
    <img src="{{ $signOff->signature_image }}" alt="Signature"><br>
    <strong>{{ $signOff->signer_name }}</strong><br>
    Signed {{ $signOff->signed_at->format('j F Y, H:i:s T') }}<br>
    Selfie taken at signing: {{ $signOff->capture_selfie_id === null ? 'no' : 'yes (held by the property\'s staff)' }}<br>
    Phone unlock at signing: {{ $signOff->phone_unlocked ? 'yes' : 'no' }}
</div>

@if ($signOff->comment !== null)
    <h2>Tenant's comment</h2>
    <p>{{ $signOff->comment }}</p>
@endif

<h2>What was signed</h2>
<table>
    <tr><th>Room</th><th>Condition</th><th>Photos</th><th>Reported problems</th></tr>
    @foreach ($signOff->content['rooms'] ?? [] as $room)
        <tr>
            <td>{{ $room['name'] }}</td>
            <td>{{ $room['condition'] === null ? 'Not rated' : ucfirst($room['condition']) }}</td>
            <td>{{ count($room['photos']) }}</td>
            <td>
                @forelse ($room['issues'] as $issue)
                    {{ ucfirst($issue['kind']) }}: {{ $issue['note'] }} ({{ count($issue['photos']) }} close-ups)<br>
                @empty
                    None
                @endforelse
            </td>
        </tr>
    @endforeach
</table>

<h2>Content fingerprint</h2>
<p>SHA-256 over the rooms, ratings, notes and photo fingerprints listed above:</p>
<p class="hash">{{ $signOff->content_hash }}</p>
<p class="muted">
    Any change to the record after signing produces a different fingerprint and needs a new signature.
    This record shows what was agreed by whoever held the capture link; it does not by itself prove identity.
</p>
</body>
</html>
