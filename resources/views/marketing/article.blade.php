@extends('marketing.layout')

@section('content')
<article class="prose">
    <h1>{{ $article['title'] }}</h1>
    <p class="lede">{{ $article['description'] }}</p>
    @foreach ($article['sections'] as $section)
        <h2>{{ $section['heading'] }}</h2>
        <p>{{ $section['body'] }}</p>
    @endforeach
    <p><a href="/resources">Back to all resources</a> · <a href="/sample-report">See a sample report</a></p>
    <p class="muted">General information, not legal advice.</p>
</article>
@endsection
