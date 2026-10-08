import type { ResultRow } from '@/types';

export function emptyResultRow(): ResultRow {
    return {
        biomarker_id: '',
        value: '',
        unit: '',
        reference_low: '',
        reference_high: '',
        comment: '',
    };
}
