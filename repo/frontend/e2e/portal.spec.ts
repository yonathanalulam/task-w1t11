import { expect, test } from '@playwright/test';
import { mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

function devBootstrapPassword(): string {
  const envPath = '/workspace/runtime/dev/runtime.env';
  const raw = readFileSync(envPath, 'utf-8');
  const line = raw
    .split('\n')
    .map((item) => item.trim())
    .find((item) => item.startsWith('DEV_BOOTSTRAP_PASSWORD='));

  if (!line) {
    throw new Error('DEV_BOOTSTRAP_PASSWORD not found in runtime env file.');
  }

  return line.slice('DEV_BOOTSTRAP_PASSWORD='.length);
}

test('shows portal shell and health panel', async ({ page }) => {
  await page.goto('/');

  await expect(page.locator('h1')).toContainText('Regulatory Operations & Analytics Portal', { timeout: 15_000 });
  await expect(page.getByText('API Live')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole('heading', { name: 'Session & Authentication' })).toBeVisible({ timeout: 15_000 });
});

test('exercises practitioner upload and system-admin review workflow with evidence', async ({ page }) => {
  const password = devBootstrapPassword();
  const evidenceDir = path.join(process.cwd(), 'e2e-artifacts');
  mkdirSync(evidenceDir, { recursive: true });

  const suffix = `${Date.now()}-${Math.floor(Math.random() * 1_000_000)}`;
  const credentialLabel = `E2E Credential ${suffix}`;

  await page.goto('/');

  await page.getByLabel('Username').fill('standard_user');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();

  await expect(page.getByText('Signed in as standard_user')).toBeVisible({ timeout: 15_000 });
  await page.getByRole('button', { name: 'Practitioner Workflow' }).click();
  await expect(page.getByRole('heading', { name: 'Practitioner profile' })).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Lawyer identity (full legal name)').fill(`E2E Lawyer ${suffix}`);
  await page.getByLabel('Firm affiliation').fill('Verification & Co Legal');
  await page.getByLabel('Bar / licensing jurisdiction').fill('CA');
  await page.getByLabel(/License number/).fill(`CA-${suffix}`);
  await page.getByRole('button', { name: 'Save profile' }).click();

  await expect(page.getByText('Practitioner profile saved.')).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'practitioner-profile-saved.png'), fullPage: true });

  await page.getByLabel('Credential label').fill(credentialLabel);
  await page
    .getByLabel('Credential file (PDF/JPG/PNG, max 10 MB)')
    .setInputFiles(path.join(process.cwd(), 'e2e', 'fixtures', 'credential-sample.pdf'));
  await page.getByRole('button', { name: 'Upload & submit to review queue' }).click();

  await expect(page.getByText('Credential uploaded and submitted to review queue.')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(credentialLabel)).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'practitioner-credential-submitted.png'), fullPage: true });

  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByText('Signed out.')).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Username').fill('system_admin');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();

  await expect(page.getByText('Signed in as system_admin')).toBeVisible({ timeout: 15_000 });
  await page.getByRole('button', { name: 'Credential Review Queue' }).click();
  await expect(page.getByRole('heading', { name: 'Review queue' })).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: new RegExp(credentialLabel) }).click();
  await expect(page.getByRole('heading', { name: 'Decision console' })).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Decision').selectOption('approve');
  await page.getByRole('textbox', { name: 'Comment' }).fill('Administrative oversight approval from system admin.');
  await page.getByRole('button', { name: 'Record decision' }).click();

  await expect(page.getByText('Reviewer decision saved and audit-logged.')).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'system-admin-review-decision.png'), fullPage: true });
});

