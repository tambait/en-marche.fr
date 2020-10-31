<?php

namespace App\DataFixtures\ORM;

use App\Entity\Adherent;
use App\Entity\Committee;
use App\Entity\CommitteeCandidacy;
use App\Image\ImageManager;
use App\ValueObject\Genders;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class LoadCommitteeCandidacyData extends Fixture implements DependentFixtureInterface
{
    private const CANDIDACY_UUID_1 = '9780b1ca-79c1-4f53-babd-643ebb2ca3cc';
    private const CANDIDACY_UUID_2 = '5417fda6-6aeb-47ab-9da3-46d60046a3d5';
    private const CANDIDACY_UUID_3 = 'c1921620-4101-1c66-967e-86b08a720aad';

    private $imageManager;

    public function __construct(ImageManager $imageManager)
    {
        $this->imageManager = $imageManager;
    }

    public function load(ObjectManager $manager)
    {
        /** @var Adherent $adherent */
        $adherent = $this->getReference('assessor-1');
        /** @var Committee $committee */
        $committee = $this->getReference('committee-6');

        $voteCommitteeMembership = $adherent->getMembershipFor($committee);
        $voteCommitteeMembership->enableVote();
        $voteCommitteeMembership->addCommitteeCandidacy(
            $candidacy = new CommitteeCandidacy(
                $committee->getCommitteeElection(),
                $adherent->getGender(),
                Uuid::fromString(self::CANDIDACY_UUID_1)
            )
        );
        $candidacy->setBiography('Voici ma plus belle candidature. Votez pour moi uniquement!');
        $candidacy->setImage(new UploadedFile(
            __DIR__.'/../../../app/data/dist/avatar_homme_02.jpg',
            'image.jpg',
            'image/jpeg',
            null,
            null,
            true
        ));

        $manager->persist($candidacy);
        $this->imageManager->saveImage($candidacy);

        $this->setReference('committee-candidacy-1', $candidacy);

        $adherent = $this->getReference('adherent-2');

        $voteCommitteeMembership = $adherent->getMembershipFor($committee);
        $voteCommitteeMembership->enableVote();
        $voteCommitteeMembership->addCommitteeCandidacy(
            $candidacy = new CommitteeCandidacy(
                $committee->getCommitteeElection(),
                Genders::FEMALE,
                Uuid::fromString(self::CANDIDACY_UUID_2)
            )
        );

        $manager->persist($candidacy);

        $adherent = $this->getReference('adherent-3');
        $committee = $this->getReference('committee-4');

        $voteCommitteeMembership = $adherent->getMembershipFor($committee);
        $voteCommitteeMembership->addCommitteeCandidacy(
            $candidacy = new CommitteeCandidacy(
                $committee->getCommitteeElection(),
                Genders::MALE,
                Uuid::fromString(self::CANDIDACY_UUID_3)
            )
        );

        $manager->persist($candidacy);

        $manager->flush();
    }

    public function getDependencies()
    {
        return [
            LoadAdherentData::class,
        ];
    }
}
