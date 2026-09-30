<?php
/**
 * Auth Controller
 * Handles authentication endpoints
 */

class AuthController {
    private $authService;
    
    public function __construct($pdo) {
        $this->authService = new AuthenticationService($pdo);
    }
    
    public function login($data) {
        $result = $this->authService->login($data['email'], $data['password']);
        
        if ($result['success']) {
            Response::success($result['message'], ['user' => $result['user']], Response::HTTP_OK);
        } else {
            Response::error($result['message'], [], Response::HTTP_UNAUTHORIZED);
        }
    }
    
    public function logout() {
        SessionMiddleware::initialize();
        AuthMiddleware::protect();
        
        $result = $this->authService->logout();
        Response::success($result['message']);
    }
    
    public function register($data) {
        SessionMiddleware::initialize();
        AuthMiddleware::protect();
        RoleMiddleware::requireRole([ROLE_SUPER_ADMIN, ROLE_DEPARTMENT_ADMIN]);
        
        $result = $this->authService->register($data);
        
        if ($result['success']) {
            Response::success($result['message'], ['user_id' => $result['user_id']], Response::HTTP_CREATED);
        } else {
            $statusCode = isset($result['errors']) ? Response::HTTP_BAD_REQUEST : Response::HTTP_CONFLICT;
            Response::error($result['message'], $result['errors'] ?? [], $statusCode);
        }
    }
}
