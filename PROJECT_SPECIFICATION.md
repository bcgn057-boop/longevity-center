# Project Specification: Medical-Grade Longevity-Center Platform

## 1. Overview & Objectives
A compliant, secure, single-clinic longevity management platform built for robust patient care, advanced biomarker tracking, and rigorous data protection.

## 2. Technology Stack
* **Backend:** PHP 8.3, Laravel 13
* **Frontend:** Vue 3 (Composition API), Inertia.js, Tailwind CSS
* **Database:** SQLite (Single-tenant optimized with multi-tenant schema extensibility)
* **Testing:** Pest PHP
* **Security Suite:** Email Verification, User Registration, Two-Factor Authentication (2FA), Passkeys, and Password Confirmation.

## 3. Architecture & Compliance
* **Tenant Model:** Single-clinic architecture designed with foreign-key isolation to allow seamless scaling into multi-clinic support in future versions.
* **Data Security:** Encrypted sensitive medical records, immutable audit logging for clinical actions, and strict session handling.

## 4. Role-Based Access Control (RBAC)
1. **Super Admin:** System oversight, global configurations, security audits.
2. **Clinic Admin:** Staff management, scheduling oversight, operational reporting.
3. **Practitioner / Doctor:** Patient diagnostics, biomarker review, prescription management.
4. **Patient:** Portal access, appointment booking, personal biomarker history view.

## 5. Core Data Models & Schema Roadmap
* **Users & Authentication:** Managed via Laravel Fortify / Jetstream security features (users, sessions, two_factor_auth).
* **Patients:** Demographic data, medical history, emergency contacts linked to user accounts.
* **Appointments:** Scheduling system linking patients to practitioners with status tracking (scheduled, completed, cancelled).
* **Biomarkers / Lab Results:** Structured storage for longevity metrics (e.g., metabolic panels, hormone levels, cardiovascular markers).
* **Prescriptions & Protocols:** Custom longevity interventions and medical notes.