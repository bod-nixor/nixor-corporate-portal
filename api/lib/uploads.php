<?php
function validate_upload_extension(string $fileName): void {
    $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        respond(['ok' => false, 'error' => 'File type not allowed'], 400);
    }
}

function validate_upload_mime(string $filePath): string {
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
    return $mime;
}

function validate_upload_tmp_file(array $file): void {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        respond(['ok' => false, 'error' => 'Upload failed'], 400);
    }
}

function upload_base_path(): string {
    $base = env_value('UPLOAD_PATH', dirname(__DIR__, 2) . '/uploads');
    if (!is_dir($base)) {
        if (!mkdir($base, 0775, true) && !is_dir($base)) {
            respond(['ok' => false, 'error' => 'Failed to create upload directory'], 500);
        }
    }
    return rtrim($base, '/\\');
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
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        respond(['ok' => false, 'error' => 'File too large (10MB limit)'], 400);
    }
    validate_upload_tmp_file($file);
    $basename = basename($file['name']);
    validate_upload_extension($basename);
    $mime = validate_upload_mime($file['tmp_name']);
    $filename = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        respond(['ok' => false, 'error' => 'Upload failed'], 500);
    }
    $normalizedPath = realpath($path) ?: $path;
    $uploadsBase = upload_base_path();
    $normalizedBase = realpath($uploadsBase) ?: $uploadsBase;
    $relative = str_starts_with($normalizedPath, $normalizedBase)
        ? ltrim(substr($normalizedPath, strlen($normalizedBase)), '/\\')
        : 'endeavours/' . $endeavourId . '/' . $docType . '/' . $filename;
    if (!str_starts_with($normalizedPath, $normalizedBase)) {
        error_log("Upload path mismatch: normalized={$normalizedPath} base={$normalizedBase}");
    }
    return ['path' => $relative, 'original' => $file['name'], 'mime' => $mime];
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
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        respond(['ok' => false, 'error' => 'File too large (10MB limit)'], 400);
    }
    validate_upload_tmp_file($file);
    $basename = basename($file['name']);
    validate_upload_extension($basename);
    $mime = validate_upload_mime($file['tmp_name']);
    $filename = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        respond(['ok' => false, 'error' => 'Upload failed'], 500);
    }
    $normalizedPath = realpath($path) ?: $path;
    $normalizedBase = realpath($uploadsBase) ?: $uploadsBase;
    $relative = str_starts_with($normalizedPath, $normalizedBase)
        ? ltrim(substr($normalizedPath, strlen($normalizedBase)), '/\\')
        : 'drive/' . $safeEntityId . '/' . $filename;
    if (!str_starts_with($normalizedPath, $normalizedBase)) {
        error_log("Drive upload path mismatch: normalized={$normalizedPath} base={$normalizedBase}");
    }
    return ['path' => $relative, 'original' => $basename, 'size' => $file['size'] ?? 0, 'mime' => $mime];
}

function social_image_upload_limits(): array {
    return [
        'max_files' => 10,
        'max_size' => 10 * 1024 * 1024,
        'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ];
}

function validate_social_image_upload(array $file): string {
    validate_upload_tmp_file($file);

    $limits = social_image_upload_limits();
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        respond(['ok' => false, 'error' => 'Image file is empty'], 400);
    }
    if ($size > $limits['max_size']) {
        respond(['ok' => false, 'error' => 'Image file too large (10MB limit)'], 400);
    }

    $basename = basename((string)($file['name'] ?? ''));
    $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $limits['extensions'], true)) {
        respond(['ok' => false, 'error' => 'Image type not allowed'], 400);
    }

    $finfoMime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $finfoMime = finfo_file($finfo, $file['tmp_name']) ?: null;
            finfo_close($finfo);
        }
    }
    $mime = $finfoMime ?: mime_content_type($file['tmp_name']);
    if (!$mime || !in_array($mime, $limits['mimes'], true)) {
        respond(['ok' => false, 'error' => 'Image type not allowed'], 400);
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    if (!$imageInfo || empty($imageInfo['mime']) || !in_array($imageInfo['mime'], $limits['mimes'], true)) {
        respond(['ok' => false, 'error' => 'Invalid image file'], 400);
    }

    return $mime;
}

function save_social_image_file(array $file): array {
    $mime = validate_social_image_upload($file);
    $basename = basename((string)($file['name'] ?? 'image'));
    $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
    $ext = $ext === 'jpeg' ? 'jpg' : $ext;

    $uploadsBase = upload_base_path();
    $dir = $uploadsBase . '/social/' . gmdate('Y') . '/' . gmdate('m');
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            respond(['ok' => false, 'error' => 'Failed to create upload directory'], 500);
        }
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        respond(['ok' => false, 'error' => 'Upload failed'], 500);
    }

    $normalizedPath = realpath($path) ?: $path;
    $normalizedBase = realpath($uploadsBase) ?: $uploadsBase;
    $normalizedPathForCheck = str_replace('\\', '/', $normalizedPath);
    $normalizedBaseForCheck = rtrim(str_replace('\\', '/', $normalizedBase), '/');
    if (!str_starts_with($normalizedPathForCheck, $normalizedBaseForCheck . '/')) {
        @unlink($path);
        error_log("Social upload path mismatch: normalized={$normalizedPath} base={$normalizedBase}");
        respond(['ok' => false, 'error' => 'Upload failed'], 500);
    }

    return [
        'path' => ltrim(substr($normalizedPathForCheck, strlen($normalizedBaseForCheck)), '/'),
        'original' => mb_substr(preg_replace('/[^\w.\- ]/', '_', $basename), 0, 190, 'UTF-8'),
        'size' => (int)($file['size'] ?? 0),
        'mime' => $mime,
    ];
}

function delete_uploaded_relative_path(?string $relativePath): void {
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '') {
        return;
    }
    $base = realpath(upload_base_path());
    if (!$base) {
        return;
    }
    $fullPath = $base . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    $resolved = realpath($fullPath);
    $normalizedResolved = $resolved ? str_replace('\\', '/', $resolved) : '';
    $normalizedBase = rtrim(str_replace('\\', '/', $base), '/');
    if ($resolved && is_file($resolved) && str_starts_with($normalizedResolved, $normalizedBase . '/')) {
        @unlink($resolved);
    }
}

function resolve_upload_path(string $relativePath): string {
    $relativePath = ltrim($relativePath, '/');
    do {
        $prev = $relativePath;
        $relativePath = str_replace(['../', '..\\', '..'], '', $relativePath);
    } while ($prev !== $relativePath);

    $base = realpath(upload_base_path());
    if (!$base || !is_dir($base)) {
        error_log('Upload base path unreachable');
        respond(['ok' => false, 'error' => 'Storage not available'], 500);
    }

    $fullPath = $base . DIRECTORY_SEPARATOR . $relativePath;
    $resolved = realpath($fullPath);

    if (!$resolved || !file_exists($resolved)) {
        respond(['ok' => false, 'error' => 'File not found'], 404);
    }

    if (!str_starts_with($resolved, $base . DIRECTORY_SEPARATOR)) {
        error_log("Path traversal attempt: {$resolved}");
        respond(['ok' => false, 'error' => 'Access denied'], 403);
    }

    return $resolved;
}
