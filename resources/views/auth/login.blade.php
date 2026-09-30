@extends('layouts.auth')

@section('title', 'Login - School Facility Maintenance System')

@section('content')
<main class="login-container">
    <header class="page-auth-header">
        <img src="{{ asset('frontend/assets/images/logo-seal.svg') }}" alt="School Logo" class="page-auth-logo">
        <div class="page-auth-text">
            <span class="page-auth-title">School Facility Maintenance</span>
            <span class="page-auth-subtitle">Philippine College of Science &amp; Technology</span>
        </div>
    </header>
    <div class="card">
        <section class="form-panel">
            <div class="form-panel-inner">
                <div id="alert-container"></div>

                <form id="login-form" autocomplete="off">
                    <h2 class="auth-title">Sign In</h2>
                    <p class="auth-subtitle">Use your school account credentials to continue</p>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="Email"
                            autocomplete="off"
                            readonly
                            value=""
                            required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-field-wrapper">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Password"
                                autocomplete="new-password"
                                readonly
                                value=""
                                required>
                            <button type="button" id="toggle-login-password" class="password-toggle-btn" aria-label="Show password" title="Show password">
                                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                                    <path d="M2 12C3.8 7.9 7.5 5 12 5C16.5 5 20.2 7.9 22 12C20.2 16.1 16.5 19 12 19C7.5 19 3.8 16.1 2 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="login-options-row">
                        <label class="remember-me-control" for="remember_me">
                            <input type="checkbox" id="remember_me" name="remember_me">
                            <span>Remember me</span>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="login-btn">Sign In</button>
                </form>

                <div style="margin-top: 1rem; text-align: center;">
                    <p>Don't have an account? <button type="button" class="btn btn-link" id="show-register-btn" style="background: none; border: none; color: #3b82f6; cursor: pointer; text-decoration: underline;">Sign up</button></p>
                </div>

                <form id="register-form" class="form-hidden" autocomplete="off">
                    <h1 class="auth-title">Create Account</h1>
                    <p class="auth-subtitle">Register and wait for Super Admin approval</p>

                    <div class="form-group">
                        <label for="register_full_name">Name</label>
                        <input type="text" id="register_full_name" name="register_full_name" placeholder="Name" autocomplete="off" readonly value="" required>
                    </div>

                    <div class="form-group">
                        <label for="register_email">Email</label>
                        <input type="email" id="register_email" name="register_email" placeholder="Email" autocomplete="off" readonly value="" required>
                    </div>

                    <div class="form-group">
                        <label for="register_password">Password</label>
                        <input type="password" id="register_password" name="register_password" placeholder="Minimum 8 characters" autocomplete="new-password" readonly value="" required>
                    </div>

                    <div class="form-group">
                        <label for="register_confirm_password">Confirm Password</label>
                        <input type="password" id="register_confirm_password" name="register_confirm_password" placeholder="Re-enter password" autocomplete="new-password" readonly value="" required>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="register-btn">Sign Up</button>
                    <button type="button" class="btn btn-secondary btn-block" id="show-login-btn">Back to Sign In</button>
                </form>
            </div>
        </section>

        <aside class="pitch-panel">
            <div class="pitch-content">
                <div class="form-brand">
                    <img src="{{ asset('frontend/assets/images/logo-seal.svg') }}" alt="School Logo">
                    <div class="form-brand-text">
                        <div class="form-brand-name">Philippine College of Science &amp; Technology</div>
                        <div class="form-brand-subtitle">Institutional Facility Management System</div>
                    </div>
                </div>
                <div class="pitch-image-wrap">
                    <img
                        id="auth-pitch-image"
                        src="{{ asset('frontend/assets/images/3.jpg') }}"
                        alt="PhilCST Campus"
                        loading="lazy">
                </div>
            </div>
        </aside>
    </div>
</main>
@endsection

@section('scripts')
<script src="{{ asset('frontend/assets/js/utils.js') }}"></script>
<script>
// Lock browser/page zoom behavior
(function lockLoginScale() {
    const isTouchDevice = window.matchMedia('(hover: none), (pointer: coarse)').matches
        || /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
        || window.innerWidth <= 1024;

    if (isTouchDevice) {
        // Prevent mobile viewport snap-to-top caused by forced zoom style updates.
        document.documentElement.style.zoom = '';
        document.body.style.zoom = '';
        return;
    }

    const applyZoomLock = () => {
        document.documentElement.style.zoom = '1';
        document.body.style.zoom = '1';
    };
    applyZoomLock();
    window.addEventListener('resize', applyZoomLock);
    window.addEventListener('wheel', (event) => {
        if (event.ctrlKey) event.preventDefault();
    }, { passive: false });
})();

