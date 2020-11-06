<?php

namespace App\Repository;

use App\Entity\MyEuropeInvitation;
use Symfony\Bridge\Doctrine\RegistryInterface;

class MyEuropeInvitationRepository extends InteractiveInvitationRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, MyEuropeInvitation::class);
    }
}
