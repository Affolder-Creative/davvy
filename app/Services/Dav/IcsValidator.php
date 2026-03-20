<?php

namespace App\Services\Dav;

use App\Services\RegistrationSettingsService;
use DateTimeImmutable;
use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

class IcsValidator
{
    public function __construct(private readonly RegistrationSettingsService $settings) {}

    /**
     * Validates and normalizes ICS payload content.
     */
    public function validateAndNormalize(string $calendarData): array
    {
        $strictModeEnabled = ! $this->settings->isDavCompatibilityModeEnabled();

        $component = $this->parseVCalendar($calendarData);
        $this->validateCalendarEnvelope($component, $strictModeEnabled);
        if (! $component instanceof VCalendar) {
            throw new BadRequest(__('dav.expected_vcalendar_payload'));
        }

        $primaryComponents = $this->primaryComponents($component);
        $componentType = $this->resolvePrimaryType($primaryComponents);
        $uid = $this->validatePrimaryComponents($primaryComponents, $componentType, $strictModeEnabled);

        [$firstOccurredAt, $lastOccurredAt] = $this->detectOccurrenceBounds($component);

        return [
            'data' => $component->serialize(),
            'uid' => $uid,
            'component_type' => $componentType,
            'first_occurred_at' => $firstOccurredAt,
            'last_occurred_at' => $lastOccurredAt,
        ];
    }

    /**
     * Extracts the UID from an ICS payload.
     */
    public function extractUid(string $calendarData): ?string
    {
        try {
            $component = $this->parseVCalendar($calendarData);
        } catch (BadRequest) {
            return null;
        }

        $primaryComponents = $this->primaryComponents($component);

        if ($primaryComponents === []) {
            return null;
        }

        $uid = trim((string) ($primaryComponents[0]->UID ?? ''));

        return $uid !== '' ? $uid : null;
    }

    /**
     * Parses v calendar.
     */
    private function parseVCalendar(string $calendarData): VCalendar
    {
        try {
            $component = Reader::read($calendarData);
        } catch (ParseException|\Throwable) {
            throw new BadRequest(__('dav.invalid_icalendar_payload'));
        }

        if (! $component instanceof VCalendar) {
            throw new BadRequest(__('dav.expected_vcalendar_payload'));
        }

        return $component;
    }

    /**
     * Validates calendar envelope.
     */
    private function validateCalendarEnvelope(VCalendar $calendar, bool $strictModeEnabled): void
    {
        if (trim((string) ($calendar->VERSION ?? '')) !== '2.0') {
            throw new BadRequest(__('dav.vcalendar_must_include_version_2_0'));
        }

        if (
            $strictModeEnabled
            && trim((string) ($calendar->PRODID ?? '')) === ''
        ) {
            throw new BadRequest(__('dav.vcalendar_must_include_prodid'));
        }
    }

    /**
     * Returns primary components.
     *
     * @return array<int, Component>
     */
    private function primaryComponents(VCalendar $calendar): array
    {
        $components = [];

        foreach (['VEVENT', 'VTODO', 'VJOURNAL'] as $type) {
            foreach ($calendar->select($type) as $component) {
                if ($component instanceof Component) {
                    $components[] = $component;
                }
            }
        }

        return $components;
    }

    /**
     * Resolves primary type.
     *
     * @param  array<int, Component>  $components
     */
    private function resolvePrimaryType(array $components): string
    {
        if ($components === []) {
            throw new BadRequest(__('dav.calendar_payload_requires_primary_component'));
        }

        $type = $components[0]->name;

        foreach ($components as $component) {
            if ($component->name !== $type) {
                throw new BadRequest(__('dav.mixed_primary_component_types_not_supported'));
            }
        }

        return $type;
    }

    /**
     * Validates primary components.
     *
     * @param  array<int, Component>  $components
     */
    private function validatePrimaryComponents(array $components, string $componentType, bool $strictModeEnabled): ?string
    {
        $resourceUid = null;
        $recurrenceIds = [];
        $hasMasterComponent = false;

        foreach ($components as $component) {
            $uid = trim((string) ($component->UID ?? ''));

            if ($uid === '' && $strictModeEnabled) {
                throw new BadRequest(__('dav.calendar_components_must_include_uid'));
            }

            if (
                $strictModeEnabled
                && $uid !== ''
                && $resourceUid !== null
                && $resourceUid !== $uid
            ) {
                throw new BadRequest(__('dav.calendar_components_must_share_same_uid'));
            }

            if ($uid !== '') {
                $resourceUid ??= $uid;
            }

            if (
                $strictModeEnabled
                && trim((string) ($component->DTSTAMP ?? '')) === ''
            ) {
                throw new BadRequest(__('dav.components_must_include_dtstamp', ['component' => $componentType]));
            }

            $this->validateSequence($component);
            $this->validateRRule($component, $strictModeEnabled);

            if (isset($component->{'RECURRENCE-ID'})) {
                $recurrenceId = trim((string) $component->{'RECURRENCE-ID'});

                if ($recurrenceId === '') {
                    throw new BadRequest(__('dav.recurrence_id_must_not_be_empty'));
                }

                if (isset($recurrenceIds[$recurrenceId])) {
                    throw new BadRequest(__('dav.duplicate_recurrence_id_not_allowed'));
                }

                $recurrenceIds[$recurrenceId] = true;
            } else {
                $hasMasterComponent = true;
            }

            if ($componentType === 'VEVENT') {
                $this->validateEventComponent($component);
            }

            if ($componentType === 'VTODO') {
                $this->validateTodoComponent($component);
            }
        }

        if (
            $strictModeEnabled
            && $recurrenceIds !== []
            && ! $hasMasterComponent
        ) {
            throw new BadRequest(__('dav.detached_recurrence_requires_master_component'));
        }

        if ($strictModeEnabled && $resourceUid === null) {
            throw new BadRequest(__('dav.calendar_components_must_include_uid'));
        }

        return $resourceUid;
    }

