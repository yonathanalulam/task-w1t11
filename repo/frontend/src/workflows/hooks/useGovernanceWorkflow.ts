import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { apiGet, apiPost, type ApiError } from '../../api/client';
import type {
  AdminAnomalyAlert,
  AdminAuditLogEntry,
  AdminCredentialRollbackCandidate,
  AdminQuestionRollbackCandidate,
  AdminSensitiveAccessLogEntry,
} from '../types';

type UseGovernanceWorkflowParams = {
  csrfToken: string;
  sessionActive: boolean;
  canUseAdminGovernance: boolean;
  canReadAdminAudit: boolean;
  canRunAdminRollback: boolean;
};

export function useGovernanceWorkflow({
  csrfToken,
  sessionActive,
  canUseAdminGovernance,
  canReadAdminAudit,
  canRunAdminRollback,
}: UseGovernanceWorkflowParams) {
  const [adminAuditLogs, setAdminAuditLogs] = useState<AdminAuditLogEntry[]>([]);
  const [adminSensitiveLogs, setAdminSensitiveLogs] = useState<AdminSensitiveAccessLogEntry[]>([]);
  const [adminAnomalyAlerts, setAdminAnomalyAlerts] = useState<AdminAnomalyAlert[]>([]);
  const [adminCredentialCandidates, setAdminCredentialCandidates] = useState<AdminCredentialRollbackCandidate[]>([]);
  const [adminQuestionCandidates, setAdminQuestionCandidates] = useState<AdminQuestionRollbackCandidate[]>([]);
  const [adminError, setAdminError] = useState('');
  const [adminStatus, setAdminStatus] = useState('');

  const [sensitiveProfileId, setSensitiveProfileId] = useState('');
  const [sensitiveReason, setSensitiveReason] = useState('');
  const [revealedLicense, setRevealedLicense] = useState<{
    profileId: number;
    lawyerFullName: string;
    licenseNumberMasked: string;
    licenseNumber: string;
  } | null>(null);

  const [credentialRollbackSubmissionId, setCredentialRollbackSubmissionId] = useState('');
  const [credentialRollbackTargetVersion, setCredentialRollbackTargetVersion] = useState('');
  const [credentialRollbackStepUpPassword, setCredentialRollbackStepUpPassword] = useState('');
  const [credentialRollbackJustification, setCredentialRollbackJustification] = useState('');

  const [questionRollbackEntryId, setQuestionRollbackEntryId] = useState('');
  const [questionRollbackTargetVersion, setQuestionRollbackTargetVersion] = useState('');
  const [questionRollbackStepUpPassword, setQuestionRollbackStepUpPassword] = useState('');
  const [questionRollbackJustification, setQuestionRollbackJustification] = useState('');

  const [resetTargetUsername, setResetTargetUsername] = useState('');
  const [resetNewPassword, setResetNewPassword] = useState('');
  const [resetStepUpPassword, setResetStepUpPassword] = useState('');
  const [resetJustification, setResetJustification] = useState('');
  const [anomalyAckNotes, setAnomalyAckNotes] = useState<Record<number, string>>({});

  useEffect(() => {
    if (sessionActive) {
      return;
    }

    setAdminAuditLogs([]);
    setAdminSensitiveLogs([]);
    setAdminAnomalyAlerts([]);
    setAdminCredentialCandidates([]);
    setAdminQuestionCandidates([]);
    setAdminError('');
    setAdminStatus('');

    setSensitiveProfileId('');
    setSensitiveReason('');
    setRevealedLicense(null);

    setCredentialRollbackSubmissionId('');
    setCredentialRollbackTargetVersion('');
    setCredentialRollbackStepUpPassword('');
    setCredentialRollbackJustification('');

    setQuestionRollbackEntryId('');
    setQuestionRollbackTargetVersion('');
    setQuestionRollbackStepUpPassword('');
    setQuestionRollbackJustification('');

    setResetTargetUsername('');
    setResetNewPassword('');
    setResetStepUpPassword('');
    setResetJustification('');
    setAnomalyAckNotes({});
  }, [sessionActive]);

  useEffect(() => {
    if (sessionActive && canUseAdminGovernance) {
      void loadAdminGovernanceWorkbench();
    }
  }, [sessionActive, canUseAdminGovernance, canReadAdminAudit, canRunAdminRollback]);

  async function loadAdminGovernanceWorkbench() {
    setAdminError('');

    try {
      if (canReadAdminAudit) {
        const [auditPayload, sensitivePayload, anomalyPayload] = await Promise.all([
          apiGet<{ immutable: boolean; logs: AdminAuditLogEntry[] }>('/api/admin/governance/audit-logs?limit=120'),
          apiGet<{ logs: AdminSensitiveAccessLogEntry[] }>('/api/admin/governance/sensitive-access-logs?limit=120'),
          apiGet<{ statusFilter: string; alerts: AdminAnomalyAlert[] }>('/api/admin/governance/anomalies?status=ALL'),
        ]);

        setAdminAuditLogs(auditPayload.logs);
        setAdminSensitiveLogs(sensitivePayload.logs);
        setAdminAnomalyAlerts(anomalyPayload.alerts);
      } else {
        setAdminAuditLogs([]);
        setAdminSensitiveLogs([]);
        setAdminAnomalyAlerts([]);
      }

      if (canRunAdminRollback) {
        const [credentialPayload, questionPayload] = await Promise.all([
          apiGet<{ submissions: AdminCredentialRollbackCandidate[] }>('/api/admin/governance/rollback/credential-submissions'),
          apiGet<{ entries: AdminQuestionRollbackCandidate[] }>('/api/admin/governance/rollback/question-entries'),
        ]);
        setAdminCredentialCandidates(credentialPayload.submissions);
        setAdminQuestionCandidates(questionPayload.entries);

        if (credentialPayload.submissions.length > 0 && credentialRollbackSubmissionId === '') {
          setCredentialRollbackSubmissionId(String(credentialPayload.submissions[0].id));
          setCredentialRollbackTargetVersion(
            credentialPayload.submissions[0].versions[0] ? String(credentialPayload.submissions[0].versions[0].versionNumber) : '',
          );
        }
        if (questionPayload.entries.length > 0 && questionRollbackEntryId === '') {
          setQuestionRollbackEntryId(String(questionPayload.entries[0].id));
          setQuestionRollbackTargetVersion(questionPayload.entries[0].versions[0] ? String(questionPayload.entries[0].versions[0].versionNumber) : '');
        }
      } else {
        setAdminCredentialCandidates([]);
        setAdminQuestionCandidates([]);
      }
    } catch (error) {
      const apiError = error as ApiError;
      setAdminError(apiError?.error?.message ?? 'Unable to load governance admin workbench.');
    }
  }

  async function handleRevealSensitiveLicense(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setAdminError('');
    setAdminStatus('');

    const profileId = Number(sensitiveProfileId);
    if (!Number.isInteger(profileId) || profileId <= 0) {
      setAdminError('Practitioner profile ID must be a positive integer.');
      return;
    }

    if (sensitiveReason.trim().length < 8) {
      setAdminError('Reason is required for sensitive-field read (minimum 8 characters).');
      return;
    }

    try {
      const payload = await apiPost<{
        profileId: number;
        lawyerFullName: string;
        licenseNumberMasked: string;
        licenseNumber: string;
      }>(`/api/admin/governance/sensitive/practitioner-profiles/${profileId}/license`, { reason: sensitiveReason.trim() }, csrfToken);

      setRevealedLicense(payload);
      setAdminStatus('Sensitive field read captured with reason and logged in the sensitive-access ledger.');
      await loadAdminGovernanceWorkbench();
    } catch (error) {
      const apiError = error as ApiError;
      setAdminError(apiError?.error?.message ?? 'Unable to read sensitive field.');
    }
  }

  async function handleRefreshAnomalies() {
    setAdminError('');
    setAdminStatus('');

    try {
      const payload = await apiPost<{ alerts: AdminAnomalyAlert[] }>('/api/admin/governance/anomalies/refresh', {}, csrfToken);
      setAdminAnomalyAlerts(payload.alerts);
      setAdminStatus(`Anomaly scan complete. ${payload.alerts.length} alerts currently tracked.`);
    } catch (error) {
      const apiError = error as ApiError;
      setAdminError(apiError?.error?.message ?? 'Unable to refresh anomaly alerts.');
    }
  }

  async function handleAcknowledgeAnomaly(alertId: number) {
    setAdminError('');
    setAdminStatus('');

    const note = (anomalyAckNotes[alertId] ?? '').trim();
    if (note.length < 8) {
      setAdminError('Acknowledgement note must be at least 8 characters.');
      return;
    }

    try {
      await apiPost(`/api/admin/governance/anomalies/${alertId}/acknowledge`, { note }, csrfToken);
      setAdminStatus(`Anomaly alert #${alertId} acknowledged.`);
      setAnomalyAckNotes((prev) => ({ ...prev, [alertId]: '' }));
      await loadAdminGovernanceWorkbench();
    } catch (error) {
      const apiError = error as ApiError;
      setAdminError(apiError?.error?.message ?? 'Unable to acknowledge anomaly alert.');
    }
  }

  async function handleRollbackCredential(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setAdminError('');
    setAdminStatus('');

    const submissionId = Number(credentialRollbackSubmissionId);
    const targetVersionNumber = Number(credentialRollbackTargetVersion);

    if (!Number.isInteger(submissionId) || submissionId <= 0 || !Number.isInteger(targetVersionNumber) || targetVersionNumber <= 0) {
      setAdminError('Credential rollback requires valid submission ID and target version number.');
      return;
    }

    if (credentialRollbackStepUpPassword.trim() === '' || credentialRollbackJustification.trim().length < 8) {
      setAdminError('Step-up password and rollback justification are required.');
      return;
    }

    try {
      const payload = await apiPost<{
        rolledBackFromVersion: number;
        newVersionNumber: number;
      }>(
        '/api/admin/governance/rollback/credentials',
        {
          submissionId,
          targetVersionNumber,
          stepUpPassword: credentialRollbackStepUpPassword,
          justificationNote: credentialRollbackJustification.trim(),
        },
        csrfToken,
      );

      setCredentialRollbackStepUpPassword('');
      setCredentialRollbackJustification('');
      setAdminStatus(
        `Credential rollback complete: restored from version ${payload.rolledBackFromVersion}, new effective version ${payload.newVersionNumber}.`,
      );
      await loadAdminGovernanceWorkbench();
    } catch (error) {
      const apiError = error as ApiError;
      setAdminError(apiError?.error?.message ?? 'Unable to rollback credential submission.');
    }
  }

  async function handleRollbackQuestion(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setAdminError('');
    setAdminStatus('');

    const entryId = Number(questionRollbackEntryId);
    const targetVersionNumber = Number(questionRollbackTargetVersion);

    if (!Number.isInteger(entryId) || entryId <= 0 || !Number.isInteger(targetVersionNumber) || targetVersionNumber <= 0) {
      setAdminError('Question rollback requires valid entry ID and target version number.');
      return;
    }

    if (questionRollbackStepUpPassword.trim() === '' || questionRollbackJustification.trim().length < 8) {
      setAdminError('Step-up password and rollback justification are required.');
      return;
    }

    try {
      const payload = await apiPost<{
        rolledBackFromVersion: number;
        newVersionNumber: number;
      }>(
        '/api/admin/governance/rollback/questions',
        {
          entryId,
          targetVersionNumber,
          stepUpPassword: questionRollbackStepUpPassword,
          justificationNote: questionRollbackJustification.trim(),
        },
        csrfToken,
      );

      setQuestionRollbackStepUpPassword('');
      setQuestionRollbackJustification('');
      setAdminStatus(
        `Question content rollback complete: restored from version ${payload.rolledBackFromVersion}, new effective version ${payload.newVersionNumber}.`,
      );
      await loadAdminGovernanceWorkbench();
    } catch (error) {
      const apiError = error as ApiError;
      setAdminError(apiError?.error?.message ?? 'Unable to rollback question content.');
    }
  }

  async function handleAdminPasswordReset(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setAdminError('');
    setAdminStatus('');

    if (resetTargetUsername.trim() === '' || resetNewPassword.length < 12 || resetStepUpPassword.trim() === '' || resetJustification.trim().length < 8) {
      setAdminError('Target username, new password, step-up password, and justification are required.');
      return;
    }

    try {
      const payload = await apiPost<{ targetUsername: string }>(
        '/api/admin/governance/users/password-reset',
        {
          targetUsername: resetTargetUsername.trim(),
          newPassword: resetNewPassword,
          stepUpPassword: resetStepUpPassword,
          justificationNote: resetJustification.trim(),
        },
        csrfToken,
      );

      setResetStepUpPassword('');
      setResetJustification('');
      setResetNewPassword('');
      setAdminStatus(`Password reset completed for ${payload.targetUsername}.`);
      await loadAdminGovernanceWorkbench();
    } catch (error) {
      const apiError = error as ApiError;
      setAdminError(apiError?.error?.message ?? 'Unable to reset user password.');
    }
  }

  const selectedCredentialRollbackCandidate = useMemo(
    () => adminCredentialCandidates.find((item) => String(item.id) === credentialRollbackSubmissionId) ?? null,
    [adminCredentialCandidates, credentialRollbackSubmissionId],
  );

  const selectedQuestionRollbackCandidate = useMemo(
    () => adminQuestionCandidates.find((item) => String(item.id) === questionRollbackEntryId) ?? null,
    [adminQuestionCandidates, questionRollbackEntryId],
  );

  return {
    adminAuditLogs,
    adminSensitiveLogs,
    adminAnomalyAlerts,
    adminCredentialCandidates,
    adminQuestionCandidates,
    adminError,
    adminStatus,
    anomalyAckNotes,
    setAnomalyAckNotes,
    sensitiveProfileId,
    setSensitiveProfileId,
    sensitiveReason,
    setSensitiveReason,
    revealedLicense,
    credentialRollbackSubmissionId,
    setCredentialRollbackSubmissionId,
    credentialRollbackTargetVersion,
    setCredentialRollbackTargetVersion,
    credentialRollbackStepUpPassword,
    setCredentialRollbackStepUpPassword,
    credentialRollbackJustification,
    setCredentialRollbackJustification,
    questionRollbackEntryId,
    setQuestionRollbackEntryId,
    questionRollbackTargetVersion,
    setQuestionRollbackTargetVersion,
    questionRollbackStepUpPassword,
    setQuestionRollbackStepUpPassword,
    questionRollbackJustification,
    setQuestionRollbackJustification,
    resetTargetUsername,
    setResetTargetUsername,
    resetNewPassword,
    setResetNewPassword,
    resetStepUpPassword,
    setResetStepUpPassword,
    resetJustification,
    setResetJustification,
    selectedCredentialRollbackCandidate,
    selectedQuestionRollbackCandidate,
    loadAdminGovernanceWorkbench,
    handleRevealSensitiveLicense,
    handleRefreshAnomalies,
    handleAcknowledgeAnomaly,
    handleRollbackCredential,
    handleRollbackQuestion,
    handleAdminPasswordReset,
  };
}
