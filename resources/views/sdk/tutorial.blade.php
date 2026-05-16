<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ $pageTitle }}</title>
    @php
        $appCssPath = public_path('css/app.css');
        $assetVersion = file_exists($appCssPath) ? filemtime($appCssPath) : time();
    @endphp
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ $assetVersion }}">
</head>
<body>
    <div class="app-shell">
        <header class="shell-header">
            <div class="shell-nav panel">
                <div class="shell-brand">
                    <strong>PBB Realtime</strong>
                    <span>Public SDK reference</span>
                </div>
                <div class="actions">
                    <a class="button button-ghost" href="{{ route('sdk.public.index') }}">SDK Docs</a>
                    <a class="button button-ghost" href="{{ route('sdk.public.backend') }}">Backend SDK</a>
                </div>
            </div>
        </header>

        <main class="app-grid">
            <section class="panel panel-stack page-shell sdk-docs-shell">
                <div class="page-head">
                    <div>
                        <p class="eyebrow">Tutorial</p>
                        <h1 class="page-title">{{ $tutorial['title'] }}</h1>
                        <p class="page-lede">{{ $tutorial['summary'] }}</p>
                    </div>
                    <div class="actions">
                        <a class="button button-ghost" href="{{ route('sdk.public.index') }}">Back to SDK Docs</a>
                        <a class="button button-ghost" href="{{ $tutorial['demo_url'] }}" target="_blank" rel="noopener">Open demo</a>
                    </div>
                </div>

                <div class="sdk-docs-body">
                    <aside class="panel panel-stack sdk-docs-nav">
                        @include('sdk.partials.nav', ['active' => $active, 'referenceDocs' => $referenceDocs, 'tutorials' => $tutorials])
                    </aside>
                    <div class="sdk-docs-content">
                        <article class="panel panel-stack">
                            <h2 class="section-title">What this tutorial covers</h2>
                            <ul class="notes-list">
                                @foreach ($tutorial['bullets'] as $bullet)
                                    <li>{{ $bullet }}</li>
                                @endforeach
                            </ul>
                        </article>

                        <article class="panel panel-stack">
                            <h2 class="section-title">Reference documents</h2>
                            <div class="field-grid two">
                                @foreach ($tutorial['reference_docs'] as $docId)
                                    @php $doc = $referenceDocs[$docId] ?? null; @endphp
                                    @if ($doc)
                                        <article class="panel panel-stack">
                                            <p class="eyebrow">{{ $doc['group'] }}</p>
                                            <h3 class="section-title">{{ $doc['title'] }}</h3>
                                            <p class="empty-note">{{ $doc['summary'] }}</p>
                                            <div class="actions">
                                                <a class="button button-ghost" href="{{ route('sdk.public.reference', ['doc' => $docId]) }}">Open HTML</a>
                                                <a class="button button-ghost" href="{{ route('sdk.docs.public.show', ['doc' => $docId]) }}" target="_blank" rel="noopener">Open raw markdown</a>
                                            </div>
                                        </article>
                                    @endif
                                @endforeach
                            </div>
                        </article>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
