<?php
declare(strict_types=1);

/** Small HTTPS transport shared by OAuth and Gmail. Error messages omit response data and credentials. */
class Http
{
    public function json(string $method, string $url, array $headers = [], ?string $data = null): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($data !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
        }
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $host = parse_url($url, PHP_URL_HOST);
        if ($response === false) {
            throw new RuntimeException("Connection to $host failed.");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("$host returned HTTP $status.", $status);
        }
        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("$host returned an unexpected response.");
        }
        return $decoded;
    }
}
