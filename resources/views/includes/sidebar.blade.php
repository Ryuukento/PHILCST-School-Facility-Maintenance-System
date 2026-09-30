@php
    $sessionUser = session('auth_user') ?? session('user') ?? [];
    $sessionRole = strtolower((string)($sessionUser['role'] ?? ''));
@endphp

{{-- Relocated out of the removed top header (includes/header.blade.php). Same
     id and class as before, so sidebar.js binds it unchanged. Below 768px the
     desktop .sidebar-toggle is hidden and the rail is parked off-canvas, so
     without this button there is no way to open navigation on a small screen.
     Placed before <aside> to preserve the .sidebar + .sidebar-overlay
     adjacency selector; hidden above 768px. --}}
<button id="sidebarToggleMobile" class="sidebar-toggle-mobile" type="button" aria-label="Toggle navigation menu" title="Toggle navigation menu">
    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
        <path d="M4 6H20M4 12H20M4 18H20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
    </svg>
</button>

<aside class="sidebar" id="sidebar">
    {{-- Same PHILCST crest, same asset, same markup and classes as
         includes/sidebar.php, so the shared sidebar.css styles both shells
         identically. This shell never had a .sidebar-brand of its own — its
         branding lived in includes/header.blade.php, which was deleted when the
         top bar was removed, leaving it with no branding at all. --}}
    <div class="sidebar-brand">
        <img src="{{ asset('frontend/assets/images/logo-seal.svg') }}"
             alt="PHILCST Centralized School Facility Maintenance Reporting System"
             class="sidebar-brand-logo" />
    </div>

    <!-- Sidebar Toggle Button (Mobile) -->
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
        <span class="hamburger-icon">
            <span></span>
            <span></span>
            <span></span>
        </span>
    </button>

    <!-- Main Navigation Menu -->
    <nav class="sidebar-nav">
        <ul class="nav-menu">
            <li class="nav-section-label"><span>Main</span></li>
            <li class="nav-item">
                <a href="{{ url('/dashboard') }}" class="nav-link {{ Route::currentRouteName() === 'dashboard' ? 'active' : '' }}" data-page="dashboard">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                        <path d="M9 11H15V15H9Z"></path>
                        <path d="M3 9H9V15H3Z"></path>
                        <path d="M15 3V9"></path>
                        <path d="M3 15V21"></path>
                    </svg>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="{{ url('/reports') }}" class="nav-link {{ Route::currentRouteName() === 'reports.index' ? 'active' : '' }}" data-page="reports">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6C4.9 2 4 2.9 4 4V20C4 21.1 4.9 22 6 22H18C19.1 22 20 21.1 20 20V8L14 2Z"></path>
                        <path d="M14 2V8H20"></path>
                        <line x1="8" y1="11" x2="16" y2="11"></line>
                        <line x1="8" y1="16" x2="16" y2="16"></line>
                    </svg>
                    <span class="nav-text">All Reports</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="{{ url('/inventory') }}" class="nav-link {{ Route::currentRouteName() === 'inventory.index' ? 'active' : '' }}" data-page="inventory">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 2C7.9 2 7 2.9 7 4V20C7 21.1 7.9 22 9 22H19C20.1 22 21 21.1 21 20V8L13 2H9Z"></path>
                        <path d="M13 2V8H21"></path>
                        <line x1="10" y1="11" x2="18" y2="11"></line>
                        <line x1="10" y1="15" x2="18" y2="15"></line>
                        <line x1="10" y1="19" x2="14" y2="19"></line>
                    </svg>
                    <span class="nav-text">Inventory</span>
                </a>
            </li>

            @if ($sessionRole === 'super_admin')
            <li class="nav-item">
                <a href="{{ url('/users') }}" class="nav-link {{ Route::currentRouteName() === 'users.index' ? 'active' : '' }}" data-page="users">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 21V19C16 16.79 14.21 15 12 15H6C3.79 15 2 16.79 2 19V21"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M22 21V19C22 17.14 20.73 15.56 19 15.13"></path>
                        <path d="M16 3.13C17.73 3.56 19 5.14 19 7C19 8.86 17.73 10.44 16 10.87"></path>
                    </svg>
                    <span class="nav-text">User Management</span>
                </a>
            </li>
            @endif

            <li class="nav-section-label" style="margin-top: 2rem;"><span>Account</span></li>
            <li class="nav-item">
                <a href="{{ url('/profile') }}" class="nav-link {{ Route::currentRouteName() === 'profile' ? 'active' : '' }}" data-page="profile">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    <span class="nav-text">Profile</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ url('/logout') }}" class="nav-link">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    <span class="nav-text">Logout</span>
                </a>
            </li>
        </ul>
    </nav>
</aside>
