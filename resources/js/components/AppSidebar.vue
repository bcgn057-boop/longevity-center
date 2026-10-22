<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarCheck,
    CalendarDays,
    ChartColumn,
    ClipboardList,
    FlaskConical,
    FolderGit2,
    LayoutGrid,
    ListChecks,
    Ruler,
    ScrollText,
    ShieldCheck,
    UserCog,
    UserRound,
    Users,
    UsersRound,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, myProfile } from '@/routes';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as usersIndex } from '@/routes/admin/users';
import { index as requisitionsIndex } from '@/routes/lab-requisitions';
import { index as labsIndex } from '@/routes/labs';
import {
    analytics as practiceAnalytics,
    staffActivity,
} from '@/routes/compliance';
import { index as auditLogs } from '@/routes/compliance/audit-logs';
import { index as appointmentsIndex } from '@/routes/appointments';
import { index as myAppointments } from '@/routes/my-appointments';
import { index as myIntake } from '@/routes/my-intake';
import { index as myMeasurements } from '@/routes/my-measurements';
import { index as myProtocols } from '@/routes/my-protocols';
import { index as myResults } from '@/routes/my-results';
import { index as patientsIndex } from '@/routes/patients';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    const role = page.props.auth.role;

    if (
        role === 'super_admin' ||
        role === 'clinic_admin' ||
        role === 'doctor' ||
        role === 'practitioner'
    ) {
        items.push({
            title: 'Patients',
            href: patientsIndex(),
            icon: Users,
        });
        items.push({
            title: 'Appointments',
            href: appointmentsIndex(),
            icon: CalendarDays,
        });
    }

    if (role === 'lab_staff' || role === 'doctor' || role === 'practitioner') {
        items.push({
            title: 'Lab results',
            href: labsIndex(),
            icon: FlaskConical,
        });
        items.push({
            title: 'Lab requisitions',
            href: requisitionsIndex(),
            icon: ClipboardList,
        });
    }

    if (role === 'patient') {
        items.push({
            title: 'My profile',
            href: myProfile(),
            icon: UserRound,
        });
        items.push({
            title: 'My appointments',
            href: myAppointments(),
            icon: CalendarCheck,
        });
        items.push({
            title: 'My results',
            href: myResults(),
            icon: FlaskConical,
        });
        items.push({
            title: 'My measurements',
            href: myMeasurements(),
            icon: Ruler,
        });
        items.push({
            title: 'Health questionnaire',
            href: myIntake(),
            icon: ClipboardList,
        });
        items.push({
            title: 'My protocols',
            href: myProtocols(),
            icon: ListChecks,
        });
    }

    if (role === 'super_admin' || role === 'clinic_admin') {
        items.push({
            title: 'Users',
            href: usersIndex(),
            icon: UsersRound,
        });
        items.push({
            title: 'Administration',
            href: adminDashboard(),
            icon: ShieldCheck,
        });
    }

    if (
        role === 'super_admin' ||
        role === 'clinic_admin' ||
        role === 'compliance_officer'
    ) {
        items.push({
            title: 'Practice analytics',
            href: practiceAnalytics(),
            icon: ChartColumn,
        });
    }

    if (role === 'super_admin' || role === 'compliance_officer') {
        items.push({
            title: 'Staff activity',
            href: staffActivity(),
            icon: UserCog,
        });
        items.push({
            title: 'Audit Log',
            href: auditLogs(),
            icon: ScrollText,
        });
    }

    return items;
});

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
