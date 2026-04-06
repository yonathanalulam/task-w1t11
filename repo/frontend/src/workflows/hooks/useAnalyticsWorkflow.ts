import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { apiGet, apiPost, apiPut, type ApiError } from '../../api/client';
import type { AnalyticsFeature, AnalyticsOptionsEnvelope, AnalyticsQueryResult } from '../types';
import { isoDateDaysAgo, toggleNumberSelection, toggleStringSelection } from '../utils';

type UseAnalyticsWorkflowParams = {
  csrfToken: string;
  sessionActive: boolean;
  canUseAnalytics: boolean;
  canQueryAnalytics: boolean;
};

export function useAnalyticsWorkflow({ csrfToken, sessionActive, canUseAnalytics, canQueryAnalytics }: UseAnalyticsWorkflowParams) {
  const [analyticsOrgUnits, setAnalyticsOrgUnits] = useState<string[]>([]);
  const [analyticsFeatures, setAnalyticsFeatures] = useState<AnalyticsFeature[]>([]);
  const [analyticsDatasets, setAnalyticsDatasets] = useState<AnalyticsOptionsEnvelope['sampleDatasets']>([]);
  const [analyticsSelectedOrgUnits, setAnalyticsSelectedOrgUnits] = useState<string[]>([]);
  const [analyticsSelectedFeatureIds, setAnalyticsSelectedFeatureIds] = useState<number[]>([]);
  const [analyticsSelectedDatasetIds, setAnalyticsSelectedDatasetIds] = useState<number[]>([]);
  const [analyticsFromDate, setAnalyticsFromDate] = useState(() => isoDateDaysAgo(120));
  const [analyticsToDate, setAnalyticsToDate] = useState(() => isoDateDaysAgo(0));
  const [analyticsIncludeLiveData, setAnalyticsIncludeLiveData] = useState(true);
  const [analyticsResult, setAnalyticsResult] = useState<AnalyticsQueryResult | null>(null);
  const [analyticsError, setAnalyticsError] = useState('');
  const [analyticsStatus, setAnalyticsStatus] = useState('');

  const [editingFeatureId, setEditingFeatureId] = useState<number | null>(null);
  const [featureName, setFeatureName] = useState('');
  const [featureDescription, setFeatureDescription] = useState('');
  const [featureTagsInput, setFeatureTagsInput] = useState('');
  const [featureFormulaExpression, setFeatureFormulaExpression] = useState('');

  useEffect(() => {
    if (sessionActive) {
      return;
    }

    setAnalyticsOrgUnits([]);
    setAnalyticsFeatures([]);
    setAnalyticsDatasets([]);
    setAnalyticsSelectedOrgUnits([]);
    setAnalyticsSelectedFeatureIds([]);
    setAnalyticsSelectedDatasetIds([]);
    setAnalyticsFromDate(isoDateDaysAgo(120));
    setAnalyticsToDate(isoDateDaysAgo(0));
    setAnalyticsIncludeLiveData(true);
    setAnalyticsResult(null);
    setAnalyticsError('');
    setAnalyticsStatus('');
    setEditingFeatureId(null);
    setFeatureName('');
    setFeatureDescription('');
    setFeatureTagsInput('');
    setFeatureFormulaExpression('');
  }, [sessionActive]);

  useEffect(() => {
    if (sessionActive && canUseAnalytics) {
      void loadAnalyticsWorkbench();
    }
  }, [sessionActive, canUseAnalytics, canQueryAnalytics]);

  function analyticsQueryPayload(): Record<string, unknown> {
    return {
      fromDate: analyticsFromDate,
      toDate: analyticsToDate,
      orgUnits: analyticsSelectedOrgUnits,
      featureIds: analyticsSelectedFeatureIds,
      datasetIds: analyticsSelectedDatasetIds,
      includeLiveData: analyticsIncludeLiveData,
    };
  }

  async function loadAnalyticsWorkbench() {
    setAnalyticsError('');

    try {
      const options = await apiGet<AnalyticsOptionsEnvelope>('/api/analytics/workbench/options');
      setAnalyticsOrgUnits(options.orgUnits);
      setAnalyticsFeatures(options.features);
      setAnalyticsDatasets(options.sampleDatasets);
      if (options.orgUnits.length > 0 && analyticsSelectedOrgUnits.length === 0) {
        setAnalyticsSelectedOrgUnits([options.orgUnits[0]]);
      }
      if (options.sampleDatasets.length > 0 && analyticsSelectedDatasetIds.length === 0) {
        setAnalyticsSelectedDatasetIds([options.sampleDatasets[0].id]);
      }

      if (canQueryAnalytics) {
        const result = await apiPost<AnalyticsQueryResult>('/api/analytics/query', analyticsQueryPayload(), csrfToken);
        setAnalyticsResult(result);
      }
    } catch (error) {
      const apiError = error as ApiError;
      setAnalyticsError(apiError?.error?.message ?? 'Unable to load analytics workbench.');
      setAnalyticsResult(null);
    }
  }

  async function handleRunAnalyticsQuery(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setAnalyticsError('');
    setAnalyticsStatus('');

    try {
      const result = await apiPost<AnalyticsQueryResult>('/api/analytics/query', analyticsQueryPayload(), csrfToken);
      setAnalyticsResult(result);
      setAnalyticsStatus(`Query complete: ${result.summary.rowCount} rows returned.`);
    } catch (error) {
      const apiError = error as ApiError;
      setAnalyticsError(apiError?.error?.message ?? 'Unable to run analytics query.');
      setAnalyticsResult(null);
    }
  }

  async function handleAnalyticsExport(mode: 'query' | 'audit') {
    setAnalyticsError('');
    setAnalyticsStatus('');

    try {
      const endpoint = mode === 'query' ? '/api/analytics/query/export' : '/api/analytics/audit-report/export';
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
        },
        body: JSON.stringify(analyticsQueryPayload()),
      });

      if (!response.ok) {
        const payload = (await response.json()) as ApiError;
        throw payload;
      }

      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = mode === 'query' ? 'analytics-query-export.csv' : 'analytics-audit-report.csv';
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      URL.revokeObjectURL(url);

      setAnalyticsStatus(mode === 'query' ? 'Analytics query CSV export generated.' : 'Analytics audit report exported.');
    } catch (error) {
      const apiError = error as ApiError;
      setAnalyticsError(apiError?.error?.message ?? 'Unable to export analytics data.');
    }
  }

  function beginFeatureEdit(feature: AnalyticsFeature) {
    setEditingFeatureId(feature.id);
    setFeatureName(feature.name);
    setFeatureDescription(feature.description);
    setFeatureTagsInput(feature.tags.join(', '));
    setFeatureFormulaExpression(feature.formulaExpression);
  }

  function resetFeatureEditor() {
    setEditingFeatureId(null);
    setFeatureName('');
    setFeatureDescription('');
    setFeatureTagsInput('');
    setFeatureFormulaExpression('');
  }

  async function handleSaveAnalyticsFeature(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setAnalyticsError('');
    setAnalyticsStatus('');

    const tags = featureTagsInput
      .split(',')
      .map((item) => item.trim())
      .filter((item) => item !== '');

    const payload = {
      name: featureName.trim(),
      description: featureDescription.trim(),
      tags,
      formulaExpression: featureFormulaExpression.trim(),
    };

    try {
      if (editingFeatureId) {
        await apiPut<{ feature: AnalyticsFeature }>(`/api/analytics/features/${editingFeatureId}`, payload, csrfToken);
        setAnalyticsStatus('Analytics feature definition updated.');
      } else {
        await apiPost<{ feature: AnalyticsFeature }>('/api/analytics/features', payload, csrfToken);
        setAnalyticsStatus('Analytics feature definition created.');
      }

      const featurePayload = await apiGet<{ features: AnalyticsFeature[] }>('/api/analytics/features');
      setAnalyticsFeatures(featurePayload.features);
      resetFeatureEditor();
    } catch (error) {
      const apiError = error as ApiError;
      setAnalyticsError(apiError?.error?.message ?? 'Unable to save analytics feature definition.');
    }
  }

  return {
    analyticsOrgUnits,
    analyticsFeatures,
    analyticsDatasets,
    analyticsSelectedOrgUnits,
    setAnalyticsSelectedOrgUnits,
    analyticsSelectedFeatureIds,
    setAnalyticsSelectedFeatureIds,
    analyticsSelectedDatasetIds,
    setAnalyticsSelectedDatasetIds,
    analyticsFromDate,
    setAnalyticsFromDate,
    analyticsToDate,
    setAnalyticsToDate,
    analyticsIncludeLiveData,
    setAnalyticsIncludeLiveData,
    analyticsResult,
    analyticsError,
    analyticsStatus,
    editingFeatureId,
    featureName,
    setFeatureName,
    featureDescription,
    setFeatureDescription,
    featureTagsInput,
    setFeatureTagsInput,
    featureFormulaExpression,
    setFeatureFormulaExpression,
    loadAnalyticsWorkbench,
    handleRunAnalyticsQuery,
    handleAnalyticsExport,
    beginFeatureEdit,
    resetFeatureEditor,
    handleSaveAnalyticsFeature,
    toggleStringSelection,
    toggleNumberSelection,
  };
}
