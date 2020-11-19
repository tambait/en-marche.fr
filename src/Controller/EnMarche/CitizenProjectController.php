<?php

namespace App\Controller\EnMarche;

use App\CitizenProject\CitizenProjectManager;
use App\Entity\CitizenProject;
use App\Entity\CitizenProjectCategory;
use App\Entity\CitizenProjectCategorySkill;
use App\Entity\Committee;
use App\Entity\TurnkeyProjectFile;
use App\Exception\CitizenProjectCommitteeSupportAlreadySupportException;
use App\Exception\CitizenProjectNotApprovedException;
use App\Repository\CommitteeRepository;
use App\Security\Http\Session\AnonymousFollowerSession;
use App\Storage\FileRequestHandler;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/projets-citoyens")
 */
class CitizenProjectController extends Controller
{
    /**
     * @Route("/aide", name="app_citizen_project_help", methods={"GET", "POST"})
     */
    public function helpAction(): Response
    {
        return new Response();
    }

    /**
     * @Route("/{slug}", name="app_citizen_project_show", methods={"GET"})
     * @Security("is_granted('SHOW_CITIZEN_PROJECT', citizenProject)")
     */
    public function showAction(
        Request $request,
        CitizenProject $citizenProject,
        CitizenProjectManager $citizenProjectManager
    ): Response {
        if ($this->isGranted('IS_ANONYMOUS')
            && $authenticate = $this->get(AnonymousFollowerSession::class)->start($request)
        ) {
            return $authenticate;
        }

        return $this->render('citizen_project/show.html.twig', [
            'citizen_project' => $citizenProject,
            'citizen_actions' => $citizenProjectManager->getCitizenProjectActions($citizenProject),
            'administrators' => $citizenProjectManager->getCitizenProjectAdministrators($citizenProject),
            'form_committee_support' => $this->createForm(FormType::class)->createView(),
        ]);
    }

    /**
     * @Route("/skills/autocompletion",
     *     name="app_citizen_project_skills_autocomplete",
     *     condition="request.isXmlHttpRequest() and request.query.get('category')",
     *     methods={"GET"}
     * )
     * @Security("is_granted('IS_AUTHENTICATED_FULLY')")
     */
    public function skillsAutocompleteAction(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$category = $entityManager->getRepository(CitizenProjectCategory::class)->find($request->query->get('category'))) {
            return new JsonResponse([], Response::HTTP_NOT_FOUND);
        }

        $citizenProjectCategorySkills = $this
            ->getDoctrine()
            ->getRepository(CitizenProjectCategorySkill::class)
            ->findByCitizenProjectCategory($category)
        ;

        /** @var CitizenProjectCategorySkill[] $citizenProjectCategorySkills */
        foreach ($citizenProjectCategorySkills as $citizenProjectCategorySkill) {
            $result[] = [
                'id' => $citizenProjectCategorySkill->getSkill()->getId(),
                'name' => $citizenProjectCategorySkill->getSkill()->getName(),
            ];
        }

