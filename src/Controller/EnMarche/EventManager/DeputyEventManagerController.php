<?php

namespace App\Controller\EnMarche\EventManager;

use ApiPlatform\Core\DataProvider\PaginatorInterface;
use App\Entity\Adherent;
use App\Event\EventManagerSpaceEnum;
use App\Repository\EventRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route(path="/espace-depute", name="app_deputy_event_manager_")
 *
 * @Security("is_granted('ROLE_DEPUTY') or (is_granted('ROLE_DELEGATED_DEPUTY') and is_granted('HAS_DELEGATED_ACCESS_EVENTS'))")
 */
class DeputyEventManagerController extends AbstractEventManagerController
{
    private $repository;

    public function __construct(EventRepository $repository)
    {
        $this->repository = $repository;
    }

    protected function getSpaceType(): string
    {
        return EventManagerSpaceEnum::DEPUTY;
    }

    protected function getEventsPaginator(Adherent $adherent, string $type = null, int $page = 1): PaginatorInterface
    {
        if (AbstractEventManagerController::EVENTS_TYPE_ALL === $type) {
            return $this->repository->findManagedByPaginator([$adherent->getManagedDistrict()->getReferentTag()], $page);
        }

        return $this->repository->findEventsByOrganizerPaginator($adherent, $page);
    }
}
