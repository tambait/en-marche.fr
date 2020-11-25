<?php

namespace App\Repository\TerritorialCouncil;

use App\Entity\Adherent;
use App\Entity\Committee;
use App\Entity\CommitteeMembership;
use App\Entity\ElectedRepresentative\Mandate;
use App\Entity\Geo\Zone;
use App\Entity\ReferentTag;
use App\Entity\TerritorialCouncil\TerritorialCouncil;
use App\Entity\VotingPlatform\Designation\Designation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\RegistryInterface;

class TerritorialCouncilRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, TerritorialCouncil::class);
    }

    public function findOneByReferentTag(ReferentTag $referentTag): ?TerritorialCouncil
    {
        return $this->createQueryBuilder('tc')
            ->innerJoin('tc.referentTags', 'tag')
            ->where('tag.id = :tag')
            ->setParameter('tag', $referentTag)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    /**
     * @return TerritorialCouncil[]
     */
    public function findAllWithoutStartedElection(Designation $designation, int $offset = 0, int $limit = 200): array
    {
        return $this->createQueryBuilder('tc')
            ->innerJoin('tc.referentTags', 'tag')
            ->leftJoin('tc.currentDesignation', 'd')
            ->where('(tc.currentDesignation IS NULL OR (d.voteEndDate IS NOT NULL AND d.voteEndDate < :date))')
            ->andWhere('tag IN (:tags)')
            ->setParameters([
                'date' => $designation->getCandidacyStartDate(),
                'tags' => $designation->getReferentTags(),
            ])
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult()
        ;
    }

    public function findByMandates(array $mandates): array
    {
        $zones = [];
        array_walk($mandates, function (Mandate $mandate) use (&$zones) {
            $zone = $mandate->getGeoZone();

            switch ($zone->getType()) {
                case Zone::BOROUGH:
                case Zone::DISTRICT:
                    $zones[] = $zone;
                    $zones = array_merge($zones, $zone->getParentsOfType(Zone::DEPARTMENT));
                    break;
                case Zone::CITY:
                case Zone::CITY_COMMUNITY:
                case Zone::CANTON:
                    $zones = array_merge($zones, $zone->getParentsOfType(Zone::DEPARTMENT));
                    break;
                case Zone::DEPARTMENT:
                case Zone::REGION:
                case Zone::FOREIGN_DISTRICT:
                    $zones[] = $zone;
                    break;
                case Zone::CONSULAR_DISTRICT:
                case Zone::COUNTRY:
                    $zones = array_merge($zones, $zone->getParentsOfType(Zone::FOREIGN_DISTRICT));
                    break;
            }
        });

        return $this->createQueryBuilder('tc')
            ->leftJoin('tc.zones', 'zone')
            ->where('zone IN (:zones)')
            ->setParameter('zones', $zones)
            ->getQuery()
            ->getResult()
        ;
    }

    public function findForSupervisor(Adherent $adherent): array
    {
        return $this->createQueryBuilder('tc')
            ->leftJoin('tc.referentTags', 'tag')
            ->leftJoin(Committee::class, 'committee', Join::WITH, 'tag MEMBER OF committee.referentTags')
            ->leftJoin(CommitteeMembership::class, 'cm', Join::WITH, 'cm.committee = committee')
            ->where('cm.adherent = :adherent')
            ->andWhere('cm.privilege = :privilege')
            ->setParameter('adherent', $adherent)
            ->setParameter('privilege', CommitteeMembership::COMMITTEE_SUPERVISOR)
            ->getQuery()
            ->getResult()
        ;
    }

    public function findByCommittees(array $committees): array
    {
        return $this->createQueryBuilder('tc')
            ->leftJoin('tc.referentTags', 'tag')
            ->leftJoin(Committee::class, 'committee', Join::WITH, 'tag MEMBER OF committee.referentTags')
            ->where('committee IN (:committees)')
            ->setParameter('committees', $committees)
            ->getQuery()
            ->getResult()
        ;
    }

    public function createQueryBuilderWithReferentTagsCondition(array $referentTags): QueryBuilder
    {
        $qb = $this
            ->createQueryBuilder('tc')
            ->innerJoin('tc.referentTags', 'tag')
        ;

        if (!$referentTags) {
            return $qb->andWhere('1 = 0');
        }

        $tagCondition = 'tag IN (:tags)';

        foreach ($referentTags as $referentTag) {
            if ('75' === $referentTag->getCode()) {
                $tagCondition = "(tag IN (:tags) OR tag.name LIKE '%Paris%')";

                break;
            }
        }

        return $qb
            ->where($tagCondition)
            ->andWhere('tc.isActive = :true')
            ->setParameters([
                'tags' => $referentTags,
                'true' => true,
            ])
        ;
    }
}
