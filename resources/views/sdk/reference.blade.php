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
                    <a class="button button-ghost" href="{{ $markdownUrl }}" target="_blank" rel="noopener">Raw markdown</a>
                </div>
            </div>
        </header>

        <main class="app-grid">
            <section class="panel panel-stack page-shell sdk-docs-shell">
                <div class="page-head">
                    <div>
                        <p class="eyebrow">{{ $eyebrow }}</p>
                        <h1 class="page-title">{{ $title }}</h1>
                    </div>
                </div>

                <div class="sdk-docs-body">
                    <aside class="panel panel-stack sdk-docs-nav">
                        @include('sdk.partials.nav', ['active' => $active, 'referenceDocs' => $referenceDocs, 'tutorials' => $tutorials])
                    </aside>
                    <div class="sdk-docs-content">
                        <article class="panel panel-stack markdown-doc">
                            {!! $html !!}
                        </article>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
