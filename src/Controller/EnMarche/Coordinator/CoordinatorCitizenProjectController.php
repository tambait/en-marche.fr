<?php

namespace App\Controller\EnMarche\Coordinator;

use App\CitizenProject\CitizenProjectManagementAuthority;
use App\CitizenProject\CitizenProjectManager;
use App\Coordinator\Filter\AbstractCoordinatorAreaFilter;
use App\Coordinator\Filter\CitizenProjectFilter;
use App\Entity\CitizenProject;
use App\Exception\BaseGroupException;
use App\Form\CoordinatorAreaType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/espace-coordinateur/projet-citoyen")
 * @Security("is_granted('ROLE_COORDINATOR_CITIZEN_PROJECT')")
 */
class CoordinatorCitizenProjectController extends Controller
{
    /**
     * @Route(path="/list", name="app_coordinator_citizen_project", methods={"GET"})
     */
    public function listAction(Request $request, CitizenProjectManager $manager): Response
    {
        try {
            $filter = CitizenProjectFilter::fromQueryString($request);
        } catch (\UnexpectedValueException $e) {
            throw new BadRequestHttpException('Unexpected Citizen Project status in the query string.', $e);
        }

        $results = $manager->getCoordinatorCitizenProjects($this->getUser(), $filter);

        $forms = [];
        $citizenProjectStatus = $filter->getStatus();

        array_walk($results, function (CitizenProject $project) use (&$forms, $citizenProjectStatus) {
            $forms[$project->getId()] = $this
                ->createForm(CoordinatorAreaType::class, $project, [
                    'data_class' => CitizenProject::class,
                    'action' => $this->generateUrl('app_coordinator_citizen_project_validate', [
                        'uuid' => $project->getUuid(),
                        'slug' => $project->getSlug(),
                    ]),
                    'status' => $citizenProjectStatus,
                ])
                ->createView()
            ;
        });

        return $this->render('coordinator/citizen_project.html.twig', [
            'results' => $results,
            'filter' => $filter,
            'forms' => $forms,
        ]);
    }

    /**
     * @Route("/{uuid}/{slug}/pre-valider", name="app_coordinator_citizen_project_validate", methods={"POST"})
     */
    public function validateAction(
        Request $request,
        CitizenProject $project,
        CitizenProjectManagementAuthority $authority
    ): Response {
        $form = $this
            ->createForm(CoordinatorAreaType::class, $project, [
                'data_class' => CitizenProject::class,
            ])
            ->handleRequest($request)
        ;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                if ($form->get('refuse')->isClicked()) {
                    $authority->preRefuse($project);
                    $this->addFlash('info', 'Merci. Votre appréciation a été transmise à nos équipes.');
                } elseif ($form->get('accept')->isClicked()) {
                    $authority->preApprove($project);
                    $this->addFlash('info', 'Merci. Votre appréciation a été transmise à nos équipes.');
                }
            } catch (BaseGroupException $exception) {
                $this->addFlash('info', sprintf('Le projet citoyen #%d a déjà été traité par un administrateur.', $project->getId()));
            }
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error_'.$project->getId(), $error->getMessage());
            }
        }

        return $this->redirectToRoute('app_coordinator_citizen_project', [
            AbstractCoordinatorAreaFilter::PARAMETER_STATUS => CitizenProject::PENDING,
        ]);
    }
}