    /**
     * Validates event component.
     */
    private function validateEventComponent(Component $component): void
    {
        if (! isset($component->DTSTART)) {
            throw new BadRequest(__('dav.vevent_must_include_dtstart'));
        }

        if (isset($component->DTEND) && isset($component->DURATION)) {
            throw new BadRequest(__('dav.vevent_cannot_have_both_dtend_and_duration'));
        }

        $start = $this->safeDateTime($component->DTSTART);
        $end = $this->safeDateTime($component->DTEND ?? null);

        if ($start && $end && $end < $start) {
            throw new BadRequest(__('dav.vevent_dtend_must_be_gte_dtstart'));
        }
    }

    /**
     * Validates todo component.
     */
    private function validateTodoComponent(Component $component): void
    {
        if (isset($component->DUE) && isset($component->DURATION)) {
            throw new BadRequest(__('dav.vtodo_cannot_have_both_due_and_duration'));
        }

        if (isset($component->DURATION) && ! isset($component->DTSTART)) {
            throw new BadRequest(__('dav.vtodo_duration_requires_dtstart'));
        }
    }

    /**
     * Validates sequence.
     */
    private function validateSequence(Component $component): void
    {
        if (! isset($component->SEQUENCE)) {
            return;
        }

        $sequence = trim((string) $component->SEQUENCE);

        if (! preg_match('/^\d+$/', $sequence)) {
            throw new BadRequest(__('dav.sequence_must_be_non_negative_integer'));
        }
    }

    /**
     * Validates r rule.
     */
    private function validateRRule(Component $component, bool $strictModeEnabled): void
    {
        if (! isset($component->RRULE)) {
            return;
        }

        $parts = [];

        foreach (explode(';', (string) $component->RRULE) as $segment) {
            if ($segment === '') {
                continue;
            }

            $pair = explode('=', $segment, 2);

            if (count($pair) !== 2) {
                throw new BadRequest(__('dav.rrule_segments_must_be_key_value'));
            }

            [$key, $value] = $pair;
            $key = strtoupper(trim($key));
            $value = trim($value);

            if ($key === '' || $value === '') {
                throw new BadRequest(__('dav.rrule_segments_must_be_key_value'));
            }

            $parts[$key] = $value;
        }

        if (! isset($parts['FREQ'])) {
            throw new BadRequest(__('dav.rrule_must_include_freq'));
        }

        if (
            $strictModeEnabled
            && isset($parts['COUNT'])
            && isset($parts['UNTIL'])
        ) {
            throw new BadRequest(__('dav.rrule_cannot_include_count_and_until'));
        }

        if (
            $strictModeEnabled
            && isset($parts['COUNT'])
            && (! preg_match('/^\d+$/', $parts['COUNT']) || (int) $parts['COUNT'] <= 0)
        ) {
            throw new BadRequest(__('dav.rrule_count_must_be_positive_integer'));
        }

        if (
            $strictModeEnabled
            && isset($parts['INTERVAL'])
            && (! preg_match('/^\d+$/', $parts['INTERVAL']) || (int) $parts['INTERVAL'] <= 0)
        ) {
            throw new BadRequest(__('dav.rrule_interval_must_be_positive_integer'));
        }
    }

    /**
     * Returns detect occurrence bounds.
     */
    private function detectOccurrenceBounds(VCalendar $calendar): array
    {
        $first = null;
        $last = null;

        foreach ($calendar->children() as $child) {
            if (! in_array($child->name, ['VEVENT', 'VTODO', 'VJOURNAL'], true)) {
                continue;
            }

            $start = $this->safeDateTime($child->DTSTART ?? null);
            $end = $this->safeDateTime($child->DTEND ?? ($child->DUE ?? null));

            if ($start && ($first === null || $start < $first)) {
                $first = $start;
            }

            if ($end && ($last === null || $end > $last)) {
                $last = $end;
            }

            if (! $end && $start && ($last === null || $start > $last)) {
                $last = $start;
            }
        }

        return [$first, $last];
    }

    /**
     * Returns safe date time.
     */
    private function safeDateTime(mixed $property): ?DateTimeImmutable
    {
        if (! $property) {
            return null;
        }

        try {
            return DateTimeImmutable::createFromInterface($property->getDateTime());
        } catch (\Throwable) {
            return null;
        }
    }
}
