<?php

namespace App\Controller\EnMarche;

use App\Address\GeoCoder;
use App\Committee\CommitteeManager;
use App\Entity\Adherent;
use App\Entity\AdherentActivationToken;
use App\Exception\AdherentAlreadyEnabledException;
use App\Exception\AdherentTokenExpiredException;
use App\Form\AdherentInterestsFormType;
use App\Form\AdherentRegistrationType;
use App\Form\BecomeAdherentType;
use App\Form\CommitteeAroundAdherentType;
use App\Form\UserRegistrationType;
use App\Geocoder\CoordinatesFactory;
use App\Intl\UnitedNationsBundle;
use App\Membership\AdherentAccountActivationHandler;
use App\Membership\MembershipRegistrationProcess;
use App\Membership\MembershipRequest;
use App\Membership\MembershipRequestHandler;
use App\OAuth\CallbackManager;
use App\Repository\AdherentRepository;
use App\Security\AuthenticationUtils;
use App\Security\Http\Session\AnonymousFollowerSession;
use GuzzleHttp\Exception\ConnectException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Entity;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class MembershipController extends Controller
{
    private $membershipRequestHandler;
    private $authenticationUtils;

    public function __construct(
        MembershipRequestHandler $membershipRequestHandler,
        AuthenticationUtils $authenticationUtils
    ) {
        $this->membershipRequestHandler = $membershipRequestHandler;
        $this->authenticationUtils = $authenticationUtils;
    }

    /**
     * This action enables a guest user to adhere to the community.
     *
     * @Route("/inscription-utilisateur", name="app_membership_register", methods={"GET", "POST"})
     */
    public function registerAction(
        Request $request,
        GeoCoder $geoCoder,
        AuthorizationCheckerInterface $authorizationChecker,
        CallbackManager $callbackManager
    ): Response {
        if ($authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $callbackManager->redirectToClientIfValid();
        }

        $membership = MembershipRequest::createWithCaptcha(
            $geoCoder->getCountryCodeFromIp($request->getClientIp()),
            $request->request->get('g-recaptcha-response')
        );

        $form = $this->createForm(UserRegistrationType::class, $membership);

        try {
            if ($form->handleRequest($request)->isSubmitted() && $form->isValid()) {
                $this->membershipRequestHandler->registerAsUser($membership);

                return $this->redirectToRoute('app_membership_complete');
            }
        } catch (ConnectException $e) {
            $this->addFlash('error_recaptcha', $this->get('translator')->trans('recaptcha.error'));
        }

        return $this->render('membership/register.html.twig', [
            'membership' => $membership,
            'form' => $form->createView(),
            'countries' => UnitedNationsBundle::getCountries($request->getLocale()),
        ]);
    }

    /**
     * This action enables a guest user to adhere to the community.
     *
     * @Route("/adhesion", name="app_membership_join", methods={"GET", "POST"})
     */
    public function adhesionAction(Request $request, GeoCoder $geoCoder): Response
    {
        if ($this->isGranted('ROLE_USER')) {
            return $this->joinAdherent($request);
        }

        $membership = MembershipRequest::createWithCaptcha(
            $geoCoder->getCountryCodeFromIp($request->getClientIp()),
            $request->request->get('g-recaptcha-response')
        );

        $form = $this
            ->createForm(AdherentRegistrationType::class, $membership)
            ->add('submit', SubmitType::class, ['label' => 'Je rejoins La République En Marche'])
            ->handleRequest($request)
        ;

        try {
            if ($form->isSubmitted() && $form->isValid()) {
                $this->membershipRequestHandler->registerAsAdherent($membership);

                return $this->redirectToRoute('app_membership_pin_interests');
            }
        } catch (ConnectException $e) {
            $this->addFlash('error_recaptcha', $this->get('translator')->trans('recaptcha.error'));
        }

        return $this->render('membership/join.html.twig', [
            'membership' => $membership,
            'form' => $form->createView(),
            'countries' => UnitedNationsBundle::getCountries($request->getLocale()),
            'nb_adherent' => $this->getDoctrine()->getRepository(Adherent::class)->countAdherents(),
        ]);
    }

    private function joinAdherent(Request $request): Response
    {
        $followerSession = $this->get(AnonymousFollowerSession::class);

        if ($followerSession->isStarted()) {
            return $followerSession->follow($request->getPathInfo());
        }

        /** @var Adherent $user */
        $user = $this->getUser();

        if ($user instanceof Adherent && $user->isAdherent()) {
            throw $this->createNotFoundException('An adherent cannot join.');
        }

        $membership = MembershipRequest::createFromAdherent($user);
        $form = $this->createForm(BecomeAdherentType::class, $membership)
            ->add('submit', SubmitType::class, ['label' => 'Je rejoins La République En Marche'])
            ->handleRequest($request)
        ;

        if ($form->isSubmitted() && $form->isValid()) {
            $this->membershipRequestHandler->join($user, $membership);

            $this->getDoctrine()->getManager()->flush();

            $this->get('security.token_storage')->setToken(null);
            $request->getSession()->invalidate();
            $this->authenticationUtils->authenticateAdherent($user);

            $this->addFlash('info', 'adherent.activation.success');

            return $this->redirectToRoute('app_adherent_home');
        }

        return $this->render('membership/join.html.twig', [
            'membership' => $membership,
            'form' => $form->createView(),
            'countries' => UnitedNationsBundle::getCountries($request->getLocale()),
            'nb_adherent' => $this->getDoctrine()->getRepository(Adherent::class)->countAdherents(),
        ]);
    }

    /**
     * This action is the landing page at the end of the subscription process.
     *
     * @Route("/presque-fini", name="app_membership_complete", methods={"GET"})
     */
    public function completeAction(MembershipRegistrationProcess $membershipRegistrationProcess): Response
    {
        $membershipRegistrationProcess->terminate();

        if ($this->getUser()) {
            $this->redirectToRoute('homepage');
        }

        return $this->render('membership/complete.html.twig');
    }

    /**
     * This action enables a new user to activate his\her newly created
     * membership account.
     *
     * @Route(
     *     path="/inscription/finaliser/{adherent_uuid}/{activation_token}",
     *     name="app_membership_activate",
     *     requirements={
     *         "adherent_uuid": "%pattern_uuid%",
     *         "activation_token": "%pattern_sha1%"
     *     },
     *     methods={"GET"}
     * )
     * @Entity("adherent", expr="repository.findOneByUuid(adherent_uuid)")
     * @Entity("activationToken", expr="repository.findByToken(activation_token)")
     */
    public function activateAction(
        Adherent $adherent,
        AdherentActivationToken $activationToken,
        CallbackManager $callbackManager,
        AdherentAccountActivationHandler $accountActivationHandler
    ): Response {
        if ($this->getUser()) {
            $this->redirectToRoute('app_search_events');
        }

        try {
            $accountActivationHandler->handle($adherent, $activationToken);

            if ($adherent->isAdherent()) {
                $this->membershipRequestHandler->sendConfirmationJoinMessage($adherent);

                $this->addFlash('info', 'adherent.activation.success');

                // We need to handle anonymous session here because the user was logged through the handler above,
                // bypassing the security success handler
                $anonymousFollowerSession = $this->get(AnonymousFollowerSession::class);

                if ($anonymousFollowerSession->isStarted()) {
                    return $anonymousFollowerSession->terminate();
                }

                return $callbackManager->redirectToClientIfValid('app_adherent_home');
            }

            return $callbackManager->redirectToClientIfValid('app_membership_join');
        } catch (AdherentAlreadyEnabledException $e) {
            $this->addFlash('info', 'adherent.activation.already_active');
        } catch (AdherentTokenExpiredException $e) {
            $this->addFlash('info', 'adherent.activation.expired_key');
        }

        // Other exceptions that may be raised will be caught by Symfony.

        return $this->redirectToRoute('app_user_login');
    }

    /**
     * This action enables a new user to pin his/her interests during the registration process.
     *
     * @Route("/inscription/centre-interets", name="app_membership_pin_interests", methods={"GET", "POST"})
     * @Security("is_granted('MEMBERSHIP_REGISTRATION_IN_PROGRESS')")
     */
    public function pinInterestsAction(
        Request $request,
        AdherentRepository $adherentRepository,
        MembershipRegistrationProcess $membershipRegistrationProcess
    ): Response {
        if (!$adherent = $adherentRepository->findByUuid($membershipRegistrationProcess->getAdherentUuid())) {
            throw $this->createNotFoundException('New adherent not found.');
        }

        $form = $this->createForm(AdherentInterestsFormType::class, $adherent)
            ->add('pass', SubmitType::class)
            ->add('submit', SubmitType::class)
        ;

        if ($form->handleRequest($request)->isSubmitted()) {
            if ($form->get('submit')->isClicked() && $form->isValid()) {
                $this->getDoctrine()->getManager()->flush();
            }

            return $this->redirectToRoute('app_membership_choose_committees_around_adherent');
        }

        return $this->render('membership/pin_interests.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * This action enables a user to follow some committees during the registration process.
     *
     * @Route("/inscription/choisir-des-comites", name="app_membership_choose_committees_around_adherent", methods={"GET", "POST"})
     * @Security("is_granted('MEMBERSHIP_REGISTRATION_IN_PROGRESS')")
     */
    public function chooseCommitteesAction(
        Request $request,
        AdherentRepository $adherentRepository,
        MembershipRegistrationProcess $membershipRegistrationProcess,
        CoordinatesFactory $coordinatesFactory,
        CommitteeManager $committeeManager
    ): Response {
        /** @var Adherent $adherent */
        if (!$adherent = $adherentRepository->findByUuid($membershipRegistrationProcess->getAdherentUuid())) {
            throw $this->createNotFoundException('New adherent not found.');
        }

        $coordinates = $coordinatesFactory->createFromPostAddress($adherent->getPostAddress());
        $committees = null === $coordinates ?
            $committeeManager->getLastApprovedCommitteesAndMembers() :
            $committeeManager->getCommitteesByCoordinatesAndCountry(
                $coordinates,
                $adherent->getCountry(),
                $adherent->getPostalCode()
            )
        ;

        $form = $this
            ->createForm(CommitteeAroundAdherentType::class, null, [
                'committees' => $committees,
            ])
            ->add('pass', SubmitType::class)
            ->add('submit', SubmitType::class)
        ;

        if ($form->handleRequest($request)->isSubmitted()) {
            if ($form->get('submit')->isClicked() && $form->isValid()) {
                $committeeManager->followCommittees($adherent, $form->get('committees')->getData());
            }

            return $this->redirectToRoute('app_membership_donation');
        }

        return $this->render('membership/choose_committees.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * This action enables a user to donate during the registration process.
     *
     * @Route("/inscription/don", name="app_membership_donation", methods={"GET"})
     * @Security("is_granted('MEMBERSHIP_REGISTRATION_IN_PROGRESS')")
     */
    public function donationAction(
        AdherentRepository $adherentRepository,
        MembershipRegistrationProcess $membershipRegistrationProcess
    ): Response {
        if (!$adherentRepository->findByUuid($membershipRegistrationProcess->getAdherentUuid())) {
            throw $this->createNotFoundException('New adherent not found.');
        }

        return $this->render('membership/donation.html.twig');
    }
}
