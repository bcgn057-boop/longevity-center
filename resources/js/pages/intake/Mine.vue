<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { show } from '@/routes/my-intake';

defineProps<{
    linked: boolean;
    forms: {
        id: number;
        status: 'draft' | 'sent_to_patient' | 'completed';
        status_label: string;
        sent_at: string | null;
        submitted_at: string | null;
    }[];
}>();
</script>

<template>
    <Head title="Health questionnaire" />

    <div class="flex h-full max-w-3xl flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Health questionnaire</h1>
            <p class="text-sm text-muted-foreground">
                Your clinic sends this before your assessment. Your answers go
                only to your care team.
            </p>
        </div>

        <p
            v-if="!linked"
            class="rounded-lg border px-4 py-3 text-sm text-muted-foreground"
        >
            Your account isn't linked to a patient record yet. Please contact
            the clinic.
        </p>
        <p v-else-if="forms.length === 0" class="text-sm text-muted-foreground">
            Your clinic hasn't sent you a questionnaire yet.
        </p>
        <ul v-else class="divide-y rounded-lg border text-sm">
            <li
                v-for="form in forms"
                :key="form.id"
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
            >
                <div>
                    <Badge
                        :variant="
                            form.status === 'sent_to_patient'
                                ? 'default'
                                : 'outline'
                        "
                    >
                        {{ form.status_label }}
                    </Badge>
                    <span class="ml-2 text-muted-foreground">
                        <template v-if="form.submitted_at">
                            Submitted {{ form.submitted_at }}
                        </template>
                        <template v-else-if="form.sent_at">
                            Sent {{ form.sent_at }}
                        </template>
                    </span>
                </div>
                <Button
                    size="sm"
                    :variant="
                        form.status === 'sent_to_patient'
                            ? 'default'
                            : 'outline'
                    "
                    as-child
                >
                    <Link :href="show(form.id)">
                        {{
                            form.status === 'sent_to_patient'
                                ? 'Fill it in'
                                : 'View'
                        }}
                    </Link>
                </Button>
            </li>
        </ul>
    </div>
</template>
