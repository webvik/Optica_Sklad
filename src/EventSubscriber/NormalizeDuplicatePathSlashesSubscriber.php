<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Vyžehlí zdvojené „/“ v path (např. http://localhost:8000//sklad/...), aby router shledal routu.
 */
final class NormalizeDuplicatePathSlashesSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 255]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        if ('' === $path || !str_contains($path, '//')) {
            return;
        }

        $collapsed = preg_replace('#/{2,}#', '/', $path);
        if ($collapsed === false || $collapsed === $path) {
            return;
        }

        $qs = $event->getRequest()->getQueryString();
        $location = $collapsed.((null !== $qs && '' !== $qs) ? '?'.$qs : '');

        $event->setResponse(new RedirectResponse($location, Response::HTTP_TEMPORARY_REDIRECT));
    }
}
