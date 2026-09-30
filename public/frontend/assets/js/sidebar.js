/**
 * FacilityFlow Sidebar Navigation JavaScript
 * Handles active state, toggle functionality, and responsive behavior
 */

document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarToggleMobile = document.getElementById('sidebarToggleMobile');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const navLinks = document.querySelectorAll('.nav-link');
    const navItems = document.querySelectorAll('.nav-item');
    const isForceProfileSetup = document.body?.dataset?.forceProfileSetup === '1';

    // Tracks the last width we actually reacted to, so resize events that only
    // change window.innerHeight (e.g. a mobile browser's URL/address bar
    // hiding or showing while the user scrolls) don't trigger a layout
    // recalculation. Real resizes/orientation changes always change innerWidth.
    let lastKnownWidth = window.innerWidth;

    // Pages without the shared sidebar (e.g. the login page) still load this
    // script via footer.php — bail out instead of throwing on missing #sidebar.
    if (!sidebar) {
        return;
    }

    // ========================================
    // INITIALIZATION
    // ========================================

    /**
     * Initialize sidebar on page load
     * Sets up event listeners and applies saved preferences
     */
    function initSidebar() {
        applyActiveState();
        attachEventListeners();
        setResponsiveMode();
        loadSidebarPreference();
    }

    // ========================================
    // ACTIVE STATE MANAGEMENT
    // ========================================

    /**
     * Apply active state to current page link
     * Uses PHP-set active class as fallback
     */
    function applyActiveState() {
        let activeLink = document.querySelector('.nav-link.active');

        if (!activeLink) {
            const currentPath = window.location.pathname.toLowerCase();
            const currentFile = currentPath.split('/').pop();

            activeLink = Array.from(navLinks).find((link) => {
                const href = link.getAttribute('href');

                if (!href || href === '#' || href.startsWith('javascript:')) {
                    return false;
                }

                try {
                    const linkUrl = new URL(href, window.location.origin);
                    const linkFile = linkUrl.pathname.toLowerCase().split('/').pop();
                    return linkFile !== '' && linkFile === currentFile;
                } catch (error) {
                    return false;
                }
            });
        }
        
        if (activeLink) {
            addActiveHighlight(activeLink);
        }
    }

    /**
     * Add visual highlight to active link
     * @param {HTMLElement} link - The nav link to highlight
     */
    function addActiveHighlight(link) {
        // Remove active class from all links
        navLinks.forEach(l => {
            l.classList.remove('active');
        });

        // Add active class to specified link
        link.classList.add('active');

        // Save preference
        saveSidebarPreference();
    }

    // ========================================
    // EVENT LISTENERS
    // ========================================

    /**
     * Attach all event listeners to sidebar elements
     */
    function attachEventListeners() {
        // Toggle button click
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleSidebar);
        }

        if (sidebarToggleMobile) {
            sidebarToggleMobile.addEventListener('click', toggleSidebar);
        }

        // Overlay click (closes sidebar on mobile)
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', closeSidebar);
        }

        // Navigation link clicks
        navLinks.forEach(link => {
            link.addEventListener('click', handleNavLinkClick);
        });

        // TASK 6 — expandable nav parents (Inventory submenu).
        // Bound separately from handleNavLinkClick: the parent is a <button>
        // with no href, so that handler returns early on it and the two never
        // fight over the active state.
        document.querySelectorAll('.nav-parent-toggle').forEach((toggle) => {
            toggle.addEventListener('click', handleNavParentToggle);
        });

        // Window resize listener for responsive behavior.
        // Gated on innerWidth so mobile browser chrome (URL bar) show/hide,
        // which only changes innerHeight, doesn't trigger a recalculation.
        window.addEventListener('resize', handleResize);

        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });
    }

    /**
     * TASK 6 — expand/collapse a nav parent (e.g. Inventory).
     *
     * The parent row is an expander only; it deliberately does not navigate,
     * because the section lists its own landing page ("Inventory") as the first
     * child. PHP renders the section already open when the current page is one
     * of its children, so this only handles the user toggling it by hand.
     *
     * Keyboard support is the sidebar's existing one: the parent carries
     * .nav-link, so the arrow-key handler below moves focus to it and
     * Enter/Space call .click(), which lands here.
     *
     * @param {Event} e - The click event
     */
    function handleNavParentToggle(e) {
        e.preventDefault();

        const parent = this.closest('.nav-parent');

        if (!parent) {
            return;
        }

        const isOpen = parent.classList.toggle('nav-parent-open');
        this.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    /**
     * Handle navigation link clicks
     * @param {Event} e - The click event
     */
    function handleNavLinkClick(e) {
        if (isForceProfileSetup) {
            const page = this.getAttribute('data-page') || '';
            const isLogout = this.hasAttribute('data-logout') || this.classList.contains('logout-link');
            const isAllowedDuringSetup = page === 'account' || isLogout;

            if (!isAllowedDuringSetup) {
                e.preventDefault();
                return;
            }
        }

        // Only handle internal links (not logout)
        const href = this.getAttribute('href');
        
        if (!href || href.includes('logout') || href.startsWith('http')) {
            return;
        }

        // Highlight the clicked link
        addActiveHighlight(this);

        // Close sidebar on mobile after clicking
        if (isMobileView()) {
            closeSidebar();
        }
    }

    // ========================================
    // SIDEBAR TOGGLE FUNCTIONALITY
    // ========================================

    /**
     * Toggle sidebar open/close
     */
    function toggleSidebar() {
        if (isMobileView()) {
            sidebar.classList.toggle('mobile-open');
            sidebar.classList.remove('collapsed');
            document.body.classList.toggle('sidebar-mobile-open', sidebar.classList.contains('mobile-open'));
            if (sidebarToggleMobile) {
                sidebarToggleMobile.setAttribute('aria-expanded', sidebar.classList.contains('mobile-open') ? 'true' : 'false');
            }
            return;
        }

        sidebar.classList.toggle('collapsed');
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', sidebar.classList.contains('collapsed') ? 'false' : 'true');
        }
    }

    /**
     * Close sidebar (mobile)
     */
    function closeSidebar() {
        sidebar.classList.remove('mobile-open');
        document.body.classList.remove('sidebar-mobile-open');
        if (sidebarToggleMobile) {
            sidebarToggleMobile.setAttribute('aria-expanded', 'false');
        }
    }

    /**
     * Open sidebar (mobile)
     */
    function openSidebar() {
        if (isMobileView()) {
            sidebar.classList.add('mobile-open');
            sidebar.classList.remove('collapsed');
            document.body.classList.add('sidebar-mobile-open');
            if (sidebarToggleMobile) {
                sidebarToggleMobile.setAttribute('aria-expanded', 'true');
            }
        }
    }

    // ========================================
    // RESPONSIVE BEHAVIOR
    // ========================================

    /**
     * Determine if current view is mobile
     * @returns {boolean} True if viewport width is 768px or less
     */
    function isMobileView() {
        return window.innerWidth <= 768;
    }

    /**
     * Set responsive mode based on viewport width
     */
    function setResponsiveMode() {
        if (isMobileView()) {
            // Mobile mode: keep sidebar hidden until opened from the header toggle
            sidebar.classList.remove('collapsed');
            sidebar.classList.remove('mobile-open');
            document.body.classList.remove('sidebar-mobile-open');
            if (sidebarToggleMobile) {
                sidebarToggleMobile.setAttribute('aria-expanded', 'false');
            }
        } else {
            // Desktop mode: clear mobile-only state, but preserve the user's
            // collapsed/expanded preference instead of resetting it on every resize.
            sidebar.classList.remove('mobile-open');
            document.body.classList.remove('sidebar-mobile-open');
            if (sidebarToggleMobile) {
                sidebarToggleMobile.setAttribute('aria-expanded', 'false');
            }
            if (sidebarToggle) {
                sidebarToggle.setAttribute('aria-expanded', sidebar.classList.contains('collapsed') ? 'false' : 'true');
            }
        }
    }

    /**
     * Resize event handler wrapper.
     *
     * Mobile browsers fire 'resize' when their URL/address bar auto-hides or
     * reappears during scrolling, even though the viewport WIDTH hasn't
     * changed (only innerHeight moves as the browser chrome collapses).
     * Running setResponsiveMode() on those events was causing unnecessary
     * class/attribute churn on the sidebar during an ordinary scroll.
     *
     * Only re-run setResponsiveMode() when innerWidth actually changed —
     * that covers real resizes, orientation changes, and desktop/tablet
     * window resizing, all of which change innerWidth.
     */
    function handleResize() {
        const currentWidth = window.innerWidth;
        if (currentWidth === lastKnownWidth) {
            return;
        }
        lastKnownWidth = currentWidth;
        setResponsiveMode();
    }

    // ========================================
    // LOCAL STORAGE PREFERENCES
    // ========================================

    /**
     * Save sidebar preferences to local storage
     */
    function saveSidebarPreference() {
        const isCollapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem('facilityflow_sidebar_collapsed', isCollapsed);
    }

    /**
     * Load sidebar preferences from local storage
     */
    function loadSidebarPreference() {
        if (isMobileView()) {
            return; // Don't use saved state on mobile
        }

        const isCollapsed = localStorage.getItem('facilityflow_sidebar_collapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
        }
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        }
    }

    /**
     * Add collapse/expand functionality via double-click on header
     */
    const sidebarHeader = document.querySelector('.sidebar-header');
    if (sidebarHeader && !isMobileView()) {
        sidebarHeader.addEventListener('dblclick', function() {
            sidebar.classList.toggle('collapsed');
            if (sidebarToggle) {
                sidebarToggle.setAttribute('aria-expanded', sidebar.classList.contains('collapsed') ? 'false' : 'true');
            }
            saveSidebarPreference();
        });
    }

    // ========================================
    // KEYBOARD ACCESSIBILITY
    // ========================================

    /**
     * Handle keyboard navigation within sidebar
     */
    navLinks.forEach((link, index) => {
        link.addEventListener('keydown', function(e) {
            let targetLink;

            switch(e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    targetLink = navLinks[index + 1];
                    if (targetLink) targetLink.focus();
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    targetLink = navLinks[index - 1];
                    if (targetLink) targetLink.focus();
                    break;

                case 'Enter':
                case ' ':
                    e.preventDefault();
                    this.click();
                    break;
            }
        });
    });

    // ========================================
    // PUBLIC API (for external use)
    // ========================================

    window.FacilityFlowSidebar = {
        /**
         * Programmatically set active nav item
         * @param {string} page - The page identifier (e.g., 'dashboard')
         */
        setActivePage: function(page) {
            const link = document.querySelector(`[data-page="${page}"]`);
            if (link) {
                addActiveHighlight(link);
            }
        },

        /**
         * Toggle sidebar visibility
         */
        toggle: toggleSidebar,

        /**
         * Open sidebar (mobile only)
         */
        open: openSidebar,

        /**
         * Close sidebar (mobile only)
         */
        close: closeSidebar,

        /**
         * Check if sidebar is collapsed
         * @returns {boolean}
         */
        isCollapsed: function() {
            return sidebar.classList.contains('collapsed');
        },

        /**
         * Get current active page
         * @returns {string|null} The page identifier or null
         */
        getActivePage: function() {
            const activeLink = document.querySelector('.nav-link.active');
            return activeLink ? activeLink.getAttribute('data-page') : null;
        }
    };

    // ========================================
    // INITIALIZATION CALL
    // ========================================
    initSidebar();
});

/**
 * Utility: Smooth scroll to section
 * Can be used for future in-page navigation
 */
function smoothScroll(selector) {
    const element = document.querySelector(selector);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth' });
    }
}

/**
 * Utility: Update active link programmatically
 * Usage: updateActiveNavLink('/dashboard.php')
 */
function updateActiveNavLink(url) {
    const currentPage = url.split('/').pop() || 'index.php';
    const link = document.querySelector(`a[href="${currentPage}"]`);
    
    if (link) {
        window.FacilityFlowSidebar.setActivePage(
            link.getAttribute('data-page')
        );
    }
}
