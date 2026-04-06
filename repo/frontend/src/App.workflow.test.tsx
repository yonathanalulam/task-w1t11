import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { type Mock } from 'vitest';
import App from './App';

function jsonResponse(payload: unknown, status = 200): Response {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

describe('Practitioner and reviewer workflow UI', () => {
  it('supports practitioner profile save and credential upload feedback', async () => {
    const user = userEvent.setup();

    let profileSaved = false;
    let credentialUploaded = false;

    const fetchMock = globalThis.fetch as unknown as Mock;
    fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const rawUrl = typeof input === 'string' ? input : input.toString();
      const [path] = rawUrl.split('?');
      const method = (init?.method ?? 'GET').toUpperCase();

      if (path === '/api/health/live') {
        return jsonResponse({ data: { status: 'live' } });
      }
      if (path === '/api/health/ready') {
        return jsonResponse({ data: { status: 'ready', database: 'ok', keyring: { activeKeyId: 'dev-key' } } });
      }
      if (path === '/api/auth/csrf-token') {
        return jsonResponse({ data: { csrfToken: 'csrf-token', headerName: 'X-CSRF-Token' } });
      }
      if (path === '/api/auth/me') {
        return jsonResponse({ error: { code: 'UNAUTHENTICATED', message: 'Authentication required.' } }, 401);
      }

      if (path === '/api/auth/login' && method === 'POST') {
        return jsonResponse({
          data: {
            user: { username: 'standard_user', roles: ['ROLE_STANDARD_USER'] },
            permissions: ['practitioner.manage.self', 'credential.upload.self', 'nav.practitioner', 'nav.dashboard'],
            navigation: ['dashboard', 'practitioner'],
          },
        });
      }

      if (path === '/api/permissions/me') {
        return jsonResponse({
          data: {
            username: 'standard_user',
            roles: ['ROLE_STANDARD_USER'],
            permissions: ['practitioner.manage.self', 'credential.upload.self', 'nav.practitioner', 'nav.dashboard'],
            navigation: ['dashboard', 'practitioner'],
          },
        });
      }

      if (path === '/api/practitioner/profile' && method === 'GET') {
        return jsonResponse({
          data: {
            profile: profileSaved
              ? {
                  id: 10,
                  lawyerFullName: 'Ariya Chen',
                  firmName: 'Northbridge Legal',
                  barJurisdiction: 'CA',
                  licenseNumberMasked: '••••7122',
                  updatedAtUtc: new Date().toISOString(),
                }
              : null,
          },
        });
      }

      if (path === '/api/practitioner/credentials' && method === 'GET') {
        return jsonResponse({
          data: {
            profileRequired: !profileSaved,
            submissions: credentialUploaded
              ? [
                  {
                    id: 41,
                    label: 'Bar Admission Credential',
                    status: 'PENDING_REVIEW',
                    currentVersionNumber: 1,
                    updatedAtUtc: new Date().toISOString(),
                    latestVersion: {
                      id: 80,
                      versionNumber: 1,
                      originalFilename: 'license.pdf',
                      mimeType: 'application/pdf',
                      sizeBytes: 1234,
                      reviewStatus: 'PENDING_REVIEW',
                      reviewComment: null,
                      reviewedByUsername: null,
                      reviewedAtUtc: null,
                      uploadedByUsername: 'standard_user',
                      uploadedAtUtc: new Date().toISOString(),
                      downloadPath: '/api/credentials/versions/80/download',
                    },
                    versions: [
                      {
                        id: 80,
                        versionNumber: 1,
                        originalFilename: 'license.pdf',
                        mimeType: 'application/pdf',
                        sizeBytes: 1234,
                        reviewStatus: 'PENDING_REVIEW',
                        reviewComment: null,
                        reviewedByUsername: null,
                        reviewedAtUtc: null,
                        uploadedByUsername: 'standard_user',
                        uploadedAtUtc: new Date().toISOString(),
                        downloadPath: '/api/credentials/versions/80/download',
                      },
                    ],
                  },
                ]
              : [],
          },
        });
      }

      if (path === '/api/practitioner/profile' && method === 'PUT') {
        profileSaved = true;
        return jsonResponse({
          data: {
            profile: {
              id: 10,
              lawyerFullName: 'Ariya Chen',
              firmName: 'Northbridge Legal',
              barJurisdiction: 'CA',
              licenseNumberMasked: '••••7122',
              updatedAtUtc: new Date().toISOString(),
            },
          },
        });
      }

      if (path === '/api/practitioner/credentials' && method === 'POST') {
        credentialUploaded = true;
        return jsonResponse(
          {
            data: {
              submission: {
                id: 41,
                label: 'Bar Admission Credential',
                status: 'PENDING_REVIEW',
                currentVersionNumber: 1,
                updatedAtUtc: new Date().toISOString(),
                latestVersion: null,
                versions: [],
              },
            },
          },
          201,
        );
      }

      return jsonResponse({ error: { code: 'NOT_FOUND', message: `No mock for ${method} ${rawUrl}` } }, 404);
    });

    render(<App />);

    await user.clear(screen.getByLabelText('Password'));
    await user.type(screen.getByLabelText('Password'), 'StrongPassword123!');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await screen.findByRole('heading', { name: 'Practitioner profile' });

    await user.clear(screen.getByLabelText(/Lawyer identity/i));
    await user.type(screen.getByLabelText(/Lawyer identity/i), 'Ariya Chen');
    await user.clear(screen.getByLabelText(/Firm affiliation/i));
    await user.type(screen.getByLabelText(/Firm affiliation/i), 'Northbridge Legal');
    await user.clear(screen.getByLabelText(/Bar \/ licensing jurisdiction/i));
    await user.type(screen.getByLabelText(/Bar \/ licensing jurisdiction/i), 'CA');
    await user.type(screen.getByLabelText(/License number/i), 'BAR-447122');
    await user.click(screen.getByRole('button', { name: 'Save profile' }));

    await screen.findByText(/Practitioner profile saved/i);
    await screen.findByText(/••••7122/);

    await user.type(screen.getByLabelText('Credential label'), 'Bar Admission Credential');
    const credentialFile = new File(['%PDF-1.4 test'], 'license.pdf', { type: 'application/pdf' });
    await user.upload(screen.getByLabelText(/Credential file/i), credentialFile);
    await user.click(screen.getByRole('button', { name: /Upload & submit to review queue/i }));

    await screen.findByText(/Credential uploaded and submitted to review queue/i);
    await screen.findByText(/Bar Admission Credential/);
    await waitFor(() => {
      expect(screen.getAllByText('PENDING_REVIEW').length).toBeGreaterThan(0);
    });
  });

  it('supports reviewer decision flow with required-comment validation', async () => {
    const user = userEvent.setup();

    let decisionSaved = false;

    const queueEntry = {
      id: 77,
      label: 'E2E Credential Queue Item',
      status: 'PENDING_REVIEW',
      currentVersionNumber: 1,
      updatedAtUtc: new Date().toISOString(),
      practitioner: {
        username: 'standard_user',
        lawyerFullName: 'Ariya Chen',
        firmName: 'Northbridge Legal',
        barJurisdiction: 'CA',
        licenseNumberMasked: '••••7122',
      },
      latestVersion: {
        id: 91,
        versionNumber: 1,
        originalFilename: 'credential.pdf',
        mimeType: 'application/pdf',
        sizeBytes: 2222,
        reviewStatus: 'PENDING_REVIEW',
        reviewComment: null,
        reviewedByUsername: null,
        reviewedAtUtc: null,
        uploadedByUsername: 'standard_user',
        uploadedAtUtc: new Date().toISOString(),
        downloadPath: '/api/credentials/versions/91/download',
      },
      versions: [
        {
          id: 91,
          versionNumber: 1,
          originalFilename: 'credential.pdf',
          mimeType: 'application/pdf',
          sizeBytes: 2222,
          reviewStatus: 'PENDING_REVIEW',
          reviewComment: null,
          reviewedByUsername: null,
          reviewedAtUtc: null,
          uploadedByUsername: 'standard_user',
          uploadedAtUtc: new Date().toISOString(),
          downloadPath: '/api/credentials/versions/91/download',
        },
      ],
    };

    const fetchMock = globalThis.fetch as unknown as Mock;
    fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const rawUrl = typeof input === 'string' ? input : input.toString();
      const [path, query = ''] = rawUrl.split('?');
      const method = (init?.method ?? 'GET').toUpperCase();

      if (path === '/api/health/live') {
        return jsonResponse({ data: { status: 'live' } });
      }
      if (path === '/api/health/ready') {
        return jsonResponse({ data: { status: 'ready', database: 'ok', keyring: { activeKeyId: 'dev-key' } } });
      }
      if (path === '/api/auth/csrf-token') {
        return jsonResponse({ data: { csrfToken: 'csrf-token', headerName: 'X-CSRF-Token' } });
      }
      if (path === '/api/auth/me') {
        return jsonResponse({ error: { code: 'UNAUTHENTICATED', message: 'Authentication required.' } }, 401);
      }

      if (path === '/api/auth/login' && method === 'POST') {
        return jsonResponse({
          data: {
            user: { username: 'system_admin', roles: ['ROLE_SYSTEM_ADMIN'] },
            permissions: ['credential.review', 'nav.credentialReview', 'nav.admin'],
            navigation: ['admin', 'credentialReview'],
          },
        });
      }

      if (path === '/api/permissions/me') {
        return jsonResponse({
          data: {
            username: 'system_admin',
            roles: ['ROLE_SYSTEM_ADMIN'],
            permissions: ['credential.review', 'nav.credentialReview', 'nav.admin'],
            navigation: ['admin', 'credentialReview'],
          },
        });
      }

      if (path === '/api/reviewer/credentials/queue' && query.includes('status=PENDING_REVIEW')) {
        return jsonResponse({
          data: {
            statusFilter: 'PENDING_REVIEW',
            queue: decisionSaved ? [] : [queueEntry],
          },
        });
      }

      if (path === '/api/reviewer/credentials/77') {
        return jsonResponse({
          data: {
            submission: {
              ...queueEntry,
              status: decisionSaved ? 'APPROVED' : 'PENDING_REVIEW',
              latestVersion: {
                ...queueEntry.latestVersion,
                reviewStatus: decisionSaved ? 'APPROVED' : 'PENDING_REVIEW',
                reviewComment: decisionSaved ? 'Administrative approval complete.' : null,
                reviewedByUsername: decisionSaved ? 'system_admin' : null,
                reviewedAtUtc: decisionSaved ? new Date().toISOString() : null,
              },
              versions: [
                {
                  ...queueEntry.versions[0],
                  reviewStatus: decisionSaved ? 'APPROVED' : 'PENDING_REVIEW',
                  reviewComment: decisionSaved ? 'Administrative approval complete.' : null,
                  reviewedByUsername: decisionSaved ? 'system_admin' : null,
                  reviewedAtUtc: decisionSaved ? new Date().toISOString() : null,
                },
              ],
            },
          },
        });
      }

      if (path === '/api/reviewer/credentials/77/decision' && method === 'POST') {
        decisionSaved = true;
        return jsonResponse({
          data: {
            submission: {
              ...queueEntry,
              status: 'APPROVED',
              latestVersion: {
                ...queueEntry.latestVersion,
                reviewStatus: 'APPROVED',
                reviewComment: 'Administrative approval complete.',
                reviewedByUsername: 'system_admin',
                reviewedAtUtc: new Date().toISOString(),
              },
              versions: [
                {
                  ...queueEntry.versions[0],
                  reviewStatus: 'APPROVED',
                  reviewComment: 'Administrative approval complete.',
                  reviewedByUsername: 'system_admin',
                  reviewedAtUtc: new Date().toISOString(),
                },
              ],
            },
          },
        });
      }

      if (path === '/api/practitioner/profile' || path === '/api/practitioner/credentials') {
        return jsonResponse({ error: { code: 'ACCESS_DENIED', message: 'Insufficient permissions.' } }, 403);
      }

      return jsonResponse({ error: { code: 'NOT_FOUND', message: `No mock for ${method} ${rawUrl}` } }, 404);
    });

    render(<App />);

    await user.clear(screen.getByLabelText('Username'));
    await user.type(screen.getByLabelText('Username'), 'system_admin');
    await user.clear(screen.getByLabelText('Password'));
    await user.type(screen.getByLabelText('Password'), 'StrongPassword123!');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await screen.findByRole('heading', { name: 'Review queue' });

    await user.click(screen.getByRole('button', { name: /E2E Credential Queue Item/i }));
    await screen.findByRole('heading', { name: 'Decision console' });

    await user.selectOptions(screen.getByLabelText('Decision'), 'reject');
    await user.click(screen.getByRole('button', { name: 'Record decision' }));
    await screen.findByText(/Comment is required for reject or request-resubmission decisions/i);

    await user.selectOptions(screen.getByLabelText('Decision'), 'approve');
    await user.type(screen.getByLabelText('Comment'), 'Administrative approval complete.');
    await user.click(screen.getByRole('button', { name: 'Record decision' }));

    await waitFor(() => {
      expect(screen.getByText(/Reviewer decision saved and audit-logged/i)).toBeInTheDocument();
    });
  });

  it('supports scheduling configuration, hold, and booking flow', async () => {
    const user = userEvent.setup();
    let generated = false;
    let hasHold = false;
    let hasBooking = false;

    const fetchMock = globalThis.fetch as unknown as Mock;
    fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const rawUrl = typeof input === 'string' ? input : input.toString();
      const [path] = rawUrl.split('?');
      const method = (init?.method ?? 'GET').toUpperCase();

      if (path === '/api/health/live') return jsonResponse({ data: { status: 'live' } });
      if (path === '/api/health/ready') {
        return jsonResponse({ data: { status: 'ready', database: 'ok', keyring: { activeKeyId: 'dev-key' } } });
      }
      if (path === '/api/auth/csrf-token') return jsonResponse({ data: { csrfToken: 'csrf-token', headerName: 'X-CSRF-Token' } });
      if (path === '/api/auth/me') return jsonResponse({ error: { code: 'UNAUTHENTICATED', message: 'Authentication required.' } }, 401);

      if (path === '/api/auth/login' && method === 'POST') {
        return jsonResponse({
          data: {
            user: { username: 'system_admin', roles: ['ROLE_SYSTEM_ADMIN'] },
            permissions: ['scheduling.admin', 'appointment.book.self', 'auth.override.cancel24h', 'nav.scheduling'],
            navigation: ['scheduling'],
          },
        });
      }

      if (path === '/api/permissions/me') {
        return jsonResponse({
          data: {
            username: 'system_admin',
            roles: ['ROLE_SYSTEM_ADMIN'],
            permissions: ['scheduling.admin', 'appointment.book.self', 'auth.override.cancel24h', 'nav.scheduling'],
            navigation: ['scheduling'],
          },
        });
      }

      if (path === '/api/scheduling/configuration' && method === 'GET') {
        return jsonResponse({
          data: {
            configuration: {
              id: 1,
              practitionerName: 'Ariya Chen',
              locationName: 'HQ-01',
              slotDurationMinutes: 30,
              slotCapacity: 1,
              weeklyAvailability: [{ weekday: 1, startTime: '09:00', endTime: '17:00' }],
              updatedAtUtc: new Date().toISOString(),
            },
          },
        });
      }

      if (path === '/api/scheduling/configuration' && method === 'PUT') {
        return jsonResponse({
          data: {
            configuration: {
              id: 1,
              practitionerName: 'Ariya Chen',
              locationName: 'HQ-01',
              slotDurationMinutes: 30,
              slotCapacity: 1,
              weeklyAvailability: [{ weekday: 1, startTime: '09:00', endTime: '17:00' }],
              updatedAtUtc: new Date().toISOString(),
            },
          },
        });
      }

      if (path === '/api/scheduling/slots/generate' && method === 'POST') {
        generated = true;
        return jsonResponse({ data: { createdCount: 6 } }, 201);
      }

      if (path === '/api/scheduling/slots' && method === 'GET') {
        return jsonResponse({
          data: {
            slots: generated
              ? [
                  {
                    id: 100,
                    startAtUtc: new Date(Date.now() + 86_400_000).toISOString(),
                    endAtUtc: new Date(Date.now() + 86_400_000 + 1_800_000).toISOString(),
                    capacity: 1,
                    bookedCount: hasBooking ? 1 : 0,
                    activeHoldCount: hasHold ? 1 : 0,
                    remainingCapacity: hasBooking || hasHold ? 0 : 1,
                    status: 'ACTIVE',
                    practitionerName: 'Ariya Chen',
                    locationName: 'HQ-01',
                    bookedByCurrentUser: hasBooking,
                    currentUserHold: hasHold ? { holdId: 44, expiresAtUtc: new Date(Date.now() + 600_000).toISOString() } : null,
                  },
                ]
              : [],
          },
        });
      }

      if (path === '/api/scheduling/bookings/me' && method === 'GET') {
        return jsonResponse({
          data: {
            bookings: hasBooking
              ? [
                  {
                    id: 88,
                    status: 'ACTIVE',
                    bookedByUsername: 'system_admin',
                    rescheduleCount: 0,
                    updatedAtUtc: new Date().toISOString(),
                    slot: {
                      id: 100,
                      startAtUtc: new Date(Date.now() + 86_400_000).toISOString(),
                      endAtUtc: new Date(Date.now() + 86_400_000 + 1_800_000).toISOString(),
                      practitionerName: 'Ariya Chen',
                      locationName: 'HQ-01',
                    },
                  },
                ]
              : [],
          },
        });
      }

      if (path === '/api/scheduling/slots/100/hold' && method === 'POST') {
        hasHold = true;
        return jsonResponse({ data: { holdId: 44 } }, 201);
      }

      if (path === '/api/scheduling/holds/44/book' && method === 'POST') {
        hasHold = false;
        hasBooking = true;
        return jsonResponse({ data: { booking: { id: 88 } } }, 201);
      }

      if (path === '/api/practitioner/profile' || path === '/api/practitioner/credentials' || path.startsWith('/api/reviewer/credentials')) {
        return jsonResponse({ error: { code: 'ACCESS_DENIED', message: 'Insufficient permissions.' } }, 403);
      }

      return jsonResponse({ error: { code: 'NOT_FOUND', message: `No mock for ${method} ${rawUrl}` } }, 404);
    });

    render(<App />);
    await user.clear(screen.getByLabelText('Username'));
    await user.type(screen.getByLabelText('Username'), 'system_admin');
    await user.clear(screen.getByLabelText('Password'));
    await user.type(screen.getByLabelText('Password'), 'StrongPassword123!');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await screen.findByRole('heading', { name: 'Availability & slot management' });
    await user.click(screen.getByRole('button', { name: 'Save weekly availability' }));
    await screen.findByText(/Scheduling configuration saved/i);
    await screen.findByText(/Active config: Ariya Chen @ HQ-01/i);

    await user.clear(screen.getByLabelText('Generate slots days ahead'));
    await user.type(screen.getByLabelText('Generate slots days ahead'), '7');
    await user.click(screen.getByRole('button', { name: 'Generate slots from config' }));
    await screen.findByText(/Slots generated from weekly availability/i);

    await user.click(screen.getByRole('button', { name: 'Place 10-minute hold' }));
    await screen.findByText(/Hold placed for 10 minutes/i);

    await user.click(screen.getByRole('button', { name: 'Confirm booking' }));
    await screen.findByText(/Appointment booked successfully/i);
    await waitFor(() => {
      expect(screen.getByText(/Reschedule count: 0 \/ 2/i)).toBeInTheDocument();
    });
  });

  it('supports content-admin question-bank create and publish workflow with duplicate-review feedback', async () => {
    const user = userEvent.setup();
    let createdId = 300;
    let questionStatus: 'DRAFT' | 'PUBLISHED' = 'DRAFT';
    let duplicateFlagRaised = false;

    const fetchMock = globalThis.fetch as unknown as Mock;
    fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const rawUrl = typeof input === 'string' ? input : input.toString();
      const [path] = rawUrl.split('?');
      const method = (init?.method ?? 'GET').toUpperCase();

      if (path === '/api/health/live') return jsonResponse({ data: { status: 'live' } });
      if (path === '/api/health/ready') return jsonResponse({ data: { status: 'ready', database: 'ok', keyring: { activeKeyId: 'dev-key' } } });
      if (path === '/api/auth/csrf-token') return jsonResponse({ data: { csrfToken: 'csrf-token', headerName: 'X-CSRF-Token' } });
      if (path === '/api/auth/me') return jsonResponse({ error: { code: 'UNAUTHENTICATED', message: 'Authentication required.' } }, 401);

      if (path === '/api/auth/login' && method === 'POST') {
        return jsonResponse({
          data: {
            user: { username: 'content_admin', roles: ['ROLE_CONTENT_ADMIN'] },
            permissions: ['question.manage', 'question.publish', 'question.importExport', 'nav.questionBank'],
            navigation: ['questionBank'],
          },
        });
      }

      if (path === '/api/permissions/me') {
        return jsonResponse({
          data: {
            username: 'content_admin',
            roles: ['ROLE_CONTENT_ADMIN'],
            permissions: ['question.manage', 'question.publish', 'question.importExport', 'nav.questionBank'],
            navigation: ['questionBank'],
          },
        });
      }

      if (path.startsWith('/api/question-bank/questions?status=')) {
        return jsonResponse({
          data: {
            statusFilter: 'ALL',
            entries: [
              {
                id: createdId,
                title: 'Liquidity Escalation Matrix',
                status: questionStatus,
                difficulty: 3,
                tags: ['liquidity', 'intake'],
                currentVersionNumber: 1,
                duplicateReviewState: duplicateFlagRaised ? 'REQUIRES_REVIEW' : 'NONE',
                updatedAtUtc: new Date().toISOString(),
              },
            ],
          },
        });
      }

      if (path === '/api/question-bank/questions' && method === 'POST') {
        return jsonResponse(
          {
            data: {
              entry: {
                id: createdId,
                title: 'Liquidity Escalation Matrix',
                plainTextContent: 'Collect liquidity runway and covenant stress indicators.',
                richTextContent: '<p>Collect liquidity runway and covenant stress indicators.</p>',
                difficulty: 3,
                tags: ['liquidity', 'intake'],
                formulas: [{ expression: 'risk = liabilities / assets', label: '' }],
                embeddedImages: [],
                status: questionStatus,
                duplicateReviewState: duplicateFlagRaised ? 'REQUIRES_REVIEW' : 'NONE',
                currentVersionNumber: 1,
                updatedAtUtc: new Date().toISOString(),
                versions: [
                  {
                    id: 11,
                    versionNumber: 1,
                    title: 'Liquidity Escalation Matrix',
                    plainTextContent: 'Collect liquidity runway and covenant stress indicators.',
                    richTextContent: '<p>Collect liquidity runway and covenant stress indicators.</p>',
                    difficulty: 3,
                    tags: ['liquidity', 'intake'],
                    formulas: [{ expression: 'risk = liabilities / assets', label: '' }],
                    embeddedImages: [],
                    changeNote: 'Initial controlled draft',
                    createdByUsername: 'content_admin',
                    createdAtUtc: new Date().toISOString(),
                  },
                ],
              },
            },
          },
          201,
        );
      }

      if (path === `/api/question-bank/questions/${createdId}` && method === 'GET') {
        return jsonResponse({
          data: {
            entry: {
              id: createdId,
              title: 'Liquidity Escalation Matrix',
              plainTextContent: 'Collect liquidity runway and covenant stress indicators.',
              richTextContent: '<p>Collect liquidity runway and covenant stress indicators.</p>',
              difficulty: 3,
              tags: ['liquidity', 'intake'],
              formulas: [{ expression: 'risk = liabilities / assets', label: '' }],
              embeddedImages: [],
              status: questionStatus,
              duplicateReviewState: duplicateFlagRaised ? 'REQUIRES_REVIEW' : 'NONE',
              currentVersionNumber: 1,
              updatedAtUtc: new Date().toISOString(),
              versions: [
                {
                  id: 11,
                  versionNumber: 1,
                  title: 'Liquidity Escalation Matrix',
                  plainTextContent: 'Collect liquidity runway and covenant stress indicators.',
                  richTextContent: '<p>Collect liquidity runway and covenant stress indicators.</p>',
                  difficulty: 3,
                  tags: ['liquidity', 'intake'],
                  formulas: [{ expression: 'risk = liabilities / assets', label: '' }],
                  embeddedImages: [],
                  changeNote: 'Initial controlled draft',
                  createdByUsername: 'content_admin',
                  createdAtUtc: new Date().toISOString(),
                },
              ],
            },
          },
        });
      }

      if (path === `/api/question-bank/questions/${createdId}/publish` && method === 'POST') {
        const body = init?.body ? JSON.parse(String(init.body)) : {};
        if (!duplicateFlagRaised && !body.overrideDuplicateReview) {
          duplicateFlagRaised = true;
          return jsonResponse(
            {
              error: {
                code: 'DUPLICATE_REVIEW_REQUIRED',
                message: 'High textual similarity detected. Duplicate review is required before publish.',
                details: [{ entryId: 55, title: 'Existing Similar Question', similarity: 0.91 }],
              },
            },
            409,
          );
        }

        questionStatus = 'PUBLISHED';
        return jsonResponse({ data: { entry: { id: createdId, status: 'PUBLISHED', duplicateReviewState: 'OVERRIDDEN', versions: [] }, similarityMatches: [] } });
      }

      if (path === '/api/question-bank/export?format=csv') {
        return new Response('id,title\n1,Sample', {
          status: 200,
          headers: { 'Content-Type': 'text/csv; charset=utf-8' },
        });
      }

      if (path === '/api/practitioner/profile' || path === '/api/practitioner/credentials' || path.startsWith('/api/reviewer/credentials')) {
        return jsonResponse({ error: { code: 'ACCESS_DENIED', message: 'Insufficient permissions.' } }, 403);
      }

      return jsonResponse({ error: { code: 'NOT_FOUND', message: `No mock for ${method} ${rawUrl}` } }, 404);
    });

    render(<App />);

    await user.clear(screen.getByLabelText('Username'));
    await user.type(screen.getByLabelText('Username'), 'content_admin');
    await user.clear(screen.getByLabelText('Password'));
    await user.type(screen.getByLabelText('Password'), 'StrongPassword123!');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await screen.findByRole('heading', { name: 'Question-bank catalog' });
    await user.type(screen.getByLabelText('Question title'), 'Liquidity Escalation Matrix');
    await user.type(screen.getByLabelText(/Plain text content/i), 'Collect liquidity runway and covenant stress indicators.');
    await user.type(screen.getByLabelText(/Rich text content/i), '<p>Collect liquidity runway and covenant stress indicators.</p>');
    await user.type(screen.getByLabelText(/Tags \(comma-separated\)/i), 'liquidity, intake');
    await user.type(screen.getByLabelText(/Formula expressions/i), 'risk = liabilities / assets');

    await user.click(screen.getByRole('button', { name: 'Create draft question' }));
    await screen.findByText(/Question draft created/i);

    await user.click(screen.getByRole('button', { name: 'Publish' }));
    await screen.findByText(/Duplicate review required before publish/i);

    await user.type(screen.getByLabelText(/Duplicate-review override comment/i), 'Reviewed and approved despite similarity.');
    await user.click(screen.getByRole('button', { name: 'Publish with duplicate-review override' }));
    await screen.findByText(/duplicate-review override audit trail/i);
  });

  it('supports analyst analytics query, compliance KPI view, export, and feature definition updates', async () => {
    const user = userEvent.setup();

    let featureCounter = 900;
    let features = [
      {
        id: 10,
        name: 'High-Risk Breach Cluster',
        description: 'Flags elevated breach and escalation overlap.',
        tags: ['high-risk', 'breach'],
        formulaExpression: '(breachCount / intakeCount) * 100 >= 6',
        updatedAtUtc: new Date().toISOString(),
      },
    ];

    const datasets = [
      {
        id: 30,
        name: 'Cross-Region Escalation Sample',
        description: 'Sample dataset for analyst what-if analysis.',
        rowCount: 3,
        createdAtUtc: new Date().toISOString(),
      },
    ];

    const queryPayload = {
      filters: {
        fromDate: '2026-01-01',
        toDate: '2026-12-31',
        orgUnits: ['North Region'],
        datasetIds: [30],
        featureIds: [10],
        includeLiveData: true,
      },
      rows: [
        {
          occurredAtUtc: new Date().toISOString(),
          orgUnit: 'North Region',
          source: 'LIVE',
          datasetName: 'Live operations feed',
          intakeCount: 120,
          breachCount: 7,
          escalationCount: 5,
          avgReviewHours: 21.5,
          resolutionWithinSlaPct: 93.4,
          evidenceCompletenessPct: 94.9,
          breachRatePct: 5.833,
          escalationRatePct: 4.166,
          complianceScorePct: 91.4,
          matchedFeatures: [{ id: 10, name: 'High-Risk Breach Cluster' }],
        },
      ],
      summary: {
        rowCount: 1,
        totalIntakeCount: 120,
        totalBreachCount: 7,
        avgBreachRatePct: 5.833,
        avgComplianceScorePct: 91.4,
      },
      dashboard: {
        trend: [{ month: '2026-04', avgBreachRatePct: 5.833, avgComplianceScorePct: 91.4 }],
        distribution: [{ orgUnit: 'North Region', recordCount: 1, intakeCount: 120, avgBreachRatePct: 5.833, avgReviewHours: 21.5 }],
        correlation: {
          reviewHoursVsBreachRate: 0.66,
          evidenceCompletenessVsBreachRate: -0.52,
        },
      },
      complianceDashboard: {
        kpis: [
          {
            id: 'rescue_volume',
            label: 'Rescue volume',
            promptAlias: 'kpi_rescue_volume',
            promptLabel: 'Rescue volume',
            implementationLabel: 'Regulatory Intervention Volume',
            value: 5,
            target: 6,
            unit: 'COUNT',
            status: 'ON_TRACK',
            comparisonDirection: 'LOWER_IS_BETTER',
          },
          {
            id: 'recovery_rate',
            label: 'Recovery rate',
            promptAlias: 'kpi_recovery_rate',
            promptLabel: 'Recovery rate',
            implementationLabel: 'Remediation Closure Rate',
            value: 93.4,
            target: 92,
            unit: 'PERCENT',
            status: 'ON_TRACK',
            comparisonDirection: 'HIGHER_IS_BETTER',
          },
          {
            id: 'adoption_conversion',
            label: 'Adoption conversion',
            promptAlias: 'kpi_adoption_conversion',
            promptLabel: 'Adoption conversion',
            implementationLabel: 'Workflow Adoption Conversion',
            value: 94.2,
            target: 94,
            unit: 'PERCENT',
            status: 'ON_TRACK',
            comparisonDirection: 'HIGHER_IS_BETTER',
          },
          {
            id: 'average_shelter_stay',
            label: 'Average shelter stay',
            promptAlias: 'kpi_average_shelter_stay',
            promptLabel: 'Average shelter stay',
            implementationLabel: 'Average Case Resolution Duration',
            value: 21.5,
            target: 24,
            unit: 'HOURS',
            status: 'ON_TRACK',
            comparisonDirection: 'LOWER_IS_BETTER',
          },
          {
            id: 'donation_mix',
            label: 'Donation mix',
            promptAlias: 'kpi_donation_mix',
            promptLabel: 'Donation mix',
            implementationLabel: 'Revenue/Compliance Fee Mix',
            value: 82.3,
            target: 78,
            unit: 'PERCENT',
            status: 'ON_TRACK',
            comparisonDirection: 'HIGHER_IS_BETTER',
          },
          {
            id: 'supply_turnover',
            label: 'Supply turnover',
            promptAlias: 'kpi_supply_turnover',
            promptLabel: 'Supply turnover',
            implementationLabel: 'Operational Capacity Turnover',
            value: 5.6,
            target: 4.5,
            unit: 'RATIO',
            status: 'ON_TRACK',
            comparisonDirection: 'HIGHER_IS_BETTER',
          },
        ],
        trend: [{ month: '2026-04', avgBreachRatePct: 5.833, avgComplianceScorePct: 91.4 }],
      },
    };

    const fetchMock = globalThis.fetch as unknown as Mock;
    fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const rawUrl = typeof input === 'string' ? input : input.toString();
      const [path] = rawUrl.split('?');
      const method = (init?.method ?? 'GET').toUpperCase();

      if (path === '/api/health/live') {
        return jsonResponse({ data: { status: 'live' } });
      }
      if (path === '/api/health/ready') {
        return jsonResponse({ data: { status: 'ready', database: 'ok', keyring: { activeKeyId: 'dev-key' } } });
      }
      if (path === '/api/auth/csrf-token') {
        return jsonResponse({ data: { csrfToken: 'csrf-token', headerName: 'X-CSRF-Token' } });
      }
      if (path === '/api/auth/me') {
        return jsonResponse({ error: { code: 'UNAUTHENTICATED', message: 'Authentication required.' } }, 401);
      }

      if (path === '/api/auth/login' && method === 'POST') {
        return jsonResponse({
          data: {
            user: { username: 'analyst_user', roles: ['ROLE_ANALYST'] },
            permissions: ['analytics.query', 'analytics.export', 'analytics.feature.manage', 'nav.analytics', 'nav.dashboard'],
            navigation: ['analytics', 'dashboard'],
          },
        });
      }

      if (path === '/api/permissions/me') {
        return jsonResponse({
          data: {
            username: 'analyst_user',
            roles: ['ROLE_ANALYST'],
            permissions: ['analytics.query', 'analytics.export', 'analytics.feature.manage', 'nav.analytics', 'nav.dashboard'],
            navigation: ['analytics', 'dashboard'],
          },
        });
      }

      if (path === '/api/analytics/workbench/options') {
        return jsonResponse({
          data: {
            orgUnits: ['North Region', 'South Region'],
            features,
            sampleDatasets: datasets,
          },
        });
      }

      if (path === '/api/analytics/query' && method === 'POST') {
        return jsonResponse({ data: queryPayload });
      }

      if (path === '/api/analytics/query/export' && method === 'POST') {
        return new Response('occurredAtUtc,orgUnit\n2026-04-01T00:00:00+00:00,North Region\n', {
          status: 200,
          headers: { 'Content-Type': 'text/csv; charset=utf-8' },
        });
      }

      if (path === '/api/analytics/audit-report/export' && method === 'POST') {
        return new Response('Section,Field,Value\nCompliance KPI,Rescue volume,5 / target 6\n', {
          status: 200,
          headers: { 'Content-Type': 'text/csv; charset=utf-8' },
        });
      }

      if (path === '/api/analytics/features' && method === 'GET') {
        return jsonResponse({ data: { features } });
      }

      if (path === '/api/analytics/features' && method === 'POST') {
        const body = JSON.parse((init?.body as string) ?? '{}') as {
          name: string;
          description: string;
          tags: string[];
          formulaExpression: string;
        };
        const created = {
          id: featureCounter++,
          name: body.name,
          description: body.description,
          tags: body.tags,
          formulaExpression: body.formulaExpression,
          updatedAtUtc: new Date().toISOString(),
        };
        features = [created, ...features];
        return jsonResponse({ data: { feature: created } }, 201);
      }

      if (path.startsWith('/api/analytics/features/') && method === 'PUT') {
        const body = JSON.parse((init?.body as string) ?? '{}') as {
          name: string;
          description: string;
          tags: string[];
          formulaExpression: string;
        };
        const id = Number(path.split('/').pop());
        features = features.map((item) =>
          item.id === id
            ? {
                ...item,
                name: body.name,
                description: body.description,
                tags: body.tags,
                formulaExpression: body.formulaExpression,
                updatedAtUtc: new Date().toISOString(),
              }
            : item,
        );
        const updated = features.find((item) => item.id === id);
        return jsonResponse({ data: { feature: updated } });
      }

      return jsonResponse({ error: { code: 'NOT_FOUND', message: `No mock for ${method} ${rawUrl}` } }, 404);
    });

    render(<App />);

    await user.clear(screen.getByLabelText('Username'));
    await user.type(screen.getByLabelText('Username'), 'analyst_user');
    await user.clear(screen.getByLabelText('Password'));
    await user.type(screen.getByLabelText('Password'), 'StrongPassword123!');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await screen.findByRole('heading', { name: 'Analytics query workbench' });
    await waitFor(() => {
      expect(screen.getAllByText(/Rescue volume/i).length).toBeGreaterThan(0);
    });
    await screen.findByText(/impl:\s*Regulatory Intervention Volume/i);

    await user.click(screen.getByRole('button', { name: 'Run analytics query' }));
    await screen.findByText(/Query complete: 1 rows returned./i);

    await user.click(screen.getByRole('button', { name: 'Export query CSV' }));
    await screen.findByText(/Analytics query CSV export generated./i);

    await user.click(screen.getByRole('button', { name: 'Export audit report' }));
    await screen.findByText(/Analytics audit report exported./i);

    await user.type(screen.getByLabelText('Feature name'), 'Evidence Escalation Lens');
    await user.type(screen.getByLabelText('Description'), 'Tracks escalation pressure with evidence quality drift.');
    await user.type(screen.getByLabelText(/Tags \(comma-separated\)/i), 'evidence, escalation');
    await user.type(screen.getByLabelText('Formula expression'), '(escalationCount / intakeCount) * 100 > 4');
    await user.click(screen.getByRole('button', { name: 'Create feature definition' }));

    await screen.findByText(/Analytics feature definition created./i);
    expect(screen.getAllByText('Evidence Escalation Lens').length).toBeGreaterThan(0);
  });

  it('supports governance admin evidence inspection, anomaly handling, rollback controls, and password reset step-up flow', async () => {
    const user = userEvent.setup();

    type GovernanceAlert = {
      id: number;
      alertType: string;
      scopeKey: string;
      status: string;
      payload: {
        firmName: string;
        rejectedCount: number;
        thresholdRejectedCount: number;
        windowHours: number;
      };
      createdAtUtc: string;
      updatedAtUtc: string;
      lastDetectedAtUtc: string;
      acknowledgedAtUtc: string | null;
      acknowledgedByUsername: string | null;
      acknowledgementNote: string | null;
      resolvedAtUtc: string | null;
    };

    let anomalyAlerts: GovernanceAlert[] = [
      {
        id: 501,
        alertType: 'CREDENTIAL_REJECTION_SPIKE',
        scopeKey: 'firm:Alpha Counsel',
        status: 'OPEN',
        payload: {
          firmName: 'Alpha Counsel',
          rejectedCount: 6,
          thresholdRejectedCount: 5,
          windowHours: 24,
        },
        createdAtUtc: new Date().toISOString(),
        updatedAtUtc: new Date().toISOString(),
        lastDetectedAtUtc: new Date().toISOString(),
        acknowledgedAtUtc: null,
        acknowledgedByUsername: null,
        acknowledgementNote: null,
        resolvedAtUtc: null,
      },
    ];

    const auditLogs = [
      {
        id: 701,
        actorUsername: 'system_admin',
        actionType: 'admin.anomaly.refresh',
        payload: { alertCount: 1 },
        createdAtUtc: new Date().toISOString(),
      },
    ];

    const sensitiveLogs = [
      {
        id: 801,
        actorUsername: 'system_admin',
        entityType: 'practitioner_profile',
        entityId: '12',
        fieldName: 'license_number',
        reason: 'Incident analysis',
        createdAtUtc: new Date().toISOString(),
      },
    ];

    const rollbackSubmissions = [
      {
        id: 51,
        label: 'Credential Evidence A',
        status: 'APPROVED',
        currentVersionNumber: 2,
        updatedAtUtc: new Date().toISOString(),
        practitionerProfile: {
          id: 19,
          username: 'standard_user',
          lawyerFullName: 'Ariya Chen',
          firmName: 'Alpha Counsel',
          barJurisdiction: 'CA',
          licenseNumberMasked: '••••1199',
        },
        versions: [
          {
            id: 901,
            versionNumber: 1,
            reviewStatus: 'APPROVED',
            reviewComment: 'Approved baseline',
            reviewedAtUtc: new Date().toISOString(),
            uploadedAtUtc: new Date().toISOString(),
            originalFilename: 'credential-v1.pdf',
          },
          {
            id: 902,
            versionNumber: 2,
            reviewStatus: 'APPROVED',
            reviewComment: 'Approved update',
            reviewedAtUtc: new Date().toISOString(),
            uploadedAtUtc: new Date().toISOString(),
            originalFilename: 'credential-v2.pdf',
          },
        ],
      },
    ];

    const rollbackQuestions = [
      {
        id: 61,
        title: 'Counterparty review question',
        status: 'PUBLISHED',
        currentVersionNumber: 2,
        updatedAtUtc: new Date().toISOString(),
        versions: [
          {
            id: 1001,
            versionNumber: 1,
            title: 'Counterparty review question v1',
            difficulty: 2,
            tags: ['counterparty', 'risk'],
            createdByUsername: 'content_admin',
            createdAtUtc: new Date().toISOString(),
            changeNote: 'Initial draft',
          },
          {
            id: 1002,
            versionNumber: 2,
            title: 'Counterparty review question v2',
            difficulty: 3,
            tags: ['counterparty', 'risk', 'enhanced'],
            createdByUsername: 'content_admin',
            createdAtUtc: new Date().toISOString(),
            changeNote: 'Expanded criteria',
          },
        ],
      },
    ];

    const fetchMock = globalThis.fetch as unknown as Mock;
    fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      const rawUrl = typeof input === 'string' ? input : input.toString();
      const [path] = rawUrl.split('?');
      const method = (init?.method ?? 'GET').toUpperCase();

      if (path === '/api/health/live') {
        return jsonResponse({ data: { status: 'live' } });
      }
      if (path === '/api/health/ready') {
        return jsonResponse({ data: { status: 'ready', database: 'ok', keyring: { activeKeyId: 'dev-key' } } });
      }
      if (path === '/api/auth/csrf-token') {
        return jsonResponse({ data: { csrfToken: 'csrf-token', headerName: 'X-CSRF-Token' } });
      }
      if (path === '/api/auth/me') {
        return jsonResponse({ error: { code: 'UNAUTHENTICATED', message: 'Authentication required.' } }, 401);
      }

      if (path === '/api/auth/login' && method === 'POST') {
        return jsonResponse({
          data: {
            user: { username: 'system_admin', roles: ['ROLE_SYSTEM_ADMIN'] },
            permissions: ['admin.audit.read', 'admin.anomaly.manage', 'admin.rollback', 'admin.passwordReset', 'nav.admin', 'nav.dashboard'],
            navigation: ['admin', 'dashboard'],
          },
        });
      }

      if (path === '/api/permissions/me') {
        return jsonResponse({
          data: {
            username: 'system_admin',
            roles: ['ROLE_SYSTEM_ADMIN'],
            permissions: ['admin.audit.read', 'admin.anomaly.manage', 'admin.rollback', 'admin.passwordReset', 'nav.admin', 'nav.dashboard'],
            navigation: ['admin', 'dashboard'],
          },
        });
      }

      if (path === '/api/admin/governance/audit-logs' && method === 'GET') {
        return jsonResponse({ data: { immutable: true, logs: auditLogs } });
      }

      if (path === '/api/admin/governance/sensitive-access-logs' && method === 'GET') {
        return jsonResponse({ data: { logs: sensitiveLogs } });
      }

      if (path === '/api/admin/governance/anomalies' && method === 'GET') {
        return jsonResponse({ data: { statusFilter: 'ALL', alerts: anomalyAlerts } });
      }

      if (path === '/api/admin/governance/rollback/credential-submissions' && method === 'GET') {
        return jsonResponse({ data: { submissions: rollbackSubmissions } });
      }

      if (path === '/api/admin/governance/rollback/question-entries' && method === 'GET') {
        return jsonResponse({ data: { entries: rollbackQuestions } });
      }

      if (path === '/api/admin/governance/sensitive/practitioner-profiles/12/license' && method === 'POST') {
        return jsonResponse({
          data: {
            profileId: 12,
            lawyerFullName: 'Ariya Chen',
            licenseNumberMasked: '••••1199',
            licenseNumber: 'CA-111199',
          },
        });
      }

      if (path === '/api/admin/governance/anomalies/refresh' && method === 'POST') {
        return jsonResponse({ data: { alerts: anomalyAlerts } });
      }

      if (path === '/api/admin/governance/anomalies/501/acknowledge' && method === 'POST') {
        anomalyAlerts = anomalyAlerts.map((item) =>
          item.id === 501
            ? {
                ...item,
                status: 'ACKNOWLEDGED',
                acknowledgedByUsername: 'system_admin',
                acknowledgementNote: 'Triaged with compliance operations.',
                acknowledgedAtUtc: new Date().toISOString(),
                updatedAtUtc: new Date().toISOString(),
              }
            : item,
        );

        return jsonResponse({ data: { alert: anomalyAlerts[0] } });
      }

      if (path === '/api/admin/governance/rollback/credentials' && method === 'POST') {
        return jsonResponse({ data: { rolledBackFromVersion: 1, newVersionNumber: 3 } });
      }

      if (path === '/api/admin/governance/rollback/questions' && method === 'POST') {
        return jsonResponse({ data: { rolledBackFromVersion: 1, newVersionNumber: 3 } });
      }

      if (path === '/api/admin/governance/users/password-reset' && method === 'POST') {
        return jsonResponse({ data: { status: 'PASSWORD_RESET', targetUsername: 'standard_user' } });
      }

      return jsonResponse({ error: { code: 'NOT_FOUND', message: `No mock for ${method} ${rawUrl}` } }, 404);
    });

    render(<App />);

    await user.clear(screen.getByLabelText('Username'));
    await user.type(screen.getByLabelText('Username'), 'system_admin');
    await user.clear(screen.getByLabelText('Password'));
    await user.type(screen.getByLabelText('Password'), 'StrongPassword123!');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await screen.findByRole('heading', { name: 'Immutable evidence views' });
    await screen.findByText(/admin.anomaly.refresh/i);
    await screen.findByText(/practitioner_profile:12/i);

    await user.click(screen.getByRole('button', { name: 'Refresh anomalies' }));
    await screen.findByText(/Anomaly scan complete./i);

    await user.type(screen.getByLabelText('Acknowledgement note'), 'Triaged with compliance operations.');
    await user.click(screen.getByRole('button', { name: 'Acknowledge alert' }));
    await screen.findByText(/Anomaly alert #501 acknowledged./i);

    await user.type(screen.getByLabelText('Practitioner profile ID'), '12');
    await user.type(screen.getByLabelText('Reason for access'), 'Investigating suspicious verification discrepancy for case review.');
    await user.click(screen.getByRole('button', { name: 'Read sensitive field' }));
    await screen.findByText(/plain CA-111199/i);

    await user.click(screen.getByRole('button', { name: 'Execute credential rollback' }));
    await screen.findByText(/Step-up password and rollback justification are required./i);
    await user.type(screen.getAllByLabelText('Step-up password')[0], 'StrongPassword123!');
    await user.type(screen.getAllByLabelText('Rollback justification')[0], 'Restore prior verified artifact after incorrect overwrite.');
    await user.click(screen.getByRole('button', { name: 'Execute credential rollback' }));
    await screen.findByText(/Credential rollback complete/i);

    await user.type(screen.getAllByLabelText('Step-up password')[1], 'StrongPassword123!');
    await user.type(screen.getAllByLabelText('Rollback justification')[1], 'Restore prior verified wording for regulatory consistency.');
    await user.click(screen.getByRole('button', { name: 'Execute question rollback' }));
    await screen.findByText(/Question content rollback complete/i);

    await user.type(screen.getByLabelText('Target username'), 'standard_user');
    await user.type(screen.getByLabelText('New password'), 'ResetPassword123!');
    await user.type(screen.getAllByLabelText('Step-up password')[2], 'StrongPassword123!');
    await user.type(screen.getByLabelText('Reset justification'), 'Operator ticket confirmed account recovery request.');
    await user.click(screen.getByRole('button', { name: 'Execute password reset' }));
    await screen.findByText(/Password reset completed for standard_user./i);
  });
});
