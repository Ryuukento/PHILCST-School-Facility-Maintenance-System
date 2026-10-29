<?php

// Rendering only: no database access, file changes, or remote image requests.
function sfms_avatar_initials(?string $name): string
{
    $parts = preg_split('/[\p{Z}\s]+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_substr($part, 0, 1, 'UTF-8');
    }
    return mb_strtoupper($initials !== '' ? $initials : 'U', 'UTF-8');
}

function sfms_avatar_url(?string $avatar, ?string $publicRoot = null): string
{
    $value = trim((string) $avatar);
    if ($value === '' || preg_match('#^(?:https?:)?//#i', $value)) return $value;
    if (preg_match('#^(?:javascript|data|vbscript):#i', $value)) return '';
    $path = preg_replace('/[?#].*$/s', '', $value);
    if (!preg_match('#^/?(?:School_Facility_Maintenance_System/)?(?:public/)?frontend/assets/uploads/avatars/([^/]+)$#D', $path, $match)) {
        return $value; // Unrelated paths are not avatar upload paths to rewrite or probe.
    }
    $filename = rawurldecode($match[1]);
    if ($filename === '' || str_contains($filename, "\0") || str_contains($filename, '\\')
        || basename($filename) !== $filename || in_array($filename, ['.', '..'], true)) return '';
    $directory = realpath(($publicRoot ?? dirname(__DIR__, 2)) . '/frontend/assets/uploads/avatars');
    if ($directory === false) return '';
    $file = realpath($directory . DIRECTORY_SEPARATOR . $filename);
    if ($file === false || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR)
        || !is_file($file) || !is_readable($file)) return '';
    return public_url('/frontend/assets/uploads/avatars/' . rawurlencode($filename)) . substr($value, strlen($path));
}
