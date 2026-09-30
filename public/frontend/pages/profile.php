<?php
/**
 * User Profile Page
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
    if (!@session_start()) {
        // 2026-09-30: transient Windows/antivirus file-lock on the session
        // save path (C:\xampp\tmp) can make session_start() fail; suppress
        // the raw warning and log it instead so users just see a clean
        // logged-out state (e.g. after auto-logout) rather than PHP noise.
        error_log('session_start() failed in ' . basename(__FILE__) . ': ' . (error_get_last()['message'] ?? 'unknown reason'));
    }
}

// Check if user is logged in FIRST - before any output
if (!isset($_SESSION['user'])) {
    header('Location: /School_Facility_Maintenance_System/frontend/pages/index.php');
    exit;
}

$pageTitle = 'Profile - SFMS';
$currentUser = $_SESSION['user'];
include __DIR__ . '/../includes/header.php';
?>

<main class="container">
    <div class="card" style="max-width: 760px; margin: 0 auto;">
            <div class="card-header">
                <h2>User Profile</h2>
                <p class="text-muted mb-0">Your account information</p>
            </div>
            
            <div class="card-body">
                <div id="alert-container"></div>
                
                <?php if ($currentUser): ?>
                    <div style="text-align: center; margin-bottom: 30px;">
                        <div style="width: 80px; height: 80px; background: linear-gradient(135deg, #7c3aed, #6d28d9); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto;">
                            <?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" value="<?php echo htmlspecialchars($currentUser['full_name'] ?? ''); ?>" readonly style="background-color: #f9fafb;">
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" value="<?php echo htmlspecialchars($currentUser['email'] ?? ''); ?>" readonly style="background-color: #f9fafb;">
                    </div>
                    
                    <div class="form-group">
                        <label>Role</label>
                        <input type="text" value="<?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $currentUser['role'] ?? ''))); ?>" readonly style="background-color: #f9fafb;">
                    </div>
                <?php else: ?>
                    <p class="text-muted">User information not available</p>
                <?php endif; ?>
            </div>
        </div>
</main>

<script src="/School_Facility_Maintenance_System/frontend/assets/js/utils.js"></script>
<script src="/School_Facility_Maintenance_System/frontend/assets/js/api.js"></script>

<script>
// Ensure API and Session are defined globally
window.API = window.API || {
    baseURL: '/School_Facility_Maintenance_System/backend/api',
    async logout() {
        const response = await fetch(`${this.baseURL}/auth.php?action=logout`);
        const data = await response.json();
        return data;
    }
};

window.Session = window.Session || {
    get(key) { 
        const v = localStorage.getItem(key);
        return v ? JSON.parse(v) : null;
    },
    set(key, value) { localStorage.setItem(key, JSON.stringify(value)); },
    clear() { localStorage.clear(); }
};
</script>

</body>
</html>
