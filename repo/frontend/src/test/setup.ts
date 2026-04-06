import '@testing-library/jest-dom/vitest';
import { vi } from 'vitest';

const defaultApiResponses: Record<string, unknown> = {
  '/api/health/live': { data: { status: 'live' }, meta: { requestId: 'test' } },
  '/api/health/ready': { data: { status: 'ready', database: 'ok', keyring: { activeKeyId: 'test' } }, meta: { requestId: 'test' } },
  '/api/auth/csrf-token': { data: { csrfToken: 'csrf-test-token', headerName: 'X-CSRF-Token' }, meta: { requestId: 'test' } },
  '/api/auth/me': { error: { code: 'UNAUTHENTICATED', message: 'Authentication required.' }, meta: { requestId: 'test' } },
};

vi.stubGlobal(
  'fetch',
  vi.fn(async (input: RequestInfo | URL) => {
    const path = typeof input === 'string' ? input : input.toString();
    const payload = defaultApiResponses[path] ?? { error: { code: 'NOT_FOUND', message: 'Not found' }, meta: { requestId: 'test' } };
    const status = 'error' in (payload as Record<string, unknown>) ? 401 : 200;

    return new Response(JSON.stringify(payload), {
      status,
      headers: { 'Content-Type': 'application/json' },
    });
  }),
);
