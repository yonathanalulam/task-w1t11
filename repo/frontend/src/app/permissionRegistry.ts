export type RoleCode =
  | 'ROLE_STANDARD_USER'
  | 'ROLE_CONTENT_ADMIN'
  | 'ROLE_CREDENTIAL_REVIEWER'
  | 'ROLE_ANALYST'
  | 'ROLE_SYSTEM_ADMIN';

export const roleDisplayName: Record<RoleCode, string> = {
  ROLE_STANDARD_USER: 'Standard User',
  ROLE_CONTENT_ADMIN: 'Content Admin',
  ROLE_CREDENTIAL_REVIEWER: 'Credential Reviewer',
  ROLE_ANALYST: 'Analyst',
  ROLE_SYSTEM_ADMIN: 'System Admin',
};
