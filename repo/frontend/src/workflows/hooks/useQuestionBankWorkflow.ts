import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { apiGet, apiPost, apiPostForm, apiPut, type ApiError } from '../../api/client';
import type {
  QuestionAsset,
  QuestionAssetEnvelope,
  QuestionDetailEnvelope,
  QuestionEntryDetail,
  QuestionEntrySummary,
  QuestionImportEnvelope,
  QuestionListEnvelope,
} from '../types';

type UseQuestionBankWorkflowParams = {
  csrfToken: string;
  sessionActive: boolean;
  canUseQuestionBank: boolean;
};

export function useQuestionBankWorkflow({ csrfToken, sessionActive, canUseQuestionBank }: UseQuestionBankWorkflowParams) {
  const [questionStatusFilter, setQuestionStatusFilter] = useState<'ALL' | 'DRAFT' | 'PUBLISHED' | 'OFFLINE'>('ALL');
  const [questionEntries, setQuestionEntries] = useState<QuestionEntrySummary[]>([]);
  const [selectedQuestion, setSelectedQuestion] = useState<QuestionEntryDetail | null>(null);
  const [questionError, setQuestionError] = useState('');
  const [questionStatusMessage, setQuestionStatusMessage] = useState('');

  const [questionTitle, setQuestionTitle] = useState('');
  const [questionPlainText, setQuestionPlainText] = useState('');
  const [questionRichText, setQuestionRichText] = useState('<p></p>');
  const [questionDifficulty, setQuestionDifficulty] = useState(3);
  const [questionTagsInput, setQuestionTagsInput] = useState('');
  const [questionFormulasInput, setQuestionFormulasInput] = useState('');
  const [questionChangeNote, setQuestionChangeNote] = useState('');
  const [embeddedAssets, setEmbeddedAssets] = useState<QuestionAsset[]>([]);
  const [assetUploadFile, setAssetUploadFile] = useState<File | null>(null);
  const [importFile, setImportFile] = useState<File | null>(null);
  const [duplicateReviewComment, setDuplicateReviewComment] = useState('');

  useEffect(() => {
    if (sessionActive) {
      return;
    }

    setQuestionStatusFilter('ALL');
    setQuestionEntries([]);
    setSelectedQuestion(null);
    setQuestionError('');
    setQuestionStatusMessage('');
    setQuestionTitle('');
    setQuestionPlainText('');
    setQuestionRichText('<p></p>');
    setQuestionDifficulty(3);
    setQuestionTagsInput('');
    setQuestionFormulasInput('');
    setQuestionChangeNote('');
    setEmbeddedAssets([]);
    setAssetUploadFile(null);
    setImportFile(null);
    setDuplicateReviewComment('');
  }, [sessionActive]);

  useEffect(() => {
    if (sessionActive && canUseQuestionBank) {
      void loadQuestionBank(questionStatusFilter);
    }
  }, [sessionActive, canUseQuestionBank, questionStatusFilter]);

  async function loadQuestionBank(status: 'ALL' | 'DRAFT' | 'PUBLISHED' | 'OFFLINE') {
    setQuestionError('');

    try {
      const payload = await apiGet<QuestionListEnvelope>(`/api/question-bank/questions?status=${encodeURIComponent(status)}`);
      setQuestionEntries(payload.entries);

      if (selectedQuestion) {
        const stillPresent = payload.entries.find((entry) => entry.id === selectedQuestion.id);
        if (!stillPresent) {
          setSelectedQuestion(null);
        }
      }
    } catch (error) {
      const apiError = error as ApiError;
      setQuestionError(apiError?.error?.message ?? 'Unable to load question-bank entries.');
      setQuestionEntries([]);
    }
  }

  async function loadQuestionDetail(entryId: number) {
    setQuestionError('');
    try {
      const payload = await apiGet<QuestionDetailEnvelope>(`/api/question-bank/questions/${entryId}`);
      setSelectedQuestion(payload.entry);
      setQuestionTitle(payload.entry.title);
      setQuestionPlainText(payload.entry.plainTextContent);
      setQuestionRichText(payload.entry.richTextContent);
      setQuestionDifficulty(payload.entry.difficulty);
      setQuestionTagsInput(payload.entry.tags.join(', '));
      setQuestionFormulasInput(payload.entry.formulas.map((item) => item.expression).join('\n'));
      setQuestionChangeNote('');
      setEmbeddedAssets(
        payload.entry.embeddedImages.map((image) => ({
          id: image.assetId,
          originalFilename: image.filename,
          mimeType: image.mimeType,
          sizeBytes: image.sizeBytes,
          downloadPath: image.downloadPath,
          uploadedAtUtc: payload.entry.updatedAtUtc,
        })),
      );
    } catch (error) {
      const apiError = error as ApiError;
      setQuestionError(apiError?.error?.message ?? 'Unable to load question detail.');
    }
  }

  function resetQuestionDraftForm() {
    setSelectedQuestion(null);
    setQuestionTitle('');
    setQuestionPlainText('');
    setQuestionRichText('<p></p>');
    setQuestionDifficulty(3);
    setQuestionTagsInput('');
    setQuestionFormulasInput('');
    setQuestionChangeNote('');
    setEmbeddedAssets([]);
    setDuplicateReviewComment('');
    setQuestionStatusMessage('Draft form reset.');
  }

  async function handleQuestionAssetUpload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setQuestionError('');
    setQuestionStatusMessage('');

    if (!assetUploadFile) {
      setQuestionError('Select an image file before uploading.');
      return;
    }

    const form = new FormData();
    form.set('file', assetUploadFile);

    try {
      const payload = await apiPostForm<QuestionAssetEnvelope>('/api/question-bank/assets', form, csrfToken);
      setEmbeddedAssets((prev) => [...prev, payload.asset]);
      setAssetUploadFile(null);
      setQuestionStatusMessage('Embedded image uploaded and ready for question content linkage.');
    } catch (error) {
      const apiError = error as ApiError;
      setQuestionError(apiError?.error?.message ?? 'Unable to upload embedded image asset.');
    }
  }

  async function handleSaveQuestionDraft(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setQuestionError('');
    setQuestionStatusMessage('');

    const parsedTags = questionTagsInput
      .split(',')
      .map((item) => item.trim())
      .filter((item) => item !== '');
    const parsedFormulas = questionFormulasInput
      .split('\n')
      .map((item) => item.trim())
      .filter((item) => item !== '')
      .map((expression) => ({ expression, label: '' }));

    if (parsedTags.length === 0) {
      setQuestionError('At least one tag is required.');
      return;
    }

    const payload = {
      title: questionTitle.trim(),
      plainTextContent: questionPlainText.trim(),
      richTextContent: questionRichText.trim(),
      difficulty: questionDifficulty,
      tags: parsedTags,
      formulas: parsedFormulas,
      embeddedAssetIds: embeddedAssets.map((asset) => asset.id),
      changeNote: questionChangeNote.trim(),
    };

    try {
      let targetEntryId = selectedQuestion?.id ?? null;

      if (selectedQuestion) {
        const updateResponse = await apiPut<QuestionDetailEnvelope>(`/api/question-bank/questions/${selectedQuestion.id}`, payload, csrfToken);
        setSelectedQuestion(updateResponse.entry);
        targetEntryId = updateResponse.entry.id;
        setQuestionStatusMessage('Question draft updated. New version captured in history.');
      } else {
        const createResponse = await apiPost<QuestionDetailEnvelope>('/api/question-bank/questions', payload, csrfToken);
        setSelectedQuestion(createResponse.entry);
        targetEntryId = createResponse.entry.id;
        setQuestionStatusMessage('Question draft created.');
      }

      await loadQuestionBank(questionStatusFilter);
      if (targetEntryId) {
        await loadQuestionDetail(targetEntryId);
      }
    } catch (error) {
      const apiError = error as ApiError;
      setQuestionError(apiError?.error?.message ?? 'Unable to save question draft.');
    }
  }

  async function handlePublishQuestion(overrideDuplicateReview: boolean) {
    if (!selectedQuestion) {
      setQuestionError('Select or create a question before publishing.');
      return;
    }

    setQuestionError('');
    setQuestionStatusMessage('');

    try {
      const payload: Record<string, unknown> = {};
      if (overrideDuplicateReview) {
        payload.overrideDuplicateReview = true;
        payload.reviewComment = duplicateReviewComment.trim();
      }

      const response = await apiPost<{ entry: QuestionEntryDetail; similarityMatches: Array<Record<string, unknown>> }>(
        `/api/question-bank/questions/${selectedQuestion.id}/publish`,
        payload,
        csrfToken,
      );

      setSelectedQuestion(response.entry);
      setQuestionStatusMessage(
        overrideDuplicateReview
          ? 'Question published with duplicate-review override audit trail.'
          : 'Question published successfully.',
      );
      await loadQuestionBank(questionStatusFilter);
      await loadQuestionDetail(selectedQuestion.id);
    } catch (error) {
      const apiError = error as ApiError;
      if (apiError?.error?.code === 'DUPLICATE_REVIEW_REQUIRED') {
        const details = apiError?.error?.details ?? [];
        const summary = details
          .map((detail) => `${detail.title ?? 'entry'} (${detail.similarity ?? '?'})`)
          .join(', ');
        setQuestionError(`Duplicate review required before publish. Similar entries: ${summary}`);
        return;
      }

      setQuestionError(apiError?.error?.message ?? 'Unable to publish question.');
    }
  }

  async function handleOfflineQuestion() {
    if (!selectedQuestion) {
      setQuestionError('Select or create a question before taking it offline.');
      return;
    }

    setQuestionError('');
    setQuestionStatusMessage('');

    try {
      const response = await apiPost<QuestionDetailEnvelope>(`/api/question-bank/questions/${selectedQuestion.id}/offline`, {}, csrfToken);
      setSelectedQuestion(response.entry);
      setQuestionStatusMessage('Question moved to OFFLINE lifecycle state.');
      await loadQuestionBank(questionStatusFilter);
    } catch (error) {
      const apiError = error as ApiError;
      setQuestionError(apiError?.error?.message ?? 'Unable to transition question to offline.');
    }
  }

  async function handleImportQuestions(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setQuestionError('');
    setQuestionStatusMessage('');

    if (!importFile) {
      setQuestionError('Select a CSV or Excel (.xlsx) file to import.');
      return;
    }

    const form = new FormData();
    form.set('file', importFile);

    try {
      const payload = await apiPostForm<QuestionImportEnvelope>('/api/question-bank/import', form, csrfToken);
      setQuestionStatusMessage(
        `Bulk import complete: created ${payload.created}, published ${payload.published}, duplicate-flagged ${payload.duplicateFlagged}.`,
      );
      if (payload.errors.length > 0) {
        const firstError = payload.errors[0];
        setQuestionError(`Import completed with errors. First issue: line ${firstError.line} - ${firstError.message}`);
      }
      setImportFile(null);
      await loadQuestionBank(questionStatusFilter);
    } catch (error) {
      const apiError = error as ApiError;
      setQuestionError(apiError?.error?.message ?? 'Unable to import question-bank file.');
    }
  }

  async function handleExportQuestions(format: 'csv' | 'excel') {
    setQuestionError('');
    setQuestionStatusMessage('');

    try {
      const response = await fetch(`/api/question-bank/export?format=${format}`, {
        credentials: 'include',
      });

      if (!response.ok) {
        const payload = (await response.json()) as ApiError;
        throw payload;
      }

      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = format === 'excel' ? 'question-bank-export.xlsx' : 'question-bank-export.csv';
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);

      setQuestionStatusMessage(`Question-bank ${format.toUpperCase()} export generated.`);
    } catch (error) {
      const apiError = error as ApiError;
      setQuestionError(apiError?.error?.message ?? 'Unable to export question-bank data.');
    }
  }

  return {
    questionStatusFilter,
    setQuestionStatusFilter,
    questionEntries,
    selectedQuestion,
    questionError,
    questionStatusMessage,
    questionTitle,
    setQuestionTitle,
    questionPlainText,
    setQuestionPlainText,
    questionRichText,
    setQuestionRichText,
    questionDifficulty,
    setQuestionDifficulty,
    questionTagsInput,
    setQuestionTagsInput,
    questionFormulasInput,
    setQuestionFormulasInput,
    questionChangeNote,
    setQuestionChangeNote,
    embeddedAssets,
    assetUploadFile,
    setAssetUploadFile,
    importFile,
    setImportFile,
    duplicateReviewComment,
    setDuplicateReviewComment,
    loadQuestionBank,
    loadQuestionDetail,
    resetQuestionDraftForm,
    handleQuestionAssetUpload,
    handleSaveQuestionDraft,
    handlePublishQuestion,
    handleOfflineQuestion,
    handleImportQuestions,
    handleExportQuestions,
  };
}
