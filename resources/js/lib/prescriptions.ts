import type { ProtocolItemRow } from '@/types';

export function emptyItemRow(): ProtocolItemRow {
    return {
        type: 'supplement',
        name: '',
        dosage: '',
        instructions: '',
        frequency: 'daily',
    };
}

/**
 * A Y-m-d calendar day, shown without shifting it through the browser's
 * timezone.
 */
export function formatDay(
    date: string,
    options: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    },
): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(
        undefined,
        { ...options, timeZone: 'UTC' },
    );
}

export function formatDateTime(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '—';
}

export const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30';

export const textareaClass =
    'w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';
