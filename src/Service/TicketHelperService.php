<?php

namespace App\Service;

/**
 * Helper service for preparing form data and orchestrating ticket creation.
 *
 * Acts as an intermediary between the controller and LeantimeService,
 * handling data transformation, form choice preparation, and batch
 * ticket creation logic.
 */
readonly class TicketHelperService
{
    /** Form value indicating the user wants to enter hours manually. */
    private const string PLANNED_HOURS_MANUAL = 'manual';
    /** Fallback hours value when manual input is empty. */
    private const float DEFAULT_MANUAL_HOURS = 1;
    /** Date format used when formatting form date values for the API. */
    public const string DATE_FORMAT = 'Y-m-d';
    /** Fallback name when a project ID cannot be resolved. */
    private const string UNKNOWN_PROJECT = 'Unknown project';
    /** Fallback name when a user ID cannot be resolved. */
    private const string UNKNOWN_USER = 'Unknown user';

    public function __construct(
        private LeantimeService $leantime,
    ) {
    }

    /**
     * Get projects formatted as form choices (name => id).
     *
     * @return array<string, int> project names as keys, IDs as values
     */
    public function getProjectChoices(): array
    {
        return array_flip($this->leantime->getProjects());
    }

    /**
     * Get all projects from Leantime.
     *
     * @return array<int, string> project IDs as keys, names as values
     */
    public function getProjects(): array
    {
        return $this->leantime->getProjects();
    }

    /**
     * Get users formatted as form choices (name => id).
     *
     * @return array<string, int> user display names as keys, IDs as values
     */
    public function getUserChoices(): array
    {
        return array_flip($this->leantime->getUsers());
    }

    /**
     * Get all users from Leantime.
     *
     * @return array<int, string> user IDs as keys, display names as values
     */
    public function getUsers(): array
    {
        return $this->leantime->getUsers();
    }

    /**
     * Get ticket priority levels formatted as form choices.
     *
     * @return array<string, string> priority labels as keys, priority IDs as values
     */
    public function getPriorityChoices(): array
    {
        return [
            'Urgent' => '1',
            'High' => '2',
            'Medium' => '3',
            'Low' => '4',
            'Lowest' => '5',
        ];
    }

    /**
     * Get milestones for a project formatted as form choices.
     *
     * Always includes a "None" option as the first entry.
     *
     * @param int $projectId the Leantime project ID
     *
     * @return array<string, int|string> milestone names as keys, IDs as values (empty string for "None")
     */
    public function getMilestoneChoices(int $projectId): array
    {
        $choices = ['None' => ''];

        $milestones = $this->leantime->getMilestones($projectId);
        foreach ($milestones as $milestone) {
            if (isset($milestone['headline'], $milestone['id'])) {
                $choices[$milestone['headline']] = $milestone['id'];
            }
        }

        return $choices;
    }

    /**
     * Resolve the planned hours value from the form selection.
     *
     * If "manual" is selected, uses the manually entered value.
     * Otherwise, casts the preset choice to a float.
     *
     * @param string $plannedHours the selected planned hours option value
     * @param mixed  $manualHours  the manually entered hours value (used when $plannedHours is "manual")
     *
     * @return float the resolved number of hours
     */
    public function resolveHours(string $plannedHours, mixed $manualHours): float
    {
        return self::PLANNED_HOURS_MANUAL === $plannedHours ? (float) ($manualHours ?? self::DEFAULT_MANUAL_HOURS) : (float) $plannedHours;
    }

    /**
     * Resolve the milestone ID from form inputs.
     *
     * Prioritizes creating a new milestone if a name is provided.
     * Falls back to the selected existing milestone ID.
     * Returns null if no milestone is specified.
     *
     * @param string|null $milestone    the selected existing milestone ID
     * @param string|null $newMilestone the name for a new milestone to create
     * @param int         $projectId   the project to find/create the milestone in
     *
     * @return int|null the resolved milestone ID, or null if none selected
     *
     * @throws \RuntimeException if milestone creation fails
     */
    public function resolveMilestoneId(?string $milestone, ?string $newMilestone, int $projectId): ?int
    {
        if (!empty($newMilestone)) {
            return $this->leantime->findOrCreateMilestone($projectId, $newMilestone);
        }

        if (!empty($milestone)) {
            return (int) $milestone;
        }

        return null;
    }

    /**
     * Create tickets across multiple projects from the "Across Projects" form.
     *
     * Iterates over each selected project, resolves the milestone (find or create),
     * and creates a ticket. Failures for individual projects are captured in the
     * results array rather than halting the entire operation.
     *
     * @param array<string, mixed> $formData the validated form data
     * @param array<int, string>   $projects the full project id => name map for display purposes
     *
     * @return array<int, array{projectId: int, projectName: string, ticketId?: int, error?: string, success: bool}> results per project
     */
    public function createTicketsAcrossProjects(array $formData, array $projects): array
    {
        $title = $formData['title'];
        $description = $formData['description'] ?? '';
        $projectIds = $formData['projects'];
        $tags = $formData['tags'] ?? '';
        $date = $formData['due_date']->format(self::DATE_FORMAT);
        $hours = $this->resolveHours($formData['planned_hours'], $formData['manual_hours'] ?? null);
        $priority = $formData['priority'];
        $milestone = $formData['milestone'];

        $results = [];

        foreach ($projectIds as $projectId) {
            try {
                $milestoneId = null;

                if (!empty($milestone)) {
                    $milestoneId = $this->leantime->findOrCreateMilestone((int) $projectId, $milestone);
                }

                $ticketId = $this->leantime->createTicket($title, (int) $projectId, $description, $tags, $milestoneId, null, $date, $hours, $priority);
                $results[] = [
                    'projectId' => $projectId,
                    'projectName' => $projects[$projectId] ?? self::UNKNOWN_PROJECT,
                    'ticketId' => $ticketId,
                    'success' => true,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'projectId' => $projectId,
                    'projectName' => $projects[$projectId] ?? self::UNKNOWN_PROJECT,
                    'error' => $e->getMessage(),
                    'success' => false,
                ];
            }
        }

        return $results;
    }

    /**
     * Create tickets across multiple users in a single project from the "Across Users" form.
     *
     * Resolves the milestone first (existing selection or new creation). Then iterates
     * over each selected user and creates a ticket assigned to them. Failures for
     * individual users are captured in the results array.
     *
     * @param array<string, mixed> $formData the validated form data
     * @param array<int, string>   $projects the full project id => name map for display purposes
     * @param array<int, string>   $users    the full user id => name map for display purposes
     *
     * @return array{results: array<int, array{projectId: int, projectName: string, userName: string, ticketId?: int, error?: string, success: bool}>, milestoneError: string|null}
     */
    public function createTicketsAcrossUsers(array $formData, array $projects, array $users): array
    {
        $title = $formData['title'];
        $description = $formData['description'] ?? '';
        $projectId = (int) $formData['project'];
        $userIds = $formData['users'];
        $tags = $formData['tags'] ?? '';
        $date = $formData['date']->format(self::DATE_FORMAT);
        $hours = $this->resolveHours($formData['planned_hours'], $formData['manual_hours'] ?? null);
        $priority = $formData['priority'];

        try {
            $milestoneId = $this->resolveMilestoneId(
                $formData['milestone'] ?: null,
                $formData['new_milestone'] ?? null,
                $projectId,
            );
        } catch (\Exception $e) {
            return ['results' => [], 'milestoneError' => $e->getMessage()];
        }

        $results = [];

        foreach ($userIds as $userId) {
            try {
                $ticketId = $this->leantime->createTicket($title, $projectId, $description, $tags, $milestoneId, (int) $userId, $date, $hours, $priority);
                $results[] = [
                    'projectId' => $projectId,
                    'projectName' => $projects[$projectId] ?? self::UNKNOWN_PROJECT,
                    'userName' => $users[$userId] ?? self::UNKNOWN_USER,
                    'ticketId' => $ticketId,
                    'success' => true,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'projectId' => $projectId,
                    'projectName' => $projects[$projectId] ?? self::UNKNOWN_PROJECT,
                    'userName' => $users[$userId] ?? self::UNKNOWN_USER,
                    'error' => $e->getMessage(),
                    'success' => false,
                ];
            }
        }

        return ['results' => $results, 'milestoneError' => null];
    }
}
