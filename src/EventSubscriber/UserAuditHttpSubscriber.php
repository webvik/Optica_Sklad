<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Entity\UserAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Zapisuje HTTP aktivitu přihlášených uživatelů (bez těla dotazu / bez hesel).
 * Režim: parametr env AUDIT_HTTP_LOG_MODE (viz .env + config/services.yaml defaults).
 */
final class UserAuditHttpSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $auditHttpLogMode,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => ['onTerminate', -255]];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $modeNorm = strtolower(trim($this->auditHttpLogMode));
        if ('' === $modeNorm || \in_array($modeNorm, ['off', 'none', 'disabled', '0'], true)) {
            return;
        }

        $request = $event->getRequest();
        $pathInfo = $request->getPathInfo();

        if (str_starts_with($pathInfo, '/_profiler')
            || str_starts_with($pathInfo, '/_wdt')
            || preg_match('#^/(assets|build)/#', $pathInfo)) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $actor = null !== $token ? $token->getUser() : null;
        if (!$actor instanceof User) {
            return;
        }

        if (!$this->shouldLog($modeNorm, $request->getMethod(), $pathInfo)) {
            return;
        }

        $routeRaw = $request->attributes->get('_route');
        $route = \is_string($routeRaw) && !str_starts_with($routeRaw, '_') ? $routeRaw : null;

        $response = $event->getResponse();
        $agent = $request->headers->get('User-Agent');

        try {
            $row = UserAuditLog::fromRequestOutcome(
                $actor,
                strtoupper($request->getMethod()),
                mb_strlen($pathInfo, 'UTF-8') <= 2048 ? $pathInfo : mb_substr($pathInfo, 0, 2048, 'UTF-8'),
                $route,
                $response->getStatusCode(),
                $request->getClientIp(),
                $agent,
            );
            $this->em->persist($row);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('user_audit_log: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function shouldLog(string $modeNorm, string $method, string $pathInfo): bool
    {
        $mUpper = strtoupper($method);
        $isRead = \in_array($mUpper, ['GET', 'HEAD', 'OPTIONS'], true);
        $inSkladSprava = \str_starts_with($pathInfo, '/sklad') || \str_starts_with($pathInfo, '/sprava');

        return match ($modeNorm) {
            'sklad_scope', 'sklad' => $inSkladSprava,
            'mutations_only', 'mutations' => !$isRead,
            'detailed', 'full', 'all' => $inSkladSprava || !$isRead,
            default => $inSkladSprava || !$isRead,
        };
    }
}
