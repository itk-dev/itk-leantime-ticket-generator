<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * JSON-RPC 2.0 client for the Leantime API.
 *
 * Handles authentication and provides methods for managing
 * projects, milestones, users, and tickets in Leantime.
 */
class LeantimeService
{
    /** JSON-RPC protocol version used by the Leantime API. */
    private const string JSONRPC_VERSION = '2.0';
    /** Path to the Leantime JSON-RPC endpoint. */
    private const string API_PATH = '/api/jsonrpc/';
    /** Date format expected by the Leantime API (ISO 8601 date). */
    private const string DATE_FORMAT = 'Y-m-d';
    /** Leantime status ID representing a new/open ticket. */
    private const string TICKET_STATUS_NEW = '3';
    /** Leantime priority ID representing critical urgency. */
    private const string TICKET_PRIORITY_CRITICAL = '1';
    /** Default number of planned and remaining hours for new tickets. */
    private const float DEFAULT_HOURS = 1;
    /** Default Leantime project name to exclude from project lists. */
    private const string EXCLUDED_PROJECT_NAME = 'My Project';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $leantimeApiUrl,
        private readonly string $leantimeApiKey,
    ) {
    }

    /**
     * Send a JSON-RPC 2.0 request to the Leantime API.
     *
     * @param string $method the JSON-RPC method name (e.g. "leantime.rpc.projects.getAllProjects")
     * @param array  $params optional parameters to pass with the request
     *
     * @return mixed the "result" value from the JSON-RPC response
     *
     * @throws \RuntimeException if the API returns an error response
     */
    public function request(string $method, array $params = []): mixed
    {
        $url = rtrim($this->leantimeApiUrl, '/').self::API_PATH;

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->leantimeApiKey,
            ],
            'json' => [
                'jsonrpc' => self::JSONRPC_VERSION,
                'method' => $method,
                'params' => $params,
                'id' => uniqid(),
            ],
        ]);

        $data = $response->toArray(false);

        if (isset($data['error'])) {
            throw new \RuntimeException(sprintf('Leantime API error (%d): %s', $data['error']['code'] ?? 0, $data['error']['message'] ?? 'Unknown error'));
        }

        return $data['result'] ?? null;
    }

    /**
     * Fetch all projects from Leantime, excluding the default "My Project".
     *
     * @return array<int, string> associative array of project id => project name
     */
    public function getProjects(): array
    {
        $result = $this->request('leantime.rpc.projects.getAllProjects');

        $projects = [];
        foreach ($result ?? [] as $project) {
            if (self::EXCLUDED_PROJECT_NAME === $project['name']) {
                continue;
            }
            $projects[$project['id']] = $project['name'];
        }

        return $projects;
    }

    /**
     * Fetch all milestones for a given project.
     *
     * @param int $projectId the Leantime project ID
     *
     * @return array<int, array> list of milestone arrays, each containing at least 'id' and 'headline'
     */
    public function getMilestones(int $projectId): array
    {
        $result = $this->request('leantime.rpc.tickets.getAllMilestones', [
            'searchCriteria' => [
                'currentProject' => $projectId,
            ],
        ]);

        return $result ?? [];
    }

    /**
     * Find an existing milestone by name in a project, or create it if it doesn't exist.
     *
     * Performs a case-insensitive search. If no match is found, creates the milestone
     * via the API and re-fetches to obtain the correct ID.
     *
     * @param int    $projectId     the Leantime project ID
     * @param string $milestoneName the milestone name to find or create
     *
     * @return array{id: int, created: bool} the milestone ID and whether it was newly created
     *
     * @throws \RuntimeException if the milestone cannot be resolved after creation
     */
    public function findOrCreateMilestone(int $projectId, string $milestoneName): array
    {
        $milestones = $this->getMilestones($projectId);

        foreach ($milestones as $milestone) {
            if (isset($milestone['headline']) && mb_strtolower($milestone['headline']) === mb_strtolower($milestoneName)) {
                return ['id' => (int) $milestone['id'], 'created' => false];
            }
        }

        $this->request('leantime.rpc.tickets.quickAddMilestone', [
            'params' => [
                'headline' => $milestoneName,
                'projectId' => $projectId,
            ],
        ]);

        // Re-fetch milestones to get the correct ID of the newly created one
        $milestones = $this->getMilestones($projectId);

        foreach ($milestones as $milestone) {
            if (isset($milestone['headline']) && mb_strtolower($milestone['headline']) === mb_strtolower($milestoneName)) {
                return ['id' => (int) $milestone['id'], 'created' => true];
            }
        }

        throw new \RuntimeException(sprintf('Failed to resolve milestone "%s" after creation.', $milestoneName));
    }

    /**
     * Fetch all users from Leantime.
     *
     * Returns user display names constructed from first and last name,
     * falling back to username if no name is available.
     *
     * @return array<int, string> associative array of user id => display name
     */
    public function getUsers(): array
    {
        $result = $this->request('leantime.rpc.users.getAll');

        $users = [];
        foreach ($result ?? [] as $user) {
            $name = trim(($user['firstname'] ?? '').' '.($user['lastname'] ?? ''));
            if ('' === $name) {
                $name = $user['username'] ?? 'Unknown';
            }
            $users[$user['id']] = $name;
        }

        return $users;
    }

    /**
     * Create a ticket in Leantime with critical priority.
     *
     * Uses the provided date for due date, work start, and work end.
     * Status is always set to new/open (3).
     *
     * @param string      $title       the ticket headline
     * @param int         $projectId   the project to create the ticket in
     * @param string      $description optional ticket description
     * @param string      $tags        optional comma-separated tags
     * @param int|null    $milestoneId optional milestone ID to associate
     * @param int|null    $userId      optional user ID to assign as editor
     * @param string|null $date        optional date (Y-m-d) for due date and work dates, defaults to today
     * @param float       $hours       planned and remaining hours, defaults to 1
     * @param string      $priority    ticket priority ID (1=Urgent, 2=High, 3=Medium, 4=Low, 5=Lowest)
     *
     * @return int the ID of the created ticket
     *
     * @throws \RuntimeException if the API returns an error
     */
    public function createTicket(string $title, int $projectId, string $description = '', string $tags = '', ?int $milestoneId = null, ?int $userId = null, ?string $date = null, float $hours = self::DEFAULT_HOURS, string $priority = self::TICKET_PRIORITY_CRITICAL): int
    {
        $date = $date ?? date(self::DATE_FORMAT);

        $result = $this->request('leantime.rpc.tickets.addTicket', [
            'values' => [
                'headline' => $title,
                'description' => $description,
                'projectId' => $projectId,
                'status' => self::TICKET_STATUS_NEW,
                'priority' => $priority,
                'dateToFinish' => $date,
                'editFrom' => $date,
                'editTo' => $date,
                'planHours' => (string) $hours,
                'hourRemaining' => (string) $hours,
                'tags' => $tags,
                'milestoneid' => $milestoneId ?? '',
                'editorId' => $userId ?? '',
            ],
        ]);

        return (int) $result[0];
    }
}
