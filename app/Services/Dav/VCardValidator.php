<?php

namespace App\Services\Dav;

use App\Services\RegistrationSettingsService;
use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

class VCardValidator
{
    public function __construct(private readonly RegistrationSettingsService $settings) {}

    /**
     * Validates and normalizes vCard payload content.
     */
    public function validateAndNormalize(string $cardData): array
    {
        $strictModeEnabled = ! $this->settings->isDavCompatibilityModeEnabled();

        $component = $this->parseVCard($cardData);
        if (! $component instanceof VCard) {
            throw new BadRequest(__('dav.expected_vcard_payload'));
        }

        $version = $this->validateVersion($component, $strictModeEnabled);
        $fn = $this->validateFullName($component, $strictModeEnabled);
        $uid = $this->validateUid($component, $strictModeEnabled);
        $this->validateEmailAddresses($component, $strictModeEnabled);

        if ($strictModeEnabled && $fn === '') {
            throw new BadRequest(__('dav.vcard_must_include_fn'));
        }

        return [
            'data' => $component->serialize(),
            'uid' => $uid,
            'version' => $version,
        ];
    }

    /**
     * Extracts the UID from a vCard payload.
     */
    public function extractUid(string $cardData): ?string
    {
        try {
            $component = $this->parseVCard($cardData);
        } catch (BadRequest) {
            return null;
        }

        $uidProperties = $component->select('UID');

        if (count($uidProperties) === 0) {
            return null;
        }

        $uid = trim((string) $uidProperties[0]);

        return $uid !== '' ? $uid : null;
    }

    /**
     * Parses v card.
     */
    private function parseVCard(string $cardData): VCard
    {
        try {
            $component = Reader::read($cardData);
        } catch (ParseException|\Throwable) {
            throw new BadRequest(__('dav.invalid_vcard_payload'));
        }

        if (! $component instanceof VCard) {
            throw new BadRequest(__('dav.expected_vcard_payload'));
        }

        return $component;
    }

    /**
     * Validates version.
     */
    private function validateVersion(VCard $card, bool $strictModeEnabled): string
    {
        $versions = $card->select('VERSION');

        if ($strictModeEnabled && count($versions) !== 1) {
            throw new BadRequest(__('dav.vcard_must_include_exactly_one_version'));
        }

        $version = trim((string) ($versions[0] ?? ''));

        if (! $strictModeEnabled && $version === '') {
            return '3.0';
        }

        if ($strictModeEnabled && ! in_array($version, ['3.0', '4.0'], true)) {
            throw new BadRequest(__('dav.vcard_version_must_be_3_or_4'));
        }

        return $version;
    }

    /**
     * Validates full name.
     */
    private function validateFullName(VCard $card, bool $strictModeEnabled): string
    {
        $fnProperties = $card->select('FN');

        if ($strictModeEnabled && count($fnProperties) !== 1) {
            throw new BadRequest(__('dav.vcard_must_include_exactly_one_fn'));
        }

        $value = trim((string) ($fnProperties[0] ?? ''));

        if ($strictModeEnabled && $value === '') {
            throw new BadRequest(__('dav.vcard_fn_must_not_be_empty'));
        }

        if (! $strictModeEnabled && $value === '') {
            $nameProperty = trim((string) ($card->N ?? ''));

            if ($nameProperty !== '') {
                return str_replace(';', ' ', $nameProperty);
            }

            return '';
        }

        return $value;
    }

    /**
     * Validates uid.
     */
    private function validateUid(VCard $card, bool $strictModeEnabled): ?string
    {
        $uidProperties = $card->select('UID');

        if ($strictModeEnabled && count($uidProperties) !== 1) {
            throw new BadRequest(__('dav.vcard_must_include_exactly_one_uid'));
        }

        $uid = trim((string) ($uidProperties[0] ?? ''));

        if ($strictModeEnabled && $uid === '') {
            throw new BadRequest(__('dav.vcard_uid_must_not_be_empty'));
        }

        return $uid !== '' ? $uid : null;
    }

    /**
     * Validates email addresses.
     */
    private function validateEmailAddresses(VCard $card, bool $strictModeEnabled): void
    {
        foreach ($card->select('EMAIL') as $emailProperty) {
            $email = trim((string) $emailProperty);

            if (
                $strictModeEnabled
                && ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false)
            ) {
                throw new BadRequest(__('dav.vcard_email_values_must_be_valid'));
            }
        }
    }
}
