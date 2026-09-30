<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>@yield('title', 'SFMS - School Facility Maintenance System')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Styles -->
    <link rel="stylesheet" href="{{ asset('frontend/assets/css/styles.css?v=20260921-2') }}">
    <link rel="stylesheet" href="{{ asset('frontend/assets/css/color-scheme.css?v=20260921-2') }}">
    <link rel="stylesheet" href="{{ asset('frontend/assets/css/login.css?v=20260415-1') }}">
    @yield('styles')
</head>
<body>
    @yield('content')

    <!-- Scripts -->
    <script>
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
    <script src="{{ asset('frontend/assets/js/api.js') }}"></script>
    @yield('scripts')
</body>
</html>
