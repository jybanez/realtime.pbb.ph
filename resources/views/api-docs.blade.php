<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>PBB Realtime API Docs</title>
    <link rel="stylesheet" href="/vendor/helpers.pbb.ph/css/ui/ui.tokens.css">
    <link rel="stylesheet" href="/vendor/helpers.pbb.ph/css/ui/ui.components.css">
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(103, 180, 255, 0.12), transparent 28%),
                radial-gradient(circle at bottom right, rgba(126, 240, 207, 0.08), transparent 24%),
                linear-gradient(145deg, #07111f, #0f1a2e);
            color: var(--ui-text);
            font-family: var(--ui-font, Inter, "Segoe UI", Tahoma, Geneva, Verdana, sans-serif);
            padding: 24px;
        }
        .shell {
            width: min(1200px, calc(100vw - 32px));
            margin: 0 auto;
        }
        .panel {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 16px;
            background: rgba(10, 17, 32, 0.76);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.2);
            padding: 18px;
        }
        pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            color: var(--ui-text);
        }
        .eyebrow {
            margin: 0 0 4px;
            color: var(--ui-muted);
            font-size: 13px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .page-title {
            margin: 0;
            font: 700 clamp(1.7rem, 3.2vw, 2.6rem)/1.05 var(--ui-font);
            letter-spacing: -0.04em;
        }
        .page-lede {
            margin: 8px 0 0;
            color: var(--ui-muted);
            line-height: 1.65;
            max-width: 72ch;
        }
        .stack { display: grid; gap: 16px; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .button {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 40px; padding: 0 14px;
            border-radius: 10px; border: 1px solid transparent; text-decoration: none; cursor: pointer; font: inherit;
            background: rgba(103, 180, 255, 0.18); border-color: rgba(103, 180, 255, 0.32); color: var(--ui-text);
        }
    </style>
</head>
<body>
    <main class="shell stack">
        <section class="panel stack">
            <div>
                <p class="eyebrow">API Docs</p>
                <h1 class="page-title">PBB Realtime OpenAPI</h1>
                <p class="page-lede">
                    Baseline API documentation for the realtime service and private admin surface.
                </p>
            </div>
            <div class="actions">
                <a class="button" href="{{ route('status') }}">Back to status</a>
                <a class="button" href="{{ route('status') }}">Open status page</a>
            </div>
        </section>

        <section class="panel stack">
            <div>
                <p class="eyebrow">OpenAPI</p>
                <h2 class="page-title" style="font-size: 1.5rem;">Specification</h2>
            </div>
            <pre>{{ $openapi }}</pre>
        </section>
    </main>
</body>
</html>
