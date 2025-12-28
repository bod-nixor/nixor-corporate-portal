<?php
function validate_upload_extension(string $fileName): void {
    $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        respond(['ok' => false, 'error' => 'File type not allowed'], 400);
    }
}

function validate_upload_mime(string $filePath): void {
    $allowedMime = [
        'image/jpeg',
        'image/png',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    $mime = mime_content_type($filePath);
    if (!$mime || !in_array($mime, $allowedMime, true)) {
        respond(['ok' => false, 'error' => 'File type not allowed'], 400);
    }
}

function ensure_upload_dir(string $endeavourId, string $docType): string {
    $safeEndeavourId = preg_replace('/[^a-zA-Z0-9_-]/', '', $endeavourId);
    $safeDocType = preg_replace('/[^a-zA-Z0-9_-]/', '', $docType);
    $base = dirname(__DIR__, 2) . '/uploads/' . $safeEndeavourId . '/' . $safeDocType;
    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }
    return $base;
}

function save_uploaded_file(string $endeavourId, string $docType, array $file): array {
    $dir = ensure_upload_dir($endeavourId, $docType);
    $basename = basename($file['name']);
    validate_upload_extension($basename);
    validate_upload_mime($file['tmp_name']);
    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        respond(['ok' => false, 'error' => 'Upload failed'], 500);
    }
    $normalizedPath = realpath($path) ?: $path;
    $uploadsBase = dirname(__DIR__, 2) . '/uploads';
    $normalizedBase = realpath($uploadsBase) ?: $uploadsBase;
    if (str_starts_with($normalizedPath, $normalizedBase)) {
        $relative = substr($normalizedPath, strlen($normalizedBase));
    } else {
        $relative = '/uploads/' . $endeavourId . '/' . $docType . '/' . $filename;
    }
    return ['path' => $relative, 'original' => $file['name']];
}

function save_drive_file(string $entityId, array $file): array {
    $safeEntityId = preg_replace('/[^a-zA-Z0-9_-]/', '', $entityId);
    $uploadsBase = dirname(__DIR__, 2) . '/uploads';
    $dir = $uploadsBase . '/drive/' . $safeEntityId;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $basename = basename($file['name']);
    validate_upload_extension($basename);
    validate_upload_mime($file['tmp_name']);
    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        respond(['ok' => false, 'error' => 'Upload failed'], 500);
    }
    $normalizedPath = realpath($path) ?: $path;
    $normalizedBase = realpath($uploadsBase) ?: $uploadsBase;
    if (str_starts_with($normalizedPath, $normalizedBase)) {
        $relative = substr($normalizedPath, strlen($normalizedBase));
    } else {
        $relative = '/drive/' . $safeEntityId . '/' . $filename;
    }
    return ['path' => $relative, 'original' => $basename, 'size' => $file['size'] ?? 0];
}
