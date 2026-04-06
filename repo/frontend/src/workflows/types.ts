import type { RoleCode } from '../app/permissionRegistry';

export type HealthPayload = {
  status: string;
  database?: string;
  keyring?: { activeKeyId?: string };
};

export type SessionPayload = {
  username: string;
  roles: RoleCode[];
};

export type PermissionPayload = {
  username: string;
  roles: RoleCode[];
  permissions: string[];
  navigation: string[];
};

export type CaptchaPayload = {
  challengeId: string;
  prompt: string;
};

export type LoginResponse = {
  user: SessionPayload;
  permissions: string[];
  navigation: string[];
};

export type CsrfPayload = {
  csrfToken: string;
  headerName: string;
};

export type PractitionerProfile = {
  id: number;
  lawyerFullName: string;
  firmName: string;
  barJurisdiction: string;
  licenseNumberMasked: string;
  updatedAtUtc: string;
};

export type CredentialVersion = {
  id: number;
  versionNumber: number;
  originalFilename: string;
  mimeType: string;
  sizeBytes: number;
  reviewStatus: string;
  reviewComment?: string | null;
  reviewedByUsername?: string | null;
  reviewedAtUtc?: string | null;
  uploadedByUsername: string;
  uploadedAtUtc: string;
  downloadPath: string;
};

export type CredentialSubmission = {
  id: number;
  label: string;
  status: string;
  currentVersionNumber: number;
  updatedAtUtc: string;
  latestVersion?: CredentialVersion | null;
  versions: CredentialVersion[];
};

export type PractitionerProfileEnvelope = {
  profile: PractitionerProfile | null;
};

export type PractitionerCredentialsEnvelope = {
  profileRequired: boolean;
  submissions: CredentialSubmission[];
};

export type ReviewerSubmission = CredentialSubmission & {
  practitioner: {
    username: string;
    lawyerFullName: string;
    firmName: string;
    barJurisdiction: string;
    licenseNumberMasked: string;
  };
};

export type ReviewerQueueEnvelope = {
  statusFilter: string;
  queue: ReviewerSubmission[];
};

export type ReviewerDetailEnvelope = {
  submission: ReviewerSubmission;
};

export type SchedulingConfiguration = {
  id: number;
  practitionerName: string;
  locationName: string;
  slotDurationMinutes: number;
  slotCapacity: number;
  weeklyAvailability: Array<{ weekday: number; startTime: string; endTime: string }>;
  updatedAtUtc: string;
};

export type SchedulingSlot = {
  id: number;
  startAtUtc: string;
  endAtUtc: string;
  capacity: number;
  bookedCount: number;
  activeHoldCount: number;
  remainingCapacity: number;
  status: string;
  practitionerName: string;
  locationName: string;
  bookedByCurrentUser: boolean;
  currentUserHold: { holdId: number; expiresAtUtc: string } | null;
};

export type SchedulingBooking = {
  id: number;
  status: string;
  bookedByUsername: string;
  rescheduleCount: number;
  updatedAtUtc: string;
  slot: {
    id: number;
    startAtUtc: string;
    endAtUtc: string;
    practitionerName: string;
    locationName: string;
  };
};

export type SchedulingSlotsEnvelope = { slots: SchedulingSlot[] };
export type SchedulingBookingsEnvelope = { bookings: SchedulingBooking[] };
export type SchedulingConfigurationEnvelope = { configuration: SchedulingConfiguration | null };

export type QuestionFormula = {
  id?: string;
  expression: string;
  label: string;
};

export type QuestionEmbeddedImage = {
  assetId: number;
  filename: string;
  mimeType: string;
  sizeBytes: number;
  downloadPath: string;
};

export type QuestionEntrySummary = {
  id: number;
  title: string;
  status: 'DRAFT' | 'PUBLISHED' | 'OFFLINE';
  difficulty: number;
  tags: string[];
  currentVersionNumber: number;
  duplicateReviewState: 'NONE' | 'REQUIRES_REVIEW' | 'OVERRIDDEN';
  updatedAtUtc: string;
};

export type QuestionEntryVersion = {
  id: number;
  versionNumber: number;
  title: string;
  plainTextContent: string;
  richTextContent: string;
  difficulty: number;
  tags: string[];
  formulas: QuestionFormula[];
  embeddedImages: QuestionEmbeddedImage[];
  changeNote: string | null;
  createdByUsername: string;
  createdAtUtc: string;
};

export type QuestionEntryDetail = {
  id: number;
  title: string;
  plainTextContent: string;
  richTextContent: string;
  difficulty: number;
  tags: string[];
  formulas: QuestionFormula[];
  embeddedImages: QuestionEmbeddedImage[];
  status: 'DRAFT' | 'PUBLISHED' | 'OFFLINE';
  duplicateReviewState: 'NONE' | 'REQUIRES_REVIEW' | 'OVERRIDDEN';
  currentVersionNumber: number;
  publishedAtUtc?: string | null;
  publishedByUsername?: string | null;
  updatedAtUtc: string;
  versions: QuestionEntryVersion[];
};