test('exercises scheduling workbench hold and booking workflow', async ({ page }) => {
  test.setTimeout(120_000);

  const password = devBootstrapPassword();
  const evidenceDir = path.join(process.cwd(), 'e2e-artifacts');
  mkdirSync(evidenceDir, { recursive: true });
  const suffix = `${Date.now()}-${Math.floor(Math.random() * 1_000_000)}`;

  await page.goto('/');
  await page.getByLabel('Username').fill('system_admin');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('Signed in as system_admin')).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Scheduling Workbench' }).click();
  await expect(page.getByRole('heading', { name: 'Availability & slot management' })).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Practitioner').fill(`Ariya Chen ${suffix}`);
  await page.getByLabel('Location').fill(`HQ-${suffix}`);
  await page.getByLabel('Slot duration (minutes)').fill('30');
  await page.getByLabel('Slot capacity').fill('1');
  await page.getByRole('button', { name: 'Save weekly availability' }).click();
  await expect(page.getByText('Scheduling configuration saved.')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(`Active config: Ariya Chen ${suffix} @ HQ-${suffix}`)).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Generate slots days ahead').fill('7');
  await page.getByRole('button', { name: 'Generate slots from config' }).click();

  const generatedStatus = page.getByText('Slots generated from weekly availability.');
  const overlapStatus = page.getByText('Cannot generate overlapping slots for the same practitioner and location.');
  await expect
    .poll(async () => {
      if (await generatedStatus.isVisible().catch(() => false)) {
        return 'generated';
      }
      if (await overlapStatus.isVisible().catch(() => false)) {
        return 'overlap';
      }

      return 'pending';
    }, { timeout: 15_000 })
    .not.toBe('pending');

  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByText('Signed out.')).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Username').fill('standard_user');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('Signed in as standard_user')).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Scheduling Workbench' }).click();
  await expect(page.getByRole('heading', { name: 'Book, reschedule, and cancel' })).toBeVisible({ timeout: 15_000 });

  const targetSlotLabel = `Ariya Chen ${suffix} · HQ-${suffix}`;
  const candidateTargetSlotCard = page
    .locator('article.submission-card')
    .filter({ hasText: targetSlotLabel })
    .filter({ has: page.locator('button:has-text("Place 10-minute hold")') })
    .last();

  const fallbackSlotCard = page
    .locator('article.submission-card')
    .filter({ has: page.locator('button:has-text("Place 10-minute hold")') })
    .first();

  const targetSlotCard =
    (await candidateTargetSlotCard.count()) > 0
      ? candidateTargetSlotCard
      : fallbackSlotCard;

  await expect(targetSlotCard).toBeVisible({ timeout: 15_000 });
  const selectedSlotStart = (await targetSlotCard.locator('.submission-head strong').first().innerText()).trim();
  const selectedSlotIdentity = (await targetSlotCard.locator('p.muted').first().innerText()).trim();
  await targetSlotCard.getByRole('button', { name: 'Place 10-minute hold' }).click();

  const heldSlotCard = page
    .locator('article.submission-card')
    .filter({ hasText: selectedSlotStart })
    .filter({ hasText: selectedSlotIdentity })
    .first();

  const confirmBookingButton = heldSlotCard.getByRole('button', { name: 'Confirm booking' });
  await expect(confirmBookingButton).toBeVisible({ timeout: 15_000 });
  await expect
    .poll(async () => {
      if (await page.getByText('Hold placed for 10 minutes. Confirm booking before expiry.').isVisible().catch(() => false)) {
        return 'hold-status';
      }
      if (await confirmBookingButton.isVisible().catch(() => false)) {
        return 'held-state';
      }
      if (await page.locator('p.inline-error').isVisible().catch(() => false)) {
        return `error:${(await page.locator('p.inline-error').first().innerText()).trim()}`;
      }
      return 'pending';
    }, { timeout: 15_000 })
    .not.toBe('pending');
  await confirmBookingButton.click();
  await expect(page.getByText('Appointment booked successfully.')).toBeVisible({ timeout: 15_000 });

  const targetBookingLabel = `Ariya Chen ${suffix} @ HQ-${suffix}`;
  const preferredBookingCard = page
    .locator('article.submission-card')
    .filter({ hasText: targetBookingLabel })
    .filter({ hasText: 'Reschedule count:' })
    .first();

  const fallbackBookingCard = page
    .locator('article.submission-card')
    .filter({ hasText: 'Reschedule count:' })
    .first();

  const bookingCard = (await preferredBookingCard.count()) > 0 ? preferredBookingCard : fallbackBookingCard;
  await expect(bookingCard).toBeVisible({ timeout: 15_000 });

  await page.screenshot({ path: path.join(evidenceDir, 'scheduling-hold-booking.png') });
});

