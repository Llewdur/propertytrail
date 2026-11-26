<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="{{ $description }}">
<meta name="theme-color" content="#0f172a">
<title>{{ $title }} · {{ config('app.name') }}</title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #f8fafc; color: #0f172a; font: 16px/1.65 system-ui, -apple-system, Segoe UI, sans-serif; }
    a { color: #0c4a6e; }
    a:hover { color: #082f49; }
    .masthead { background: #0f172a; color: #fff; padding: 1rem 1.25rem; }
    .masthead a { color: #fff; text-decoration: none; }
    .masthead-inner { max-width: 62rem; margin: 0 auto; display: flex; flex-wrap: wrap; gap: .75rem 1.5rem; align-items: center; justify-content: space-between; }
    .brand { font-weight: 800; font-size: 1.25rem; letter-spacing: -.02em; }
    .masthead nav { display: flex; flex-wrap: wrap; gap: .5rem 1rem; font-size: .9rem; }
    .masthead nav a:hover { text-decoration: underline; }
    main, footer > div { max-width: 62rem; margin: 0 auto; padding: 2rem 1.25rem; }
    h1 { font-size: clamp(1.9rem, 5vw, 2.8rem); line-height: 1.15; letter-spacing: -.03em; margin: 0 0 1rem; }
    h2 { font-size: 1.35rem; margin: 2.5rem 0 .6rem; }
    h3 { font-size: 1.05rem; margin: 1.4rem 0 .3rem; }
    p, ul, ol { margin: .6rem 0; }
    .lede { font-size: 1.15rem; color: #334155; max-width: 42rem; }
    .hero { background: #fff; border-bottom: 1px solid #e2e8f0; }
    .hero-inner { max-width: 62rem; margin: 0 auto; padding: 3rem 1.25rem; }
    .cta { display: inline-flex; flex-wrap: wrap; gap: .75rem; margin: 1.25rem 0 .5rem; }
    .button { display: inline-block; padding: .7rem 1.1rem; border-radius: .5rem; background: #0f172a; color: #fff; text-decoration: none; font-weight: 700; }
    .button.secondary { background: #fff; color: #0f172a; border: 1px solid #cbd5e1; }
    .grid { display: grid; gap: 1rem; grid-template-columns: 1fr; }
    @media (min-width: 42rem) { .grid.two { grid-template-columns: repeat(2, 1fr); } .grid.three { grid-template-columns: repeat(3, 1fr); } }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: .75rem; padding: 1.25rem; }
    .card p:last-child { margin-bottom: 0; }
    .step { font-size: .8rem; font-weight: 800; color: #0c4a6e; text-transform: uppercase; letter-spacing: .08em; }
    .note { color: #475569; font-size: .95rem; }
    .muted { color: #64748b; font-size: .9rem; }
    table { width: 100%; border-collapse: collapse; background: #fff; font-size: .95rem; }
    th, td { text-align: left; vertical-align: top; padding: .7rem; border-bottom: 1px solid #e2e8f0; }
    th { background: #f1f5f9; }
    .prose h1 { font-size: 1.8rem; }
    .prose h2 { margin-top: 2rem; }
    footer { border-top: 1px solid #e2e8f0; background: #fff; }
    footer p { color: #475569; font-size: .9rem; }
    @media print { .masthead nav, .cta, footer { display: none; } body { background: #fff; } .card { border: 0; padding: 0 0 .75rem 0; } }
</style>
</head>
<body>
<header class="masthead">
    <div class="masthead-inner">
        <a class="brand" href="/">{{ config('app.name') }}</a>
        <nav aria-label="Main navigation">
            <a href="/sample-report">Sample report</a>
            <a href="/faq">FAQ</a>
            <a href="/resources">Resources</a>
            <a href="/privacy">Privacy</a>
            <a href="/terms">Terms</a>
            <a href="/app">Log in</a>
        </nav>
    </div>
</header>
<main>
@yield('content')
</main>
<footer>
    <div>
        <p>
            {{ config('app.name') }} · Property condition records, evidence and signed reports.
            Built for South African landlords, agents and tenants.
        </p>
        <p><a href="/privacy">Privacy (POPIA)</a> · <a href="/terms">Terms</a> · <a href="/app">Open the app</a></p>
    </div>
</footer>
</body>
</html>
