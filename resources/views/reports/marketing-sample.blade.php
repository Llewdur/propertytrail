<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $reference }} — SAMPLE</title>
<style>
    @page { margin: 34mm 16mm 26mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2933; }
    .sample-banner { position: fixed; top: -28mm; left: 0; right: 0; text-align: center; background: #0f172a; color: #fff; padding: 5px; font-size: 10px; font-weight: bold; }
    .sample-footer { position: fixed; bottom: -20mm; left: 0; right: 0; text-align: center; border-top: 1px solid #0f172a; padding-top: 4px; font-size: 9px; font-weight: bold; }
    .watermark { position: fixed; top: 40%; left: 0; right: 0; text-align: center; font-size: 58px; color: #e4e7eb; transform: rotate(-30deg); }
    h1 { font-size: 16px; margin: 0 0 4px 0; }
    h2 { font-size: 12px; margin: 14px 0 4px 0; border-bottom: 1px solid #e4e7eb; padding-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; vertical-align: top; padding: 3px 4px; border-bottom: 1px solid #e4e7eb; }
    th { background: #f5f7fa; font-size: 9px; }
    .parties td { border: 0; width: 33%; }
    .muted { color: #52606d; }
    .room { page-break-inside: avoid; }
    .sig { width: 48%; display: inline-block; vertical-align: top; border: 1px solid #cbd2d9; padding: 6px; min-height: 70px; }
</style>
</head>
<body>
<div class="sample-banner">{{ $stamp }}</div>
<div class="sample-footer">{{ $stamp }} · {{ $reference }}</div>
<div class="watermark">{{ $watermark }}</div>

<h1>Property inspection report</h1>
<p class="muted">
    Reference {{ $reference }} &middot; {{ ucfirst($phase) }} inspection
    &middot; Inspected {{ $inspectedAt }}
</p>

<table class="parties">
    <tr>
        <td>
            <strong>Property</strong><br>
            {{ $property['name'] }}<br>
            {{ $property['address_line_1'] }}<br>
            {{ $property['city'] }} {{ $property['postal_code'] }}
        </td>
        <td>
            <strong>Landlord / agent</strong><br>
            {{ $landlord['signer_name'] }}<br>
            <span class="muted">{{ $organisationName }}</span>
        </td>
        <td>
            <strong>Tenant</strong><br>
            {{ $tenant['signer_name'] }}<br>
            <span class="muted">Lease {{ $lease['lease_reference'] }}</span>
        </td>
    </tr>
</table>

<h2>Overall notes</h2>
<p>{{ $notes }}</p>

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
                    <td>{{ $item['condition'] }}</td>
                    <td>{{ $item['note'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endforeach

<div style="page-break-before: always">
    <h2>Signatures</h2>
    @foreach ([['Landlord / agent signature', $landlord], ['Tenant signature', $tenant]] as [$title, $signature])
        <div class="sig">
            <strong>{{ $title }}</strong><br>
            {{ $signature['signer_name'] }}<br>
            <span class="muted">Signed {{ $signature['signed_at'] }}</span>
        </div>
    @endforeach
    <p class="muted">{{ $footer }}</p>
</div>
</body>
</html>