// Prevent browser autofill
document.addEventListener('DOMContentLoaded', () => {
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const rememberMeInput = document.getElementById('remember_me');

    if (emailInput) {
        emailInput.readOnly = true;
        emailInput.addEventListener('focus', () => { emailInput.readOnly = false; }, { once: true });
    }
    if (passwordInput) {
        passwordInput.readOnly = true;
        passwordInput.addEventListener('focus', () => { passwordInput.readOnly = false; }, { once: true });
    }
});

// Mobile only: keep the focused field visible above the on-screen keyboard.
// Android Chrome shrinks the visual viewport (not the layout viewport) when
// the keyboard opens, so a fixed-height/short-content page like this login
// form has no scroll room for the browser to bring the field above the
// keyboard on its own. We measure the keyboard via the VisualViewport API,
// reserve that much scroll room, and re-run scrollIntoView once the
// keyboard finishes animating in.
(function keyboardAwareScroll() {
    const isMobile = () => window.matchMedia('(max-width: 900px)').matches;
    let activeField = null;

    function updateKeyboardOffset() {
        if (!window.visualViewport) return;
        const offset = Math.max(0, window.innerHeight - window.visualViewport.height);
        document.documentElement.style.setProperty('--kb-offset', offset + 'px');

        // Require a meaningful offset so the Android address-bar show/hide
        // (~50-100px) isn't mistaken for the keyboard opening.
        if (isMobile() && activeField && offset > 150) {
            document.body.classList.add('keyboard-open');
        } else {
            document.body.classList.remove('keyboard-open');
        }
    }

    function scrollActiveFieldIntoView() {
        if (activeField) {
            activeField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function handleFocus(e) {
        if (!isMobile()) return;
        activeField = e.target;
        // Give the keyboard time to start animating in before measuring/scrolling.
        setTimeout(() => {
            updateKeyboardOffset();
            scrollActiveFieldIntoView();
        }, 300);
    }

    function handleBlur() {
        if (!isMobile()) return;
        activeField = null;
        // Delay so focus moving to the next field in the same form doesn't flicker.
        setTimeout(() => {
            const stillInForm = document.activeElement
                && document.activeElement.closest('#login-form, #register-form');
            if (!stillInForm) {
                document.body.classList.remove('keyboard-open');
                document.documentElement.style.setProperty('--kb-offset', '0px');
            }
        }, 50);
    }

    document.querySelectorAll('#login-form input, #register-form input').forEach((input) => {
        input.addEventListener('focus', handleFocus);
        input.addEventListener('blur', handleBlur);
    });

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', () => {
            updateKeyboardOffset();
            if (activeField) scrollActiveFieldIntoView();
        });
    }
})();

// Handle login form
document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    
    try {
        const response = await fetch(window.API_BASE_URL + '/auth/login', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.CSRF_TOKEN
            },
            body: JSON.stringify({ email, password })
        });
        
        const data = await response.json();
        
        if (data.success || response.ok) {
            window.location.href = '/dashboard';
        } else {
            alert(data.message || 'Login failed');
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
});

// Handle register form
document.getElementById('register-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = {
        email: document.getElementById('register_email').value,
        password: document.getElementById('register_password').value,
        full_name: document.getElementById('register_full_name').value
    };
    
    try {
        const response = await fetch(window.API_BASE_URL + '/auth/register', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.CSRF_TOKEN
            },
            body: JSON.stringify(formData)
        });
        
        const data = await response.json();
        
        if (data.success || response.ok) {
            alert('Registration successful! Please wait for admin approval.');
            document.getElementById('show-login-btn').click();
        } else {
            alert(data.message || 'Registration failed');
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
});

// Toggle between forms
document.getElementById('show-register-btn')?.addEventListener('click', (e) => {
    e.preventDefault();
    document.getElementById('login-form').classList.add('form-hidden');
    document.getElementById('register-form').classList.remove('form-hidden');
});

document.getElementById('show-login-btn')?.addEventListener('click', (e) => {
    e.preventDefault();
    document.getElementById('register-form').classList.add('form-hidden');
    document.getElementById('login-form').classList.remove('form-hidden');
});
</script>
@endsection
