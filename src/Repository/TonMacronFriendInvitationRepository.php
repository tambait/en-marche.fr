<?php

namespace App\Repository;

use App\Entity\TonMacronFriendInvitation;
use Symfony\Bridge\Doctrine\RegistryInterface;

class TonMacronFriendInvitationRepository extends InteractiveInvitationRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, TonMacronFriendInvitation::class);
    }
}
