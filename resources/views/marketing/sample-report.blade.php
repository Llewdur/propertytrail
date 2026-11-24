@extends('marketing.layout')

@section('content')
<h1>See exactly what you and your tenant get</h1>
<p class="lede">This is a complete inspection report produced by PropertyTrail's own PDF pipeline. The property, the people and the condition notes are fictional; every page is stamped SAMPLE.</p>

<div class="card">
    <h2>What is in the sample</h2>
    <ul>
        <li>A move-out inspection with five rooms and per-item condition notes</li>
        <li>Both parties' signatures and the report reference</li>
        <li>The same footer disclaimer used on a real locked report</li>
        <li>A SAMPLE stamp on every page</li>
    </ul>
    <p class="note">No real property, person or photograph appears in this document. The sample is generated on request and is not stored in any customer's account.</p>
    <div class="cta">
        <a class="button" href="/sample-report/download">Download the sample PDF</a>
        <a class="button secondary" href="/app">Create an account</a>
    </div>
</div>

<section>
    <h2>How a real report works</h2>
    <p>In a real inspection, the notes and photographs are captured room by room, both parties sign, and the PDF is generated once both signatures are in. The signed file is kept with the property record and a private, revocable link can be shared with the other party.</p>
    <p><a href="/faq">Read the FAQ</a> · <a href="/">Back to the home page</a></p>
</section>
@endsection
