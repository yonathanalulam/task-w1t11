import { useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { apiGet, apiPost, type ApiError } from './api/client';
import { roleDisplayName } from './app/permissionRegistry';
import {
  AnalyticsWorkflowPanel,
  AuthSessionPanel,
  GovernanceAdminPanel,
  OperationsPanel,
  PractitionerWorkflowPanel,
  QuestionBankWorkflowPanel,
  ReviewWorkflowPanel,
  SchedulingWorkflowPanel,
} from './workflows/Panels';
import { useAnalyticsWorkflow } from './workflows/hooks/useAnalyticsWorkflow';
import { useCredentialWorkflow } from './workflows/hooks/useCredentialWorkflow';
import { useGovernanceWorkflow } from './workflows/hooks/useGovernanceWorkflow';
import { useQuestionBankWorkflow } from './workflows/hooks/useQuestionBankWorkflow';
import { useSchedulingWorkflow } from './workflows/hooks/useSchedulingWorkflow';
import type {
  CaptchaPayload,
  CsrfPayload,
  HealthPayload,
  LoginResponse,
  PermissionPayload,
  SessionPayload,
  ViewMode,
} from './workflows/types';
import { formatComplianceKpi } from './workflows/utils';

function App() {
  const [liveStatus, setLiveStatus] = useState<'loading' | 'ok' | 'error'>('loading');
  const [readyStatus, setReadyStatus] = useState<'loading' | 'ok' | 'error'>('loading');
  const [readyDetail, setReadyDetail] = useState<HealthPayload | null>(null);
  const [csrfToken, setCsrfToken] = useState('');

  const [username, setUsername] = useState('standard_user');
  const [password, setPassword] = useState('');
  const [authError, setAuthError] = useState('');
  const [authStatus, setAuthStatus] = useState('');

  const [captcha, setCaptcha] = useState<CaptchaPayload | null>(null);
  const [captchaResponse, setCaptchaResponse] = useState('');

  const [session, setSession] = useState<SessionPayload | null>(null);
  const [permissionView, setPermissionView] = useState<PermissionPayload | null>(null);
  const [activeView, setActiveView] = useState<ViewMode>('operations');

  useEffect(() => {
    void apiGet<HealthPayload>('/api/health/live')
      .then(() => setLiveStatus('ok'))
      .catch(() => setLiveStatus('error'));

    void apiGet<HealthPayload>('/api/health/ready')
      .then((payload) => {
        setReadyStatus('ok');
        setReadyDetail(payload);
      })
      .catch(() => setReadyStatus('error'));

    void refreshCsrfToken();

    void apiGet<SessionPayload>('/api/auth/me')
      .then((payload) => setSession(payload))
      .catch(() => setSession(null));
  }, []);

  useEffect(() => {
    if (!session) {
      setPermissionView(null);
      return;
    }

    void apiGet<PermissionPayload>('/api/permissions/me')
      .then((payload) => {
        setPermissionView(payload);
      })
      .catch(() => setPermissionView(null));
  }, [session]);

  const permissions = permissionView?.permissions ?? [];
  const canManagePractitionerProfile = permissions.includes('practitioner.manage.self');
  const canUploadCredentials = permissions.includes('credential.upload.self');
  const canReviewCredentials = permissions.includes('credential.review');
  const canBookAppointments = permissions.includes('appointment.book.self');
  const canAdminScheduling = permissions.includes('scheduling.admin');
  const canUseScheduling = canBookAppointments || canAdminScheduling;
  const canManageQuestions = permissions.includes('question.manage');
  const canPublishQuestions = permissions.includes('question.publish');
  const canImportExportQuestions = permissions.includes('question.importExport');
  const canUseQuestionBank = canManageQuestions || canPublishQuestions || canImportExportQuestions;
  const canQueryAnalytics = permissions.includes('analytics.query');
  const canExportAnalytics = permissions.includes('analytics.export');
  const canManageAnalyticsFeatures = permissions.includes('analytics.feature.manage');
  const canUseAnalytics = canQueryAnalytics || canExportAnalytics || canManageAnalyticsFeatures;
  const canReadAdminAudit = permissions.includes('admin.audit.read');
  const canManageAdminAnomalies = permissions.includes('admin.anomaly.manage');
  const canRunAdminRollback = permissions.includes('admin.rollback');
  const canResetPasswords = permissions.includes('admin.passwordReset');
  const canUseAdminGovernance = canReadAdminAudit || canManageAdminAnomalies || canRunAdminRollback || canResetPasswords;

  const credentialWorkflow = useCredentialWorkflow({
    csrfToken,
    sessionActive: Boolean(session),
    canManagePractitionerProfile,
    canUploadCredentials,
    canReviewCredentials,
  });

  const schedulingWorkflow = useSchedulingWorkflow({
    csrfToken,
    sessionActive: Boolean(session),
    canUseScheduling,
    canAdminScheduling,
  });

  const questionWorkflow = useQuestionBankWorkflow({
    csrfToken,
    sessionActive: Boolean(session),
    canUseQuestionBank,
  });

  const analyticsWorkflow = useAnalyticsWorkflow({
    csrfToken,
    sessionActive: Boolean(session),
    canUseAnalytics,
    canQueryAnalytics,
  });

  const governanceWorkflow = useGovernanceWorkflow({
    csrfToken,
    sessionActive: Boolean(session),
    canUseAdminGovernance,
    canReadAdminAudit,
    canRunAdminRollback,
  });

  useEffect(() => {
    if (canManagePractitionerProfile || canUploadCredentials) {
      setActiveView('practitioner');
    } else if (canUseAdminGovernance) {
      setActiveView('admin');
    } else if (canUseAnalytics) {
      setActiveView('analytics');
    } else if (canUseQuestionBank) {
      setActiveView('questionBank');
    } else if (canUseScheduling) {
      setActiveView('scheduling');
    } else if (canReviewCredentials) {
      setActiveView('review');
    } else {
      setActiveView('operations');
    }
  }, [canManagePractitionerProfile, canUploadCredentials, canUseAdminGovernance, canUseAnalytics, canUseQuestionBank, canUseScheduling, canReviewCredentials]);

  const authSubmitReady = useMemo(
    () => username.trim() !== '' && password.trim() !== '',
    [username, password],
  );

  async function refreshCsrfToken() {
    try {
      const payload = await apiGet<CsrfPayload>('/api/auth/csrf-token');
      setCsrfToken(payload.csrfToken);
    } catch {
      setCsrfToken('');
    }
  }

  async function handleLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setAuthError('');
    setAuthStatus('');

    if (!authSubmitReady) {
      setAuthError('Username and password are required.');
      return;
    }

    const payload: Record<string, unknown> = {
      username: username.trim(),
      password,
    };

    if (captcha && captchaResponse.trim() !== '') {
      payload.captchaChallengeId = captcha.challengeId;
      payload.captchaResponse = captchaResponse.trim();
    }

    try {
      const data = await apiPost<LoginResponse>('/api/auth/login', payload, csrfToken);
      setSession(data.user);
      setPermissionView({
        username: data.user.username,
        roles: data.user.roles,
        permissions: data.permissions,
        navigation: data.navigation,
      });
      await refreshCsrfToken();
      setCaptcha(null);
      setCaptchaResponse('');
      setAuthStatus('Signed in. Session controls and role menus are now active.');
    } catch (error) {
      const apiError = error as ApiError;
      const code = apiError?.error?.code;
      setAuthError(apiError?.error?.message ?? 'Login failed.');

      if (code === 'VALIDATION_ERROR') {
        const challenge = await apiGet<CaptchaPayload>('/api/auth/captcha').catch(() => null);
        if (challenge) {
          setCaptcha(challenge);
        }
      }

      if (code === 'ACCOUNT_LOCKED') {
        setAuthStatus('Account lockout active: 15-minute cooldown after repeated failures.');
      }
    }
  }

  async function handleRegister() {
    setAuthError('');
    setAuthStatus('');

    if (!authSubmitReady) {
      setAuthError('Username and password are required.');
      return;
    }

    try {
      await apiPost('/api/auth/register', { username: username.trim(), password }, csrfToken);
      setAuthStatus('Account registered. You can sign in immediately.');
    } catch (error) {
      const apiError = error as ApiError;
      setAuthError(apiError?.error?.message ?? 'Registration failed.');
    }
  }

  async function handleLogout() {
    setAuthError('');
    setAuthStatus('');
    try {
      await apiPost('/api/auth/logout', {}, csrfToken);
      setSession(null);
      setPermissionView(null);
      setCaptcha(null);
      setCaptchaResponse('');
      await refreshCsrfToken();
      setAuthStatus('Signed out.');
    } catch {
      setAuthError('Sign out failed.');
    }
  }

  return (
    <main className="app-shell">
      <section className="hero-panel">
        <div>
          <p className="eyebrow">On-Prem Regulatory Operations Console</p>
          <h1>Regulatory Operations &amp; Analytics Portal</h1>
          <p className="hero-copy">
            Practitioner identity and credential-review workflow with encrypted-at-rest license handling, masked data
            presentation, queue-based reviewer decisions, controlled question-bank lifecycle management, and scheduling
            hold/booking controls with audit traceability plus analyst-driven compliance KPI and audit-report export,
            alongside system-admin governance controls for audit inspection, anomaly response, rollback, and password-reset
            operations.
          </p>
        </div>
        <div className="health-strip">
          <div>
            <span>API Live</span>
            <strong>{liveStatus}</strong>
          </div>
          <div>
            <span>API Ready</span>
            <strong>{readyStatus}</strong>
          </div>
          <div>
            <span>DB</span>
            <strong>{readyDetail?.database ?? 'n/a'}</strong>
          </div>
          <div>
            <span>Active Key</span>
            <strong>{readyDetail?.keyring?.activeKeyId ?? 'n/a'}</strong>
          </div>
        </div>
      </section>

      <AuthSessionPanel
        model={{
          session,
          username,
          setUsername,
          password,
          setPassword,
          captcha,
          captchaResponse,
          setCaptchaResponse,
          authError,
          authStatus,
          handleLogin,
          handleRegister,
          handleLogout,
          permissionView,
          roleDisplayName,
        }}
      />

      <section className="panel">
        <div className="view-tabs">
          <button type="button" className={activeView === 'operations' ? 'tab active' : 'tab'} onClick={() => setActiveView('operations')}>
            Operations
          </button>
          <button
            type="button"
            className={activeView === 'practitioner' ? 'tab active' : 'tab'}
            onClick={() => setActiveView('practitioner')}
            disabled={!canManagePractitionerProfile && !canUploadCredentials}
          >
            Practitioner Workflow
          </button>
          <button
            type="button"
            className={activeView === 'review' ? 'tab active' : 'tab'}
            onClick={() => setActiveView('review')}
            disabled={!canReviewCredentials}
          >
            Credential Review Queue
          </button>
          <button
            type="button"
            className={activeView === 'questionBank' ? 'tab active' : 'tab'}
            onClick={() => setActiveView('questionBank')}
            disabled={!canUseQuestionBank}
          >
            Question Bank Workbench
          </button>
          <button
            type="button"
            className={activeView === 'analytics' ? 'tab active' : 'tab'}
            onClick={() => setActiveView('analytics')}
            disabled={!canUseAnalytics}
          >
            Analytics &amp; Compliance
          </button>
          <button
            type="button"
            className={activeView === 'admin' ? 'tab active' : 'tab'}
            onClick={() => setActiveView('admin')}
            disabled={!canUseAdminGovernance}
          >
            Governance Admin
          </button>
          <button
            type="button"
            className={activeView === 'scheduling' ? 'tab active' : 'tab'}
            onClick={() => setActiveView('scheduling')}
            disabled={!canUseScheduling}
          >
            Scheduling Workbench
          </button>
        </div>

        {activeView === 'operations' ? <OperationsPanel /> : null}

        {activeView === 'admin' ? (
          <GovernanceAdminPanel
            model={{
              ...governanceWorkflow,
              canManageAdminAnomalies,
              canReadAdminAudit,
              canRunAdminRollback,
              canResetPasswords,
            }}
          />
        ) : null}

        {activeView === 'practitioner' ? (
          <PractitionerWorkflowPanel
            model={{
              ...credentialWorkflow,
              canManagePractitionerProfile,
              canUploadCredentials,
            }}
          />
        ) : null}

        {activeView === 'review' ? <ReviewWorkflowPanel model={credentialWorkflow} /> : null}

        {activeView === 'questionBank' ? (
          <QuestionBankWorkflowPanel
            model={{
              ...questionWorkflow,
              canImportExportQuestions,
              canManageQuestions,
              canPublishQuestions,
            }}
          />
        ) : null}

        {activeView === 'analytics' ? (
          <AnalyticsWorkflowPanel
            model={{
              ...analyticsWorkflow,
              canQueryAnalytics,
              canExportAnalytics,
              canManageAnalyticsFeatures,
              formatComplianceKpi,
            }}
          />
        ) : null}

        {activeView === 'scheduling' ? (
          <SchedulingWorkflowPanel
            model={{
              ...schedulingWorkflow,
              canAdminScheduling,
            }}
          />
        ) : null}
      </section>
    </main>
  );
}

export default App;
