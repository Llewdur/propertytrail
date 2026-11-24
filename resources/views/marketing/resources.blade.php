@extends('marketing.layout')

@section('content')
<h1>Resources</h1>
<p class="lede">A printable move-in and move-out checklist, and short guides to the property questions people in South Africa search for most.</p>

<h2>Free printable checklist</h2>
<div class="card">
    <h3>Move-in and move-out condition checklist</h3>
    <p>Print this page, walk the property room by room, and write down what you see before you hand over keys or take them back. Measure marks where you can, and take a photo of each item you note.</p>
    <table>
        <thead><tr><th>Room</th><th>Items to check</th><th>Notes</th></tr></thead>
        <tbody>
            <tr><td>Entrance / hallway</td><td>Walls, floors, doors, lights</td><td>Condition + note</td></tr>
            <tr><td>Lounge</td><td>Walls, floors, windows, ceiling, lights</td><td>Condition + note</td></tr>
            <tr><td>Kitchen</td><td>Counters, cupboards, sink, appliances, floors, lights</td><td>Condition + note</td></tr>
            <tr><td>Bedroom</td><td>Walls, floors, windows, cupboards, lights</td><td>Condition + note</td></tr>
            <tr><td>Bathroom</td><td>Toilet, basin, shower/bath, tiles, lights</td><td>Condition + note</td></tr>
        </tbody>
    </table>
    <p class="note">Use the same rooms and items at move-in and move-out. Photographs are a record of what was captured; they are not independently verified or legally conclusive.</p>
</div>

<h2>Guides</h2>
<div class="grid two">
    @foreach ($articles as $slug => $article)
        <div class="card">
            <h3><a href="/resources/{{ $slug }}">{{ $article['title'] }}</a></h3>
            <p>{{ $article['description'] }}</p>
        </div>
    @endforeach
</div>

<p class="muted">These guides are general information, not legal advice. <a href="/privacy">See how we handle personal information</a>.</p>
@endsection
