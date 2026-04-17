import { describe, expect, it, vi } from 'vitest';
import {
  formatComplianceKpi,
  isoDateDaysAgo,
  toggleNumberSelection,
  toggleStringSelection,
} from './utils';

describe('isoDateDaysAgo', () => {
  it('returns today when daysAgo is 0', () => {
    const fixed = new Date('2026-05-14T08:00:00Z');
    vi.useFakeTimers();
    vi.setSystemTime(fixed);
    try {
      expect(isoDateDaysAgo(0)).toBe('2026-05-14');
    } finally {
      vi.useRealTimers();
    }
  });

  it('returns a date exactly N days in the past', () => {
    const fixed = new Date('2026-05-14T08:00:00Z');
    vi.useFakeTimers();
    vi.setSystemTime(fixed);
    try {
      expect(isoDateDaysAgo(10)).toBe('2026-05-04');
      expect(isoDateDaysAgo(120)).toBe('2026-01-14');
    } finally {
      vi.useRealTimers();
    }
  });

  it('clamps negative inputs to zero (today)', () => {
    const fixed = new Date('2026-05-14T08:00:00Z');
    vi.useFakeTimers();
    vi.setSystemTime(fixed);
    try {
      expect(isoDateDaysAgo(-5)).toBe('2026-05-14');
    } finally {
      vi.useRealTimers();
    }
  });
});

describe('formatComplianceKpi', () => {
  it('renders percentages with 2 decimal places and a % suffix', () => {
    expect(formatComplianceKpi(12.345, 'PERCENT')).toBe('12.35%');
  });

  it('renders hours with a space-delimited unit', () => {
    expect(formatComplianceKpi(3.1, 'HOURS')).toBe('3.10 h');
  });

  it('renders other unit types as plain fixed-precision numbers', () => {
    expect(formatComplianceKpi(42, 'COUNT')).toBe('42.00');
  });
});

describe('toggleStringSelection / toggleNumberSelection', () => {
  it('adds the value when it is not yet present', () => {
    const setter = vi.fn();
    toggleStringSelection('CA', ['NY'], setter);
    expect(setter).toHaveBeenCalledWith(['NY', 'CA']);
  });

  it('removes the value when it is already selected', () => {
    const setter = vi.fn();
    toggleStringSelection('CA', ['NY', 'CA'], setter);
    expect(setter).toHaveBeenCalledWith(['NY']);
  });

  it('operates identically on number selections', () => {
    const setter = vi.fn();
    toggleNumberSelection(3, [1, 2, 3], setter);
    expect(setter).toHaveBeenCalledWith([1, 2]);
  });
});
