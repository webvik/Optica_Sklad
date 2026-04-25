<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Логин в БД в нижнем регистре; при вводе Petr.ivanov ищем petr.ivanov.
 */
final class LoginFormSubscriber implements EventSubscriberInterface
{
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('POST') || $request->attributes->get('_route') !== 'app_home') {
            return;
        }

        $u = $request->request->get('username');
        if (is_string($u) && $u !== '') {
            $request->request->set('username', mb_strtolower($u, 'UTF-8'));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['onKernelRequest', 256]]];
    }
}
