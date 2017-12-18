<?php

namespace AppBundle\Mailer\Message;

use AppBundle\Entity\Adherent;
use AppBundle\Entity\CitizenProject;
use Ramsey\Uuid\Uuid;

class CitizenProjectCreationConfirmationMessage extends Message
{
    public static function create(Adherent $adherent, CitizenProject $citizenProject): self
    {
        $message = new self(
            Uuid::uuid4(),
            '244426',
            $adherent->getEmailAddress(),
            $adherent->getFullName(),
            'Nous avons bien reçu votre demande de création de projet citoyen !',
            [
                'target_firstname' => self::escape($adherent->getFirstName()),
                'citizen_project_name' => self::escape($citizenProject->getName()),
            ]
        );

        $message->setSenderEmail('projetscitoyens@en-marche.fr');

        return $message;
    }
}
