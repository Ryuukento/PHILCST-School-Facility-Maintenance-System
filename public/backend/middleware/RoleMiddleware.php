<?php
/**
 * Role Middleware
 * Checks user role-based access control
 */

class RoleMiddleware {
    
    private static $permissions = [
        ROLE_SUPER_ADMIN => [
            'manage_users', 'manage_departments', 'view_all_reports',
            'manage_system', 'view_analytics', 'manage_inventory', 'view_audit_logs'
        ],
        ROLE_DEPARTMENT_ADMIN => [
            'manage_staff', 'view_department_reports', 'assign_reports',
            'view_department_analytics', 'view_audit_logs'
        ],
        ROLE_MAINTENANCE_ADMIN => [
            'view_all_reports', 'manage_inventory', 'view_audit_logs', 'update_report_status'
        ],
        ROLE_MAINTENANCE_STAFF => [
            'view_assigned_reports', 'update_report_status', 'add_comments', 'manage_inventory'
        ],
        ROLE_USER => [
            'create_report', 'view_own_reports', 'view_dashboard'
        ]
    ];
    
    public static function require($permission) {
        if (!self::isAuthenticated()) {
            Response::error('Unauthorized', [], Response::HTTP_UNAUTHORIZED);
        }
        
        $userRole = self::resolveRole();
        if (!self::hasPermission($userRole, $permission)) {
            Response::error('Forbidden: Insufficient permissions', [], Response::HTTP_FORBIDDEN);
        }
    }
    
    public static function requireRole($role) {
        if (!self::isAuthenticated()) {
            Response::error('Unauthorized', [], Response::HTTP_UNAUTHORIZED);
        }
        
        $userRole = self::resolveRole();
        $allowedRoles = is_array($role) ? $role : [$role];
        
        if (!in_array($userRole, $allowedRoles)) {
            Response::error('Forbidden: Access denied', [], Response::HTTP_FORBIDDEN);
        }
    }
    
    private static function isAuthenticated() {
        return SessionMiddleware::isAuthenticated();
    }
    
    private static function hasPermission($role, $permission) {
        $rolePermissions = self::$permissions[$role] ?? [];
        return in_array($permission, $rolePermissions);
    }

    private static function resolveRole() {
        $rawRole = $_SESSION['role']
            ?? $_SESSION['user']['role']
            ?? $_SESSION['auth_user']['role']
            ?? '';

        $role = strtolower(trim((string)$rawRole));

        if ($role === 'admin_maintenance') {
            return ROLE_MAINTENANCE_ADMIN;
        }

        if ($role === 'eelab_staff' || $role === 'maintenance_personnel') {
            return ROLE_MAINTENANCE_STAFF;
        }

        return $role;
    }
}
