import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { useAnalyticsWorkflow } from './useAnalyticsWorkflow';

/**
 * Focused hook tests using narrow, per-test fetch mocks instead of a giant
 * route-switch mock. Each test supplies the exact response its specific code
 * path needs, so failures point at a concrete contract — not a router regex.
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

describe('useAnalyticsWorkflow — feature editor state transitions', () => {
  it('beginFeatureEdit populates form state and resetFeatureEditor clears it', () => {
    const { result } = renderHook(() =>
      useAnalyticsWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false, // session off => mount effect skips network
        canUseAnalytics: false,
        canQueryAnalytics: false,
      }),
    );

    act(() => {
      result.current.beginFeatureEdit({
        id: 42,
        name: 'Breach Sentinel',
        description: 'Detects outliers.',
        tags: ['compliance', 'live'],
        formulaExpression: 'breachCount > 3',
        updatedAtUtc: '2026-01-01T00:00:00+00:00',
      });
    });

    expect(result.current.editingFeatureId).toBe(42);
    expect(result.current.featureName).toBe('Breach Sentinel');
    expect(result.current.featureTagsInput).toBe('compliance, live');
    expect(result.current.featureFormulaExpression).toBe('breachCount > 3');

    act(() => {
      result.current.resetFeatureEditor();
    });

    expect(result.current.editingFeatureId).toBeNull();
    expect(result.current.featureName).toBe('');
    expect(result.current.featureTagsInput).toBe('');
  });
});

describe('useAnalyticsWorkflow — loadAnalyticsWorkbench', () => {
  it('populates org units/features/datasets from /api/analytics/workbench/options and auto-selects first entries', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({
        data: {
          orgUnits: ['North Region', 'South Region'],
          features: [
            {
              id: 1,
              name: 'Feature One',
              description: '',
              tags: ['live'],
              formulaExpression: '1',
              updatedAtUtc: '2026-01-01T00:00:00+00:00',
            },
          ],
          sampleDatasets: [
            { id: 7, name: 'Sample', description: '', rowCount: 10, createdAtUtc: '2026-01-01T00:00:00+00:00' },
          ],
        },
      }),
    );

    const { result } = renderHook(() =>
      useAnalyticsWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canUseAnalytics: false,
        canQueryAnalytics: false,
      }),
    );

    await act(async () => {
      await result.current.loadAnalyticsWorkbench();
    });

    expect(fetchMock).toHaveBeenCalledWith('/api/analytics/workbench/options', expect.objectContaining({ credentials: 'include' }));
    expect(result.current.analyticsOrgUnits).toEqual(['North Region', 'South Region']);
    expect(result.current.analyticsSelectedOrgUnits).toEqual(['North Region']);
    expect(result.current.analyticsSelectedDatasetIds).toEqual([7]);
    expect(result.current.analyticsFeatures).toHaveLength(1);
  });

  it('surfaces the API error message when workbench options fail', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(
        { error: { code: 'ACCESS_DENIED', message: 'Insufficient permissions.' } },
        403,
      ),
    );

    const { result } = renderHook(() =>
      useAnalyticsWorkflow({
        csrfToken: '',
        sessionActive: false,
        canUseAnalytics: false,
        canQueryAnalytics: false,
      }),
    );

    await act(async () => {
      await result.current.loadAnalyticsWorkbench();
    });

    expect(result.current.analyticsError).toBe('Insufficient permissions.');
    expect(result.current.analyticsResult).toBeNull();
  });
});

describe('useAnalyticsWorkflow — handleRunAnalyticsQuery', () => {
  it('posts query payload with CSRF header and sets status with row count on success', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({
        data: {
          filters: {
            fromDate: '2026-01-01',
            toDate: '2026-01-31',
            orgUnits: [],
            datasetIds: [],
            featureIds: [],
            includeLiveData: true,
          },
          rows: [],
          summary: {
            rowCount: 17,
            totalIntakeCount: 0,
            totalBreachCount: 0,
            avgBreachRatePct: 0,
            avgComplianceScorePct: 0,
          },
          dashboard: { trend: [], distribution: [], correlation: {} },
          complianceDashboard: { kpis: [], promptKpis: [] },
        },
      }),
    );

    const { result } = renderHook(() =>
      useAnalyticsWorkflow({
        csrfToken: 'abc',
        sessionActive: false,
        canUseAnalytics: false,
        canQueryAnalytics: false,
      }),
    );

    await act(async () => {
      await result.current.handleRunAnalyticsQuery({ preventDefault: () => undefined } as never);
    });

    const [calledUrl, init] = fetchMock.mock.calls[0] ?? [];
    expect(calledUrl).toBe('/api/analytics/query');
    expect((init as RequestInit | undefined)?.method).toBe('POST');
    expect((init as RequestInit | undefined)?.headers).toMatchObject({ 'X-CSRF-Token': 'abc' });
    expect(result.current.analyticsStatus).toBe('Query complete: 17 rows returned.');
    expect(result.current.analyticsError).toBe('');
  });

  it('clears result and surfaces API error on failure', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(
        { error: { code: 'VALIDATION_ERROR', message: 'fromDate must be earlier than toDate.' } },
        422,
      ),
    );

    const { result } = renderHook(() =>
      useAnalyticsWorkflow({
        csrfToken: 'abc',
        sessionActive: false,
        canUseAnalytics: false,
        canQueryAnalytics: false,
      }),
    );

    await act(async () => {
      await result.current.handleRunAnalyticsQuery({ preventDefault: () => undefined } as never);
    });

    await waitFor(() => expect(result.current.analyticsError).toBe('fromDate must be earlier than toDate.'));
    expect(result.current.analyticsResult).toBeNull();
  });
});
