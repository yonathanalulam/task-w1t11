import { beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { apiGet, apiPost, apiPostForm, apiPut, type ApiError } from './client';

/**
 * These tests exercise the raw API client with narrow, per-call fetch mocks.
 * They intentionally do NOT share a route-switch mock; each test supplies the
 * exact Response its call expects, so the tests stay fragile only to the
 * envelope contract the backend actually exposes.
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

describe('apiGet', () => {
  it('sends GET with credentials included and returns the unwrapped data payload', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: { username: 'standard_user' } }));

    const result = await apiGet<{ username: string }>('/api/auth/me');

    expect(result).toEqual({ username: 'standard_user' });
    const [calledUrl, init] = fetchMock.mock.calls[0] ?? [];
    expect(calledUrl).toBe('/api/auth/me');
    expect((init as RequestInit | undefined)?.credentials).toBe('include');
  });

  it('throws the ApiError envelope when response is not ok', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({ error: { code: 'UNAUTHENTICATED', message: 'Authentication required.' } }, 401),
    );

    await expect(apiGet('/api/auth/me')).rejects.toMatchObject<ApiError>({
      error: { code: 'UNAUTHENTICATED', message: 'Authentication required.' },
    });
  });
});

describe('apiPost', () => {
  it('sends JSON body, attaches CSRF header when provided, and returns data', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: { ok: true } }));

    const result = await apiPost<{ ok: boolean }>(
      '/api/auth/logout',
      { reason: 'user-initiated' },
      'csrf-value',
    );

    expect(result).toEqual({ ok: true });
    const [, init] = fetchMock.mock.calls[0] ?? [];
    const typedInit = init as RequestInit | undefined;
    expect(typedInit?.method).toBe('POST');
    expect(typedInit?.credentials).toBe('include');
    expect(typedInit?.body).toBe(JSON.stringify({ reason: 'user-initiated' }));
    expect(typedInit?.headers).toMatchObject({
      'Content-Type': 'application/json',
      'X-CSRF-Token': 'csrf-value',
    });
  });

  it('omits CSRF header when csrfToken argument is absent', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: {} }));

    await apiPost('/api/auth/login', { username: 'a', password: 'b' });

    const [, init] = fetchMock.mock.calls[0] ?? [];
    expect((init as RequestInit | undefined)?.headers).not.toHaveProperty('X-CSRF-Token');
  });

  it('returns an empty object when the server replies with 204 No Content', async () => {
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));

    const result = await apiPost('/api/auth/logout', {}, 'csrf');

    expect(result).toEqual({});
  });

  it('throws ApiError with validation details on 422 responses', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(
        {
          error: {
            code: 'VALIDATION_ERROR',
            message: 'Registration payload is invalid.',
            details: [{ field: 'username', issue: 'This value should not be blank.' }],
          },
        },
        422,
      ),
    );

    await expect(apiPost('/api/auth/register', {}, 'csrf')).rejects.toMatchObject({
      error: {
        code: 'VALIDATION_ERROR',
        details: [{ field: 'username', issue: 'This value should not be blank.' }],
      },
    });
  });
});

describe('apiPostForm', () => {
  it('passes the FormData body as-is without setting Content-Type and attaches CSRF', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: { submissionId: 42 } }));

    const form = new FormData();
    form.set('label', 'bar-license.pdf');

    const result = await apiPostForm<{ submissionId: number }>('/api/practitioner/credentials', form, 'csrf-token');

    expect(result).toEqual({ submissionId: 42 });
    const [, init] = fetchMock.mock.calls[0] ?? [];
    const typedInit = init as RequestInit | undefined;
    expect(typedInit?.body).toBe(form);
    expect(typedInit?.headers).toEqual({ 'X-CSRF-Token': 'csrf-token' });
  });
});

describe('apiPut', () => {
  it('sends PUT with JSON body and CSRF, returns data on success', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: { profile: { id: 1 } } }));

    const result = await apiPut<{ profile: { id: number } }>(
      '/api/practitioner/profile',
      { lawyerFullName: 'Ariya' },
      'csrf-token',
    );

    expect(result).toEqual({ profile: { id: 1 } });
    const [, init] = fetchMock.mock.calls[0] ?? [];
    const typedInit = init as RequestInit | undefined;
    expect(typedInit?.method).toBe('PUT');
    expect(typedInit?.headers).toMatchObject({ 'X-CSRF-Token': 'csrf-token' });
  });
});
