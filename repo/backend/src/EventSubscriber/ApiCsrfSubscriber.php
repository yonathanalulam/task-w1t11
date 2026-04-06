<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\ApiRouteAccessPolicy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ApiCsrfSubscriber implements EventSubscriberInterface
{
    private const TOKEN_ID = 'api';

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ApiRouteAccessPolicy $routeAccessPolicy,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onRequest',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!$this->routeAccessPolicy->isApiPath($path)) {
            return;
        }

        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        if ($this->routeAccessPolicy->isPublicPath($path)) {
            return;
        }

        $token = $request->headers->get('X-CSRF-Token');
        if (!is_string($token) || $token === '') {
            throw new AccessDeniedHttpException('Missing CSRF token.');
        }

        $isValid = $this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, $token));
        if (!$isValid) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
