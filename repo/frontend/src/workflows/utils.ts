import type { AnalyticsKpi } from './types';

export function isoDateDaysAgo(daysAgo: number): string {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() - Math.max(0, daysAgo));

  return date.toISOString().slice(0, 10);
}

export function formatComplianceKpi(value: number, unit: AnalyticsKpi['unit']): string {
  if (unit === 'PERCENT') {
    return `${value.toFixed(2)}%`;
  }

  if (unit === 'HOURS') {
    return `${value.toFixed(2)} h`;
  }

  return value.toFixed(2);
}

export function toggleStringSelection(value: string, selected: string[], setter: (items: string[]) => void) {
  if (selected.includes(value)) {
    setter(selected.filter((item) => item !== value));
    return;
  }

  setter([...selected, value]);
}

export function toggleNumberSelection(value: number, selected: number[], setter: (items: number[]) => void) {
  if (selected.includes(value)) {
    setter(selected.filter((item) => item !== value));
    return;
  }

  setter([...selected, value]);
}
