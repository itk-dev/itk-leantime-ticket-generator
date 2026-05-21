<?php

namespace App\Controller;

use App\Form\AcrossUsersType;
use App\Form\TicketType;
use App\Service\LeantimeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TicketController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->render('home.html.twig');
    }

    #[Route('/across-projects', name: 'app_ticket_across_projects')]
    public function acrossProjects(Request $request, LeantimeService $leantime): Response
    {
        try {
            $projects = $leantime->getProjects();
        } catch (\Exception $e) {
            $this->addFlash('error', 'Could not connect to Leantime. Please check that LEANTIME_API_URL and LEANTIME_API_KEY are configured in .env.local.');

            return $this->render('ticket/across_projects.html.twig', [
                'form' => null,
            ]);
        }

        $projectChoices = array_flip($projects);

        $form = $this->createForm(TicketType::class, null, [
            'project_choices' => $projectChoices,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $title = $data['title'];
            $description = $data['description'] ?? '';
            $projectIds = $data['projects'];
            $tags = $data['tags'] ?? '';
            $date = $data['due_date']->format('Y-m-d');
            $plannedHours = $data['planned_hours'];
            $hours = 'manual' === $plannedHours ? (float) ($data['manual_hours'] ?? 1) : (float) $plannedHours;
            $milestone = $data['milestone'];

            $results = [];

            foreach ($projectIds as $projectId) {
                try {
                    $milestoneId = null;

                    if (!empty($milestone)) {
                        $milestoneId = $leantime->findOrCreateMilestone((int) $projectId, $milestone);
                    }

                    $ticketId = $leantime->createTicket($title, (int) $projectId, $description, $tags, $milestoneId, null, $date, $hours);
                    $results[] = [
                        'projectId' => $projectId,
                        'projectName' => $projects[$projectId] ?? 'Unknown',
                        'ticketId' => $ticketId,
                        'success' => true,
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'projectId' => $projectId,
                        'projectName' => $projects[$projectId] ?? 'Unknown',
                        'error' => $e->getMessage(),
                        'success' => false,
                    ];
                }
            }

            return $this->render('ticket/success.html.twig', [
                'results' => $results,
                'title' => $title,
            ]);
        }

        return $this->render('ticket/across_projects.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/across-users', name: 'app_ticket_across_users')]
    public function acrossUsers(Request $request, LeantimeService $leantime): Response
    {
        try {
            $projects = $leantime->getProjects();
            $users = $leantime->getUsers();
        } catch (\Exception $e) {
            $this->addFlash('error', 'Could not connect to Leantime. Please check that LEANTIME_API_URL and LEANTIME_API_KEY are configured in .env.local.');

            return $this->render('ticket/across_users.html.twig', [
                'form' => null,
            ]);
        }

        $projectChoices = array_flip($projects);
        $userChoices = array_flip($users);

        // Get milestones for the selected project (on POST), or empty
        $milestoneChoices = ['None' => ''];
        $selectedProject = $request->request->all('across_users')['project'] ?? null;
        if ($selectedProject) {
            try {
                $milestones = $leantime->getMilestones((int) $selectedProject);
                foreach ($milestones as $milestone) {
                    if (isset($milestone['headline'], $milestone['id'])) {
                        $milestoneChoices[$milestone['headline']] = $milestone['id'];
                    }
                }
            } catch (\Exception) {
            }
        }

        $form = $this->createForm(AcrossUsersType::class, null, [
            'project_choices' => $projectChoices,
            'user_choices' => $userChoices,
            'milestone_choices' => $milestoneChoices,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $title = $data['title'];
            $description = $data['description'] ?? '';
            $projectId = (int) $data['project'];
            $userIds = $data['users'];
            $tags = $data['tags'] ?? '';
            $date = $data['date']->format('Y-m-d');
            $plannedHours = $data['planned_hours'];
            $hours = 'manual' === $plannedHours ? (float) ($data['manual_hours'] ?? 1) : (float) $plannedHours;
            $milestoneId = $data['milestone'] ? (int) $data['milestone'] : null;
            $newMilestone = $data['new_milestone'] ?? '';

            // If a new milestone name was entered, use that instead
            if (!empty($newMilestone)) {
                try {
                    $milestoneId = $leantime->findOrCreateMilestone($projectId, $newMilestone);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Failed to create milestone: '.$e->getMessage());

                    return $this->render('ticket/across_users.html.twig', [
                        'form' => $form,
                    ]);
                }
            }

            $results = [];

            foreach ($userIds as $userId) {
                try {
                    $ticketId = $leantime->createTicket($title, $projectId, $description, $tags, $milestoneId, (int) $userId, $date, $hours);
                    $results[] = [
                        'projectId' => $projectId,
                        'projectName' => $projects[$projectId] ?? 'Unknown',
                        'userName' => $users[$userId] ?? 'Unknown',
                        'ticketId' => $ticketId,
                        'success' => true,
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'projectId' => $projectId,
                        'projectName' => $projects[$projectId] ?? 'Unknown',
                        'userName' => $users[$userId] ?? 'Unknown',
                        'error' => $e->getMessage(),
                        'success' => false,
                    ];
                }
            }

            return $this->render('ticket/success_users.html.twig', [
                'results' => $results,
                'title' => $title,
            ]);
        }

        return $this->render('ticket/across_users.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/api/milestones/{projectId}', name: 'app_api_milestones', methods: ['GET'])]
    public function milestones(int $projectId, LeantimeService $leantime): JsonResponse
    {
        try {
            $milestones = $leantime->getMilestones($projectId);
            $choices = [['label' => 'None', 'value' => '']];
            foreach ($milestones as $milestone) {
                if (isset($milestone['headline'], $milestone['id'])) {
                    $choices[] = ['label' => $milestone['headline'], 'value' => $milestone['id']];
                }
            }

            return $this->json($choices);
        } catch (\Exception) {
            return $this->json([['label' => 'None', 'value' => '']], 500);
        }
    }
}
