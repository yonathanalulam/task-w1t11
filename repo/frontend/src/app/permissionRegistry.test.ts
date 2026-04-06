import { roleDisplayName } from './permissionRegistry';

describe('permission registry', () => {
  it('keeps baseline role display labels stable', () => {
    expect(roleDisplayName.ROLE_SYSTEM_ADMIN).toBe('System Admin');
    expect(roleDisplayName.ROLE_STANDARD_USER).toBe('Standard User');
  });
});