test('exercises content-admin question-bank workflow with evidence', async ({ page }) => {
  test.setTimeout(120_000);

  const password = devBootstrapPassword();
  const evidenceDir = path.join(process.cwd(), 'e2e-artifacts');
  mkdirSync(evidenceDir, { recursive: true });
  const suffix = Date.now().toString().slice(-6);

  const primaryTitle = `QBank Liquidity Intake ${suffix}`;
  const duplicateTitle = `QBank Liquidity Intake Duplicate ${suffix}`;
  const uniquePlain = `Collect liquidity runway and covenant stress indicators for controlled intake routing ${suffix}.`;
  const uniqueRich = `<p>Collect liquidity runway and covenant stress indicators for controlled intake routing ${suffix}.</p>`;

  await page.goto('/');
  await page.getByLabel('Username').fill('content_admin');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('Signed in as content_admin')).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Question Bank Workbench' }).click();
  await expect(page.getByRole('heading', { name: 'Question-bank catalog' })).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Question title').fill(primaryTitle);
  await page.getByLabel('Plain text content (duplicate/similarity baseline)').fill(uniquePlain);
  await page.getByLabel('Rich text content (HTML allowed)').fill(uniqueRich);
  await page.getByLabel('Difficulty (1-5)').fill('3');
  await page.getByLabel('Tags (comma-separated)').fill('liquidity, intake, controlled');
  await page.getByLabel('Formula expressions (one per line)').fill('risk_score = liabilities / assets');
  await page.getByLabel('Change note').fill('Initial authoring draft for controlled content flow.');

  await page.getByRole('button', { name: 'Create draft question' }).click();
  await expect(page.getByText('Question draft created.')).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'question-bank-draft-created.png'), fullPage: true });

  await page.getByLabel('Question title').fill(`${primaryTitle} v2`);
  await page.getByLabel('Change note').fill('Updated copy for version history evidence.');
  await page.getByRole('button', { name: 'Save draft revision' }).click();
  await expect(page.getByText('Question draft updated. New version captured in history.')).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'question-bank-edited-version.png'), fullPage: true });

  await page.getByRole('button', { name: 'Publish', exact: true }).click();

  const firstPublishDuplicate = page.getByText(/Duplicate review required before publish./);
  const firstPublishSuccess = page.getByText('Question published successfully.');
  const firstPublishOverrideSuccess = page.getByText('Question published with duplicate-review override audit trail.');
  await expect
    .poll(async () => {
      if (await firstPublishDuplicate.isVisible().catch(() => false)) {
        return 'duplicate-review';
      }
      if (await firstPublishSuccess.isVisible().catch(() => false)) {
        return 'published';
      }
      if (await firstPublishOverrideSuccess.isVisible().catch(() => false)) {
        return 'override-published';
      }
      return 'pending';
    }, { timeout: 15_000 })
    .not.toBe('pending');

  const firstPublishOutcomeValue = await (async () => {
    if (await firstPublishDuplicate.isVisible().catch(() => false)) {
      return 'duplicate-review';
    }
    if (await firstPublishSuccess.isVisible().catch(() => false)) {
      return 'published';
    }
    if (await firstPublishOverrideSuccess.isVisible().catch(() => false)) {
      return 'override-published';
    }
    return 'pending';
  })();

  if (firstPublishOutcomeValue === 'duplicate-review') {
    await page.getByLabel('Duplicate-review override comment (required for forced publish)').fill(
      'Initial publish required override due existing legacy similarity in test environment.',
    );
    await page.getByRole('button', { name: 'Publish with duplicate-review override' }).click();
    await expect(page.getByText('Question published with duplicate-review override audit trail.')).toBeVisible({ timeout: 15_000 });
  } else if (firstPublishOutcomeValue === 'published') {
    await expect(firstPublishSuccess).toBeVisible({ timeout: 15_000 });
  } else {
    await expect(firstPublishOverrideSuccess).toBeVisible({ timeout: 15_000 });
  }
  await page.screenshot({ path: path.join(evidenceDir, 'question-bank-published.png'), fullPage: true });

  await page.getByRole('button', { name: 'New draft question' }).click();
  await page.getByLabel('Question title').fill(duplicateTitle);
  await page.getByLabel('Plain text content (duplicate/similarity baseline)').fill(uniquePlain);
  await page.getByLabel('Rich text content (HTML allowed)').fill(uniqueRich);
  await page.getByLabel('Difficulty (1-5)').fill('3');
  await page.getByLabel('Tags (comma-separated)').fill('liquidity, duplicate, review');
  await page.getByLabel('Formula expressions (one per line)').fill('risk_score = liabilities / assets');
  await page.getByLabel('Change note').fill('Draft candidate for duplicate review gate.');

  await page.getByRole('button', { name: 'Create draft question' }).click();
  await expect(page.getByText('Question draft created.')).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Publish', exact: true }).click();
  await expect(page.getByText(/Duplicate review required before publish./)).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'question-bank-duplicate-review-required.png'), fullPage: true });

  await page.getByLabel('Duplicate-review override comment (required for forced publish)').fill(
    'Reviewed by content admin and accepted as an intentional controlled duplicate variant.',
  );
  await page.getByRole('button', { name: 'Publish with duplicate-review override' }).click();
  await expect(page.getByText('Question published with duplicate-review override audit trail.')).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'question-bank-duplicate-override-published.png'), fullPage: true });

  await page.getByRole('button', { name: 'Set offline' }).click();
  await expect(page.getByText('Question moved to OFFLINE lifecycle state.')).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(/Current state:\s*OFFLINE/)).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'question-bank-offline-state.png'), fullPage: true });

  const csvImport = [
    'title,plainTextContent,richTextContent,difficulty,tags,formulas,status,changeNote',
    `Bulk Import Q1 ${suffix},"Capture sanctions indicators ${suffix}.","<p>Capture sanctions indicators ${suffix}.</p>",2,"sanctions|intake","risk = score * weight",DRAFT,"Imported row one"`,
    `Bulk Import Q2 ${suffix},"Capture solvency indicators ${suffix}.","<p>Capture solvency indicators ${suffix}.</p>",4,"solvency|intake","probability = liabilities / assets",PUBLISHED,"Imported row two"`,
  ].join('\n');

  await page.getByLabel('CSV or Excel (.xlsx) file').setInputFiles({
    name: `question-bank-import-${suffix}.csv`,
    mimeType: 'text/csv',
    buffer: Buffer.from(csvImport, 'utf-8'),
  });
  await page.getByRole('button', { name: 'Run bulk import' }).click();
  await expect(page.getByText(/Bulk import complete: created/)).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'question-bank-import-complete.png'), fullPage: true });

  await page.getByRole('button', { name: 'Export CSV' }).click();
  await expect(page.getByText('Question-bank CSV export generated.')).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Export Excel (.xlsx)' }).click();
  await expect(page.getByText('Question-bank EXCEL export generated.')).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'question-bank-export-complete.png'), fullPage: true });
});

