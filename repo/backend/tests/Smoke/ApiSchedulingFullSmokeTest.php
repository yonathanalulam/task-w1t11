<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * End-to-end scheduling flow over real HTTP.
 *
 * Endpoints exercised:
 *   POST /api/scheduling/slots/generate
 *   GET  /api/scheduling/slots
 *   POST /api/scheduling/slots/{slotId}/hold
 *   POST /api/scheduling/holds/{holdId}/release
 *   POST /api/scheduling/holds/{holdId}/book
 *   GET  /api/scheduling/bookings/me
 *   POST /api/scheduling/bookings/{bookingId}/reschedule
 *   POST /api/scheduling/bookings/{bookingId}/cancel
 */
final class ApiSchedulingFullSmokeTest extends AbstractHttpSmokeTestCase
{
    public function testAdminGeneratesAndListsSlotsOverRealHttp(): void
    {
        $csrf = $this->loginAs('system_admin');
        $suffix = bin2hex(random_bytes(3));

        $put = $this->request('PUT', '/api/scheduling/configuration', [
            'json' => [
                'practitionerName' => 'SchedSmoke ' . $suffix,
                'locationName' => 'SchedRoom ' . $suffix,
                'slotDurationMinutes' => 30,
                'slotCapacity' => 1,
                'weeklyAvailability' => [
                    ['weekday' => 1, 'startTime' => '09:00', 'endTime' => '11:00'],
                    ['weekday' => 2, 'startTime' => '09:00', 'endTime' => '11:00'],
                    ['weekday' => 3, 'startTime' => '09:00', 'endTime' => '11:00'],
                    ['weekday' => 4, 'startTime' => '09:00', 'endTime' => '11:00'],
                    ['weekday' => 5, 'startTime' => '09:00', 'endTime' => '11:00'],
                ],
            ],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(200, $put['status']);

        $generate = $this->request('POST', '/api/scheduling/slots/generate', [
            'json' => ['daysAhead' => 14],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(201, $generate['status'], 'generate body: ' . $generate['body']);
        $data = $this->json($generate['body'])['data'] ?? [];
        self::assertArrayHasKey('createdCount', $data);
        self::assertIsInt($data['createdCount']);
        // createdCount may be 0 on re-runs when config exists identically, so assert shape not value
        self::assertGreaterThanOrEqual(0, (int) $data['createdCount']);

        $list = $this->request('GET', '/api/scheduling/slots');
        self::assertSame(200, $list['status']);
        $slots = $this->json($list['body'])['data']['slots'] ?? null;
        self::assertIsArray($slots);
        self::assertNotEmpty($slots, 'slot listing must expose at least one slot after generation');
    }

    public function testStandardUserHoldBookListRescheduleOverRealHttp(): void
    {
        $this->ensureGeneratedSlots();

        $csrf = $this->loginAs('standard_user');

        $slots = $this->json($this->request('GET', '/api/scheduling/slots')['body'])['data']['slots'] ?? [];
        $target = $this->findBookableSlot($slots);
        self::assertNotNull($target, 'scheduling smoke requires at least one bookable future slot; none available');

        $hold = $this->request('POST', sprintf('/api/scheduling/slots/%d/hold', $target['id']), [
            'json' => [],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(201, $hold['status'], 'hold body: ' . $hold['body']);
        $holdId = (int) ($this->json($hold['body'])['data']['holdId'] ?? 0);
        self::assertGreaterThan(0, $holdId);

        $book = $this->request('POST', sprintf('/api/scheduling/holds/%d/book', $holdId), [
            'json' => [],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(201, $book['status'], 'book body: ' . $book['body']);
        $bookingId = (int) ($this->json($book['body'])['data']['booking']['id'] ?? 0);
        self::assertGreaterThan(0, $bookingId);

        $myBookings = $this->request('GET', '/api/scheduling/bookings/me');
        self::assertSame(200, $myBookings['status']);
        $bookings = $this->json($myBookings['body'])['data']['bookings'] ?? [];
        self::assertIsArray($bookings);
        self::assertNotEmpty($bookings);

        $rescheduleTarget = $this->findAnotherBookableSlot($slots, $target['id']);
        if ($rescheduleTarget !== null) {
            $reschedule = $this->request('POST', sprintf('/api/scheduling/bookings/%d/reschedule', $bookingId), [
                'json' => ['targetSlotId' => $rescheduleTarget['id']],
                'headers' => ['X-CSRF-Token' => $csrf],
            ]);
            // Either success or a well-formed domain conflict (e.g. limit/horizon).
            self::assertContains($reschedule['status'], [200, 409], 'reschedule body: ' . $reschedule['body']);
        } else {
            // If only one slot is available we still exercise the route with an explicit call.
            $reschedule = $this->request('POST', sprintf('/api/scheduling/bookings/%d/reschedule', $bookingId), [
                'json' => ['targetSlotId' => $target['id']],
                'headers' => ['X-CSRF-Token' => $csrf],
            ]);
            self::assertContains($reschedule['status'], [200, 409, 422]);
        }
    }

    public function testStandardUserHoldReleasesHoldOverRealHttp(): void
    {
        $this->ensureGeneratedSlots();

        $csrf = $this->loginAs('standard_user');
        $slots = $this->json($this->request('GET', '/api/scheduling/slots')['body'])['data']['slots'] ?? [];
        $target = $this->findBookableSlot($slots);
        self::assertNotNull($target, 'release test requires a bookable slot');

        $hold = $this->request('POST', sprintf('/api/scheduling/slots/%d/hold', $target['id']), [
            'json' => [],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(201, $hold['status']);
        $holdId = (int) ($this->json($hold['body'])['data']['holdId'] ?? 0);

        $release = $this->request('POST', sprintf('/api/scheduling/holds/%d/release', $holdId), [
            'json' => [],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        self::assertSame(200, $release['status'], 'release body: ' . $release['body']);
        self::assertSame('RELEASED', $this->json($release['body'])['data']['status'] ?? null);
    }

    public function testAdminCanCancelRecentlyCreatedBookingOverRealHttp(): void
    {
        $this->ensureGeneratedSlots();

        // Standard user books a slot, admin cancels (admin can override 24h window)
        $userCsrf = $this->loginAs('standard_user');
        $slots = $this->json($this->request('GET', '/api/scheduling/slots')['body'])['data']['slots'] ?? [];
        $target = $this->findBookableSlot($slots);
        self::assertNotNull($target, 'cancel test requires a bookable slot');

        $hold = $this->request('POST', sprintf('/api/scheduling/slots/%d/hold', $target['id']), [
            'json' => [],
            'headers' => ['X-CSRF-Token' => $userCsrf],
        ]);
        self::assertSame(201, $hold['status']);
        $holdId = (int) ($this->json($hold['body'])['data']['holdId'] ?? 0);

        $book = $this->request('POST', sprintf('/api/scheduling/holds/%d/book', $holdId), [
            'json' => [],
            'headers' => ['X-CSRF-Token' => $userCsrf],
        ]);
        self::assertSame(201, $book['status']);
        $bookingId = (int) ($this->json($book['body'])['data']['booking']['id'] ?? 0);

        // Swap identities
        $this->cookieJar = [];
        $adminCsrf = $this->loginAs('system_admin');

        $cancel = $this->request('POST', sprintf('/api/scheduling/bookings/%d/cancel', $bookingId), [
            'json' => ['reason' => 'Smoke coverage admin cancel for scheduling coverage.'],
            'headers' => ['X-CSRF-Token' => $adminCsrf],
        ]);
        self::assertSame(200, $cancel['status'], 'cancel body: ' . $cancel['body']);
        self::assertSame('CANCELLED', $this->json($cancel['body'])['data']['booking']['status'] ?? null);
    }

    private function ensureGeneratedSlots(): void
    {
        $csrf = $this->loginAs('system_admin');

        // Idempotent upsert: set config + generate up to 14 days of capacity.
        $suffix = bin2hex(random_bytes(3));
        $this->request('PUT', '/api/scheduling/configuration', [
            'json' => [
                'practitionerName' => 'Smoke Flow ' . $suffix,
                'locationName' => 'Smoke Loc ' . $suffix,
                'slotDurationMinutes' => 30,
                'slotCapacity' => 1,
                'weeklyAvailability' => [
                    ['weekday' => 1, 'startTime' => '09:00', 'endTime' => '11:00'],
                    ['weekday' => 2, 'startTime' => '09:00', 'endTime' => '11:00'],
                    ['weekday' => 3, 'startTime' => '09:00', 'endTime' => '11:00'],
                    ['weekday' => 4, 'startTime' => '09:00', 'endTime' => '11:00'],
                    ['weekday' => 5, 'startTime' => '09:00', 'endTime' => '11:00'],
                ],
            ],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);
        $this->request('POST', '/api/scheduling/slots/generate', [
            'json' => ['daysAhead' => 14],
            'headers' => ['X-CSRF-Token' => $csrf],
        ]);

        // drop admin session so callers can re-login as whomever
        $this->cookieJar = [];
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     * @return array<string, mixed>|null
     */
    private function findBookableSlot(array $slots): ?array
    {
        foreach ($slots as $slot) {
            $remaining = (int) ($slot['remainingCapacity'] ?? 0);
            $bookedByMe = (bool) ($slot['bookedByCurrentUser'] ?? false);
            $startAt = $slot['startAtUtc'] ?? null;
            if ($remaining > 0 && !$bookedByMe && is_string($startAt)) {
                $startAtStamp = strtotime($startAt);
                if ($startAtStamp !== false && $startAtStamp > time() + 3600) {
                    return $slot;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     * @return array<string, mixed>|null
     */
    private function findAnotherBookableSlot(array $slots, int $excludeSlotId): ?array
    {
        foreach ($slots as $slot) {
            if ((int) ($slot['id'] ?? 0) === $excludeSlotId) {
                continue;
            }
            $remaining = (int) ($slot['remainingCapacity'] ?? 0);
            $bookedByMe = (bool) ($slot['bookedByCurrentUser'] ?? false);
            $startAt = $slot['startAtUtc'] ?? null;
            if ($remaining > 0 && !$bookedByMe && is_string($startAt)) {
                $startAtStamp = strtotime($startAt);
                if ($startAtStamp !== false && $startAtStamp > time() + 3600) {
                    return $slot;
                }
            }
        }

        return null;
    }
}
