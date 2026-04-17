import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { useQuestionBankWorkflow } from './useQuestionBankWorkflow';

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

describe('useQuestionBankWorkflow — handlePublishQuestion guards', () => {
  it('refuses to publish when no question is selected', async () => {
    const { result } = renderHook(() =>
      useQuestionBankWorkflow({
        csrfToken: 'csrf-x',
        sessionActive: false,
        canUseQuestionBank: false,
      }),
    );

    await act(async () => {
      await result.current.handlePublishQuestion(false);
    });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.questionError).toBe('Select or create a question before publishing.');
  });
});

describe('useQuestionBankWorkflow — loadQuestionBank', () => {
  it('populates entries with a list filtered by status', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({
        data: {
          entries: [
            {
              id: 1,
              title: 'First',
              status: 'PUBLISHED',
              difficulty: 2,
              tags: ['compliance'],
              currentVersionNumber: 1,
              duplicateReviewState: 'NOT_REQUIRED',
              updatedAtUtc: '2026-01-01T00:00:00+00:00',
            },
          ],
          statusFilter: 'PUBLISHED',
        },
      }),
    );

    const { result } = renderHook(() =>
      useQuestionBankWorkflow({
        csrfToken: '',
        sessionActive: false,
        canUseQuestionBank: false,
      }),
    );

    await act(async () => {
      await result.current.loadQuestionBank('PUBLISHED');
    });

    const [calledUrl] = fetchMock.mock.calls[0] ?? [];
    expect(calledUrl).toBe('/api/question-bank/questions?status=PUBLISHED');
    await waitFor(() => expect(result.current.questionEntries).toHaveLength(1));
    expect(result.current.questionEntries[0]?.title).toBe('First');
  });

  it('surfaces error message and resets entries on failure', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse({ error: { code: 'ACCESS_DENIED', message: 'Insufficient permissions.' } }, 403),
    );

    const { result } = renderHook(() =>
      useQuestionBankWorkflow({
        csrfToken: '',
        sessionActive: false,
        canUseQuestionBank: false,
      }),
    );

    await act(async () => {
      await result.current.loadQuestionBank('ALL');
    });

    expect(result.current.questionError).toBe('Insufficient permissions.');
    expect(result.current.questionEntries).toEqual([]);
  });
});

describe('useQuestionBankWorkflow — resetQuestionDraftForm', () => {
  it('clears all drafting state', () => {
    const { result } = renderHook(() =>
      useQuestionBankWorkflow({
        csrfToken: '',
        sessionActive: false,
        canUseQuestionBank: false,
      }),
    );

    act(() => {
      result.current.setQuestionTitle('drafting');
      result.current.setQuestionTagsInput('a, b');
      result.current.setQuestionChangeNote('in-progress');
    });

    expect(result.current.questionTitle).toBe('drafting');

    act(() => result.current.resetQuestionDraftForm());

    expect(result.current.questionTitle).toBe('');
    expect(result.current.questionTagsInput).toBe('');
    expect(result.current.questionChangeNote).toBe('');
  });
});
