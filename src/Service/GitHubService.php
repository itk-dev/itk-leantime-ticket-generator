<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * GitHub REST API client.
 *
 * Reads repositories, issues and milestones from a single configured GitHub
 * organization. Authentication is optional: when GITHUB_TOKEN is empty the
 * service hits the API anonymously (60 req/h IP rate limit, public data only).
 * When the token is set, private repos and the authenticated 5,000 req/h
 * limit become available.
 *
 * Responses are cached server-side so that the setup page does not hit the
 * GitHub API on every request. The repository list (which is paginated and
 * therefore expensive) is cached for ten minutes; issue and milestone lists
 * are cached for one minute so users still see fresh state when iterating.
 */
readonly class GitHubService
{
    /** Accept header value for the GitHub v3 REST API. */
    private const string ACCEPT_HEADER = 'application/vnd.github+json';
    /** GitHub REST API version identifier. */
    private const string API_VERSION = '2022-11-28';
    /** Page size used for paginated list endpoints. */
    private const int PER_PAGE = 100;
    /** Issue / milestone state filter value for open items. */
    private const string STATE_OPEN = 'open';
    /** Cache TTL for the org repository list, in seconds. */
    private const int CACHE_TTL_REPOS = 600;
    /** Cache TTL for issues and milestones, in seconds. */
    private const int CACHE_TTL_ITEMS = 60;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private string $githubApiUrl,
        private string $githubOrg,
        private string $githubToken,
    ) {
    }

    /**
     * Fetch all repositories belonging to the configured organization.
     *
     * Returns each repo's bare name as both key and value so the caller can
     * build a Symfony ChoiceType without further mapping.
     *
     * @return array<string, string> repo name => repo name, sorted alphabetically
     *
     * @throws \RuntimeException when GITHUB_ORG is empty or the API returns a non-2xx response
     */
    public function getRepos(): array
    {
        $this->requireOrg();

        return $this->cache->get(
            $this->cacheKey('repos', $this->githubOrg),
            function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL_REPOS);

                $repos = [];
                foreach ($this->requestPaginated(sprintf('/orgs/%s/repos', rawurlencode($this->githubOrg)), ['type' => 'all']) as $repo) {
                    if (isset($repo['name'])) {
                        $repos[$repo['name']] = $repo['name'];
                    }
                }

                ksort($repos);

                return $repos;
            },
        );
    }

    /**
     * Fetch all open issues for the given repository.
     *
     * The GitHub /repos/{owner}/{repo}/issues endpoint also returns pull requests;
     * those are filtered out via the "pull_request" key. Null bodies are coerced
     * to an empty string and labels are flattened to an array of label names.
     *
     * @param string $repo the repository name within the configured organization
     *
     * @return list<array{number: int, title: string, body: string, labels: list<string>, html_url: string}>
     *
     * @throws \RuntimeException when GITHUB_ORG is empty or the API returns a non-2xx response
     */
    public function getOpenIssues(string $repo): array
    {
        $this->requireOrg();

        return $this->cache->get(
            $this->cacheKey('issues', $this->githubOrg, $repo),
            function (ItemInterface $item) use ($repo): array {
                $item->expiresAfter(self::CACHE_TTL_ITEMS);

                $items = [];
                foreach ($this->requestPaginated(sprintf('/repos/%s/%s/issues', rawurlencode($this->githubOrg), rawurlencode($repo)), ['state' => self::STATE_OPEN]) as $issue) {
                    if (isset($issue['pull_request'])) {
                        continue;
                    }

                    $labels = [];
                    foreach ($issue['labels'] ?? [] as $label) {
                        if (isset($label['name'])) {
                            $labels[] = (string) $label['name'];
                        }
                    }

                    $items[] = [
                        'number' => (int) ($issue['number'] ?? 0),
                        'title' => (string) ($issue['title'] ?? ''),
                        'body' => (string) ($issue['body'] ?? ''),
                        'labels' => $labels,
                        'html_url' => (string) ($issue['html_url'] ?? ''),
                    ];
                }

                return $items;
            },
        );
    }

    /**
     * Fetch all open milestones for the given repository.
     *
     * Null descriptions are coerced to an empty string. The due_on value is left
     * as the ISO 8601 timestamp returned by GitHub (or null when unset).
     *
     * @param string $repo the repository name within the configured organization
     *
     * @return list<array{number: int, title: string, description: string, due_on: ?string, html_url: string}>
     *
     * @throws \RuntimeException when GITHUB_ORG is empty or the API returns a non-2xx response
     */
    public function getOpenMilestones(string $repo): array
    {
        $this->requireOrg();

        return $this->cache->get(
            $this->cacheKey('milestones', $this->githubOrg, $repo),
            function (ItemInterface $item) use ($repo): array {
                $item->expiresAfter(self::CACHE_TTL_ITEMS);

                $items = [];
                foreach ($this->requestPaginated(sprintf('/repos/%s/%s/milestones', rawurlencode($this->githubOrg), rawurlencode($repo)), ['state' => self::STATE_OPEN]) as $milestone) {
                    $items[] = [
                        'number' => (int) ($milestone['number'] ?? 0),
                        'title' => (string) ($milestone['title'] ?? ''),
                        'description' => (string) ($milestone['description'] ?? ''),
                        'due_on' => $milestone['due_on'] ?? null,
                        'html_url' => (string) ($milestone['html_url'] ?? ''),
                    ];
                }

                return $items;
            },
        );
    }

    /**
     * Ensure the GITHUB_ORG environment variable is configured.
     *
     * @throws \RuntimeException when the configured organization is empty
     */
    private function requireOrg(): void
    {
        if ('' === $this->githubOrg) {
            throw new \RuntimeException('GITHUB_ORG is not configured. Set it in .env.local.');
        }
    }

    /**
     * Build a deterministic, PSR-6-safe cache key from the given parts.
     *
     * Each part is hashed with crc32 so the resulting key contains only
     * characters allowed by PSR-6 (alphanumerics, underscore, dot).
     *
     * @param string ...$parts the components of the key
     *
     * @return string the composed cache key
     */
    private function cacheKey(string ...$parts): string
    {
        $hashed = array_map(static fn (string $part): string => dechex(crc32($part)), $parts);

        return 'github.'.implode('.', $hashed);
    }

    /**
     * Send a GET request to the GitHub API and decode the JSON response.
     *
     * Attaches the standard Accept and X-GitHub-Api-Version headers, and an
     * Authorization header only when GITHUB_TOKEN is set. A non-2xx response
     * raises a RuntimeException using GitHub's "message" field when available.
     *
     * @param string               $path  the path portion of the URL (must start with "/")
     * @param array<string, mixed> $query query string parameters
     *
     * @return array<mixed> the decoded JSON body
     *
     * @throws \RuntimeException when the API returns a non-2xx response
     */
    private function request(string $path, array $query = []): array
    {
        $url = rtrim($this->githubApiUrl, '/').$path;

        $headers = [
            'Accept' => self::ACCEPT_HEADER,
            'X-GitHub-Api-Version' => self::API_VERSION,
        ];

        if ('' !== $this->githubToken) {
            $headers['Authorization'] = 'Bearer '.$this->githubToken;
        }

        $response = $this->httpClient->request('GET', $url, [
            'headers' => $headers,
            'query' => $query,
        ]);

        $status = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($status < 200 || $status >= 300) {
            $message = isset($data['message']) ? (string) $data['message'] : 'Unknown error';
            throw new \RuntimeException(sprintf('GitHub API error (%d): %s', $status, $message));
        }

        return $data;
    }

    /**
     * Iterate every page of a list endpoint and yield individual items.
     *
     * Pages until a response is smaller than the configured page size,
     * which signals the last page.
     *
     * @param string               $path  the path portion of the URL (must start with "/")
     * @param array<string, mixed> $query base query parameters; page and per_page are added per request
     *
     * @return iterable<array<string, mixed>>
     *
     * @throws \RuntimeException when any page returns a non-2xx response
     */
    private function requestPaginated(string $path, array $query = []): iterable
    {
        $page = 1;

        while (true) {
            $pageQuery = array_merge($query, ['per_page' => self::PER_PAGE, 'page' => $page]);
            $items = $this->request($path, $pageQuery);

            foreach ($items as $item) {
                yield $item;
            }

            if (count($items) < self::PER_PAGE) {
                break;
            }

            ++$page;
        }
    }
}
