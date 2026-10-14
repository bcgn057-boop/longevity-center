<?php

namespace App\Enums;

/**
 * What an appointment is for: a general visit or one of the clinic's
 * longevity therapies.
 */
enum AppointmentType: string
{
    case GeneralVisit = 'general_visit';
    case ExosomeTherapy = 'exosome_therapy';
    case PeptideTherapy = 'peptide_therapy';
    case StemCellTherapy = 'stem_cell_therapy';
    case SenolyticTherapies = 'senolytic_therapies';
    case Hbot = 'hbot';
    case OzoneTherapy = 'ozone_therapy';
    case Ihht = 'ihht';
    case RedLightTherapy = 'red_light_therapy';
    case PemfTherapy = 'pemf_therapy';
    case FocusedUltrasound = 'focused_ultrasound';
    case PlasmaExchange = 'plasma_exchange';
    case YoungPlasmaInfusion = 'young_plasma_infusion';

    public function label(): string
    {
        return match ($this) {
            self::GeneralVisit => 'General visit',
            self::ExosomeTherapy => 'Exosome Therapy',
            self::PeptideTherapy => 'Peptide Therapy',
            self::StemCellTherapy => 'Stem Cell Therapy',
            self::SenolyticTherapies => 'Senolytic Therapies',
            self::Hbot => 'Hyperbaric Oxygen Therapy (HBOT)',
            self::OzoneTherapy => 'Ozone Therapy',
            self::Ihht => 'Intermittent Hypoxia-Hyperoxia Therapy (IHHT)',
            self::RedLightTherapy => 'Red Light Therapy (Photobiomodulation)',
            self::PemfTherapy => 'Pulsed Electromagnetic Field (PEMF) Therapy',
            self::FocusedUltrasound => 'Focused Ultrasound',
            self::PlasmaExchange => 'Therapeutic Plasma Exchange (TPE)',
            self::YoungPlasmaInfusion => 'Young Plasma/Blood Component Infusions',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
