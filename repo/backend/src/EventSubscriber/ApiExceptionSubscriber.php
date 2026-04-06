<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\ApiValidationException;
use App\Exception\AnalyticsFlowException;
use App\Exception\QuestionBankFlowException;
use App\Exception\SchedulingFlowException;
use App\Http\ApiResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onException',
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $e = $event->getThrowable();
        $requestId = $request->attributes->get('request_id');

        if ($e instanceof ApiValidationException) {
            $event->setResponse(ApiResponse::error(
                'VALIDATION_ERROR',
                $e->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->details(),
                is_string($requestId) ? $requestId : null,
            ));

            return;
        }

        if ($e instanceof SchedulingFlowException) {
            $event->setResponse(ApiResponse::error(
                $e->apiCode(),
                $e->getMessage(),
                $e->httpStatus(),
                $e->details(),
                is_string($requestId) ? $requestId : null,
            ));

            return;
        }

        if ($e instanceof QuestionBankFlowException) {
            $event->setResponse(ApiResponse::error(
                $e->apiCode(),
                $e->getMessage(),
                $e->httpStatus(),
                $e->details(),
                is_string($requestId) ? $requestId : null,
            ));

            return;
        }

        if ($e instanceof AnalyticsFlowException) {
            $event->setResponse(ApiResponse::error(
                $e->apiCode(),
                $e->getMessage(),
                $e->httpStatus(),
                $e->details(),
                is_string($requestId) ? $requestId : null,
            ));

            return;
        }

        if ($e instanceof AccessDeniedHttpException) {
            $event->setResponse(ApiResponse::error(
                'ACCESS_DENIED',
                $e->getMessage() !== '' ? $e->getMessage() : 'Access denied.',
                Response::HTTP_FORBIDDEN,
                [],
                is_string($requestId) ? $requestId : null,
            ));

            return;
        }

        if ($e instanceof HttpExceptionInterface) {
            $event->setResponse(ApiResponse::error(
                'HTTP_ERROR',
                $e->getMessage() !== '' ? $e->getMessage() : 'HTTP error.',
                $e->getStatusCode(),
                [],
                is_string($requestId) ? $requestId : null,
            ));

            return;
        }

        $event->setResponse(ApiResponse::error(
            'INTERNAL_ERROR',
            'Unexpected server error.',
            Response::HTTP_INTERNAL_SERVER_ERROR,
            [],
            is_string($requestId) ? $requestId : null,
        ));
    }
}
