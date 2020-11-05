<?php

namespace App\Controller\Admin;

use App\CitizenProject\CitizenProjectAuthority;
use App\CitizenProject\CitizenProjectManagementAuthority;
use App\CitizenProject\CitizenProjectManager;
use App\Entity\Adherent;
use App\Entity\CitizenProject;
use App\Exception\BaseGroupException;
use App\Exception\CitizenProjectMembershipException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/projets-citoyens")
 * @Security("has_role('ROLE_ADMIN_CITIZEN_PROJECTS')")
 */
class AdminCitizenProjectController extends Controller
{
    private $authority;

    public function __construct(CitizenProjectManagementAuthority $authority)
    {
        $this->authority = $authority;
    }

    /**
     * Approves the citizen project.
     *
     * @Route("/{id}/approve", name="app_admin_citizenproject_approve", methods={"GET"})
     */
    public function approveAction(CitizenProject $citizenProject): Response
    {
        try {
            $this->authority->approve($citizenProject);
            $this->addFlash('sonata_flash_success', sprintf('Le projet citoyen « %s » a été approuvé avec succès.', $citizenProject->getName()));
        } catch (BaseGroupException $exception) {
            throw $this->createNotFoundException(sprintf('CitizenProject %u must be pending in order to be approved.', $citizenProject->getId()), $exception);
        }

        return $this->redirectToRoute('admin_app_citizenproject_list');
    }

    /**
     * Refuses the citizen project.
     *
     * @Route("/{id}/refuse", name="app_admin_citizenproject_refuse", methods={"GET"})
     */
    public function refuseAction(CitizenProject $citizenProject): Response
    {
        try {
            $this->authority->refuse($citizenProject);
            $this->addFlash('sonata_flash_success', sprintf('Le projet citoyen « %s » a été refusé avec succès.', $citizenProject->getName()));
        } catch (BaseGroupException $exception) {
            throw $this->createNotFoundException(sprintf('CitizenProject %u must be pending in order to be refused.', $citizenProject->getId()), $exception);
        }

        return $this->redirectToRoute('admin_app_citizenproject_list');
    }

    /**
     * @Route("/{id}/members", name="app_admin_citizenproject_members", methods={"GET"})
     */
    public function membersAction(CitizenProject $citizenProject, CitizenProjectManager $manager): Response
    {
        return $this->render('admin/citizen_project/members.html.twig', [
            'citizen_project' => $citizenProject,
            'memberships' => $manager->getCitizenProjectMemberships($citizenProject),
        ]);
    }

    /**
     * @Route("/{citizenProject}/members/{adherent}/set-privilege/{privilege}", name="app_admin_citizenproject_change_privilege", methods={"GET"})
     */
    public function changePrivilegeAction(
        Request $request,
        CitizenProject $citizenProject,
        Adherent $adherent,
        string $privilege,
        CitizenProjectAuthority $authority
    ): Response {
        if (!$this->isCsrfTokenValid(sprintf('citizen_project.change_privilege.%s', $adherent->getId()), $request->query->get('token'))) {
            throw new BadRequestHttpException('Invalid Csrf token provided.');
        }

        try {
            $authority->changePrivilege($adherent, $citizenProject, $privilege);
            $this->getDoctrine()->getManager()->flush();
        } catch (CitizenProjectMembershipException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_citizenproject_members', [
            'id' => $citizenProject->getId(),
        ]);
    }
}
