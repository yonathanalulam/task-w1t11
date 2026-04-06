type PanelProps = {
  model: any;
};

export function AuthSessionPanel({ model }: PanelProps) {
  return (
    <section className="panel auth-panel">
      <div className="panel-title-row">
        <h2>Session &amp; Authentication</h2>
        {model.session ? <span className="badge badge-ok">Signed in as {model.session.username}</span> : <span className="badge">Anonymous</span>}
      </div>
      <div className="grid-two">
        <form className="form-stack" onSubmit={model.handleLogin}>
          <label>
            Username
            <input value={model.username} onChange={(event) => model.setUsername(event.target.value)} />
          </label>
          <label>
            Password
            <input type="password" value={model.password} onChange={(event) => model.setPassword(event.target.value)} minLength={12} />
          </label>
          {model.captcha ? (
            <label>
              CAPTCHA · {model.captcha.prompt}
              <input value={model.captchaResponse} onChange={(event) => model.setCaptchaResponse(event.target.value)} />
            </label>
          ) : null}
          {model.authError ? <p className="inline-error">{model.authError}</p> : null}
          {model.authStatus ? <p className="inline-status">{model.authStatus}</p> : null}
          <div className="button-row">
            <button type="submit">Sign in</button>
            <button type="button" className="secondary" onClick={model.handleRegister}>
              Register
            </button>
            <button type="button" className="secondary" disabled={!model.session} onClick={model.handleLogout}>
              Sign out
            </button>
          </div>
        </form>

        <div className="meta-card">
          <h3>Active role context</h3>
          <p>{model.session ? model.session.roles.map((role: string) => model.roleDisplayName[role] ?? role).join(', ') : 'No active session.'}</p>
          <h3>Navigation contract</h3>
          <div className="chip-wrap">
            {(model.permissionView?.navigation ?? []).map((item: string) => (
              <span key={item} className="chip">
                {item}
              </span>
            ))}
            {(model.permissionView?.navigation ?? []).length === 0 ? <span className="chip muted">no route access</span> : null}
          </div>
          <h3>Permission contract</h3>
          <div className="permission-list">
            {(model.permissionView?.permissions ?? []).map((permission: string) => (
              <code key={permission}>{permission}</code>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
}

export function OperationsPanel() {
  return (
    <div className="panel-content">
      <h2>Operational baseline</h2>
      <ul className="bullet-list">
        <li>Authenticated-by-default API boundary with explicit public route allowlist.</li>
        <li>CSRF header enforcement for all mutating authenticated API routes.</li>
        <li>Encrypted-field keyring support with request-ID and normalized API envelopes.</li>
        <li>Audit persistence for upload/review decisions and reviewer actions.</li>
        <li>Analyst workbench for trend/distribution/correlation and compliance KPI monitoring.</li>
        <li>System-admin governance console with immutable evidence views and step-up controlled high-risk actions.</li>
      </ul>
    </div>
  );
}

export function GovernanceAdminPanel({ model }: PanelProps) {
  return (
    <div className="panel-content grid-two review-grid">
      <div className="workflow-column">
        <h2>Immutable evidence views</h2>
        <p className="muted">
          Read-only governance evidence. Audit and sensitive-access ledgers are append-only records for operator
          accountability and incident review.
        </p>

        <div className="meta-card">
          <div className="panel-title-row">
            <h3>Audit log ledger</h3>
            <span className="badge">Immutable</span>
          </div>
          {model.adminAuditLogs.length === 0 ? <p className="muted">No audit entries in current window.</p> : null}
          <ul>
            {model.adminAuditLogs.slice(0, 16).map((entry: any) => (
              <li key={entry.id}>
                #{entry.id} · {new Date(entry.createdAtUtc).toLocaleString()} · {entry.actionType} · actor {entry.actorUsername ?? 'anonymous'}
              </li>
            ))}
          </ul>
        </div>

        <div className="meta-card">
          <h3>Sensitive-field access ledger</h3>
          {model.adminSensitiveLogs.length === 0 ? <p className="muted">No sensitive field reads logged yet.</p> : null}
          <ul>
            {model.adminSensitiveLogs.slice(0, 16).map((entry: any) => (
              <li key={entry.id}>
                #{entry.id} · {entry.entityType}:{entry.entityId} · {entry.fieldName} · {entry.actorUsername} ·{' '}
                {new Date(entry.createdAtUtc).toLocaleString()}
              </li>
            ))}
          </ul>
        </div>

        <div className="meta-card">
          <div className="panel-title-row">
            <h3>Local anomaly alerts</h3>
            <button type="button" className="secondary" disabled={!model.canManageAdminAnomalies} onClick={() => void model.handleRefreshAnomalies()}>
              Refresh anomalies
            </button>
          </div>
          {model.adminAnomalyAlerts.length === 0 ? <p className="muted">No active anomaly alerts.</p> : null}
          <div className="submission-list">
            {model.adminAnomalyAlerts.map((alert: any) => (
              <article key={alert.id} className="submission-card">
                <div className="submission-head">
                  <strong>{alert.alertType}</strong>
                  <span className={`status-pill status-${alert.status.toLowerCase()}`}>{alert.status}</span>
                </div>
                <p className="muted">Scope: {alert.scopeKey}</p>
                <p className="muted">Last detected: {new Date(alert.lastDetectedAtUtc).toLocaleString()}</p>
                {'firmName' in alert.payload ? <p className="muted">Firm: {String(alert.payload.firmName)}</p> : null}
                {'rejectedCount' in alert.payload ? <p className="muted">Rejected in window: {String(alert.payload.rejectedCount)}</p> : null}

                {model.canManageAdminAnomalies && alert.status !== 'RESOLVED' ? (
                  <div className="form-stack">
                    <label>
                      Acknowledgement note
                      <input
                        value={model.anomalyAckNotes[alert.id] ?? ''}
                        onChange={(event) =>
                          model.setAnomalyAckNotes((prev: Record<number, string>) => ({
                            ...prev,
                            [alert.id]: event.target.value,
                          }))
                        }
                      />
                    </label>
                    <button type="button" className="secondary" onClick={() => void model.handleAcknowledgeAnomaly(alert.id)}>
                      Acknowledge alert
                    </button>
                  </div>
                ) : null}
              </article>
            ))}
          </div>
        </div>
      </div>

      <div className="workflow-column">
        <h2>High-risk admin actions</h2>
        <p className="muted">
          High-risk actions require deliberate operator input. Rollbacks and password resets require step-up
          password confirmation plus justification.
        </p>

        <div className="meta-card">
          <h3>Sensitive-field read (licensed identity)</h3>
          <form className="form-stack" onSubmit={model.handleRevealSensitiveLicense}>
            <label>
              Practitioner profile ID
              <input value={model.sensitiveProfileId} onChange={(event) => model.setSensitiveProfileId(event.target.value)} placeholder="e.g. 12" />
            </label>
            <label>
              Reason for access
              <textarea value={model.sensitiveReason} onChange={(event) => model.setSensitiveReason(event.target.value)} rows={3} />
            </label>
            <button type="submit" disabled={!model.canReadAdminAudit}>
              Read sensitive field
            </button>
          </form>
          {model.revealedLicense ? (
            <p className="muted">
              Profile #{model.revealedLicense.profileId} · {model.revealedLicense.lawyerFullName} · masked {model.revealedLicense.licenseNumberMasked}
              {' / '}plain {model.revealedLicense.licenseNumber}
            </p>
          ) : null}
        </div>

        <div className="meta-card">
          <h3>Credential document rollback</h3>
          <form className="form-stack" onSubmit={model.handleRollbackCredential}>
            <label>
              Credential submission
              <select
                value={model.credentialRollbackSubmissionId}
                onChange={(event) => {
                  const nextId = event.target.value;
                  model.setCredentialRollbackSubmissionId(nextId);
                  const nextCandidate = model.adminCredentialCandidates.find((item: any) => String(item.id) === nextId) ?? null;
                  model.setCredentialRollbackTargetVersion(nextCandidate?.versions[0] ? String(nextCandidate.versions[0].versionNumber) : '');
                }}
              >
                <option value="">Choose submission</option>
                {model.adminCredentialCandidates.map((candidate: any) => (
                  <option key={candidate.id} value={candidate.id}>
                    #{candidate.id} · {candidate.label} · {candidate.practitionerProfile.firmName}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Target version
              <select value={model.credentialRollbackTargetVersion} onChange={(event) => model.setCredentialRollbackTargetVersion(event.target.value)}>
                <option value="">Choose version</option>
                {(model.selectedCredentialRollbackCandidate?.versions ?? []).map((version: any) => (
                  <option key={version.id} value={version.versionNumber}>
                    v{version.versionNumber} · {version.originalFilename} · {version.reviewStatus}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Step-up password
              <input type="password" value={model.credentialRollbackStepUpPassword} onChange={(event) => model.setCredentialRollbackStepUpPassword(event.target.value)} />
            </label>
            <label>
              Rollback justification
              <textarea value={model.credentialRollbackJustification} onChange={(event) => model.setCredentialRollbackJustification(event.target.value)} rows={3} />
            </label>
            <button type="submit" disabled={!model.canRunAdminRollback}>
              Execute credential rollback
            </button>
          </form>
        </div>

        <div className="meta-card">
          <h3>Question content rollback</h3>
          <form className="form-stack" onSubmit={model.handleRollbackQuestion}>
            <label>
              Question entry
              <select
                value={model.questionRollbackEntryId}
                onChange={(event) => {
                  const nextId = event.target.value;
                  model.setQuestionRollbackEntryId(nextId);
                  const nextCandidate = model.adminQuestionCandidates.find((item: any) => String(item.id) === nextId) ?? null;
                  model.setQuestionRollbackTargetVersion(nextCandidate?.versions[0] ? String(nextCandidate.versions[0].versionNumber) : '');
                }}
              >
                <option value="">Choose question entry</option>
                {model.adminQuestionCandidates.map((candidate: any) => (
                  <option key={candidate.id} value={candidate.id}>
                    #{candidate.id} · v{candidate.currentVersionNumber} · {candidate.title}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Target version
              <select value={model.questionRollbackTargetVersion} onChange={(event) => model.setQuestionRollbackTargetVersion(event.target.value)}>
                <option value="">Choose version</option>
                {(model.selectedQuestionRollbackCandidate?.versions ?? []).map((version: any) => (
                  <option key={version.id} value={version.versionNumber}>
                    v{version.versionNumber} · {version.title}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Step-up password
              <input type="password" value={model.questionRollbackStepUpPassword} onChange={(event) => model.setQuestionRollbackStepUpPassword(event.target.value)} />
            </label>
            <label>
              Rollback justification
              <textarea value={model.questionRollbackJustification} onChange={(event) => model.setQuestionRollbackJustification(event.target.value)} rows={3} />
            </label>
            <button type="submit" disabled={!model.canRunAdminRollback}>
              Execute question rollback
            </button>
          </form>
        </div>

        <div className="meta-card">
          <h3>Admin-initiated password reset</h3>
          <form className="form-stack" onSubmit={model.handleAdminPasswordReset}>
            <label>
              Target username
              <input value={model.resetTargetUsername} onChange={(event) => model.setResetTargetUsername(event.target.value)} />
            </label>
            <label>
              New password
              <input type="password" value={model.resetNewPassword} onChange={(event) => model.setResetNewPassword(event.target.value)} minLength={12} />
            </label>
            <label>
              Step-up password
              <input type="password" value={model.resetStepUpPassword} onChange={(event) => model.setResetStepUpPassword(event.target.value)} />
            </label>
            <label>
              Reset justification
              <textarea value={model.resetJustification} onChange={(event) => model.setResetJustification(event.target.value)} rows={3} />
            </label>
            <button type="submit" disabled={!model.canResetPasswords}>
              Execute password reset
            </button>
          </form>
        </div>

        {model.adminError ? <p className="inline-error">{model.adminError}</p> : null}
        {model.adminStatus ? <p className="inline-status">{model.adminStatus}</p> : null}
      </div>
    </div>
  );
}

export function PractitionerWorkflowPanel({ model }: PanelProps) {
  return (
    <div className="panel-content grid-two">
      <div className="workflow-column">
        <h2>Practitioner profile</h2>
        {!model.canManagePractitionerProfile ? <p className="muted">Your role cannot edit practitioner profile data.</p> : null}
        {model.canManagePractitionerProfile ? (
          <form className="form-stack" onSubmit={model.handleSaveProfile}>
            <label>
              Lawyer identity (full legal name)
              <input value={model.lawyerFullName} onChange={(event) => model.setLawyerFullName(event.target.value)} />
            </label>
            <label>
              Firm affiliation
              <input value={model.firmName} onChange={(event) => model.setFirmName(event.target.value)} />
            </label>
            <label>
              Bar / licensing jurisdiction
              <input value={model.barJurisdiction} onChange={(event) => model.setBarJurisdiction(event.target.value)} />
            </label>
            <label>
              License number {model.profile ? '(leave blank to keep current)' : ''}
              <input value={model.licenseNumber} onChange={(event) => model.setLicenseNumber(event.target.value)} />
            </label>
            <p className="muted">
              Current masked license: <strong>{model.profile?.licenseNumberMasked ?? 'not set'}</strong>
            </p>
            {model.profileError ? <p className="inline-error">{model.profileError}</p> : null}
            {model.profileStatus ? <p className="inline-status">{model.profileStatus}</p> : null}
            <button type="submit">Save profile</button>
          </form>
        ) : null}
      </div>

      <div className="workflow-column">
        <h2>Credential submission</h2>
        {!model.canUploadCredentials ? <p className="muted">Your role cannot upload or resubmit credentials.</p> : null}
        {model.canUploadCredentials ? (
          <>
            {model.profileRequiredForCredential ? (
              <p className="inline-error">Complete practitioner profile first. Upload queue is locked until profile exists.</p>
            ) : (
              <form className="form-stack" onSubmit={model.handleCredentialUpload}>
                <label>
                  Credential label
                  <input value={model.credentialLabel} onChange={(event) => model.setCredentialLabel(event.target.value)} />
                </label>
                <label>
                  Credential file (PDF/JPG/PNG, max 10 MB)
                  <input
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                    onChange={(event) => model.setCredentialFile(event.target.files?.[0] ?? null)}
                  />
                </label>
                {model.credentialError ? <p className="inline-error">{model.credentialError}</p> : null}
                {model.credentialStatus ? <p className="inline-status">{model.credentialStatus}</p> : null}
                <button type="submit">Upload &amp; submit to review queue</button>
              </form>
            )}

            <h3>Submission history</h3>
            {model.mySubmissions.length === 0 ? <p className="muted">No credential submissions yet.</p> : null}
            <div className="submission-list">
              {model.mySubmissions.map((submission: any) => (
                <article key={submission.id} className="submission-card">
                  <div className="submission-head">
                    <strong>{submission.label}</strong>
                    <span className={`status-pill status-${submission.status.toLowerCase()}`}>{submission.status}</span>
                  </div>
                  <p className="muted">Last update: {new Date(submission.updatedAtUtc).toLocaleString()}</p>
                  <ul>
                    {submission.versions.map((version: any) => (
                      <li key={version.id}>
                        v{version.versionNumber} · {version.originalFilename} · {version.reviewStatus}
                        {version.reviewComment ? <em> — {version.reviewComment}</em> : null} <a href={version.downloadPath}>Download</a>
                      </li>
                    ))}
                  </ul>

                  {submission.status === 'RESUBMISSION_REQUIRED' ? (
                    <div className="resubmit-box">
                      <label>
                        Updated label (optional)
                        <input
                          value={model.resubmitLabels[submission.id] ?? ''}
                          onChange={(event) =>
                            model.setResubmitLabels((prev: Record<number, string>) => ({
                              ...prev,
                              [submission.id]: event.target.value,
                            }))
                          }
                        />
                      </label>
                      <label>
                        Resubmission file
                        <input
                          type="file"
                          accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                          onChange={(event) =>
                            model.setResubmitFiles((prev: Record<number, File | null>) => ({
                              ...prev,
                              [submission.id]: event.target.files?.[0] ?? null,
                            }))
                          }
                        />
                      </label>
                      <button type="button" className="secondary" onClick={() => void model.handleResubmission(submission.id)}>
                        Resubmit credential
                      </button>
                    </div>
                  ) : null}
                </article>
              ))}
            </div>
          </>
        ) : null}
      </div>
    </div>
  );
}

export function ReviewWorkflowPanel({ model }: PanelProps) {
  return (
    <div className="panel-content grid-two review-grid">
      <div className="workflow-column">
        <div className="panel-title-row">
          <h2>Review queue</h2>
          <select value={model.reviewStatusFilter} onChange={(event) => model.setReviewStatusFilter(event.target.value)}>
            <option value="PENDING_REVIEW">Pending review</option>
            <option value="RESUBMISSION_REQUIRED">Resubmission requested</option>
            <option value="APPROVED">Approved</option>
            <option value="REJECTED">Rejected</option>
            <option value="ALL">All</option>
          </select>
        </div>

        {model.reviewQueue.length === 0 ? <p className="muted">No submissions in this queue slice.</p> : null}
        <div className="submission-list">
          {model.reviewQueue.map((submission: any) => (
            <button
              type="button"
              key={submission.id}
              className={model.selectedReviewSubmission?.id === submission.id ? 'queue-item active' : 'queue-item'}
              onClick={() => void model.loadReviewDetail(submission.id)}
            >
              <strong>{submission.label}</strong>
              <span>{submission.practitioner.lawyerFullName}</span>
              <span>{submission.practitioner.firmName}</span>
              <span className={`status-pill status-${submission.status.toLowerCase()}`}>{submission.status}</span>
            </button>
          ))}
        </div>
      </div>

      <div className="workflow-column">
        <h2>Decision console</h2>
        {!model.selectedReviewSubmission ? <p className="muted">Select a queue item to inspect version history and decide.</p> : null}
        {model.selectedReviewSubmission ? (
          <>
            <div className="meta-card reviewer-meta">
              <p>
                <strong>Practitioner:</strong> {model.selectedReviewSubmission.practitioner.lawyerFullName}
              </p>
              <p>
                <strong>Firm:</strong> {model.selectedReviewSubmission.practitioner.firmName}
              </p>
              <p>
                <strong>Jurisdiction:</strong> {model.selectedReviewSubmission.practitioner.barJurisdiction}
              </p>
              <p>
                <strong>License (masked):</strong> {model.selectedReviewSubmission.practitioner.licenseNumberMasked}
              </p>
            </div>

            <h3>Version history</h3>
            <ul>
              {model.selectedReviewSubmission.versions.map((version: any) => (
                <li key={version.id}>
                  v{version.versionNumber} · {version.originalFilename} · {version.reviewStatus}
                  {version.reviewComment ? <em> — {version.reviewComment}</em> : null} <a href={version.downloadPath}>Open file</a>
                </li>
              ))}
            </ul>

            <form className="form-stack" onSubmit={model.handleReviewDecision}>
              <label>
                Decision
                <select value={model.reviewAction} onChange={(event) => model.setReviewAction(event.target.value)}>
                  <option value="approve">Approve</option>
                  <option value="reject">Reject (comment required)</option>
                  <option value="request_resubmission">Request resubmission (comment required)</option>
                </select>
              </label>
              <label>
                Comment
                <textarea value={model.reviewComment} onChange={(event) => model.setReviewComment(event.target.value)} rows={4} />
              </label>
              {model.reviewError ? <p className="inline-error">{model.reviewError}</p> : null}
              {model.reviewStatusMessage ? <p className="inline-status">{model.reviewStatusMessage}</p> : null}
              <button type="submit">Record decision</button>
            </form>
          </>
        ) : null}
      </div>
    </div>
  );
}

export function QuestionBankWorkflowPanel({ model }: PanelProps) {
  return (
    <div className="panel-content grid-two review-grid">
      <div className="workflow-column">
        <div className="panel-title-row">
          <h2>Question-bank catalog</h2>
          <select
            value={model.questionStatusFilter}
            onChange={(event) => model.setQuestionStatusFilter(event.target.value)}
          >
            <option value="ALL">All lifecycle states</option>
            <option value="DRAFT">Draft</option>
            <option value="PUBLISHED">Published</option>
            <option value="OFFLINE">Offline</option>
          </select>
        </div>

        <p className="muted">
          Controlled intake/internal-assessment question-bank with strict tagging, difficulty, lifecycle, and duplicate review controls.
        </p>

        <div className="button-row">
          <button type="button" className="secondary" onClick={model.resetQuestionDraftForm}>
            New draft question
          </button>
          <button type="button" className="secondary" onClick={() => void model.handleExportQuestions('csv')} disabled={!model.canImportExportQuestions}>
            Export CSV
          </button>
          <button type="button" className="secondary" onClick={() => void model.handleExportQuestions('excel')} disabled={!model.canImportExportQuestions}>
            Export Excel (.xlsx)
          </button>
        </div>

        <h3>Catalog entries</h3>
        {model.questionEntries.length === 0 ? <p className="muted">No question entries in this filter slice.</p> : null}
        <div className="submission-list">
          {model.questionEntries.map((entry: any) => (
            <button
              type="button"
              key={entry.id}
              className={model.selectedQuestion?.id === entry.id ? 'queue-item active' : 'queue-item'}
              onClick={() => void model.loadQuestionDetail(entry.id)}
            >
              <strong>{entry.title}</strong>
              <span>
                Difficulty {entry.difficulty} · v{entry.currentVersionNumber}
              </span>
              <span>{entry.tags.join(', ')}</span>
              <span className={`status-pill status-${entry.status.toLowerCase()}`}>{entry.status}</span>
            </button>
          ))}
        </div>

        <h3>Bulk import</h3>
        <form className="form-stack" onSubmit={model.handleImportQuestions}>
          <label>
            CSV or Excel (.xlsx) file
            <input
              type="file"
              accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
              onChange={(event) => model.setImportFile(event.target.files?.[0] ?? null)}
            />
          </label>
          <button type="submit" disabled={!model.canImportExportQuestions}>
            Run bulk import
          </button>
        </form>
      </div>

      <div className="workflow-column">
        <h2>Question editor &amp; lifecycle controls</h2>
        <form className="form-stack" onSubmit={model.handleSaveQuestionDraft}>
          <label>
            Question title
            <input value={model.questionTitle} onChange={(event) => model.setQuestionTitle(event.target.value)} />
          </label>
          <label>
            Plain text content (duplicate/similarity baseline)
            <textarea value={model.questionPlainText} onChange={(event) => model.setQuestionPlainText(event.target.value)} rows={4} />
          </label>
          <label>
            Rich text content (HTML allowed)
            <textarea value={model.questionRichText} onChange={(event) => model.setQuestionRichText(event.target.value)} rows={6} />
          </label>
          <label>
            Difficulty (1-5)
            <input
              type="number"
              min={1}
              max={5}
              value={model.questionDifficulty}
              onChange={(event) => model.setQuestionDifficulty(Number(event.target.value))}
            />
          </label>
          <label>
            Tags (comma-separated)
            <input value={model.questionTagsInput} onChange={(event) => model.setQuestionTagsInput(event.target.value)} placeholder="compliance, intake, solvency" />
          </label>
          <label>
            Formula expressions (one per line)
            <textarea
              value={model.questionFormulasInput}
              onChange={(event) => model.setQuestionFormulasInput(event.target.value)}
              rows={4}
              placeholder={'risk_score = liabilities / assets\nexpected_loss = probability * impact'}
            />
          </label>
          <label>
            Change note
            <input value={model.questionChangeNote} onChange={(event) => model.setQuestionChangeNote(event.target.value)} placeholder="Why this version changed" />
          </label>

          <button type="submit" disabled={!model.canManageQuestions}>
            {model.selectedQuestion ? 'Save draft revision' : 'Create draft question'}
          </button>
        </form>

        <h3>Embedded images</h3>
        <form className="form-stack" onSubmit={model.handleQuestionAssetUpload}>
          <label>
            Upload image (PNG/JPG/GIF/WEBP)
            <input
              type="file"
              accept="image/png,image/jpeg,image/gif,image/webp,.png,.jpg,.jpeg,.gif,.webp"
              onChange={(event) => model.setAssetUploadFile(event.target.files?.[0] ?? null)}
            />
          </label>
          <button type="submit" className="secondary" disabled={!model.canManageQuestions}>
            Upload embedded image
          </button>
        </form>
        {model.embeddedAssets.length === 0 ? <p className="muted">No embedded images linked in this draft yet.</p> : null}
        <ul>
          {model.embeddedAssets.map((asset: any) => (
            <li key={asset.id}>
              #{asset.id} · {asset.originalFilename} · {asset.mimeType} ·{' '}
              <a href={asset.downloadPath} target="_blank" rel="noreferrer">
                open
              </a>
            </li>
          ))}
        </ul>

        <h3>Lifecycle actions</h3>
        <div className="button-row">
          <button type="button" onClick={() => void model.handlePublishQuestion(false)} disabled={!model.selectedQuestion || !model.canPublishQuestions}>
            Publish
          </button>
          <button type="button" className="secondary" onClick={model.handleOfflineQuestion} disabled={!model.selectedQuestion || !model.canManageQuestions}>
            Set offline
          </button>
        </div>
        <label>
          Duplicate-review override comment (required for forced publish)
          <textarea value={model.duplicateReviewComment} onChange={(event) => model.setDuplicateReviewComment(event.target.value)} rows={3} />
        </label>
        <button type="button" className="secondary" onClick={() => void model.handlePublishQuestion(true)} disabled={!model.selectedQuestion || !model.canPublishQuestions}>
          Publish with duplicate-review override
        </button>

        {model.selectedQuestion ? (
          <div className="meta-card">
            <p>
              <strong>Current state:</strong> {model.selectedQuestion.status}
            </p>
            <p>
              <strong>Duplicate review:</strong> {model.selectedQuestion.duplicateReviewState}
            </p>
            <p>
              <strong>Version:</strong> {model.selectedQuestion.currentVersionNumber}
            </p>
            <h3>Version history</h3>
            <ul>
              {model.selectedQuestion.versions.map((version: any) => (
                <li key={version.id}>
                  v{version.versionNumber} · {new Date(version.createdAtUtc).toLocaleString()} · {version.createdByUsername}
                  {version.changeNote ? ` · ${version.changeNote}` : ''}
                </li>
              ))}
            </ul>
          </div>
        ) : null}

        {model.questionError ? <p className="inline-error">{model.questionError}</p> : null}
        {model.questionStatusMessage ? <p className="inline-status">{model.questionStatusMessage}</p> : null}
      </div>
    </div>
  );
}

export function AnalyticsWorkflowPanel({ model }: PanelProps) {
  return (
    <div className="panel-content grid-two review-grid">
      <div className="workflow-column">
        <h2>Analytics query workbench</h2>
        <p className="muted">
          Run date-range and org-unit filtered analysis across live snapshots and reusable sample datasets. Export query rows or a
          compliance-ready audit report in one click.
        </p>

        <form className="form-stack" onSubmit={model.handleRunAnalyticsQuery}>
          <label>
            From date
            <input type="date" value={model.analyticsFromDate} onChange={(event) => model.setAnalyticsFromDate(event.target.value)} />
          </label>
          <label>
            To date
            <input type="date" value={model.analyticsToDate} onChange={(event) => model.setAnalyticsToDate(event.target.value)} />
          </label>

          <div className="meta-card">
            <h3>Org-unit scope</h3>
            <div className="chip-wrap">
              {model.analyticsOrgUnits.map((orgUnit: string) => (
                <label key={orgUnit} className="chip">
                  <input
                    type="checkbox"
                    checked={model.analyticsSelectedOrgUnits.includes(orgUnit)}
                    onChange={() => model.toggleStringSelection(orgUnit, model.analyticsSelectedOrgUnits, model.setAnalyticsSelectedOrgUnits)}
                  />{' '}
                  {orgUnit}
                </label>
              ))}
              {model.analyticsOrgUnits.length === 0 ? <span className="chip muted">No org units available</span> : null}
            </div>
          </div>

          <div className="meta-card">
            <h3>Feature filters</h3>
            <div className="chip-wrap">
              {model.analyticsFeatures.map((feature: any) => (
                <label key={feature.id} className="chip">
                  <input
                    type="checkbox"
                    checked={model.analyticsSelectedFeatureIds.includes(feature.id)}
                    onChange={() => model.toggleNumberSelection(feature.id, model.analyticsSelectedFeatureIds, model.setAnalyticsSelectedFeatureIds)}
                  />{' '}
                  {feature.name}
                </label>
              ))}
              {model.analyticsFeatures.length === 0 ? <span className="chip muted">No feature definitions</span> : null}
            </div>
          </div>

          <div className="meta-card">
            <h3>Sample datasets</h3>
            <div className="chip-wrap">
              {model.analyticsDatasets.map((dataset: any) => (
                <label key={dataset.id} className="chip">
                  <input
                    type="checkbox"
                    checked={model.analyticsSelectedDatasetIds.includes(dataset.id)}
                    onChange={() => model.toggleNumberSelection(dataset.id, model.analyticsSelectedDatasetIds, model.setAnalyticsSelectedDatasetIds)}
                  />{' '}
                  {dataset.name}
                </label>
              ))}
              {model.analyticsDatasets.length === 0 ? <span className="chip muted">No sample datasets</span> : null}
            </div>
          </div>

          <label>
            <input
              type="checkbox"
              checked={model.analyticsIncludeLiveData}
              onChange={(event) => model.setAnalyticsIncludeLiveData(event.target.checked)}
            />{' '}
            Include live operations snapshots
          </label>

          <div className="button-row">
            <button type="submit" disabled={!model.canQueryAnalytics}>
              Run analytics query
            </button>
            <button type="button" className="secondary" onClick={() => void model.handleAnalyticsExport('query')} disabled={!model.canExportAnalytics}>
              Export query CSV
            </button>
            <button type="button" className="secondary" onClick={() => void model.handleAnalyticsExport('audit')} disabled={!model.canExportAnalytics}>
              Export audit report
            </button>
          </div>
        </form>

        {model.analyticsResult ? (
          <div className="meta-card">
            <h3>Query summary</h3>
            <p>
              <strong>Rows:</strong> {model.analyticsResult.summary.rowCount}
            </p>
            <p>
              <strong>Total intake:</strong> {model.analyticsResult.summary.totalIntakeCount}
            </p>
            <p>
              <strong>Total breaches:</strong> {model.analyticsResult.summary.totalBreachCount}
            </p>
            <p>
              <strong>Avg breach rate:</strong> {model.analyticsResult.summary.avgBreachRatePct.toFixed(2)}%
            </p>
            <p>
              <strong>Avg compliance score:</strong> {model.analyticsResult.summary.avgComplianceScorePct.toFixed(2)}%
            </p>
          </div>
        ) : null}

        {model.analyticsError ? <p className="inline-error">{model.analyticsError}</p> : null}
        {model.analyticsStatus ? <p className="inline-status">{model.analyticsStatus}</p> : null}
      </div>

      <div className="workflow-column">
        <h2>Compliance dashboard &amp; feature definitions</h2>

        {model.analyticsResult ? (
          <>
            <div className="meta-card">
              <h3>Prompt KPI contract (traceable to implementation labels)</h3>
              {model.analyticsResult.complianceDashboard.kpis.length === 0 ? <p className="muted">No KPI values for the current query scope.</p> : null}
              <ul>
                {model.analyticsResult.complianceDashboard.kpis.map((kpi: any) => (
                  <li key={kpi.id}>
                    <strong>{kpi.promptLabel ?? kpi.label}</strong> · {model.formatComplianceKpi(kpi.value, kpi.unit)} / target{' '}
                    {model.formatComplianceKpi(kpi.target, kpi.unit)} · {kpi.status} · impl: {kpi.implementationLabel ?? kpi.label} · alias: {kpi.promptAlias}
                  </li>
                ))}
              </ul>
            </div>

            <div className="meta-card">
              <h3>Trend / distribution / correlation</h3>
              <p className="muted">Monthly trend points: {model.analyticsResult.dashboard.trend.length}</p>
              <p className="muted">Org-unit distribution groups: {model.analyticsResult.dashboard.distribution.length}</p>
              <p className="muted">
                Correlation (review-hours ↔ breach-rate): {model.analyticsResult.dashboard.correlation.reviewHoursVsBreachRate.toFixed(4)}
              </p>
              <p className="muted">
                Correlation (evidence-completeness ↔ breach-rate): {model.analyticsResult.dashboard.correlation.evidenceCompletenessVsBreachRate.toFixed(4)}
              </p>
            </div>

            <div className="meta-card">
              <h3>Query row preview</h3>
              {model.analyticsResult.rows.length === 0 ? <p className="muted">No rows in current result set.</p> : null}
              <ul>
                {model.analyticsResult.rows.slice(0, 6).map((row: any, index: number) => (
                  <li key={`${row.occurredAtUtc}-${row.orgUnit}-${index}`}>
                    {new Date(row.occurredAtUtc).toLocaleString()} · {row.orgUnit} · breach {row.breachRatePct.toFixed(2)}% · compliance{' '}
                    {row.complianceScorePct.toFixed(2)}% · {row.matchedFeatures.map((item: any) => item.name).join(', ') || 'no feature match'}
                  </li>
                ))}
              </ul>
            </div>
          </>
        ) : (
          <p className="muted">Run a query to load compliance KPI cards and dashboard metrics.</p>
        )}

        {model.canManageAnalyticsFeatures ? (
          <>
            <h3>Feature definition editor</h3>
            <form className="form-stack" onSubmit={model.handleSaveAnalyticsFeature}>
              <label>
                Feature name
                <input value={model.featureName} onChange={(event) => model.setFeatureName(event.target.value)} />
              </label>
              <label>
                Description
                <textarea value={model.featureDescription} onChange={(event) => model.setFeatureDescription(event.target.value)} rows={3} />
              </label>
              <label>
                Tags (comma-separated)
                <input value={model.featureTagsInput} onChange={(event) => model.setFeatureTagsInput(event.target.value)} placeholder="breach, escalation, audit" />
              </label>
              <label>
                Formula expression
                <textarea
                  value={model.featureFormulaExpression}
                  onChange={(event) => model.setFeatureFormulaExpression(event.target.value)}
                  rows={3}
                  placeholder="(breachCount / intakeCount) * 100 >= 6"
                />
              </label>
              <div className="button-row">
                <button type="submit">{model.editingFeatureId ? 'Update feature definition' : 'Create feature definition'}</button>
                <button type="button" className="secondary" onClick={model.resetFeatureEditor}>
                  Reset feature editor
                </button>
              </div>
            </form>
          </>
        ) : null}

        <div className="submission-list">
          {model.analyticsFeatures.map((feature: any) => (
            <article key={feature.id} className="submission-card">
              <div className="submission-head">
                <strong>{feature.name}</strong>
                <span className="badge">#{feature.id}</span>
              </div>
              <p className="muted">{feature.description}</p>
              <p className="muted">Tags: {feature.tags.join(', ')}</p>
              <p className="muted">Formula: {feature.formulaExpression}</p>
              {model.canManageAnalyticsFeatures ? (
                <button type="button" className="secondary" onClick={() => model.beginFeatureEdit(feature)}>
                  Edit definition
                </button>
              ) : null}
            </article>
          ))}
        </div>
      </div>
    </div>
  );
}

export function SchedulingWorkflowPanel({ model }: PanelProps) {
  const weekdayLabels = [
    { weekday: 1, label: 'Monday' },
    { weekday: 2, label: 'Tuesday' },
    { weekday: 3, label: 'Wednesday' },
    { weekday: 4, label: 'Thursday' },
    { weekday: 5, label: 'Friday' },
    { weekday: 6, label: 'Saturday' },
    { weekday: 7, label: 'Sunday' },
  ];
  const availabilityByWeekday: Map<number, { weekday: number; startTime: string; endTime: string }> = new Map(
    (model.configWeeklyAvailability ?? []).map((entry: any) => [entry.weekday, entry]),
  );
  const sortedSlots = [...model.schedulingSlots].sort(
    (a, b) => new Date(a.startAtUtc).getTime() - new Date(b.startAtUtc).getTime(),
  );

  const dayKeys = Array.from(new Set(sortedSlots.map((slot: any) => new Date(slot.startAtUtc).toISOString().slice(0, 10))));
  const timeKeys = Array.from(new Set(sortedSlots.map((slot: any) => new Date(slot.startAtUtc).toISOString().slice(11, 16))));

  const slotMap = new Map<string, any[]>();
  for (const slot of sortedSlots) {
    const day = new Date(slot.startAtUtc).toISOString().slice(0, 10);
    const time = new Date(slot.startAtUtc).toISOString().slice(11, 16);
    const key = `${day}|${time}`;
    const current = slotMap.get(key) ?? [];
    current.push(slot);
    slotMap.set(key, current);
  }

  return (
    <div className="panel-content grid-two review-grid">
      <div className="workflow-column">
        <div className="panel-title-row">
          <h2>Availability &amp; slot management</h2>
          {model.canAdminScheduling ? <span className="badge badge-ok">Admin controls enabled</span> : <span className="badge">Read only</span>}
        </div>

        {model.canAdminScheduling ? (
          <>
            <form className="form-stack" onSubmit={model.handleSaveSchedulingConfig}>
              <label>
                Practitioner
                <input value={model.configPractitionerName} onChange={(event) => model.setConfigPractitionerName(event.target.value)} />
              </label>
              <label>
                Location
                <input value={model.configLocationName} onChange={(event) => model.setConfigLocationName(event.target.value)} />
              </label>
              <label>
                Slot duration (minutes)
                <input
                  type="number"
                  min={15}
                  max={120}
                  value={model.configSlotDurationMinutes}
                  onChange={(event) => model.setConfigSlotDurationMinutes(Number(event.target.value))}
                />
              </label>
              <label>
                Slot capacity
                <input
                  type="number"
                  min={1}
                  max={12}
                  value={model.configSlotCapacity}
                  onChange={(event) => model.setConfigSlotCapacity(Number(event.target.value))}
                />
              </label>
              <div className="meta-card">
                <h3>Weekly availability (UTC)</h3>
                <div className="form-stack">
                  {weekdayLabels.map((day) => {
                    const availability = availabilityByWeekday.get(day.weekday);
                    const isEnabled = Boolean(availability);

                    return (
                      <div key={day.weekday} className="submission-card">
                        <label>
                          <input
                            type="checkbox"
                            checked={isEnabled}
                            onChange={(event) => model.toggleWeeklyAvailabilityDay(day.weekday, event.target.checked)}
                          />{' '}
                          {day.label}
                        </label>
                        <div className="button-row">
                          <label>
                            Start
                            <input
                              type="time"
                              step={300}
                              value={availability?.startTime ?? '09:00'}
                              disabled={!isEnabled}
                              onChange={(event) => model.setWeeklyAvailabilityTime(day.weekday, 'startTime', event.target.value)}
                            />
                          </label>
                          <label>
                            End
                            <input
                              type="time"
                              step={300}
                              value={availability?.endTime ?? '17:00'}
                              disabled={!isEnabled}
                              onChange={(event) => model.setWeeklyAvailabilityTime(day.weekday, 'endTime', event.target.value)}
                            />
                          </label>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
              <button type="submit">Save weekly availability</button>
            </form>

            <div className="meta-card">
              <label>
                Generate slots days ahead
                <input
                  type="number"
                  min={1}
                  max={90}
                  value={model.generateDaysAhead}
                  onChange={(event) => model.setGenerateDaysAhead(Number(event.target.value))}
                />
              </label>
              <button type="button" className="secondary" onClick={() => void model.handleGenerateSlots()}>
                Generate slots from config
              </button>
              <p className="muted">
                Active config: {model.schedulingConfig ? `${model.schedulingConfig.practitionerName} @ ${model.schedulingConfig.locationName}` : 'not configured'}
              </p>
            </div>
          </>
        ) : (
          <p className="muted">System-admin role is required to configure weekly availability and generate slots.</p>
        )}

        {model.schedulingError ? <p className="inline-error">{model.schedulingError}</p> : null}
        {model.schedulingStatus ? <p className="inline-status">{model.schedulingStatus}</p> : null}
      </div>

      <div className="workflow-column">
        <h2>Book, reschedule, and cancel</h2>

        <h3>Calendar-style slot grid</h3>
        {sortedSlots.length === 0 ? <p className="muted">No upcoming slots available.</p> : null}
        {sortedSlots.length > 0 ? (
          <div className="calendar-grid-wrapper">
            <table className="calendar-grid">
              <thead>
                <tr>
                  <th>Time (UTC)</th>
                  {dayKeys.map((dayKey) => (
                    <th key={dayKey}>{new Date(`${dayKey}T00:00:00Z`).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {timeKeys.map((timeKey) => (
                  <tr key={timeKey}>
                    <td className="calendar-time-cell">{timeKey}</td>
                    {dayKeys.map((dayKey) => {
                      const slots = slotMap.get(`${dayKey}|${timeKey}`) ?? [];
                      return (
                        <td key={`${dayKey}-${timeKey}`} className="calendar-slot-cell">
                          {slots.length === 0 ? <span className="muted">—</span> : null}
                          <div className="submission-list">
                            {slots.map((slot: any) => (
                              <article key={slot.id} className="submission-card">
                                <div className="submission-head">
                                  <strong>{new Date(slot.startAtUtc).toLocaleString()}</strong>
                                  <span className="status-pill status-approved">Remaining {slot.remainingCapacity}</span>
                                </div>
                                <p className="muted">
                                  {slot.practitionerName} · {slot.locationName}
                                </p>
                                <p className="muted">
                                  Booked: {slot.bookedCount}/{slot.capacity} · Active holds: {slot.activeHoldCount}
                                </p>

                                {!slot.bookedByCurrentUser && !slot.currentUserHold ? (
                                  <button type="button" className="secondary" onClick={() => void model.handlePlaceHold(slot.id)}>
                                    Place 10-minute hold
                                  </button>
                                ) : null}

                                {slot.currentUserHold ? (
                                  <div className="button-row">
                                    <button type="button" onClick={() => void model.handleBookFromHold(slot.currentUserHold.holdId)}>
                                      Confirm booking
                                    </button>
                                    <button type="button" className="secondary" onClick={() => void model.handleReleaseHold(slot.currentUserHold.holdId)}>
                                      Release hold
                                    </button>
                                  </div>
                                ) : null}
                              </article>
                            ))}
                          </div>
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}

        <h3>My bookings</h3>
        {model.myBookings.length === 0 ? <p className="muted">No active bookings.</p> : null}
        <div className="submission-list">
          {model.myBookings.map((booking: any) => (
            <article key={booking.id} className="submission-card">
              <p>
                <strong>{new Date(booking.slot.startAtUtc).toLocaleString()}</strong> · {booking.slot.practitionerName} @ {booking.slot.locationName}
              </p>
              <p className="muted">Reschedule count: {booking.rescheduleCount} / 2</p>
              <label>
                Reschedule target slot
                <select
                  value={model.rescheduleTargets[booking.id] ?? ''}
                  onChange={(event) =>
                    model.setRescheduleTargets((prev: Record<number, number>) => ({
                      ...prev,
                      [booking.id]: Number(event.target.value),
                    }))
                  }
                >
                  <option value="">Choose slot</option>
                  {sortedSlots
                    .filter((slot: any) => slot.id !== booking.slot.id)
                    .map((slot: any) => (
                      <option key={slot.id} value={slot.id}>
                        {new Date(slot.startAtUtc).toLocaleString()} · {slot.locationName}
                      </option>
                    ))}
                </select>
              </label>
              <div className="button-row">
                <button type="button" className="secondary" onClick={() => void model.handleRescheduleBooking(booking.id)}>
                  Reschedule
                </button>
                <button type="button" className="secondary" onClick={() => void model.handleCancelBooking(booking.id)}>
                  Cancel booking
                </button>
              </div>
            </article>
          ))}
        </div>
      </div>
    </div>
  );
}