test('exercises analyst analytics workbench and compliance export workflow', async ({ page }) => {
  test.setTimeout(90_000);

  const password = devBootstrapPassword();
  const evidenceDir = path.join(process.cwd(), 'e2e-artifacts');
  mkdirSync(evidenceDir, { recursive: true });
  const suffix = Date.now().toString().slice(-6);

  await page.goto('/');
  await page.getByLabel('Username').fill('analyst_user');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();

  await expect(page.getByText('Signed in as analyst_user')).toBeVisible({ timeout: 15_000 });
  await page.getByRole('button', { name: 'Analytics & Compliance' }).click();
  await expect(page.getByRole('heading', { name: 'Analytics query workbench' })).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('From date').fill('2026-01-01');
  await page.getByLabel('To date').fill('2026-12-31');
  await page.getByRole('button', { name: 'Run analytics query' }).click();
  await expect(page.getByText(/Query complete:/)).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(/Rescue volume/i)).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'analytics-query-results.png'), fullPage: true });

  await page.getByRole('button', { name: 'Export query CSV' }).click();
  await expect(page.getByText('Analytics query CSV export generated.')).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Export audit report' }).click();
  await expect(page.getByText('Analytics audit report exported.')).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Feature name').fill(`E2E Compliance Lens ${suffix}`);
  await page.getByLabel('Description').fill('Detects variance between escalation pressure and evidence quality.');
  await page.getByLabel('Tags (comma-separated)').fill('compliance, escalation, evidence');
  await page.getByLabel('Formula expression').fill('(escalationCount / intakeCount) * 100 > 4');
  await page.getByRole('button', { name: 'Create feature definition' }).click();
  await expect(page.getByText('Analytics feature definition created.')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('article.submission-card strong', { hasText: `E2E Compliance Lens ${suffix}` }).first()).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: path.join(evidenceDir, 'analytics-feature-created.png'), fullPage: true });
});

