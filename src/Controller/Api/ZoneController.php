<?php

namespace App\Controller\Api;

use App\Controller\EnMarche\AccessDelegatorTrait;
use App\Entity\Adherent;
use App\Entity\Geo\Zone;
use App\Entity\ReferentTag;
use App\Repository\Geo\ZoneRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Translation\TranslatorInterface;

class ZoneController extends AbstractController
{
    private const SUGGESTIONS_PER_TYPE = 5;

    private const TYPES = [
        Zone::CUSTOM,
        Zone::FOREIGN_DISTRICT,
        Zone::COUNTRY,
        Zone::CONSULAR_DISTRICT,
        Zone::REGION,
        Zone::DEPARTMENT,
        Zone::CITY,
        Zone::DISTRICT,
        Zone::CITY_COMMUNITY,
        Zone::CANTON,
    ];

    use AccessDelegatorTrait;

    /**
     * @Route("/zone/autocompletion", name="api_zone_autocomplete", methods={"GET"})
     * @Security("is_granted('IS_AUTHENTICATED_FULLY')")
     */
    public function autocomplete(
        Request $request,
        ZoneRepository $repository,
        TranslatorInterface $translator
    ): Response {
        $term = (string) $request->query->get('q', '');
        $space = (string) $request->query->get('space', '');

        $max = (int) $request->query->get('page_limit', self::SUGGESTIONS_PER_TYPE);
        $max = min($max, self::SUGGESTIONS_PER_TYPE);

        $user = $this->getMainUser($request->getSession());
        $managedZones = $this->getManagedZones($user, $space);

        $zones = $repository->searchByTermAndManagedZonesGroupedByType($term, $managedZones, self::TYPES, $max);

        $results = $this->normalizeZoneForSelect2($translator, $zones);

        return new JsonResponse([
            'results' => array_values($results),
            'pagination' => [
                'more' => false,
            ],
        ]);
    }

    /**
     * @return Zone[]
     */
    private function getManagedZones(Adherent $adherent, string $spaceType): array
    {
        if ('deputy' === $spaceType) {
            return [$adherent->getManagedDistrict()->getReferentTag()->getZone()];
        }

        if ('lre' === $spaceType) {
            if ($adherent->getLreArea()->isAllTags()) {
                return [];
            }

            return [$adherent->getLreArea()->getReferentTag()->getZone()];
        }

        if ('referent' === $spaceType) {
            return $adherent->getManagedArea()->getZones()->toArray();
        }

        if ('senator' === $spaceType) {
            return [$adherent->getSenatorArea()->getDepartmentTag()->getZone()];
        }

        if ('senatorial_candidate' === $spaceType) {
            $zones = [];

            /* @var ReferentTag $referentTag */
            $referentTags = $adherent->getSenatorialCandidateManagedArea()->getDepartmentTags();
            foreach ($referentTags as $referentTag) {
                $zones[] = $referentTag->getZone();
            }

            return $zones;
        }

        if ('candidate' === $spaceType) {
            return [$adherent->getCandidateManagedArea()->getZone()];
        }

        if ('legislative_candidate' === $spaceType) {
            return [$adherent->getLegislativeCandidateManagedDistrict()->getReferentTag()->getZone()];
        }

        return [];
    }

    /**
     * @param Zone[] $zones
     */
    private function normalizeZoneForSelect2(TranslatorInterface $translator, array $zones): array
    {
        $results = array_fill_keys(self::TYPES, null);

        foreach ($zones as $zone) {
            $type = $zone->getType();
            $groupText = $translator->trans("geo_zone.{$type}") ?: $type;

            if (!isset($results[$type])) {
                $results[$type] = [
                    'text' => $groupText,
                    'children' => [],
                ];
            }

            $results[$type]['children'][] = [
                'id' => $zone->getId(),
                'text' => $zone->getNameCode(),
            ];
        }

        return array_values(array_filter($results));
    }
}
