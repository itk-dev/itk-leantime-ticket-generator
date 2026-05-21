<?php

namespace App\Controller;

use App\Form\AcrossUsersType;
use App\Form\TicketType;
use App\Service\TicketHelperService;
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
    public function acrossProjects(Request $request, TicketHelperService $helper): Response
    {
        try {
            $projects = $helper->getProjects();
            $projectChoices = $helper->getProjectChoices();
        } catch (\Exception) {
            $this->addFlash('error', 'Could not connect to Leantime. Please check that LEANTIME_API_URL and LEANTIME_API_KEY are configured in .env.local.');

            return $this->render('ticket/across_projects.html.twig', ['form' => null]);
        }

        $priorityChoices = $helper->getPriorityChoices();

        $form = $this->createForm(TicketType::class, null, [
            'project_choices' => $projectChoices,
            'priority_choices' => $priorityChoices,
            'default_priority' => $priorityChoices['High'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $outcome = $helper->createTicketsAcrossProjects($form->getData(), $projects);

            return $this->render('ticket/success.html.twig', [
                'results' => $outcome['results'],
                'milestonesCreated' => $outcome['milestonesCreated'],
                'title' => $form->getData()['title'],
            ]);
        }

        return $this->render('ticket/across_projects.html.twig', ['form' => $form]);
    }

    #[Route('/across-users', name: 'app_ticket_across_users')]
    public function acrossUsers(Request $request, TicketHelperService $helper): Response
    {
        try {
            $projects = $helper->getProjects();
            $users = $helper->getUsers();
        } catch (\Exception) {
            $this->addFlash('error', 'Could not connect to Leantime. Please check that LEANTIME_API_URL and LEANTIME_API_KEY are configured in .env.local.');

            return $this->render('ticket/across_users.html.twig', ['form' => null]);
        }

        $milestoneChoices = ['None' => ''];
        $selectedProject = $request->request->all('across_users')['project'] ?? null;
        if ($selectedProject) {
            try {
                $milestoneChoices = $helper->getMilestoneChoices((int) $selectedProject);
            } catch (\Exception) {
            }
        }

        $priorityChoices = $helper->getPriorityChoices();

        $form = $this->createForm(AcrossUsersType::class, null, [
            'project_choices' => $helper->getProjectChoices(),
            'user_choices' => $helper->getUserChoices(),
            'milestone_choices' => $milestoneChoices,
            'priority_choices' => $priorityChoices,
            'default_priority' => $priorityChoices['Lowest'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $outcome = $helper->createTicketsAcrossUsers($form->getData(), $projects, $users);

            if ($outcome['milestoneError']) {
                $this->addFlash('error', 'Failed to create milestone: '.$outcome['milestoneError']);

                return $this->render('ticket/across_users.html.twig', ['form' => $form]);
            }

            return $this->render('ticket/success_users.html.twig', [
                'results' => $outcome['results'],
                'milestonesCreated' => $outcome['milestonesCreated'],
                'title' => $form->getData()['title'],
            ]);
        }

        return $this->render('ticket/across_users.html.twig', ['form' => $form]);
    }

    #[Route('/api/milestones/{projectId}', name: 'app_api_milestones', methods: ['GET'])]
    public function milestones(int $projectId, TicketHelperService $helper): JsonResponse
    {
        try {
            $choices = $helper->getMilestoneChoices($projectId);
            $result = [];
            foreach ($choices as $label => $value) {
                $result[] = ['label' => $label, 'value' => $value];
            }

            return $this->json($result);
        } catch (\Exception) {
            return $this->json([['label' => 'None', 'value' => '']], 500);
        }
    }
}
