<?php
function ensure_upload_dir(string $endeavourId, string $docType): string {
    $base = dirname(__DIR__, 2) . '/uploads/' . $endeavourId . '/' . $docType;
    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }
    return $base;
}

function save_uploaded_file(string $endeavourId, string $docType, array $file): array {
    $dir = ensure_upload_dir($endeavourId, $docType);
    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $path = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        respond(['ok' => false, 'error' => 'Upload failed'], 500);
    }
    $relative = str_replace(dirname(__DIR__, 2), '', $path);
    return ['path' => $relative, 'original' => $file['name']];
}
