// Accent colours by category. The 600 steps pass the categorical palette
// checks (lightness, chroma, colour-blind separation, contrast) on both the
// light and dark surfaces, so the same step is used in both themes. Colour
// always comes with an icon and a label: it is never the only cue.
export type Tone =
    | 'emerald'
    | 'indigo'
    | 'amber'
    | 'violet'
    | 'sky'
    | 'rose'
    | 'slate';

type ToneClasses = {
    // Chart marks.
    fill: string;
    // Card accent bar.
    accent: string;
    // Icon tile.
    icon: string;
    // Soft card background.
    wash: string;
};

export const tones: Record<Tone, ToneClasses> = {
    emerald: {
        fill: 'fill-emerald-600',
        accent: 'bg-emerald-600',
        icon: 'bg-emerald-600/10 text-emerald-600 dark:bg-emerald-600/20',
        wash: 'from-emerald-600/[0.07]',
    },
    indigo: {
        fill: 'fill-indigo-600',
        accent: 'bg-indigo-600',
        icon: 'bg-indigo-600/10 text-indigo-600 dark:bg-indigo-600/20',
        wash: 'from-indigo-600/[0.07]',
    },
    amber: {
        fill: 'fill-amber-600',
        accent: 'bg-amber-600',
        icon: 'bg-amber-600/10 text-amber-600 dark:bg-amber-600/20',
        wash: 'from-amber-600/[0.07]',
    },
    violet: {
        fill: 'fill-violet-600',
        accent: 'bg-violet-600',
        icon: 'bg-violet-600/10 text-violet-600 dark:bg-violet-600/20',
        wash: 'from-violet-600/[0.07]',
    },
    sky: {
        fill: 'fill-sky-600',
        accent: 'bg-sky-600',
        icon: 'bg-sky-600/10 text-sky-600 dark:bg-sky-600/20',
        wash: 'from-sky-600/[0.07]',
    },
    rose: {
        fill: 'fill-rose-600',
        accent: 'bg-rose-600',
        icon: 'bg-rose-600/10 text-rose-600 dark:bg-rose-600/20',
        wash: 'from-rose-600/[0.07]',
    },
    slate: {
        fill: 'fill-slate-500',
        accent: 'bg-slate-400 dark:bg-slate-500',
        icon: 'bg-slate-500/10 text-slate-600 dark:bg-slate-500/20 dark:text-slate-400',
        wash: 'from-slate-500/[0.05]',
    },
};

// Card, chart and action keys sent by the server, to their category colour.
// A key keeps its colour wherever it appears.
const toneByKey: Record<string, Tone> = {
    patients: 'emerald',
    appointments: 'indigo',
    book: 'indigo',
    labs: 'amber',
    review: 'amber',
    lab_entry: 'sky',
    results: 'sky',
    protocol: 'sky',
    protocols: 'sky',
    analytics: 'sky',
    phi: 'violet',
    audit: 'violet',
    staff: 'violet',
    sign_in: 'amber',
    denied: 'rose',
    exports: 'slate',
    export: 'slate',
    admin: 'slate',
};

export function toneFor(key: string): ToneClasses {
    return tones[toneByKey[key] ?? 'slate'];
}
