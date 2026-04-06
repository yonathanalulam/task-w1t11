<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppointmentBooking;
use App\Entity\AppointmentHold;
use App\Entity\AppointmentSlot;
use App\Entity\SchedulingConfiguration;
use App\Entity\User;
use App\Exception\ApiValidationException;
use App\Http\ApiResponse;
use App\Http\JsonBodyParser;
use App\Repository\AppointmentBookingRepository;
use App\Repository\AppointmentHoldRepository;
use App\Service\AuditLogger;
use App\Service\SchedulingService;
use App\Security\AuthSessionService;
use App\Security\AuthorizationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/scheduling')]
final class SchedulingController extends AbstractController
{
    public function __construct(
        private readonly AuthSessionService $authSession,
        private readonly AuthorizationService $authorization,
        private readonly JsonBodyParser $jsonBodyParser,
        private readonly SchedulingService $scheduling,
        private readonly AppointmentBookingRepository $bookings,
        private readonly AppointmentHoldRepository $holds,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/configuration', name: 'api_scheduling_configuration_get', methods: ['GET'])]
    public function getConfiguration(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        $this->authorization->assertPermission($user, 'scheduling.admin');

        $config = $this->scheduling->configuration();
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'configuration' => $config instanceof SchedulingConfiguration ? $this->serializeConfiguration($config) : null,
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/configuration', name: 'api_scheduling_configuration_upsert', methods: ['PUT'])]
    public function upsertConfiguration(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        $this->authorization->assertPermission($user, 'scheduling.admin');
        $payload = $this->jsonBodyParser->parse($request);

        $practitionerName = $this->requireTrimmedString($payload['practitionerName'] ?? null, 'practitionerName');
        $locationName = $this->requireTrimmedString($payload['locationName'] ?? null, 'locationName');
        $slotDurationMinutes = $this->requireInt($payload['slotDurationMinutes'] ?? 30, 'slotDurationMinutes', 15, 120);
        $slotCapacity = $this->requireInt($payload['slotCapacity'] ?? 1, 'slotCapacity', 1, 12);
        $weeklyAvailability = $this->normalizeWeeklyAvailability($payload['weeklyAvailability'] ?? null);

        $configuration = $this->scheduling->upsertConfiguration(
            $practitionerName,
            $locationName,
            $slotDurationMinutes,
            $slotCapacity,
            $weeklyAvailability,
            $this->username($user),
        );

        $this->auditLogger->log('scheduling.configuration_changed', $this->username($user), [
            'configurationId' => $configuration->getId(),
            'practitionerName' => $configuration->getPractitionerName(),
            'locationName' => $configuration->getLocationName(),
            'slotDurationMinutes' => $configuration->getSlotDurationMinutes(),
            'slotCapacity' => $configuration->getSlotCapacity(),
            'weeklyAvailability' => $configuration->getWeeklyAvailability(),
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'configuration' => $this->serializeConfiguration($configuration),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/slots/generate', name: 'api_scheduling_slots_generate', methods: ['POST'])]
    public function generateSlots(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        $this->authorization->assertPermission($user, 'scheduling.admin');
        $payload = $this->jsonBodyParser->parse($request);

        $daysAhead = $this->requireInt($payload['daysAhead'] ?? 14, 'daysAhead', 1, 90);

        $slots = $this->scheduling->generateSlotsFromConfiguration($daysAhead, $this->username($user));

        $this->auditLogger->log('scheduling.slot_created', $this->username($user), [
            'count' => count($slots),
            'daysAhead' => $daysAhead,
            'slotIds' => array_values(array_filter(array_map(fn (AppointmentSlot $slot): ?int => $slot->getId(), $slots))),
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'createdCount' => count($slots),
        ], JsonResponse::HTTP_CREATED, is_string($requestId) ? $requestId : null);
    }

    #[Route('/slots', name: 'api_scheduling_slots_list', methods: ['GET'])]
    public function listSlots(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        $this->assertSchedulingAccess($user);
        $username = $this->username($user);

        $start = $this->optionalDate($request->query->get('startDate'));
        $end = $this->optionalDate($request->query->get('endDate'));
        if ($start instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable && $end < $start) {
            throw new ApiValidationException('Invalid date range.', [
                ['field' => 'endDate', 'issue' => 'must_be_after_startDate'],
            ]);
        }

        $slots = $this->scheduling->listSlots($start, $end);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $bookedMap = $this->bookings->bookedSlotMapForUser($slots, $username);
        $activeHoldMap = $this->holds->activeHoldMapForUser($slots, $username, $now);
        $activeHoldCountMap = $this->holds->activeCountMapForSlots($slots, $now);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'slots' => array_map(function (AppointmentSlot $slot) use ($bookedMap, $activeHoldMap, $activeHoldCountMap): array {
                $slotId = $slot->getId() ?? 0;
                $activeHold = $activeHoldMap[$slotId] ?? null;
                $activeHoldCount = (int) ($activeHoldCountMap[$slotId] ?? 0);

                return [
                    'id' => $slot->getId(),
                    'startAtUtc' => $slot->getStartAtUtc()->format(DATE_ATOM),
                    'endAtUtc' => $slot->getEndAtUtc()->format(DATE_ATOM),
                    'capacity' => $slot->getCapacity(),
                    'bookedCount' => $slot->getBookedCount(),
                    'activeHoldCount' => $activeHoldCount,
                    'remainingCapacity' => max(0, $slot->getCapacity() - $slot->getBookedCount() - $activeHoldCount),
                    'status' => $slot->getStatus(),
                    'practitionerName' => $slot->getPractitionerName(),
                    'locationName' => $slot->getLocationName(),
                    'bookedByCurrentUser' => (bool) ($bookedMap[$slotId] ?? false),
                    'currentUserHold' => $activeHold instanceof AppointmentHold ? [
                        'holdId' => $activeHold->getId(),
                        'expiresAtUtc' => $activeHold->getExpiresAtUtc()->format(DATE_ATOM),
                    ] : null,
                ];
            }, $slots),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/slots/{slotId}/hold', name: 'api_scheduling_slot_hold', methods: ['POST'])]
    public function holdSlot(int $slotId, Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        $this->assertSchedulingAccess($user);
        $username = $this->username($user);

        $hold = $this->scheduling->placeHold($slotId, $username);

        $this->auditLogger->log('scheduling.hold_created', $username, [
            'holdId' => $hold->getId(),
            'slotId' => $hold->getSlot()->getId(),
            'expiresAtUtc' => $hold->getExpiresAtUtc()->format(DATE_ATOM),
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'holdId' => $hold->getId(),
            'slotId' => $hold->getSlot()->getId(),
            'expiresAtUtc' => $hold->getExpiresAtUtc()->format(DATE_ATOM),
            'status' => $hold->getStatus(),
        ], JsonResponse::HTTP_CREATED, is_string($requestId) ? $requestId : null);
    }

    #[Route('/holds/{holdId}/release', name: 'api_scheduling_hold_release', methods: ['POST'])]
    public function releaseHold(int $holdId, Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        $this->assertSchedulingAccess($user);
        $username = $this->username($user);

        $adminOverride = $this->authorization->hasPermission($user, 'scheduling.admin');
        $this->scheduling->releaseHold($holdId, $username, $adminOverride);

        $this->auditLogger->log('scheduling.hold_released', $username, [
            'holdId' => $holdId,
            'adminOverride' => $adminOverride,
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'holdId' => $holdId,
            'status' => 'RELEASED',
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/holds/{holdId}/book', name: 'api_scheduling_hold_book', methods: ['POST'])]
    public function bookFromHold(int $holdId, Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        $this->assertSchedulingAccess($user);
        $username = $this->username($user);

        $booking = $this->scheduling->bookFromHold($holdId, $username);

        $this->auditLogger->log('scheduling.slot_booked', $username, [
            'bookingId' => $booking->getId(),
            'slotId' => $booking->getSlot()->getId(),
            'holdId' => $holdId,
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'booking' => $this->serializeBooking($booking),
        ], JsonResponse::HTTP_CREATED, is_string($requestId) ? $requestId : null);
    }

    #[Route('/bookings/me', name: 'api_scheduling_bookings_me', methods: ['GET'])]
    public function listBookings(Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        $this->assertSchedulingAccess($user);

        $bookings = $this->scheduling->listBookingsForUser($this->username($user));
        $requestId = $request->attributes->get('request_id');

        return ApiResponse::success([
            'bookings' => array_map(fn (AppointmentBooking $booking): array => $this->serializeBooking($booking), $bookings),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/bookings/{bookingId}/reschedule', name: 'api_scheduling_booking_reschedule', methods: ['POST'])]
    public function rescheduleBooking(int $bookingId, Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        $this->assertSchedulingAccess($user);
        $payload = $this->jsonBodyParser->parse($request);
        $targetSlotId = $this->requireInt($payload['targetSlotId'] ?? null, 'targetSlotId', 1, PHP_INT_MAX);

        $adminOverride = $this->authorization->hasPermission($user, 'scheduling.admin');
        $booking = $this->scheduling->rescheduleBooking($bookingId, $targetSlotId, $this->username($user), $adminOverride);

        $this->auditLogger->log('scheduling.booking_rescheduled', $this->username($user), [
            'bookingId' => $booking->getId(),
            'newSlotId' => $booking->getSlot()->getId(),
            'rescheduleCount' => $booking->getRescheduleCount(),
            'adminOverride' => $adminOverride,
        ]);

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'booking' => $this->serializeBooking($booking),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    #[Route('/bookings/{bookingId}/cancel', name: 'api_scheduling_booking_cancel', methods: ['POST'])]
    public function cancelBooking(int $bookingId, Request $request): JsonResponse
    {
        $user = $this->authSession->currentUser();
        $this->assertSchedulingAccess($user);
        $payload = $this->jsonBodyParser->parse($request);

        $reason = null;
        if (isset($payload['reason']) && is_string($payload['reason']) && trim($payload['reason']) !== '') {
            $reason = trim($payload['reason']);
        }

        $adminOverride = $this->authorization->hasPermission($user, 'auth.override.cancel24h');
        $booking = $this->scheduling->cancelBooking($bookingId, $this->username($user), $adminOverride, $reason);

        $this->auditLogger->log('scheduling.booking_cancelled', $this->username($user), [
            'bookingId' => $booking->getId(),
            'slotId' => $booking->getSlot()->getId(),
            'adminOverride' => $adminOverride,
            'reason' => $reason,
        ]);

        if ($adminOverride) {
            $this->auditLogger->log('scheduling.cancellation_override_used', $this->username($user), [
                'bookingId' => $booking->getId(),
            ]);
        }

        $requestId = $request->attributes->get('request_id');
        return ApiResponse::success([
            'booking' => $this->serializeBooking($booking),
        ], requestId: is_string($requestId) ? $requestId : null);
    }

    /** @return array<string, mixed> */
    private function serializeConfiguration(SchedulingConfiguration $config): array
    {
        return [
            'id' => $config->getId(),
            'practitionerName' => $config->getPractitionerName(),
            'locationName' => $config->getLocationName(),
            'slotDurationMinutes' => $config->getSlotDurationMinutes(),
            'slotCapacity' => $config->getSlotCapacity(),
            'weeklyAvailability' => $config->getWeeklyAvailability(),
            'updatedAtUtc' => $config->getUpdatedAtUtc()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeBooking(AppointmentBooking $booking): array
    {
        $slot = $booking->getSlot();

        return [
            'id' => $booking->getId(),
            'status' => $booking->getStatus(),
            'bookedByUsername' => $booking->getBookedByUsername(),
            'rescheduleCount' => $booking->getRescheduleCount(),
            'updatedAtUtc' => $booking->getUpdatedAtUtc()->format(DATE_ATOM),
            'slot' => [
                'id' => $slot->getId(),
                'startAtUtc' => $slot->getStartAtUtc()->format(DATE_ATOM),
                'endAtUtc' => $slot->getEndAtUtc()->format(DATE_ATOM),
                'practitionerName' => $slot->getPractitionerName(),
                'locationName' => $slot->getLocationName(),
            ],
        ];
    }

    private function assertSchedulingAccess(?User $user): void
    {
        if ($this->authorization->hasPermission($user, 'appointment.book.self')) {
            return;
        }

        $this->authorization->assertPermission($user, 'scheduling.admin');
    }

    private function username(?User $user): string
    {
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated user is required.');
        }

        return $user->getUsername();
    }

    private function requireTrimmedString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ApiValidationException('Scheduling payload is invalid.', [
                ['field' => $field, 'issue' => 'required'],
            ]);
        }

        return trim($value);
    }

    private function requireInt(mixed $value, string $field, int $min, int $max): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            throw new ApiValidationException('Scheduling payload is invalid.', [
                ['field' => $field, 'issue' => 'must_be_integer'],
            ]);
        }

        $int = (int) $value;
        if ($int < $min || $int > $max) {
            throw new ApiValidationException('Scheduling payload is invalid.', [
                ['field' => $field, 'issue' => sprintf('must_be_between_%d_and_%d', $min, $max)],
            ]);
        }

        return $int;
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeWeeklyAvailability(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new ApiValidationException('Scheduling payload is invalid.', [
                ['field' => 'weeklyAvailability', 'issue' => 'required'],
            ]);
        }

        $normalized = [];
        foreach ($raw as $index => $entry) {
            if (!is_array($entry)) {
                throw new ApiValidationException('Scheduling payload is invalid.', [
                    ['field' => sprintf('weeklyAvailability[%d]', (int) $index), 'issue' => 'must_be_object'],
                ]);
            }

            $weekday = $this->requireInt($entry['weekday'] ?? null, sprintf('weeklyAvailability[%d].weekday', (int) $index), 1, 7);
            $startTime = $this->requireTrimmedString($entry['startTime'] ?? null, sprintf('weeklyAvailability[%d].startTime', (int) $index));
            $endTime = $this->requireTrimmedString($entry['endTime'] ?? null, sprintf('weeklyAvailability[%d].endTime', (int) $index));

            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $startTime) !== 1 || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $endTime) !== 1) {
                throw new ApiValidationException('Scheduling payload is invalid.', [
                    ['field' => sprintf('weeklyAvailability[%d]', (int) $index), 'issue' => 'invalid_time_format'],
                ]);
            }

            if ($endTime <= $startTime) {
                throw new ApiValidationException('Scheduling payload is invalid.', [
                    ['field' => sprintf('weeklyAvailability[%d].endTime', (int) $index), 'issue' => 'must_be_after_startTime'],
                ]);
            }

            $normalized[] = [
                'weekday' => $weekday,
                'startTime' => $startTime,
                'endTime' => $endTime,
            ];
        }

        return $normalized;
    }

    private function optionalDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new ApiValidationException('Invalid date filter.', [
                ['field' => 'date', 'issue' => 'must_be_string'],
            ]);
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($trimmed, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new ApiValidationException('Invalid date filter.', [
                ['field' => 'date', 'issue' => 'invalid_datetime'],
            ]);
        }
    }
}
