<?php

namespace App\Service;

/**
 * Helper service for preparing GitHub-sourced data and orchestrating
 * Leantime ticket creation from GitHub issues or milestones.
 *
 * Acts as an intermediary between the controller and the lower-level
 * GitHubService and LeantimeService: shapes data for forms, builds
 * pre-populated form rows from fetched GitHub items, and performs the
 * batch ticket creation loop.
 */
readonly class GitHubHelperService
{
    /** Source value indicating GitHub issues should be imported. */
    public const string SOURCE_ISSUES = 'issues';
    /** Source value indicating GitHub milestones should be imported. */
    public const string SOURCE_MILESTONES = 'milestones';
    /** Date format used when emitting due dates to the Leantime API. */
    public const string DATE_FORMAT = 'Y-m-d';
    /** Fallback name when a project ID cannot be resolved. */
    private const string UNKNOWN_PROJECT = 'Unknown project';

    public function __construct(
        private GitHubService $github,
        private LeantimeService $leantime,
    ) {
    }

    /**
     * Get repository names for the configured organization as form choices.
     *
     * @return array<string, string> repo name => repo name
     *
     * @throws \RuntimeException when the GitHub API returns an error
     */
    public function getRepoChoices(): array
    {
        return $this->github->getRepos();
    }

    /**
     * Build pre-populated form rows from the GitHub items of the chosen source.
     *
     * Each row carries the fields the user-facing form needs (visible inputs
     * default to sensible values; hidden inputs round-trip enough of the
     * GitHub payload to create the ticket on submit without refetching).
     *
     * @param string $source one of self::SOURCE_ISSUES or self::SOURCE_MILESTONES
     * @param string $repo   the GitHub repository name
     *
     * @return list<array{include: bool, number: int, title: string, description: string, html_url: string, labels: string, planned_hours: float, priority: string, due_date: \DateTimeInterface}>
     *
     * @throws \RuntimeException when the GitHub API returns an error
     */
    public function buildFormRows(string $source, string $repo): array
    {
        $today = new \DateTimeImmutable('today');

        if (self::SOURCE_ISSUES === $source) {
            $rows = [];
            foreach ($this->github->getOpenIssues($repo) as $issue) {
                $rows[] = [
                    'include' => false,
                    'number' => $issue['number'],
                    'title' => $issue['title'],
                    'description' => $issue['body'],
                    'html_url' => $issue['html_url'],
                    'labels' => implode(',', $issue['labels']),
                    'planned_hours' => 1.0,
                    'priority' => '3',
                    'due_date' => $today,
                ];
            }

            return $rows;
        }

        $rows = [];
        foreach ($this->github->getOpenMilestones($repo) as $milestone) {
            $rows[] = [
                'include' => false,
                'number' => $milestone['number'],
                'title' => $milestone['title'],
                'description' => $milestone['description'],
                'html_url' => $milestone['html_url'],
                'labels' => '',
                'planned_hours' => 1.0,
                'priority' => '3',
                'due_date' => $this->parseMilestoneDueDate($milestone['due_on']) ?? $today,
            ];
        }

        return $rows;
    }

    /**
     * Create Leantime tickets from the submitted form rows.
     *
     * Iterates the submitted rows, skipping any row whose "include" checkbox
     * is unticked. Each remaining row produces one Leantime ticket in the
     * chosen project. For issues, the GitHub html_url is appended to the
     * ticket description so the Leantime ticket links back to its source.
     * Per-row failures are captured in the results array rather than aborting
     * the whole batch.
     *
     * @param array<int, array<string, mixed>> $formRows  the rows from the submitted form
     * @param int                              $projectId the Leantime project to create tickets in
     * @param array<int, string>               $projects  full id => name map of Leantime projects (for display)
     *
     * @return array{results: list<array{title: string, projectName: string, ticketId?: int, error?: string, success: bool}>}
     */
    public function createTicketsFromGithub(array $formRows, int $projectId, array $projects): array
    {
        $projectName = $projects[$projectId] ?? self::UNKNOWN_PROJECT;
        $results = [];

        foreach ($formRows as $row) {
            if (empty($row['include'])) {
                continue;
            }

            $title = (string) ($row['title'] ?? '');
            $description = (string) ($row['description'] ?? '');
            $htmlUrl = (string) ($row['html_url'] ?? '');
            $labels = (string) ($row['labels'] ?? '');
            $priority = (string) ($row['priority'] ?? '3');
            $plannedHours = (float) ($row['planned_hours'] ?? 1);
            $dueDate = $row['due_date'] ?? null;

            $date = $dueDate instanceof \DateTimeInterface
                ? $dueDate->format(self::DATE_FORMAT)
                : (new \DateTimeImmutable('today'))->format(self::DATE_FORMAT);

            if ('' !== $htmlUrl) {
                $description = '' === $description
                    ? sprintf('Source: %s', $htmlUrl)
                    : sprintf("%s\n\nSource: %s", $description, $htmlUrl);
            }

            try {
                $ticketId = $this->leantime->createTicket(
                    $title,
                    $projectId,
                    $description,
                    $labels,
                    null,
                    null,
                    $date,
                    $plannedHours,
                    $priority,
                );

                $results[] = [
                    'title' => $title,
                    'projectName' => $projectName,
                    'ticketId' => $ticketId,
                    'success' => true,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'title' => $title,
                    'projectName' => $projectName,
                    'error' => $e->getMessage(),
                    'success' => false,
                ];
            }
        }

        return ['results' => $results];
    }

    /**
     * Parse a GitHub milestone "due_on" timestamp into a DateTimeImmutable.
     *
     * GitHub returns ISO 8601 strings like "2025-12-31T23:59:59Z". The time
     * portion is discarded by the consumer (only Y-m-d is sent to Leantime),
     * but kept here for fidelity. Returns null when the input is null or
     * cannot be parsed.
     *
     * @param string|null $dueOn the raw due_on value from the GitHub API
     *
     * @return \DateTimeImmutable|null the parsed date, or null when the input is null/invalid
     */
    private function parseMilestoneDueDate(?string $dueOn): ?\DateTimeImmutable
    {
        if (null === $dueOn || '' === $dueOn) {
            return null;
        }

        try {
            return new \DateTimeImmutable($dueOn);
        } catch (\Exception) {
            return null;
        }
    }
}
