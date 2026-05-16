<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark">
    <title>{{ $service }} | Status</title>
</head>
<body>
    <div id="app"
         data-page="/"
         data-app-name="PBB Realtime"
         data-flash-message=""
         data-ws-host="{{ $wsHost }}"
         data-ws-port="{{ $wsPort }}"
         data-token-audience="{{ $tokenAudience }}"
         data-environment="{{ $environment }}"
         data-laravel="{{ $laravel }}">
    </div>
    <script type="module" src="{{ asset('js/app.js') }}"></script>
</body>
</html>