        return new JsonResponse($result ?? []);
    }

    /**
     * @Route("/comite/autocompletion",
     *     name="app_citizen_project_committee_autocomplete",
     *     condition="request.isXmlHttpRequest()",
     *     methods={"GET"}
     * )
     * @Security("is_granted('IS_AUTHENTICATED_FULLY')")
     */
    public function committeeAutocompleteAction(Request $request): Response
    {
        if (!$term = $request->query->get('term')) {
            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        }

        $committees = $this
            ->getDoctrine()
            ->getRepository(Committee::class)
            ->findByPartialName($term)
        ;

        foreach ($committees as $committee) {
            $result[] = [
                'uuid' => $committee->getUuid()->toString(),
                'name' => $committee->getName(),
            ];
        }

        return new JsonResponse($result ?? []);
    }

    /**
     * @Route("/mon-comite-soutien/{slug}", name="app_citizen_project_committee_support", methods={"GET", "POST"})
     * @Security("is_granted('ROLE_SUPERVISOR')")
     */
    public function committeeSupportAction(
        Request $request,
        CitizenProject $citizenProject,
        CitizenProjectManager $citizenProjectManager,
        CommitteeRepository $committeeRepository
    ): Response {
        $form = $this->createForm(FormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $committeeUuid = $this->getUser()->getMemberships()->getCommitteeSupervisorMemberships()->last()->getCommitteeUuid();
            /* @var Committee $committee */
            $committee = $committeeRepository->findOneByUuid($committeeUuid);

            try {
                $citizenProjectManager->approveCommitteeSupport(
                    $committee,
                    $citizenProject
                );
                $flashMessage = sprintf(
                    'Votre comité %s soutient maintenant le projet citoyen %s',
                    $committee->getName(),
                    $citizenProject->getName()
                );
            } catch (CitizenProjectCommitteeSupportAlreadySupportException $committeeSupportAlreadySupportException) {
                $citizenProjectManager->deleteCommitteeSupport($committee, $citizenProject);
                $flashMessage = sprintf(
                    'Votre comité %s ne soutient plus le projet citoyen %s',
                    $committeeSupportAlreadySupportException->getCommittee()->getName(),
                    $committeeSupportAlreadySupportException->getCitizenProject()->getName()
                );
            } catch (CitizenProjectNotApprovedException $approvedException) {
                throw $this->createAccessDeniedException();
            }

            $this->addFlash('info', $flashMessage);

            return $this->redirectToRoute('app_citizen_project_show', [
                'slug' => $citizenProject->getSlug(),
            ]);
        }

        return $this->render('citizen_project/committee_confirm_support.html.twig', [
            'form' => $form->createView(),
            'citizen_project' => $citizenProject,
        ]);
    }

    /**
     * @Route("/{slug}/rejoindre", name="app_citizen_project_follow", condition="request.request.has('token')", methods={"POST"})
     * @Security("is_granted('FOLLOW_CITIZEN_PROJECT', citizenProject)")
     */
    public function followAction(
        Request $request,
        CitizenProject $citizenProject,
        CitizenProjectManager $manager
    ): Response {
        if (!$this->isCsrfTokenValid('citizen_project.follow', $request->request->get('token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF protection token to follow citizen project.');
        }

        $manager->followCitizenProject($this->getUser(), $citizenProject);

        return new JsonResponse([
            'button' => [
                'label' => 'Quitter ce projet citoyen',
                'action' => 'quitter',
                'csrf_token' => (string) $this->get('security.csrf.token_manager')->getToken('citizen_project.unfollow'),
            ],
        ]);
    }

    /**
     * @Route("/{slug}/quitter", name="app_citizen_project_unfollow", condition="request.request.has('token')", methods={"POST"})
     * @Security("is_granted('UNFOLLOW_CITIZEN_PROJECT', citizenProject)")
     */
    public function unfollowAction(
        Request $request,
        CitizenProject $citizenProject,
        CitizenProjectManager $manager
    ): Response {
        if (!$this->isCsrfTokenValid('citizen_project.unfollow', $request->request->get('token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF protection token to unfollow citizen project.');
        }

        $manager->unfollowCitizenProject($this->getUser(), $citizenProject);

        return new JsonResponse([
            'button' => [
                'label' => 'Suivre ce projet citoyen',
                'action' => 'rejoindre',
                'csrf_token' => (string) $this->get('security.csrf.token_manager')->getToken('citizen_project.follow'),
            ],
        ]);
    }

    /**
     * @Route("/kits/{slug}.{extension}", name="app_citizen_project_kit_file", methods={"GET"})
     * @Cache(maxage=900, smaxage=900)
     */
    public function getKitFile(FileRequestHandler $fileRequestHandler, TurnkeyProjectFile $file): Response
    {
        return $fileRequestHandler->createResponse($file);
    }
}
