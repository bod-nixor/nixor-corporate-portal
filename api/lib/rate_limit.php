<?php
function get_client_ip(): string {
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded) {
        $parts = array_map('trim', explode(',', $forwarded));
        $candidate = $parts[0] ?? '';
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if ($realIp && filter_var($realIp, FILTER_VALIDATE_IP)) {
        return $realIp;
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

function rate_limit(string $key, int $limit, int $windowSeconds): bool {
    $ip = get_client_ip();
    $bucket = sys_get_temp_dir() . '/nixor_rate_' . md5($key . $ip);
    $now = time();
    $handle = fopen($bucket, 'c+');
    if ($handle === false) {
        error_log("Rate limit bucket open failed for key={$key} ip={$ip}");
        return true;
    }
    flock($handle, LOCK_EX);
    $contents = stream_get_contents($handle);
    $entries = [];
    if ($contents) {
        $data = json_decode($contents, true);
        if (is_array($data)) {
            $entries = array_filter($data, fn($ts) => ($now - $ts) < $windowSeconds);
        }
    }
    if (count($entries) >= $limit) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return false;
    }
    $entries[] = $now;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode(array_values($entries)));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return true;
}
