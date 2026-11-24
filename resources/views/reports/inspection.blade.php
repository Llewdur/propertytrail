<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $reference }}</title>
<style>
    @page { margin: 28mm 16mm 22mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2933; }
    .header { position: fixed; top: -22mm; left: 0; right: 0; border-bottom: 1px solid #cbd2d9; padding-bottom: 4px; }
    .footer { position: fixed; bottom: -17mm; left: 0; right: 0; border-top: 1px solid #cbd2d9; padding-top: 4px; font-size: 8px; color: #52606d; }
    .pagenum:before { content: counter(page); }
    h1 { font-size: 16px; margin: 0 0 4px 0; }
    h2 { font-size: 12px; margin: 14px 0 4px 0; border-bottom: 1px solid #e4e7eb; padding-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; vertical-align: top; padding: 3px 4px; border-bottom: 1px solid #e4e7eb; }
    th { background: #f5f7fa; font-size: 9px; }
    .parties td { border: 0; width: 33%; }
    .muted { color: #52606d; }
    .room { page-break-inside: avoid; }
    .photo { display: inline-block; width: 31%; margin: 0 1% 6px 0; vertical-align: top; }
    .photo img { width: 100%; }
    .sig { width: 48%; display: inline-block; vertical-align: top; border: 1px solid #cbd2d9; padding: 6px; min-height: 90px; }
    .sig img { max-height: 60px; max-width: 100%; }
</style>
</head>
<body>
<div class="header">
    <strong>{{ $organisationName }}</strong>
    <span class="muted"> &middot; {{ ucfirst($inspection->phase) }} inspection &middot; {{ $reference }}</span>
</div>
<div class="footer">
    {{ $footer }}<br>
    Report {{ $reference }} &middot; Page <span class="pagenum"></span>
</div>

<h1>Property inspection report</h1>
<p class="muted">
    Reference {{ $reference }} &middot; {{ ucfirst($inspection->phase) }} inspection #{{ $inspection->id }}
    &middot; Inspected {{ $inspection->submitted_at?->format('j F Y, H:i') }}
</p>

<table class="parties">
    <tr>
        <td>
            <strong>Property</strong><br>
            {{ $property?->name }}<br>
            {{ $property?->address_line_1 }}<br>
            {{ $property?->city }} {{ $property?->postal_code }}
        </td>
        <td>
            <strong>Landlord / agent</strong><br>
            {{ $landlord?->signer_name }}<br>
            <span class="muted">{{ $organisationName }}</span>
        </td>
        <td>
            <strong>Tenant</strong><br>
            {{ $tenant?->signer_name }}<br>
            @if ($lease)
                <span class="muted">Lease {{ $lease->lease_reference }}</span>
            @endif
        </td>
    </tr>
</table>

@if ($inspection->overall_notes)
    <h2>Overall notes</h2>
    <p>{{ $inspection->overall_notes }}</p>
@endif

@foreach ($rooms as $room)
    <div class="room">
        <h2>{{ $room['title'] }}</h2>
        <table>
            <thead>
                <tr><th style="width:34%">Item</th><th style="width:18%">Condition</th><th>Notes</th></tr>
            </thead>
            <tbody>
            @foreach ($room['items'] as $item)
                <tr>
                    <td>{{ $item['label'] }}</td>
                    <td>{{ $item['condition'] ?? 'Not rated' }}</td>
                    <td>{{ $item['note'] }}@if ($item['marker']) {{ $item['marker'] }}@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($room['photos'] !== [])
            <p><strong>Photos</strong></p>
            @foreach ($room['photos'] as $photo)
                <div class="photo">
                    <img src="{{ $photo['data_uri'] }}" alt="">
                    <div class="muted">{{ $photo['caption'] }}</div>
                </div>
            @endforeach
        @endif
    </div>
@endforeach

@if ($comparison)
    <div style="page-break-before: always">
        <h2>Move-in vs move-out comparison</h2>
        <p class="muted">
            Compared with the move-in inspection #{{ $comparison['baseline_id'] }}. Categories describe what the record shows;
            they do not decide who is responsible for any cost.
        </p>
        <table>
            <thead><tr><th>Category</th><th style="width:14%">Items</th></tr></thead>
            <tbody>
            @foreach ($discrepancyCategories as $key => $label)
                <tr><td>{{ $label }}</td><td>{{ $comparison['summary'][$key] }}</td></tr>
            @endforeach
            </tbody>
        </table>
        @if ($comparison['evidence_gaps'] > 0)
            <p><strong>{{ $comparison['evidence_gaps'] }}</strong> item(s) are rated Poor or Damaged without a move-in photo to compare against.</p>
        @endif
        <h2>Condition changes</h2>
        <table>
            <thead><tr><th>Room</th><th>Item</th><th>Move-in</th><th>Move-out</th><th>Category</th></tr></thead>
            <tbody>
            @foreach ($comparison['rows'] as $row)
                @continue ($row['category'] === 'unchanged')
                <tr>
                    <td>{{ $row['room'] }}</td>
                    <td>{{ $row['item'] }}</td>
                    <td>{{ $row['incoming'] ? ucfirst($row['incoming']) : 'Not recorded' }}</td>
                    <td>{{ $row['outgoing'] ? ucfirst($row['outgoing']) : 'Not rated' }}</td>
                    <td>{{ $row['label'] }}@if ($row['evidence_gap']) &middot; no move-in photo @endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

<div style="page-break-before: always">
    <h2>Signatures</h2>
    @foreach ([['Landlord / agent signature', $landlord], ['Tenant signature', $tenant]] as [$title, $signature])
        <div class="sig">
            <strong>{{ $title }}</strong><br>
            @if ($signature)
                <img src="{{ $signature->signature_image }}" alt=""><br>
                {{ $signature->signer_name }}<br>
                <span class="muted">Signed {{ $signature->signed_at->format('j F Y, H:i') }}</span>
            @endif
        </div>
    @endforeach
    <p class="muted" style="margin-top:12px">Generated {{ $generatedAt }} &middot; {{ $reference }}</p>
</div>
</body>
</html>
