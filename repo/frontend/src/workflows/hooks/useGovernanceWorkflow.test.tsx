import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { useGovernanceWorkflow } from './useGovernanceWorkflow';

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

function formEvent(): never {
  return { preventDefault: () => undefined } as never;
}

describe('useGovernanceWorkflow — handleRevealSensitiveLicense validation', () => {
  it('requires a positive integer profile id', async () => {
    const { result } = renderHook(() =>
      useGovernanceWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canUseAdminGovernance: false,
        canReadAdminAudit: false,
        canRunAdminRollback: false,
      }),
    );

    act(() => result.current.setSensitiveProfileId('abc'));

    await act(async () => {
      await result.current.handleRevealSensitiveLicense(formEvent());
    });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.adminError).toBe('Practitioner profile ID must be a positive integer.');
  });

  it('requires a minimum 8-character reason', async () => {
    const { result } = renderHook(() =>
      useGovernanceWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canUseAdminGovernance: false,
        canReadAdminAudit: false,
        canRunAdminRollback: false,
      }),
    );

    act(() => {
      result.current.setSensitiveProfileId('42');
      result.current.setSensitiveReason('short');
    });

    await act(async () => {
      await result.current.handleRevealSensitiveLicense(formEvent());
    });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.adminError).toBe(
      'Reason is required for sensitive-field read (minimum 8 characters).',
    );
  });
});

describe('useGovernanceWorkflow — handleRefreshAnomalies', () => {
  it('posts to anomalies/refresh and updates alert count status', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({
        data: {
          alerts: [
            {
              id: 7,
              alertType: 'CREDENTIAL_REJECTION_SPIKE',
              scopeKey: 'firm:acme',
              status: 'OPEN',
              payload: { rejectedCount: 6 },
              createdAtUtc: '2026-01-01T00:00:00+00:00',
              updatedAtUtc: '2026-01-01T00:00:00+00:00',
              lastDetectedAtUtc: '2026-01-01T00:00:00+00:00',
            },
          ],
        },
      }),
    );

    const { result } = renderHook(() =>
      useGovernanceWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canUseAdminGovernance: false,
        canReadAdminAudit: false,
        canRunAdminRollback: false,
      }),
    );

    await act(async () => {
      await result.current.handleRefreshAnomalies();
    });

    const [calledUrl, init] = fetchMock.mock.calls[0] ?? [];
    expect(calledUrl).toBe('/api/admin/governance/anomalies/refresh');
    expect((init as RequestInit | undefined)?.method).toBe('POST');
    expect((init as RequestInit | undefined)?.headers).toMatchObject({ 'X-CSRF-Token': 'csrf-x' });
    await waitFor(() => {
      expect(result.current.adminStatus).toBe('Anomaly scan complete. 1 alerts currently tracked.');
    });
    expect(result.current.adminAnomalyAlerts).toHaveLength(1);
  });
});

describe('useGovernanceWorkflow — handleAcknowledgeAnomaly validation', () => {
  it('blocks when note is under 8 characters', async () => {
    const { result } = renderHook(() =>
      useGovernanceWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canUseAdminGovernance: false,
        canReadAdminAudit: false,
        canRunAdminRollback: false,
      }),
    );

    act(() => {
      result.current.setAnomalyAckNotes({ 42: 'short' });
    });

    await act(async () => {
      await result.current.handleAcknowledgeAnomaly(42);
    });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.adminError).toBe('Acknowledgement note must be at least 8 characters.');
  });
});