test('exercises system-admin governance console actions with evidence', async ({ page }) => {
  test.setTimeout(120_000);

  const password = devBootstrapPassword();
  const evidenceDir = path.join(process.cwd(), 'e2e-artifacts');
  mkdirSync(evidenceDir, { recursive: true });
  const suffix = Date.now().toString().slice(-6);

  const resetUsername = `governance_reset_${suffix}`;
  const resetOriginalPassword = 'InitialResetPassword123!';
  const resetNewPassword = 'AdminResetPassword123!';

  await page.goto('/');

  // Seed a practitioner profile so governance can perform sensitive-field read.
  await page.getByLabel('Username').fill('standard_user');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('Signed in as standard_user')).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Practitioner Workflow' }).click();
  await expect(page.getByRole('heading', { name: 'Practitioner profile' })).toBeVisible({ timeout: 15_000 });

  const saveProfileResponse = page.waitForResponse((response) => {
    return response.url().includes('/api/practitioner/profile') && response.request().method() === 'PUT';
  });

  await page.getByLabel('Lawyer identity (full legal name)').fill(`Governance Profile ${suffix}`);
  await page.getByLabel('Firm affiliation').fill(`Governance Firm ${suffix}`);
  await page.getByLabel('Bar / licensing jurisdiction').fill('CA');
  await page.getByLabel(/License number/).fill(`CA-GOV-${suffix}`);
  await page.getByRole('button', { name: 'Save profile' }).click();
  await expect(page.getByText('Practitioner profile saved.')).toBeVisible({ timeout: 15_000 });

  const profilePayload = await (await saveProfileResponse).json();
  const profileId = Number(profilePayload?.data?.profile?.id ?? 0);
  expect(profileId).toBeGreaterThan(0);

  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByText('Signed out.')).toBeVisible({ timeout: 15_000 });

  // Create a dedicated user for admin password-reset validation.
  await page.getByLabel('Username').fill(resetUsername);
  await page.getByLabel('Password').fill(resetOriginalPassword);
  await page.getByRole('button', { name: 'Register' }).click();
  await expect(page.getByText('Account registered. You can sign in immediately.')).toBeVisible({ timeout: 15_000 });

  // Create a multi-version question so system-admin rollback has a valid candidate.
  const questionTitle = `Governance rollback question ${suffix}`;
  const updatedQuestionTitle = `${questionTitle} updated`;
  await page.getByLabel('Username').fill('content_admin');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('Signed in as content_admin')).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Question Bank Workbench' }).click();
  await expect(page.getByRole('heading', { name: 'Question-bank catalog' })).toBeVisible({ timeout: 15_000 });

  const createQuestionResponse = page.waitForResponse((response) => {
    return response.url().includes('/api/question-bank/questions') && response.request().method() === 'POST';
  });

  await page.getByLabel('Question title').fill(questionTitle);
  await page.getByLabel('Plain text content (duplicate/similarity baseline)').fill(`Rollback baseline ${suffix}`);
  await page.getByLabel('Rich text content (HTML allowed)').fill(`<p>Rollback baseline ${suffix}</p>`);
  await page.getByLabel('Difficulty (1-5)').fill('3');
  await page.getByLabel('Tags (comma-separated)').fill('governance, rollback');
  await page.getByLabel('Formula expressions (one per line)').fill('score = numerator / denominator');
  await page.getByLabel('Change note').fill('Initial rollback baseline version.');
  await page.getByRole('button', { name: 'Create draft question' }).click();
  await expect(page.getByText('Question draft created.')).toBeVisible({ timeout: 15_000 });

  const createQuestionPayload = await (await createQuestionResponse).json();
  const questionEntryId = Number(createQuestionPayload?.data?.entry?.id ?? 0);
  expect(questionEntryId).toBeGreaterThan(0);

  await page.getByLabel('Question title').fill(updatedQuestionTitle);
  await page.getByLabel('Change note').fill('Second version for rollback target validation.');
  await page.getByRole('button', { name: 'Save draft revision' }).click();
  await expect(page.getByText('Question draft updated. New version captured in history.')).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByText('Signed out.')).toBeVisible({ timeout: 15_000 });

  // Execute governance operations as system admin.
  await page.getByLabel('Username').fill('system_admin');
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('Signed in as system_admin')).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Governance Admin' }).click();
  await expect(page.getByRole('heading', { name: 'Immutable evidence views' })).toBeVisible({ timeout: 15_000 });

  await page.getByRole('button', { name: 'Refresh anomalies' }).click();
  await expect(page.getByText(/Anomaly scan complete./)).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Practitioner profile ID').fill(String(profileId));
  await page.getByLabel('Reason for access').fill('Investigating controlled sensitive-field read behavior for audit evidence.');
  await page.getByRole('button', { name: 'Read sensitive field' }).click();
  await expect(page.getByText(new RegExp(`Profile #${profileId}`))).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Question entry').selectOption(String(questionEntryId));
  await page.getByLabel('Target version').nth(1).selectOption('1');
  await page.getByLabel('Step-up password').nth(1).fill(password);
  await page.getByLabel('Rollback justification').nth(1).fill('Restore prior wording after governance review determined update was invalid.');
  await page.getByRole('button', { name: 'Execute question rollback' }).click();
  await expect(page.getByText(/Question content rollback complete/)).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Target username').fill(resetUsername);
  await page.getByLabel('New password').fill(resetNewPassword);
  await page.getByLabel('Step-up password').nth(2).fill(password);
  await page.getByLabel('Reset justification').fill('Verified account recovery ticket approved by governance operations.');
  await page.getByRole('button', { name: 'Execute password reset' }).click();
  await expect(page.getByText(new RegExp(`Password reset completed for ${resetUsername}`))).toBeVisible({ timeout: 15_000 });

  await page.screenshot({ path: path.join(evidenceDir, 'governance-admin-console-actions.png'), fullPage: true });

  await page.getByRole('button', { name: 'Sign out' }).click();
  await expect(page.getByText('Signed out.')).toBeVisible({ timeout: 15_000 });

  await page.getByLabel('Username').fill(resetUsername);
  await page.getByLabel('Password').fill(resetNewPassword);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText(`Signed in as ${resetUsername}`)).toBeVisible({ timeout: 15_000 });
});
