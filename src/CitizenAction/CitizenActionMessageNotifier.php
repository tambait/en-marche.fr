<?php

namespace App\CitizenAction;

use App\CitizenProject\CitizenProjectManager;
use App\Entity\Adherent;
use App\Entity\CitizenAction;
use App\Entity\EventRegistration;
use App\Events;
use App\Mailer\MailerService;
use App\Mailer\Message\CitizenActionCancellationMessage;
use App\Mailer\Message\CitizenActionNotificationMessage;
use App\Repository\EventRegistrationRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CitizenActionMessageNotifier implements EventSubscriberInterface
{
    private $mailer;
    private $urlGenerator;
    private $citizenProjectManager;
    private $registrationRepository;

    public function __construct(
        MailerService $transactionalMailer,
        UrlGeneratorInterface $urlGenerator,
        CitizenProjectManager $citizenProjectManager,
        EventRegistrationRepository $registrationRepository
    ) {
        $this->mailer = $transactionalMailer;
        $this->urlGenerator = $urlGenerator;
        $this->citizenProjectManager = $citizenProjectManager;
        $this->registrationRepository = $registrationRepository;
    }

    public function sendCreationEmail(CitizenActionEvent $citizenActionEvent): void
    {
        $citizenAction = $citizenActionEvent->getCitizenAction();

        $chunks = array_chunk(
            $this->citizenProjectManager->getOptinCitizenProjectFollowers($citizenAction->getCitizenProject())->toArray(),
            MailerService::PAYLOAD_MAXSIZE
        );

        foreach ($chunks as $chunk) {
            $this->mailer->sendMessage($this->createMessage($chunk, $citizenAction, $citizenAction->getOrganizer()));
        }
    }

    public function sendCancellationEmail(CitizenActionEvent $citizenActionEvent): void
    {
        $citizenAction = $citizenActionEvent->getCitizenAction();
        if (!$citizenAction->isCancelled()) {
            return;
        }

        $subscriptions = $this->registrationRepository->findByEvent($citizenAction);

        if (\count($subscriptions) > 0) {
            $registrationChunks = array_chunk($subscriptions->toArray(), MailerService::PAYLOAD_MAXSIZE);

            foreach ($registrationChunks as $chunk) {
                $this->mailer->sendMessage($this->createCancelMessage(
                    $chunk,
                    $citizenAction,
                    $citizenActionEvent->getAuthor()
                ));
            }
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            Events::CITIZEN_ACTION_CREATED => ['sendCreationEmail'],
            Events::CITIZEN_ACTION_CANCELLED => ['sendCancellationEmail'],
        ];
    }

    private function createMessage(
        array $followers,
        CitizenAction $citizenAction,
        Adherent $host
    ): CitizenActionNotificationMessage {
        return CitizenActionNotificationMessage::create(
            $followers,
            $host,
            $citizenAction,
            $this->generateUrl('app_citizen_action_attend', [
                'slug' => $citizenAction->getSlug(),
            ])
        );
    }

    private function createCancelMessage(
        array $registered,
        CitizenAction $citizenAction,
        Adherent $host
    ): CitizenActionCancellationMessage {
        return CitizenActionCancellationMessage::create(
            $registered,
            $host,
            $citizenAction,
            $this->generateUrl('app_search_events'),
            function (EventRegistration $registration) {
                return CitizenActionCancellationMessage::getRecipientVars($registration->getFirstName());
            }
        );
    }

    private function generateUrl(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