export type QuestionAsset = {
  id: number;
  originalFilename: string;
  mimeType: string;
  sizeBytes: number;
  downloadPath: string;
  uploadedAtUtc: string;
};

export type QuestionListEnvelope = {
  statusFilter: string;
  entries: QuestionEntrySummary[];
};

export type QuestionDetailEnvelope = {
  entry: QuestionEntryDetail;
};

export type QuestionAssetEnvelope = {
  asset: QuestionAsset;
};

export type QuestionImportEnvelope = {
  created: number;
  published: number;
  duplicateFlagged: number;
  errors: Array<{ line: number; message: string }>;
};

export type AnalyticsFeature = {
  id: number;
  name: string;
  description: string;
  tags: string[];
  formulaExpression: string;
  updatedAtUtc: string;
};

export type AnalyticsDataset = {
  id: number;
  name: string;
  description: string;
  rowCount: number;
  createdAtUtc: string;
};

export type AnalyticsOptionsEnvelope = {
  orgUnits: string[];
  features: AnalyticsFeature[];
  sampleDatasets: AnalyticsDataset[];
};

export type AnalyticsKpi = {
  id: string;
  label: string;
  promptAlias: string;
  promptLabel?: string;
  implementationLabel?: string;
  value: number;
  target: number;
  unit: 'PERCENT' | 'HOURS' | 'COUNT' | 'RATIO';
  status: 'ON_TRACK' | 'AT_RISK';
  comparisonDirection: 'HIGHER_IS_BETTER' | 'LOWER_IS_BETTER';
};

export type AnalyticsQueryResult = {
  filters: {
    fromDate: string;
    toDate: string;
    orgUnits: string[];
    datasetIds: number[];
    featureIds: number[];
    includeLiveData: boolean;
  };
  rows: Array<{
    occurredAtUtc: string;
    orgUnit: string;
    source: string;
    datasetName: string;
    intakeCount: number;
    breachCount: number;
    escalationCount: number;
    avgReviewHours: number;
    resolutionWithinSlaPct: number;
    evidenceCompletenessPct: number;
    breachRatePct: number;
    escalationRatePct: number;
    complianceScorePct: number;
    matchedFeatures: Array<{ id: number; name: string }>;
  }>;
  summary: {
    rowCount: number;
    totalIntakeCount: number;
    totalBreachCount: number;
    avgBreachRatePct: number;
    avgComplianceScorePct: number;
  };
  dashboard: {
    trend: Array<{ month: string; avgBreachRatePct: number; avgComplianceScorePct: number }>;
    distribution: Array<{ orgUnit: string; recordCount: number; intakeCount: number; avgBreachRatePct: number; avgReviewHours: number }>;
    correlation: {
      reviewHoursVsBreachRate: number;
      evidenceCompletenessVsBreachRate: number;
    };
  };
  complianceDashboard: {
    kpis: AnalyticsKpi[];
    promptKpis?: AnalyticsKpi[];
    trend: Array<{ month: string; avgBreachRatePct: number; avgComplianceScorePct: number }>;
  };
};

export type AdminAuditLogEntry = {
  id: number;
  actorUsername: string | null;
  actionType: string;
  payload: Record<string, unknown>;
  createdAtUtc: string;
};

export type AdminSensitiveAccessLogEntry = {
  id: number;
  actorUsername: string;
  entityType: string;
  entityId: string;
  fieldName: string;
  reason: string;
  createdAtUtc: string;
};

export type AdminAnomalyAlert = {
  id: number;
  alertType: string;
  scopeKey: string;
  status: 'OPEN' | 'ACKNOWLEDGED' | 'RESOLVED';
  payload: Record<string, unknown>;
  createdAtUtc: string;
  updatedAtUtc: string;
  lastDetectedAtUtc: string;
  acknowledgedAtUtc?: string | null;
  acknowledgedByUsername?: string | null;
  acknowledgementNote?: string | null;
  resolvedAtUtc?: string | null;
};

export type AdminCredentialRollbackCandidate = {
  id: number;
  label: string;
  status: string;
  currentVersionNumber: number;
  updatedAtUtc: string;
  practitionerProfile: {
    id: number;
    username: string;
    lawyerFullName: string;
    firmName: string;
    barJurisdiction: string;
    licenseNumberMasked: string;
  };
  versions: Array<{
    id: number;
    versionNumber: number;
    reviewStatus: string;
    reviewComment?: string | null;
    reviewedAtUtc?: string | null;
    uploadedAtUtc: string;
    originalFilename: string;
  }>;
};

export type AdminQuestionRollbackCandidate = {
  id: number;
  title: string;
  status: string;
  currentVersionNumber: number;
  updatedAtUtc: string;
  versions: Array<{
    id: number;
    versionNumber: number;
    title: string;
    difficulty: number;
    tags: string[];
    createdByUsername: string;
    createdAtUtc: string;
    changeNote?: string | null;
  }>;
};

export type ViewMode = 'operations' | 'practitioner' | 'review' | 'scheduling' | 'questionBank' | 'analytics' | 'admin';
