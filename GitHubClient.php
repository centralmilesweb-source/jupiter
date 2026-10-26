<?php
/**
 * Minimal GitHub REST API client for core PHP (cURL only, no Composer).
 *
 * Usage:
 *   $gh = new GitHubClient($config);
 *   $repos = $gh->get('/user/repos', ['per_page' => 20]);
 */
class GitHubClient
{
    private const BASE_URL    = 'https://api.github.com';
    private const API_VERSION = '2022-11-28';

    private string $token;
    private string $userAgent;
    private int $cacheTtl;
    private string $cacheDir;

    /** Rate limit info from the last response */
    public array $rateLimit = ['limit' => null, 'remaining' => null, 'reset' => null];

    public function __construct(array $config)
    {
        $this->token     = $config['token'] ?? '';
        $this->userAgent = $config['user_agent'] ?? 'PHP-GitHub-Client';
        $this->cacheTtl  = (int) ($config['cache_ttl'] ?? 0);
        $this->cacheDir  = $config['cache_dir'] ?? sys_get_temp_dir();

        if ($this->token === '') {
            throw new InvalidArgumentException('GitHub token is missing in config.');
        }
    }

    public function get(string $path, array $query = []): array
    {
        $url = self::BASE_URL . $path . ($query ? '?' . http_build_query($query) : '');

        $cached = $this->readCache($url);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->request('GET', $url);
        $this->writeCache($url, $data);
        return $data;
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', self::BASE_URL . $path, $body);
    }

    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', self::BASE_URL . $path, $body);
    }

    /**
     * Fetch every page of a list endpoint (follows the Link header).
     * Use a sensible $maxPages to avoid burning the rate limit.
     */
    public function getAll(string $path, array $query = [], int $maxPages = 5): array
    {
        $query['per_page'] = $query['per_page'] ?? 100;
        $all = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $query['page'] = $page;
            $items = $this->get($path, $query);
            $all = array_merge($all, $items);
            if (count($items) < $query['per_page']) {
                break;
            }
        }
        return $all;
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $this->token,
            'X-GitHub-Api-Version: ' . self::API_VERSION,
            'User-Agent: ' . $this->userAgent,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) {
                $this->captureRateLimit($line);
                return strlen($line);
            },
        ]);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('cURL error: ' . $error);
        }

        $data = json_decode($response, true) ?? [];

        if ($status >= 400) {
            $message = $data['message'] ?? 'Unknown error';
            if ($status === 403 && $this->rateLimit['remaining'] === '0') {
                $message = 'Rate limit exceeded. Resets at '
                    . date('H:i:s', (int) $this->rateLimit['reset']);
            }
            throw new RuntimeException("GitHub API {$status}: {$message}", $status);
        }

        return $data;
    }

    private function captureRateLimit(string $line): void
    {
        $map = [
            'x-ratelimit-limit'     => 'limit',
            'x-ratelimit-remaining' => 'remaining',
            'x-ratelimit-reset'     => 'reset',
        ];
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $key = strtolower(trim($parts[0]));
            if (isset($map[$key])) {
                $this->rateLimit[$map[$key]] = trim($parts[1]);
            }
        }
    }

    private function cacheFile(string $url): string
    {
        return rtrim($this->cacheDir, '/') . '/gh_' . md5($url) . '.json';
    }

    private function readCache(string $url): ?array
    {
        if ($this->cacheTtl <= 0) {
            return null;
        }
        $file = $this->cacheFile($url);
        if (is_file($file) && (time() - filemtime($file)) < $this->cacheTtl) {
            return json_decode((string) file_get_contents($file), true);
        }
        return null;
    }

    private function writeCache(string $url, array $data): void
    {
        if ($this->cacheTtl <= 0) {
            return;
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        @file_put_contents($this->cacheFile($url), json_encode($data));
    }
}
