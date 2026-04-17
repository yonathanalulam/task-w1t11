import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { useSchedulingWorkflow } from './useSchedulingWorkflow';

/**
 * Narrow, per-test fetch mocks for useSchedulingWorkflow. Each test supplies
 * exactly the Response its code path expects so assertions are contract-level,
 * not regex-route-switch level.
 */

function jsonResponse(payload: unknown, status = 200): Response {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

const fetchMock = globalThis.fetch as unknown as Mock;

beforeEach(() => {
  fetchMock.mockReset();
});

describe('useSchedulingWorkflow — weekly availability editor', () => {
  it('toggleWeeklyAvailabilityDay adds a day when enabled and drops it when disabled', () => {
    const { result } = renderHook(() =>
      useSchedulingWorkflow({
        csrfToken: '',
        sessionActive: false,
        canUseScheduling: false,
        canAdminScheduling: false,
      }),
    );

    // default availability includes weekday 3 (Wednesday)
    expect(result.current.configWeeklyAvailability.some((e) => e.weekday === 3)).toBe(true);

    act(() => result.current.toggleWeeklyAvailabilityDay(3, false));
    expect(result.current.configWeeklyAvailability.some((e) => e.weekday === 3)).toBe(false);

    act(() => result.current.toggleWeeklyAvailabilityDay(6, true));
    expect(result.current.configWeeklyAvailability.some((e) => e.weekday === 6)).toBe(true);
  });

  it('setWeeklyAvailabilityTime updates only the requested weekday and field', () => {
    const { result } = renderHook(() =>
      useSchedulingWorkflow({
        csrfToken: '',
        sessionActive: false,
        canUseScheduling: false,
        canAdminScheduling: false,
      }),
    );

    act(() => result.current.setWeeklyAvailabilityTime(2, 'startTime', '08:30'));
    const tuesday = result.current.configWeeklyAvailability.find((e) => e.weekday === 2);
    expect(tuesday?.startTime).toBe('08:30');
    expect(tuesday?.endTime).toBe('17:00');

    const monday = result.current.configWeeklyAvailability.find((e) => e.weekday === 1);
    expect(monday?.startTime).toBe('09:00');
  });
});

describe('useSchedulingWorkflow — handlePlaceHold', () => {
  it('posts to /slots/{id}/hold with CSRF and reloads workbench data on success', async () => {
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ data: { holdId: 99, expiresAtUtc: '', status: 'ACTIVE' } }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: { slots: [] } }))
      .mockResolvedValueOnce(jsonResponse({ data: { bookings: [] } }));

    const { result } = renderHook(() =>
      useSchedulingWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canUseScheduling: false,
        canAdminScheduling: false,
      }),
    );

    await act(async () => {
      await result.current.handlePlaceHold(42);
    });

    const [holdUrl, holdInit] = fetchMock.mock.calls[0] ?? [];
    expect(holdUrl).toBe('/api/scheduling/slots/42/hold');
    expect((holdInit as RequestInit | undefined)?.method).toBe('POST');
    expect((holdInit as RequestInit | undefined)?.headers).toMatchObject({ 'X-CSRF-Token': 'csrf-x' });
    await waitFor(() => {
      expect(result.current.schedulingStatus).toBe('Hold placed for 10 minutes. Confirm booking before expiry.');
    });
  });

  it('surfaces backend error when hold is rejected', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({ error: { code: 'BOOKING_HORIZON_EXCEEDED', message: 'Slot is beyond 90 days.' } }, 422),
    );

    const { result } = renderHook(() =>
      useSchedulingWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canUseScheduling: false,
        canAdminScheduling: false,
      }),
    );

    await act(async () => {
      await result.current.handlePlaceHold(42);
    });

    await waitFor(() => expect(result.current.schedulingError).toBe('Slot is beyond 90 days.'));
  });
});

describe('useSchedulingWorkflow — handleRescheduleBooking validation', () => {
  it('requires a selected target slot before calling the API', async () => {
    const { result } = renderHook(() =>
      useSchedulingWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canUseScheduling: false,
        canAdminScheduling: false,
      }),
    );

    await act(async () => {
      await result.current.handleRescheduleBooking(7);
    });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.schedulingError).toBe('Choose a target slot before rescheduling.');
  });
});
