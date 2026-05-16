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
                    <a class="button button-ghost" href="{{ route('status') }}">Service</a>
                    <a class="button button-ghost" href="{{ route('sdk.public.index') }}">SDK Docs</a>
                    <a class="button button-ghost" href="{{ route('sdk.public.backend') }}">Backend SDK</a>
                </div>
            </div>
        </header>

        <main class="app-grid">
            <section class="panel panel-stack page-shell sdk-docs-shell">
                <div class="page-head">
                    <div>
                        <p class="eyebrow">{{ $eyebrow }}</p>
                        <h1 class="page-title">{{ $title }}</h1>
                        <p class="page-lede">{{ $lede }}</p>
                    </div>
                    <div class="actions">
                        <a class="button button-ghost" href="/api/sdk-docs/integration-guide" target="_blank" rel="noopener">Raw markdown API</a>
                    </div>
                </div>

                <div class="sdk-docs-body">
                    <aside class="panel panel-stack sdk-docs-nav">
                        @include('sdk.partials.nav', ['active' => $active, 'referenceDocs' => $referenceDocs, 'tutorials' => $tutorials])
                    </aside>
                    <div class="sdk-docs-content">
                        @if ($mode === 'backend')
                            <article class="panel panel-stack">
                                <h2 class="section-title">Backend SDK</h2>
                                <p class="empty-note">Use the backend SDK when a product backend needs to issue trusted Realtime admission for the frontend SDK.</p>
                                <dl class="detail-list">
                                    <div>
                                        <dt>Purpose</dt>
                                        <dd>Build and sign policy-safe Realtime admission payloads from a product backend.</dd>
                                    </div>
                                    <div>
                                        <dt>Packaging</dt>
                                        <dd>Plain PHP first, framework-agnostic, easy to vendor into existing PBB projects.</dd>
                                    </div>
                                    <div>
                                        <dt>Downloads</dt>
                                        <dd><a href="/api/admin/sdk-downloads/backend-php">Internal admin download</a> remains private. Public docs stay read-only.</dd>
                                    </div>
                                </dl>
                            </article>
                        @else
                            <article class="panel panel-stack">
                                <h2 class="section-title">Frontend SDK</h2>
                                <p class="empty-note">Use the frontend SDK when a browser app needs shared transport behavior owned by Realtime.</p>
                                <dl class="detail-list">
                                    <div>
                                        <dt>Owns</dt>
                                        <dd>Transport lifecycle, room membership, presence, chat, attachment transport, media chunk transport, and small-group call/conference helpers.</dd>
                                    </div>
                                    <div>
                                        <dt>Does not own</dt>
                                        <dd>Product workflow, routing, business authorization, or case management.</dd>
                                    </div>
                                    <div>
                                        <dt>AI-friendly source</dt>
                                        <dd>Each reference document is available as HTML and as raw markdown through the public docs API.</dd>
                                    </div>
                                </dl>
                            </article>
                        @endif

                        <article class="panel panel-stack">
                            <h2 class="section-title">Reference Documents</h2>
                            <div class="field-grid two">
                                @foreach ($referenceDocs as $id => $doc)
                                    <article class="panel panel-stack">
                                        <p class="eyebrow">{{ $doc['group'] }}</p>
                                        <h3 class="section-title">{{ $doc['title'] }}</h3>
                                        <p class="empty-note">{{ $doc['summary'] }}</p>
                                        <div class="actions">
                                            <a class="button button-ghost" href="{{ route('sdk.public.reference', ['doc' => $id]) }}">Open HTML</a>
                                            <a class="button button-ghost" href="{{ route('sdk.docs.public.show', ['doc' => $id]) }}" target="_blank" rel="noopener">Open raw markdown</a>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </article>

                        <article class="panel panel-stack">
                            <h2 class="section-title">Tutorials</h2>
                            <div class="field-grid two">
                                @foreach ($tutorials as $slug => $tutorial)
                                    <article class="panel panel-stack">
                                        <h3 class="section-title">{{ $tutorial['title'] }}</h3>
                                        <p class="empty-note">{{ $tutorial['summary'] }}</p>
                                        <div class="actions">
                                            <a class="button button-ghost" href="{{ route('sdk.public.tutorials.show', ['tutorial' => $slug]) }}">Open tutorial</a>
                                            <a class="button button-ghost" href="{{ $tutorial['demo_url'] }}" target="_blank" rel="noopener">Open demo</a>
                                        </div>
                                    </article>
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
