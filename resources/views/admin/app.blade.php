<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark">
    <title>PBB Realtime Admin</title>
</head>
<body>
    @php
        $appJsPath = public_path('js/app.js');
        $assetVersion = file_exists($appJsPath) ? filemtime($appJsPath) : time();
    @endphp
    <div id="app"
         data-page="/{{ ltrim(request()->path(), '/') }}"
         data-app-name="PBB Realtime"
         data-asset-version="{{ $assetVersion }}"
         data-flash-message="{{ session('status') }}"
         data-account-login-error="{{ session('account_login_error') }}">
    </div>
    <script type="module" src="{{ asset('js/app.js') }}?v={{ $assetVersion }}"></script>
</body>
</html>
