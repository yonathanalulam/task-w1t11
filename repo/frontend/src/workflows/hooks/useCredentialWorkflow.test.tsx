import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { useCredentialWorkflow } from './useCredentialWorkflow';

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

function baseEvent(): never {
  return { preventDefault: () => undefined } as never;
}

describe('useCredentialWorkflow — handleSaveProfile client validation', () => {
  it('blocks save when lawyerFullName / firmName / barJurisdiction are below minimum length', async () => {
    const { result } = renderHook(() =>
      useCredentialWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canManagePractitionerProfile: false,
        canUploadCredentials: false,
        canReviewCredentials: false,
      }),
    );

    act(() => {
      result.current.setLawyerFullName('AB');
      result.current.setFirmName('X');
      result.current.setBarJurisdiction('Y');
    });

    await act(async () => {
      await result.current.handleSaveProfile(baseEvent());
    });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.profileError).toBe(
      'Name, firm, and jurisdiction are required and must be meaningful.',
    );
  });

  it('blocks save when creating a new profile without a license number', async () => {
    const { result } = renderHook(() =>
      useCredentialWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canManagePractitionerProfile: false,
        canUploadCredentials: false,
        canReviewCredentials: false,
      }),
    );

    act(() => {
      result.current.setLawyerFullName('Ariya Chen');
      result.current.setFirmName('Northbridge');
      result.current.setBarJurisdiction('CA');
      // no license number set; profile is null (no session-load)
    });

    await act(async () => {
      await result.current.handleSaveProfile(baseEvent());
    });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.profileError).toBe('License number is required when creating a profile.');
  });
});

describe('useCredentialWorkflow — handleCredentialUpload validation', () => {
  it('reports missing label', async () => {
    const { result } = renderHook(() =>
      useCredentialWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canManagePractitionerProfile: false,
        canUploadCredentials: false,
        canReviewCredentials: false,
      }),
    );

    await act(async () => {
      await result.current.handleCredentialUpload(baseEvent());
    });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.credentialError).toBe('Credential label is required.');
  });

  it('reports missing file after label is set', async () => {
    const { result } = renderHook(() =>
      useCredentialWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canManagePractitionerProfile: false,
        canUploadCredentials: false,
        canReviewCredentials: false,
      }),
    );

    act(() => result.current.setCredentialLabel('Bar Admission Certificate'));

    await act(async () => {
      await result.current.handleCredentialUpload(baseEvent());
    });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.credentialError).toBe('Credential file is required.');
  });
});

describe('useCredentialWorkflow — loadReviewQueue', () => {
  it('populates review queue from /api/reviewer/credentials/queue', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({
        data: {
          queue: [
            {
              id: 1,
              label: 'Target Submission',
              status: 'PENDING_REVIEW',
              currentVersionNumber: 1,
              updatedAtUtc: '2026-01-01T00:00:00+00:00',
              practitioner: {
                username: 'standard_user',
                lawyerFullName: 'Ariya Chen',
                firmName: 'Firm',
                barJurisdiction: 'CA',
                licenseNumberMasked: '••••1234',
              },
              latestVersion: null,
              versions: [],
            },
          ],
          statusFilter: 'PENDING_REVIEW',
        },
      }),
    );

    const { result } = renderHook(() =>
      useCredentialWorkflow({
        csrfToken: '',
        sessionActive: false,
        canManagePractitionerProfile: false,
        canUploadCredentials: false,
        canReviewCredentials: false,
      }),
    );

    await act(async () => {
      await result.current.loadReviewQueue('PENDING_REVIEW');
    });

    const [calledUrl] = fetchMock.mock.calls[0] ?? [];
    expect(calledUrl).toBe('/api/reviewer/credentials/queue?status=PENDING_REVIEW');
    await waitFor(() => expect(result.current.reviewQueue).toHaveLength(1));
    expect(result.current.reviewQueue[0]?.label).toBe('Target Submission');
  });
});
