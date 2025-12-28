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

function upload_base_path(): string {
    $base = env_value('UPLOAD_PATH', dirname(__DIR__, 2) . '/uploads');
    if (!is_dir($base)) {
        if (!mkdir($base, 0775, true) && !is_dir($base)) {
            respond(['ok' => false, 'error' => 'Failed to create upload directory'], 500);
        }
    }
    return rtrim($base, '/');
}

function ensure_upload_dir(string $endeavourId, string $docType): string {
    $safeEndeavourId = preg_replace('/[^a-zA-Z0-9_-]/', '', $endeavourId);
    $safeDocType = preg_replace('/[^a-zA-Z0-9_-]/', '', $docType);
    $base = upload_base_path() . '/endeavours/' . $safeEndeavourId . '/' . $safeDocType;
    if (!is_dir($base)) {
        if (!mkdir($base, 0775, true) && !is_dir($base)) {
            respond(['ok' => false, 'error' => 'Failed to create upload directory'], 500);
        }
    }
    return $base;
}

function save_uploaded_file(string $endeavourId, string $docType, array $file): array {
    $dir = ensure_upload_dir($endeavourId, $docType);
    $basename = basename($file['name']);
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        respond(['ok' => false, 'error' => 'File too large'], 400);
    }
    validate_upload_extension($basename);
    validate_upload_mime($file['tmp_name']);
    $filename = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        respond(['ok' => false, 'error' => 'Upload failed'], 500);
    }
    $normalizedPath = realpath($path) ?: $path;
    $uploadsBase = upload_base_path();
    $normalizedBase = realpath($uploadsBase) ?: $uploadsBase;
    $relative = str_starts_with($normalizedPath, $normalizedBase)
        ? ltrim(substr($normalizedPath, strlen($normalizedBase)), '/')
        : 'endeavours/' . $endeavourId . '/' . $docType . '/' . $filename;
    return ['path' => $relative, 'original' => $file['name']];
}

function save_drive_file(string $entityId, array $file): array {
    $safeEntityId = preg_replace('/[^a-zA-Z0-9_-]/', '', $entityId);
    $uploadsBase = upload_base_path();
    $dir = $uploadsBase . '/drive/' . $safeEntityId;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            respond(['ok' => false, 'error' => 'Failed to create upload directory'], 500);
        }
    }
    $basename = basename($file['name']);
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        respond(['ok' => false, 'error' => 'File too large'], 400);
    }
    validate_upload_extension($basename);
    validate_upload_mime($file['tmp_name']);
    $filename = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        respond(['ok' => false, 'error' => 'Upload failed'], 500);
    }
    $normalizedPath = realpath($path) ?: $path;
    $normalizedBase = realpath($uploadsBase) ?: $uploadsBase;
    $relative = str_starts_with($normalizedPath, $normalizedBase)
        ? ltrim(substr($normalizedPath, strlen($normalizedBase)), '/')
        : 'drive/' . $safeEntityId . '/' . $filename;
    return ['path' => $relative, 'original' => $basename, 'size' => $file['size'] ?? 0];
}

function resolve_upload_path(string $relativePath): string {
    $relativePath = ltrim($relativePath, '/');
    do {
        $prev = $relativePath;
        $relativePath = str_replace(['../', '..\\', '..'], '', $relativePath);
    } while ($prev !== $relativePath);
    return upload_base_path() . '/' . $relativePath;
}
