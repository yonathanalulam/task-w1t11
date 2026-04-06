<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestIdSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onRequest',
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $requestId = $request->headers->get('X-Request-Id');
        if (!is_string($requestId) || $requestId === '') {
            $requestId = bin2hex(random_bytes(16));
        }

        $request->attributes->set('request_id', $requestId);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $requestId = $event->getRequest()->attributes->get('request_id');
        if (is_string($requestId) && $requestId !== '') {
            $event->getResponse()->headers->set('X-Request-Id', $requestId);
        }
    }
}
