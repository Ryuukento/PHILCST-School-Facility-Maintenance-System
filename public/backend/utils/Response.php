<?php
/**
 * Response Utility
 * Standardized API responses
 */

class Response {
    const HTTP_OK = 200;
    const HTTP_CREATED = 201;
    const HTTP_BAD_REQUEST = 400;
    const HTTP_UNAUTHORIZED = 401;
    const HTTP_FORBIDDEN = 403;
    const HTTP_NOT_FOUND = 404;
    const HTTP_CONFLICT = 409;
    const HTTP_INTERNAL_ERROR = 500;
    
    public static function success($message = 'Success', $data = [], $statusCode = self::HTTP_OK) {
        return self::send([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }
    
    public static function error($message = 'Error', $data = [], $statusCode = self::HTTP_BAD_REQUEST) {
        return self::send([
            'success' => false,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }
    
    public static function send($data, $statusCode = self::HTTP_OK) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
