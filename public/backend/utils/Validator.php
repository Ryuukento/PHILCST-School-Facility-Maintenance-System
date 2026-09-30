<?php
/**
 * Validator Utility
 * Input validation and sanitization
 */

class Validator {
    private $errors = [];
    
    public function validate($data, $rules) {
        foreach ($rules as $field => $fieldRules) {
            $this->validateField($field, $data[$field] ?? null, $fieldRules);
        }
        return empty($this->errors);
    }
    
    private function validateField($field, $value, $rules) {
        $ruleArray = is_string($rules) ? explode('|', $rules) : $rules;
        
        foreach ($ruleArray as $rule) {
            if (strpos($rule, ':') !== false) {
                list($ruleName, $params) = explode(':', $rule, 2);
                $this->applyRule($field, $value, $ruleName, $params);
            } else {
                $this->applyRule($field, $value, $rule);
            }
        }
    }
    
    private function applyRule($field, $value, $rule, $params = null) {
        switch ($rule) {
            case 'required':
                if (empty($value)) {
                    $this->addError($field, "{$field} is required");
                }
                break;
            
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "{$field} must be a valid email");
                }
                break;
            
            case 'min':
                if (strlen($value) < (int)$params) {
                    $this->addError($field, "{$field} must be at least {$params} characters");
                }
                break;
            
            case 'max':
                if (strlen($value) > (int)$params) {
                    $this->addError($field, "{$field} cannot exceed {$params} characters");
                }
                break;
            
            case 'numeric':
                if (!is_numeric($value)) {
                    $this->addError($field, "{$field} must be numeric");
                }
                break;
            
            case 'in':
                $allowed = explode(',', $params);
                if (!in_array($value, $allowed)) {
                    $this->addError($field, "{$field} has invalid value");
                }
                break;
            
            case 'letters_only':
                // Only letters, spaces, hyphens, apostrophes, and accented chars
                if ($value && !preg_match("/^[a-zA-Z\s\-'àáâãäåèéêëìíîïòóôõöùúûüñçÀÁÂÃÄÅÈÉÊËÌÍÎÏÒÓÔÕÖÙÚÛÜÑÇ]+$/", $value)) {
                    $this->addError($field, "{$field} must contain only letters, spaces, and hyphens (no numbers)");
                }
                // Also check that it's not only numbers
                if ($value && preg_match('/^\d+$/', $value)) {
                    $this->addError($field, "{$field} must contain only letters, spaces, and hyphens (no numbers)");
                }
                break;
        }
    }
    
    private function addError($field, $message) {
        $this->errors[$field] = $message;
    }
    
    public function getErrors() {
        return $this->errors;
    }
    
    public function sanitize($value, $type = 'string') {
        switch ($type) {
            case 'email':
                return filter_var($value, FILTER_SANITIZE_EMAIL);
            case 'url':
                return filter_var($value, FILTER_SANITIZE_URL);
            case 'integer':
                return (int)$value;
            case 'string':
            default:
                return trim(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        }
    }
}
