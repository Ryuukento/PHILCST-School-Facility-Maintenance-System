<?php
/**
 * Authentication Service
 * Handles user authentication logic
 */

class AuthenticationService {
    private $pdo;
    private $userModel;
    private $activityLog;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->userModel = new User($pdo);
        $this->activityLog = new ActivityLog($pdo);
    }
    
    public function login($email, $password) {
        // Validate input
        if (empty($email) || empty($password)) {
            return [
                'success' => false,
                'message' => 'Email and password required'
            ];
        }
        
        // Sanitize email
        $email = trim(filter_var($email, FILTER_SANITIZE_EMAIL));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Invalid email format'
            ];
        }
        
        // Find user
        $user = $this->userModel->findByEmail($email);
        if (!$user) {
            Logger::warning('Failed login attempt', ['email' => $email]);
            return [
                'success' => false,
                'message' => 'Invalid email or password'
            ];
        }
        
        // Check status
        if ($user['status'] !== STATUS_ACTIVE) {
            return [
                'success' => false,
                'message' => 'Account is inactive'
            ];
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            Logger::warning('Failed login attempt', ['email' => $email]);
            return [
                'success' => false,
                'message' => 'Invalid email or password'
            ];
        }
        
        $normalizedRole = $this->normalizeRoleAlias($user['role'] ?? '');

        // Create session
        SessionMiddleware::initialize();
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $normalizedRole;
        $_SESSION['department_id'] = $user['department_id'];
        $_SESSION['last_activity'] = time();
        $_SESSION['session_token'] = bin2hex(random_bytes(32));
        
        // Also set as array for frontend compatibility
        $_SESSION['user'] = [
            'user_id' => $user['user_id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'role' => $normalizedRole,
            'department_id' => $user['department_id']
        ];
        
        // Log activity
        $this->activityLog->log($user['user_id'], 'LOGIN', 'user', $user['user_id']);
        
        Logger::info('User logged in', ['user_id' => $user['user_id'], 'email' => $email]);
        
        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'user_id' => $user['user_id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $normalizedRole
            ]
        ];
    }

    private function normalizeRoleAlias($role) {
        $role = strtolower(trim((string)$role));

        if ($role === 'admin_maintenance') {
            return 'maintenance_admin';
        }

        if ($role === 'eelab_staff' || $role === 'maintenance_personnel' || $role === '') {
            return ROLE_MAINTENANCE_STAFF;
        }

        return $role;
    }
    
    public function logout() {
        if (SessionMiddleware::isAuthenticated()) {
            $userId = $_SESSION['user_id'];
            $this->activityLog->log($userId, 'LOGOUT', 'user', $userId);
            Logger::info('User logged out', ['user_id' => $userId]);
        }
        
        SessionMiddleware::destroy();
        
        return [
            'success' => true,
            'message' => 'Logout successful'
        ];
    }
    
    public function register($data) {
        // Validate input
        $validator = new Validator();
        if (!$validator->validate($data, [
            'full_name' => 'required|max:255|letters_only',
            'email' => 'required|email',
            'password' => 'required|min:' . PASSWORD_MIN_LENGTH,
            'role' => 'required|in:' . implode(',', [ROLE_SUPER_ADMIN, ROLE_DEPARTMENT_ADMIN, ROLE_MAINTENANCE_ADMIN, ROLE_MAINTENANCE_STAFF, ROLE_USER])
        ])) {
            return [
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->getErrors()
            ];
        }
        
        // Check if email exists
        if ($this->userModel->findByEmail($data['email'])) {
            return [
                'success' => false,
                'message' => 'Email already registered'
            ];
        }
        
        // Create user
        if (!$this->userModel->create($data)) {
            Logger::error('Failed to create user', ['email' => $data['email']]);
            return [
                'success' => false,
                'message' => 'Failed to create user'
            ];
        }
        
        $newUser = $this->userModel->findByEmail($data['email']);
        
        Logger::info('New user registered', ['user_id' => $newUser['user_id'], 'email' => $data['email']]);
        
        return [
            'success' => true,
            'message' => 'User registered successfully',
            'user_id' => $newUser['user_id']
        ];
    }
}
