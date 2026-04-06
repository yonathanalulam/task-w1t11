import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { apiGet, apiPost, apiPut, type ApiError } from '../../api/client';
import type {
  SchedulingBookingsEnvelope,
  SchedulingConfiguration,
  SchedulingConfigurationEnvelope,
  SchedulingSlot,
  SchedulingSlotsEnvelope,
} from '../types';

type WeeklyAvailabilityWindow = {
  weekday: number;
  startTime: string;
  endTime: string;
};

const DEFAULT_WEEKLY_AVAILABILITY: WeeklyAvailabilityWindow[] = [1, 2, 3, 4, 5].map((weekday) => ({
  weekday,
  startTime: '09:00',
  endTime: '17:00',
}));

type UseSchedulingWorkflowParams = {
  csrfToken: string;
  sessionActive: boolean;
  canUseScheduling: boolean;
  canAdminScheduling: boolean;
};

export function useSchedulingWorkflow({ csrfToken, sessionActive, canUseScheduling, canAdminScheduling }: UseSchedulingWorkflowParams) {
  const [schedulingConfig, setSchedulingConfig] = useState<SchedulingConfiguration | null>(null);
  const [schedulingSlots, setSchedulingSlots] = useState<SchedulingSlot[]>([]);
  const [myBookings, setMyBookings] = useState<SchedulingBookingsEnvelope['bookings']>([]);
  const [schedulingError, setSchedulingError] = useState('');
  const [schedulingStatus, setSchedulingStatus] = useState('');

  const [configPractitionerName, setConfigPractitionerName] = useState('Ariya Chen');
  const [configLocationName, setConfigLocationName] = useState('HQ-01');
  const [configSlotDurationMinutes, setConfigSlotDurationMinutes] = useState(30);
  const [configSlotCapacity, setConfigSlotCapacity] = useState(1);
  const [configWeeklyAvailability, setConfigWeeklyAvailability] = useState<WeeklyAvailabilityWindow[]>(DEFAULT_WEEKLY_AVAILABILITY);
  const [generateDaysAhead, setGenerateDaysAhead] = useState(14);
  const [rescheduleTargets, setRescheduleTargets] = useState<Record<number, number>>({});

  useEffect(() => {
    if (sessionActive) {
      return;
    }

    setSchedulingConfig(null);
    setSchedulingSlots([]);
    setMyBookings([]);
    setSchedulingError('');
    setSchedulingStatus('');
    setConfigPractitionerName('Ariya Chen');
    setConfigLocationName('HQ-01');
    setConfigSlotDurationMinutes(30);
    setConfigSlotCapacity(1);
    setConfigWeeklyAvailability(DEFAULT_WEEKLY_AVAILABILITY);
    setGenerateDaysAhead(14);
    setRescheduleTargets({});
  }, [sessionActive]);

  useEffect(() => {
    if (sessionActive && canUseScheduling) {
      void loadSchedulingWorkbench();
    }
  }, [sessionActive, canUseScheduling, canAdminScheduling]);

  async function loadSchedulingWorkbench() {
    setSchedulingError('');

    await Promise.all([
      loadSchedulingOperationalData(),
      canAdminScheduling ? loadSchedulingConfiguration() : Promise.resolve(),
    ]);
  }

  async function loadSchedulingOperationalData() {
    setSchedulingError('');

    try {
      const [slotPayload, bookingPayload] = await Promise.all([
        apiGet<SchedulingSlotsEnvelope>('/api/scheduling/slots'),
        apiGet<SchedulingBookingsEnvelope>('/api/scheduling/bookings/me'),
      ]);
      setSchedulingSlots(slotPayload.slots);
      setMyBookings(bookingPayload.bookings);
    } catch (error) {
      const apiError = error as ApiError;
      setSchedulingError(apiError?.error?.message ?? 'Unable to load scheduling workbench.');
    }
  }

  async function loadSchedulingConfiguration() {
    setSchedulingError('');

    try {
      const configPayload = await apiGet<SchedulingConfigurationEnvelope>('/api/scheduling/configuration');
      applySchedulingConfiguration(configPayload.configuration);
    } catch (error) {
      const apiError = error as ApiError;
      setSchedulingError(apiError?.error?.message ?? 'Unable to load scheduling workbench.');
    }
  }

  function applySchedulingConfiguration(configuration: SchedulingConfiguration | null) {
    setSchedulingConfig(configuration);
    if (!configuration) {
      return;
    }

    setConfigPractitionerName(configuration.practitionerName);
    setConfigLocationName(configuration.locationName);
    setConfigSlotDurationMinutes(configuration.slotDurationMinutes);
    setConfigSlotCapacity(configuration.slotCapacity);
    setConfigWeeklyAvailability(
      [...configuration.weeklyAvailability]
        .sort((left, right) => left.weekday - right.weekday)
        .map((entry) => ({
          weekday: entry.weekday,
          startTime: entry.startTime,
          endTime: entry.endTime,
        })),
    );
  }

  function toggleWeeklyAvailabilityDay(weekday: number, enabled: boolean) {
    setConfigWeeklyAvailability((previous) => {
      const existing = previous.find((entry) => entry.weekday === weekday);

      if (!enabled) {
        return previous.filter((entry) => entry.weekday !== weekday);
      }

      if (existing) {
        return previous;
      }

      return [...previous, { weekday, startTime: '09:00', endTime: '17:00' }].sort((left, right) => left.weekday - right.weekday);
    });
  }

  function setWeeklyAvailabilityTime(weekday: number, field: 'startTime' | 'endTime', value: string) {
    setConfigWeeklyAvailability((previous) =>
      previous.map((entry) => (entry.weekday === weekday ? { ...entry, [field]: value } : entry)),
    );
  }

  async function handleSaveSchedulingConfig(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSchedulingError('');
    setSchedulingStatus('');

    if (configWeeklyAvailability.length === 0) {
      setSchedulingError('Select at least one weekday in weekly availability.');
      return;
    }

    try {
      const response = await apiPut<SchedulingConfigurationEnvelope>(
        '/api/scheduling/configuration',
        {
          practitionerName: configPractitionerName.trim(),
          locationName: configLocationName.trim(),
          slotDurationMinutes: configSlotDurationMinutes,
          slotCapacity: configSlotCapacity,
          weeklyAvailability: configWeeklyAvailability,
        },
        csrfToken,
      );

      applySchedulingConfiguration(response.configuration);
      setSchedulingStatus('Scheduling configuration saved.');
      void loadSchedulingOperationalData();
    } catch (error) {
      const apiError = error as ApiError;
      setSchedulingError(apiError?.error?.message ?? 'Unable to save scheduling configuration.');
    }
  }

  async function handleGenerateSlots() {
    setSchedulingError('');
    setSchedulingStatus('');

    try {
      await apiPost('/api/scheduling/slots/generate', { daysAhead: generateDaysAhead }, csrfToken);
      setSchedulingStatus('Slots generated from weekly availability.');
      await loadSchedulingWorkbench();
    } catch (error) {
      const apiError = error as ApiError;
      setSchedulingError(apiError?.error?.message ?? 'Unable to generate slots.');
    }
  }

  async function handlePlaceHold(slotId: number) {
    setSchedulingError('');
    setSchedulingStatus('');

    try {
      await apiPost(`/api/scheduling/slots/${slotId}/hold`, {}, csrfToken);
      setSchedulingStatus('Hold placed for 10 minutes. Confirm booking before expiry.');
      await loadSchedulingWorkbench();
    } catch (error) {
      const apiError = error as ApiError;
      setSchedulingError(apiError?.error?.message ?? 'Unable to place hold.');
    }
  }

  async function handleReleaseHold(holdId: number) {
    setSchedulingError('');
    setSchedulingStatus('');

    try {
      await apiPost(`/api/scheduling/holds/${holdId}/release`, {}, csrfToken);
      setSchedulingStatus('Hold released.');
      await loadSchedulingWorkbench();
    } catch (error) {
      const apiError = error as ApiError;
      setSchedulingError(apiError?.error?.message ?? 'Unable to release hold.');
    }
  }

  async function handleBookFromHold(holdId: number) {
    setSchedulingError('');
    setSchedulingStatus('');

    try {
      await apiPost(`/api/scheduling/holds/${holdId}/book`, {}, csrfToken);
      setSchedulingStatus('Appointment booked successfully.');
      await loadSchedulingWorkbench();
    } catch (error) {
      const apiError = error as ApiError;
      setSchedulingError(apiError?.error?.message ?? 'Unable to confirm booking.');
    }
  }

  async function handleRescheduleBooking(bookingId: number) {
    const targetSlotId = rescheduleTargets[bookingId] ?? 0;
    if (targetSlotId <= 0) {
      setSchedulingError('Choose a target slot before rescheduling.');
      return;
    }

    setSchedulingError('');
    setSchedulingStatus('');
    try {
      await apiPost(`/api/scheduling/bookings/${bookingId}/reschedule`, { targetSlotId }, csrfToken);
      setSchedulingStatus('Appointment rescheduled.');
      await loadSchedulingWorkbench();
    } catch (error) {
      const apiError = error as ApiError;
      setSchedulingError(apiError?.error?.message ?? 'Unable to reschedule booking.');
    }
  }

  async function handleCancelBooking(bookingId: number) {
    setSchedulingError('');
    setSchedulingStatus('');

    try {
      await apiPost(`/api/scheduling/bookings/${bookingId}/cancel`, { reason: 'Cancelled via scheduling workbench UI.' }, csrfToken);
      setSchedulingStatus('Appointment cancelled.');
      await loadSchedulingWorkbench();
    } catch (error) {
      const apiError = error as ApiError;
      setSchedulingError(apiError?.error?.message ?? 'Unable to cancel booking.');
    }
  }

  return {
    schedulingConfig,
    schedulingSlots,
    myBookings,
    schedulingError,
    schedulingStatus,
    configPractitionerName,
    setConfigPractitionerName,
    configLocationName,
    setConfigLocationName,
    configSlotDurationMinutes,
    setConfigSlotDurationMinutes,
    configSlotCapacity,
    setConfigSlotCapacity,
    configWeeklyAvailability,
    toggleWeeklyAvailabilityDay,
    setWeeklyAvailabilityTime,
    generateDaysAhead,
    setGenerateDaysAhead,
    rescheduleTargets,
    setRescheduleTargets,
    loadSchedulingWorkbench,
    handleSaveSchedulingConfig,
    handleGenerateSlots,
    handlePlaceHold,
    handleReleaseHold,
    handleBookFromHold,
    handleRescheduleBooking,
    handleCancelBooking,
  };
}
