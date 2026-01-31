<?php
class TestClient {
    private string $baseUrl;
    private string $cookieJar;
    private array $lastHeaders = [];

    public function __construct(string $baseUrl) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'nixor_cookie_') ?: sys_get_temp_dir() . '/nixor_cookie.txt';
    }

    public function request(string $method, string $path, ?array $body = null, array $headers = []): array {
        $this->lastHeaders = [];
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        $payload = $body !== null ? json_encode($body) : null;
        $defaultHeaders = ['Accept: application/json'];
        if ($payload !== null) {
            $defaultHeaders[] = 'Content-Type: application/json';
        }
        foreach ($headers as $header) {
            $defaultHeaders[] = $header;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $defaultHeaders,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) {
                $len = strlen($header);
                $header = trim($header);
                if ($header !== '') {
                    $this->lastHeaders[] = $header;
                }
                return $len;
            }
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException($error);
        }
        curl_close($ch);
        $data = json_decode($response, true);
        return [
            'status' => $status,
            'data' => is_array($data) ? $data : [],
            'headers' => $this->lastHeaders
        ];
    }

    public function getCookie(string $name): ?string {
        $contents = file_get_contents($this->cookieJar);
        if ($contents === false) {
            return null;
        }
        foreach (explode("\n", $contents) as $line) {
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#HttpOnly_')) {
                $line = substr($line, strlen('#HttpOnly_'));
            } elseif (str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[5] === $name) {
                return $parts[6] ?? null;
            }
        }
        return null;
    }
}
