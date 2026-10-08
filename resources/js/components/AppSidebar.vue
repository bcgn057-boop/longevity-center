<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarCheck,
    CalendarDays,
    FlaskConical,
    FolderGit2,
    LayoutGrid,
    ScrollText,
    ShieldCheck,
    UserRound,
    Users,
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
import { index as labsIndex } from '@/routes/labs';
import { index as auditLogs } from '@/routes/admin/audit-logs';
import { index as appointmentsIndex } from '@/routes/appointments';
import { index as myAppointments } from '@/routes/my-appointments';
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
    }

    if (role === 'super_admin' || role === 'clinic_admin') {
        items.push({
            title: 'Administration',
            href: adminDashboard(),
            icon: ShieldCheck,
        });
    }

    if (role === 'super_admin') {
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
