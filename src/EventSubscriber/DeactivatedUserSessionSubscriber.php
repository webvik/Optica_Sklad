<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Při deaktivaci účtu ukončí běžící sezení (včetně remember-me) při dalším požadavku.
 */
final class DeactivatedUserSessionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserRepository $userRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('app_logout' === $request->attributes->get('_route')) {
            return;
        }

        $authUser = $this->security->getUser();
        if (!$authUser instanceof User) {
            return;
        }

        $fresh = $this->userRepository->find($authUser->getId());
        if ($fresh instanceof User && $fresh->getIsActive()) {
            return;
        }

        $message = $fresh instanceof User
            ? $fresh->getLoginDeactivationMessage()
            : User::DEFAULT_DEACTIVATION_MESSAGE;

        $username = $fresh instanceof User ? $fresh->getUserIdentifier() : $authUser->getUserIdentifier();

        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->set(
                SecurityRequestAttributes::AUTHENTICATION_ERROR,
                new CustomUserMessageAccountStatusException($message),
            );
            $session->set(SecurityRequestAttributes::LAST_USERNAME, $username);
        }

        $this->security->logout(false);

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_home')));
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }
}
