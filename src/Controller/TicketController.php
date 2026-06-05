<?php

namespace App\Controller;

use App\Form\AcrossUsersType;
use App\Form\GithubImportType;
use App\Form\TicketType;
use App\Service\GitHubHelperService;
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

    #[Route('/from-github', name: 'app_ticket_from_github')]
    public function fromGithub(GitHubHelperService $githubHelper, TicketHelperService $helper): Response
    {
        $repoChoices = [];
        $projectChoices = [];

        try {
            $repoChoices = $githubHelper->getRepoChoices();
        } catch (\Exception $e) {
            $this->addFlash('error', 'Could not fetch GitHub repositories: '.$e->getMessage());
        }

        try {
            $projectChoices = $helper->getProjectChoices();
        } catch (\Exception) {
            $this->addFlash('error', 'Could not connect to Leantime. Please check that LEANTIME_API_URL and LEANTIME_API_KEY are configured in .env.local.');
        }

        return $this->render('from_github.html.twig', [
            'repoChoices' => $repoChoices,
            'projectChoices' => $projectChoices,
        ]);
    }

    #[Route('/from-github/select', name: 'app_ticket_from_github_select', methods: ['GET', 'POST'])]
    public function fromGithubSelect(Request $request, GitHubHelperService $githubHelper, TicketHelperService $helper): Response
    {
        $repo = (string) $request->query->get('repo', '');
        $projectId = (int) $request->query->get('projectId', 0);
        $source = (string) $request->query->get('source', '');

        if ('' === $repo || 0 === $projectId || !in_array($source, [GitHubHelperService::SOURCE_ISSUES, GitHubHelperService::SOURCE_MILESTONES], true)) {
            $this->addFlash('error', 'Please pick a repository, a Leantime project and a source (issues or milestones).');

            return $this->redirectToRoute('app_ticket_from_github');
        }

        $priorityChoices = $helper->getPriorityChoices();

        if ($request->isMethod('POST')) {
            $form = $this->createForm(GithubImportType::class, null, [
                'priority_choices' => $priorityChoices,
            ]);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                $rows = $data['rows'] ?? [];
                $selectedRows = array_filter($rows, static fn (array $row): bool => !empty($row['include']));

                if (empty($selectedRows)) {
                    $this->addFlash('error', 'Please tick at least one row to import.');

                    return $this->render('from_github_select.html.twig', [
                        'form' => $form->createView(),
                        'repo' => $repo,
                        'source' => $source,
                        'projectId' => $projectId,
                    ]);
                }

                try {
                    $projects = $helper->getProjects();
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Could not connect to Leantime: '.$e->getMessage());

                    return $this->render('from_github_select.html.twig', [
                        'form' => $form->createView(),
                        'repo' => $repo,
                        'source' => $source,
                        'projectId' => $projectId,
                    ]);
                }

                $outcome = $githubHelper->createTicketsFromGithub($rows, $projectId, $projects);

                return $this->render('from_github_success.html.twig', [
                    'results' => $outcome['results'],
                    'title' => sprintf('%s from %s', GitHubHelperService::SOURCE_MILESTONES === $source ? 'Milestones' : 'Issues', $repo),
                ]);
            }
        }

        try {
            $rows = $githubHelper->buildFormRows($source, $repo);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Could not fetch GitHub data: '.$e->getMessage());

            return $this->redirectToRoute('app_ticket_from_github');
        }

        if (empty($rows)) {
            return $this->render('from_github_select.html.twig', [
                'form' => null,
                'repo' => $repo,
                'source' => $source,
                'projectId' => $projectId,
            ]);
        }

        $form = $this->createForm(GithubImportType::class, ['rows' => $rows], [
            'priority_choices' => $priorityChoices,
        ]);

        return $this->render('from_github_select.html.twig', [
            'form' => $form->createView(),
            'repo' => $repo,
            'source' => $source,
            'projectId' => $projectId,
        ]);
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
