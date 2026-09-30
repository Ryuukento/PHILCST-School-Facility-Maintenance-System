<!DOCTYPE html>
<html lang="en">
@php
    $sessionUser = session('auth_user') ?? session('user');
    $sessionRole = strtolower((string)($sessionUser['role'] ?? ''));
@endphp
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>@yield('title', 'SFMS - School Facility Maintenance System')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- THEME BOOT for the Laravel shell. Sits above the stylesheet links on
         purpose so the first painted frame is already themed.

         LIGHT IS THE ONLY THEME. This script no longer reads a saved preference
         and no longer has a dark branch; it just asserts light. A stale 'dark'
         value may still exist in localStorage from before and is ignored, which
         is what makes the change stick for users who had chosen dark.

         The attributes are still written because every colour token lives under
         :root[data-theme-resolved='light'] — the hook is required, its value is
         not variable. Kept in step with frontend/includes/header.php and
         ThemeManager in frontend/assets/js/main.js. --}}
    <script>
        (function () {
            document.documentElement.setAttribute('data-theme-mode', 'light');
            document.documentElement.setAttribute('data-theme-resolved', 'light');
            document.documentElement.setAttribute('data-theme', 'light');
            document.documentElement.style.colorScheme = 'light';
        })();
    </script>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Styles -->
    <link rel="stylesheet" href="{{ asset('frontend/assets/css/styles.css') }}?v=20260921-2">
    <link rel="stylesheet" href="{{ asset('frontend/assets/css/color-scheme.css') }}?v=20260921-2">
    @if (!empty($sessionUser))
    {{-- The version tokens are NOT decoration. Without them this shell
         requested `sidebar.css` while includes/header.php requested
         `sidebar.css?v=...` — two different URLs, therefore two independent
         browser cache entries. The legacy shell's entry was invalidated by its
         version bump and the Blade shell's was not, so the two shells could
         serve different generations of the sidebar palette at the same time.
         That is how a dark rail ended up pairing with the old light-theme text
         colours. Keep these in lockstep with includes/header.php. --}}
    <link rel="stylesheet" href="{{ asset('frontend/assets/css/sidebar.css') }}?v=20260921-1">
    <link rel="stylesheet" href="{{ asset('frontend/assets/css/laravel-shell.css') }}?v=20260920-5">
    @endif
    @yield('styles')
    @if (!empty($sessionUser))
    {{-- Versioned for the first time with the purple border accent: this sheet
         now consumes the --purple-* tokens, and an unversioned URL would be
         served from cache indefinitely. --}}
    <link rel="stylesheet" href="{{ asset('frontend/assets/css/light-mode-polish.css') }}?v=20260921-2">
    @endif
</head>
<body @if(!empty($sessionUser)) data-user-role="{{ $sessionRole }}" @endif>

@if (!empty($sessionUser))
    @include('includes.sidebar')
    {{-- @include('includes.header') was here. The top bar (brand, notification
         bell, user chip) is gone from the authenticated UI; the sidebar and main
         content now own the full viewport height. The header partial itself is
         deleted, and .top-header's offsets in laravel-shell.css are reset to 0.
         Notification routes/APIs and the notifications centre are untouched —
         only this bar's UI was removed. Account and Log Out already live in the
         sidebar footer, so nothing was rebuilt to replace them. --}}
    <main class="main-content">
        @yield('content')
    </main>
    @include('includes.footer')
@else
    @yield('content')
@endif

<!-- Scripts -->
<script src="{{ asset('frontend/assets/js/api.js') }}"></script>
<script>
    // Configure API base URL for Laravel
    window.API_BASE_URL = '{{ url('/api') }}';
    window.CSRF_TOKEN = '{{ csrf_token() }}';
    
    // Add CSRF token to all fetch requests
    const originalFetch = window.fetch;
    window.fetch = async (...args) => {
        const [resource, config = {}] = args;
        const isExternal = typeof resource === 'string' && resource.startsWith('http') && !resource.startsWith(window.location.origin);
        
        if (!isExternal && config.method && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(config.method.toUpperCase())) {
            config.headers = config.headers || {};
            config.headers['X-CSRF-TOKEN'] = window.CSRF_TOKEN;
        }
        
        return originalFetch.apply(this, args);
    };
</script>
@if (!empty($sessionUser))
<script src="{{ asset('frontend/assets/js/sidebar.js') }}"></script>
@endif
{{-- The theme runtime that used to live here (setTheme/getStoredMode plus the
     click handlers that bound every [data-theme-toggle]) was removed with the
     toggle itself. The boot script in <head> already asserts light before the
     first paint, so there is nothing left for a DOMContentLoaded pass to do. --}}
@yield('scripts')
</body>
</html>
