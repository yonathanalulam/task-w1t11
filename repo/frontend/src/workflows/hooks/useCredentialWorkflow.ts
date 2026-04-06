import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { apiGet, apiPost, apiPostForm, apiPut, type ApiError } from '../../api/client';
import type {
  PractitionerCredentialsEnvelope,
  PractitionerProfile,
  PractitionerProfileEnvelope,
  ReviewerDetailEnvelope,
  ReviewerQueueEnvelope,
  ReviewerSubmission,
} from '../types';

type UseCredentialWorkflowParams = {
  csrfToken: string;
  sessionActive: boolean;
  canManagePractitionerProfile: boolean;
  canUploadCredentials: boolean;
  canReviewCredentials: boolean;
};

export function useCredentialWorkflow({
  csrfToken,
  sessionActive,
  canManagePractitionerProfile,
  canUploadCredentials,
  canReviewCredentials,
}: UseCredentialWorkflowParams) {
  const [profile, setProfile] = useState<PractitionerProfile | null>(null);
  const [lawyerFullName, setLawyerFullName] = useState('');
  const [firmName, setFirmName] = useState('');
  const [barJurisdiction, setBarJurisdiction] = useState('');
  const [licenseNumber, setLicenseNumber] = useState('');
  const [profileError, setProfileError] = useState('');
  const [profileStatus, setProfileStatus] = useState('');

  const [credentialLabel, setCredentialLabel] = useState('');
  const [credentialFile, setCredentialFile] = useState<File | null>(null);
  const [credentialError, setCredentialError] = useState('');
  const [credentialStatus, setCredentialStatus] = useState('');
  const [profileRequiredForCredential, setProfileRequiredForCredential] = useState(false);
  const [mySubmissions, setMySubmissions] = useState<PractitionerCredentialsEnvelope['submissions']>([]);
  const [resubmitFiles, setResubmitFiles] = useState<Record<number, File | null>>({});
  const [resubmitLabels, setResubmitLabels] = useState<Record<number, string>>({});

  const [reviewStatusFilter, setReviewStatusFilter] = useState('PENDING_REVIEW');
  const [reviewQueue, setReviewQueue] = useState<ReviewerSubmission[]>([]);
  const [selectedReviewSubmission, setSelectedReviewSubmission] = useState<ReviewerSubmission | null>(null);
  const [reviewAction, setReviewAction] = useState<'approve' | 'reject' | 'request_resubmission'>('approve');
  const [reviewComment, setReviewComment] = useState('');
  const [reviewError, setReviewError] = useState('');
  const [reviewStatusMessage, setReviewStatusMessage] = useState('');

  useEffect(() => {
    if (sessionActive) {
      return;
    }

    setProfile(null);
    setLawyerFullName('');
    setFirmName('');
    setBarJurisdiction('');
    setLicenseNumber('');
    setProfileError('');
    setProfileStatus('');

    setCredentialLabel('');
    setCredentialFile(null);
    setCredentialError('');
    setCredentialStatus('');
    setProfileRequiredForCredential(false);
    setMySubmissions([]);
    setResubmitFiles({});
    setResubmitLabels({});

    setReviewStatusFilter('PENDING_REVIEW');
    setReviewQueue([]);
    setSelectedReviewSubmission(null);
    setReviewAction('approve');
    setReviewComment('');
    setReviewError('');
    setReviewStatusMessage('');
  }, [sessionActive]);

  useEffect(() => {
    if (sessionActive && (canManagePractitionerProfile || canUploadCredentials)) {
      void loadPractitionerWorkflow();
    }
  }, [sessionActive, canManagePractitionerProfile, canUploadCredentials]);

  useEffect(() => {
    if (sessionActive && canReviewCredentials) {
      void loadReviewQueue(reviewStatusFilter);
    }
  }, [sessionActive, canReviewCredentials, reviewStatusFilter]);

  async function loadPractitionerWorkflow() {
    try {
      const [profilePayload, credentialsPayload] = await Promise.all([
        apiGet<PractitionerProfileEnvelope>('/api/practitioner/profile'),
        apiGet<PractitionerCredentialsEnvelope>('/api/practitioner/credentials'),
      ]);

      setProfile(profilePayload.profile);
      setProfileRequiredForCredential(credentialsPayload.profileRequired);
      setMySubmissions(credentialsPayload.submissions);

      if (profilePayload.profile) {
        setLawyerFullName(profilePayload.profile.lawyerFullName);
        setFirmName(profilePayload.profile.firmName);
        setBarJurisdiction(profilePayload.profile.barJurisdiction);
      }
    } catch (error) {
      const apiError = error as ApiError;
      setProfileError(apiError?.error?.message ?? 'Unable to load practitioner workflow.');
    }
  }

  async function loadReviewQueue(status: string) {
    try {
      const payload = await apiGet<ReviewerQueueEnvelope>(`/api/reviewer/credentials/queue?status=${encodeURIComponent(status)}`);
      setReviewQueue(payload.queue);

      if (selectedReviewSubmission) {
        const stillPresent = payload.queue.find((item) => item.id === selectedReviewSubmission.id);
        if (!stillPresent) {
          setSelectedReviewSubmission(null);
        }
      }
    } catch (error) {
      const apiError = error as ApiError;
      setReviewError(apiError?.error?.message ?? 'Unable to load credential review queue.');
      setReviewQueue([]);
    }
  }

  async function loadReviewDetail(submissionId: number) {
    try {
      const payload = await apiGet<ReviewerDetailEnvelope>(`/api/reviewer/credentials/${submissionId}`);
      setSelectedReviewSubmission(payload.submission);
    } catch (error) {
      const apiError = error as ApiError;
      setReviewError(apiError?.error?.message ?? 'Unable to load credential detail.');
    }
  }

  async function handleSaveProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setProfileError('');
    setProfileStatus('');

    if (lawyerFullName.trim().length < 3 || firmName.trim().length < 2 || barJurisdiction.trim().length < 2) {
      setProfileError('Name, firm, and jurisdiction are required and must be meaningful.');
      return;
    }

    if (!profile && licenseNumber.trim().length < 4) {
      setProfileError('License number is required when creating a profile.');
      return;
    }

    if (licenseNumber.trim() !== '' && licenseNumber.trim().length < 4) {
      setProfileError('License number must be at least 4 characters when provided.');
      return;
    }

    try {
      const payload = await apiPut<PractitionerProfileEnvelope>(
        '/api/practitioner/profile',
        {
          lawyerFullName: lawyerFullName.trim(),
          firmName: firmName.trim(),
          barJurisdiction: barJurisdiction.trim(),
          ...(licenseNumber.trim() !== '' ? { licenseNumber: licenseNumber.trim() } : {}),
        },
        csrfToken,
      );

      setProfile(payload.profile);
      setLicenseNumber('');
      setProfileStatus('Practitioner profile saved. License remains encrypted at rest and masked by default.');
      setProfileRequiredForCredential(false);
    } catch (error) {
      const apiError = error as ApiError;
      setProfileError(apiError?.error?.message ?? 'Unable to save practitioner profile.');
    }
  }

  async function handleCredentialUpload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setCredentialError('');
    setCredentialStatus('');

    if (credentialLabel.trim() === '') {
      setCredentialError('Credential label is required.');
      return;
    }

    if (!credentialFile) {
      setCredentialError('Credential file is required.');
      return;
    }

    if (credentialFile.size > 10_485_760) {
      setCredentialError('Credential file must be 10 MB or smaller.');
      return;
    }

    try {
      const form = new FormData();
      form.set('label', credentialLabel.trim());
      form.set('file', credentialFile);
      await apiPostForm('/api/practitioner/credentials', form, csrfToken);

      setCredentialLabel('');
      setCredentialFile(null);
      setCredentialStatus('Credential uploaded and submitted to review queue.');
      await loadPractitionerWorkflow();
      await loadReviewQueue(reviewStatusFilter);
    } catch (error) {
      const apiError = error as ApiError;
      setCredentialError(apiError?.error?.message ?? 'Credential upload failed.');
    }
  }

  async function handleResubmission(submissionId: number) {
    setCredentialError('');
    setCredentialStatus('');

    const file = resubmitFiles[submissionId] ?? null;
    if (!file) {
      setCredentialError('Resubmission file is required.');
      return;
    }

    if (file.size > 10_485_760) {
      setCredentialError('Resubmission file must be 10 MB or smaller.');
      return;
    }

    try {
      const form = new FormData();
      form.set('file', file);

      const label = (resubmitLabels[submissionId] ?? '').trim();
      if (label !== '') {
        form.set('label', label);
      }

      await apiPostForm(`/api/practitioner/credentials/${submissionId}/resubmit`, form, csrfToken);

      setCredentialStatus('Credential resubmitted for reviewer verification.');
      setResubmitFiles((prev) => ({ ...prev, [submissionId]: null }));
      setResubmitLabels((prev) => ({ ...prev, [submissionId]: '' }));
      await loadPractitionerWorkflow();
      await loadReviewQueue(reviewStatusFilter);
    } catch (error) {
      const apiError = error as ApiError;
      setCredentialError(apiError?.error?.message ?? 'Credential resubmission failed.');
    }
  }

  async function handleReviewDecision(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setReviewError('');
    setReviewStatusMessage('');

    if (!selectedReviewSubmission) {
      setReviewError('Select a submission before recording a decision.');
      return;
    }

    if ((reviewAction === 'reject' || reviewAction === 'request_resubmission') && reviewComment.trim() === '') {
      setReviewError('Comment is required for reject or request-resubmission decisions.');
      return;
    }

    try {
      await apiPost(
        `/api/reviewer/credentials/${selectedReviewSubmission.id}/decision`,
        {
          action: reviewAction,
          comment: reviewComment,
        },
        csrfToken,
      );

      setReviewStatusMessage('Reviewer decision saved and audit-logged.');
      setReviewComment('');
      await loadReviewQueue(reviewStatusFilter);
      await loadReviewDetail(selectedReviewSubmission.id);
      await loadPractitionerWorkflow();
    } catch (error) {
      const apiError = error as ApiError;
      setReviewError(apiError?.error?.message ?? 'Unable to save reviewer decision.');
    }
  }

  return {
    profile,
    lawyerFullName,
    setLawyerFullName,
    firmName,
    setFirmName,
    barJurisdiction,
    setBarJurisdiction,
    licenseNumber,
    setLicenseNumber,
    profileError,
    profileStatus,
    handleSaveProfile,

    credentialLabel,
    setCredentialLabel,
    credentialFile,
    setCredentialFile,
    credentialError,
    credentialStatus,
    profileRequiredForCredential,
    mySubmissions,
    resubmitFiles,
    setResubmitFiles,
    resubmitLabels,
    setResubmitLabels,
    handleCredentialUpload,
    handleResubmission,
    loadPractitionerWorkflow,

    reviewStatusFilter,
    setReviewStatusFilter,
    reviewQueue,
    selectedReviewSubmission,
    reviewAction,
    setReviewAction,
    reviewComment,
    setReviewComment,
    reviewError,
    reviewStatusMessage,
    loadReviewQueue,
    loadReviewDetail,
    handleReviewDecision,
  };
}
