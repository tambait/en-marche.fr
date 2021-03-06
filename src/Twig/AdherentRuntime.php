<?php

namespace App\Twig;

use App\Adherent\SessionModal\SessionModalActivatorListener;
use App\Entity\Adherent;
use App\Entity\ElectedRepresentative\ElectedRepresentative;
use App\Entity\ReferentSpaceAccessInformation;
use App\Repository\AdherentMandate\CommitteeAdherentMandateRepository;
use App\Repository\ElectedRepresentative\ElectedRepresentativeRepository;
use App\Repository\ReferentSpaceAccessInformationRepository;
use Symfony\Component\HttpFoundation\Request;
use Twig\Extension\RuntimeExtensionInterface;

class AdherentRuntime implements RuntimeExtensionInterface
{
    private $memberInterests;
    private $accessInformationRepository;
    private $electedRepresentativeRepository;
    private $committeeMandateRepository;

    public function __construct(
        ElectedRepresentativeRepository $electedRepresentativeRepository,
        ReferentSpaceAccessInformationRepository $accessInformationRepository,
        CommitteeAdherentMandateRepository $committeeMandateRepository,
        array $interests
    ) {
        $this->electedRepresentativeRepository = $electedRepresentativeRepository;
        $this->accessInformationRepository = $accessInformationRepository;
        $this->committeeMandateRepository = $committeeMandateRepository;
        $this->memberInterests = $interests;
    }

    public function getMemberInterestLabel(string $interest)
    {
        if (!isset($this->memberInterests[$interest])) {
            return '';
        }

        return $this->memberInterests[$interest];
    }

    public function getUserLevelLabel(Adherent $adherent): string
    {
        if (!$adherent->isAdherent()) {
            return 'Non-adhérent(e)';
        }

        if ($adherent->isReferent()) {
            return $adherent->isFemale() ? 'Référente 🥇' : 'Référent 🥇';
        }

        if ($adherent->isCoReferent()) {
            return 'Équipe du référent 🥈';
        }

        if ($adherent->isDeputy()) {
            return $adherent->isFemale() ? 'Députée 🏛' : 'Député 🏛';
        }

        if ($adherent->isSupervisor()) {
            return $adherent->isFemale() ? 'Animatrice 🏅' : 'Animateur 🏅';
        }

        if ($adherent->isHost()) {
            return $adherent->isFemale() ? 'Co-animatrice 🏅' : 'Co-animateur 🏅';
        }

        // It means the user is an adherent
        return $adherent->isFemale() ? 'Adhérente 😍' : 'Adhérent 😍';
    }

    public function getAdherentRoleLabels(Adherent $adherent): array
    {
        $labels = [];

        if ($adherent->isAdherent()) {
            $labels[] = $adherent->isFemale() ? 'Adhérente' : 'Adhérent';
        } else {
            $labels[] = 'Non-adhérent(e)';
        }

        if ($adherent->isReferent()) {
            $labels[] = $adherent->isFemale() ? 'Référente' : 'Référent';
        }

        if ($adherent->isCoReferent() || $adherent->isDelegatedReferent()) {
            $labels[] = 'Équipe du référent';
        }

        if ($adherent->isDeputy()) {
            $labels[] = $adherent->isFemale() ? 'Députée' : 'Député';
        }

        if ($adherent->isDelegatedDeputy()) {
            $labels[] = 'Équipe du député';
        }

        if ($adherent->isSenator()) {
            $labels[] = $adherent->isFemale() ? 'Sénatrice' : 'Sénateur';
        }

        if ($adherent->isDelegatedSenator()) {
            $labels[] = 'Équipe du sénateur';
        }

        if ($adherent->isSupervisor()) {
            $labels[] = $adherent->isFemale() ? 'Animatrice' : 'Animateur';
        }

        if ($adherent->isHost()) {
            $labels[] = $adherent->isFemale() ? 'Co-animatrice' : 'Co-animateur';
        }

        if ($adherent->isMunicipalChief()) {
            $labels[] = 'Candidat Municipales 2020';
        }

        if ($adherent->isTerritorialCouncilMember()) {
            $labels[] = 'Membre des instances';
        }

        if ($this->committeeMandateRepository->hasActiveMandate($adherent)) {
            $labels[] = $adherent->isFemale() ? 'Adhérente désignée' : 'Adhérent désigné';
        }

        return $labels;
    }

    public function getReferentPreviousVisitDate(Adherent $adherent): ?\DateTimeInterface
    {
        /** @var ReferentSpaceAccessInformation $accessInformation */
        $accessInformation = $this->accessInformationRepository->findByAdherent($adherent, 7200);

        if ($accessInformation) {
            return $accessInformation->getPreviousDate();
        }

        return null;
    }

    public function getElectedRepresentative(Adherent $adherent): ?ElectedRepresentative
    {
        return $this->electedRepresentativeRepository->findOneBy(['adherent' => $adherent]);
    }

    public function hasActiveParliamentaryMandate(Adherent $adherent): bool
    {
        return $this->electedRepresentativeRepository->hasActiveParliamentaryMandate($adherent);
    }

    public function getSessionModalContext(Request $request): ?string
    {
        // get and remove session modal context if presents
        return $request->getSession()->remove(SessionModalActivatorListener::SESSION_KEY);
    }
}
