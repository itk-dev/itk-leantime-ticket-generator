<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class LeantimeService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $leantimeApiUrl,
        private readonly string $leantimeApiKey,
    ) {
    }

    public function request(string $method, array $params = []): mixed
    {
        $url = rtrim($this->leantimeApiUrl, '/').'/api/jsonrpc/';

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->leantimeApiKey,
            ],
            'json' => [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => uniqid(),
            ],
        ]);

        $data = $response->toArray(false);

        if (isset($data['error'])) {
            throw new \RuntimeException(sprintf(
                'Leantime API error (%d): %s',
                $data['error']['code'] ?? 0,
                $data['error']['message'] ?? 'Unknown error'
            ));
        }

        return $data['result'] ?? null;
    }

    /**
     * @return array<int, string> associative array of project id => name
     */
    public function getProjects(): array
    {
        $result = $this->request('leantime.rpc.projects.getAllProjects');

        $projects = [];
        foreach ($result ?? [] as $project) {
            if ('My Project' === $project['name']) {
                continue;
            }
            $projects[$project['id']] = $project['name'];
        }

        return $projects;
    }

    /**
     * @return array list of milestone objects for the given project
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

    public function findOrCreateMilestone(int $projectId, string $milestoneName): int
    {
        $milestones = $this->getMilestones($projectId);

        foreach ($milestones as $milestone) {
            if (isset($milestone['headline']) && mb_strtolower($milestone['headline']) === mb_strtolower($milestoneName)) {
                return (int) $milestone['id'];
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
                return (int) $milestone['id'];
            }
        }

        throw new \RuntimeException(sprintf('Failed to resolve milestone "%s" after creation.', $milestoneName));
    }

    /**
     * @return array<int, string> associative array of user id => name
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

    public function createTicket(string $title, int $projectId, string $description = '', string $tags = '', ?int $milestoneId = null, ?int $userId = null, ?string $date = null, float $hours = 1): int
    {
        $date = $date ?? date('Y-m-d');

        $result = $this->request('leantime.rpc.tickets.addTicket', [
            'values' => [
                'headline' => $title,
                'description' => $description,
                'projectId' => $projectId,
                'status' => '3',
                'priority' => '1',
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
