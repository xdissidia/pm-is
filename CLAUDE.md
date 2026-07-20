# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

PAGASA PMIS — a project management system forked from [LaraCollab](https://github.com/vstruhar/lara-collab) and customized for PAGASA (custom SSO, task priorities, multi-assignee tasks). Laravel 11 backend, React 18 (JSX, not TypeScript) frontend, glued with Inertia. UI is built on Mantine v7. Runs locally under XAMPP on Windows (app URL `https://pm.pagasa.ict`).

## Commands

- `npm run dev` — Vite dev server (HMR)
- `npm run build` — production frontend build
- `npm run format` — Prettier over `resources/js`
- `vendor/bin/pint` — PHP formatting (Laravel preset)
- `php artisan test` — run tests (Pest; expects a MySQL database named `testing`)
- `php artisan test --filter=TaskPriorityTest` — run a single test
- `php artisan migrate --seed` — migrate + seed (seeder creates admin user from `ADMIN_*` env vars)

Clockwork is installed for backend debugging (`/clockwork`).

## Architecture

### Backend (`app/`)

- **Routes**: everything app-facing is in `routes/web.php` behind `auth:sanctum`; login/SSO/password routes in `routes/auth.php`. Nested task resources use `->scopeBindings()`. There is no meaningful REST API — Inertia responses only.
- **Controllers are thin**; create/update logic lives in single-purpose Action classes (`app/Actions/{Task,User,Invoice,...}/CreateX.php`, `UpdateX.php`) and cross-cutting logic in `app/Services` (`NotificationService`, `PermissionService`, `ProjectService`, `UserMentionService`, `InvoiceService`).
- **Authorization**: spatie/laravel-permission roles + permissions, enforced via Policies (one per model in `app/Policies`). Roles/permissions are seeded through `PermissionService`.
- **Model conventions** (most models combine these):
  - `joelbutcher/laravel-archivable` — models are archived (`archived_at`), not deleted. Nearly every resource has a matching `POST .../{id}/restore` route and an "archived" filter in the UI.
  - `lacodix/laravel-model-filter` — query filtering via `$filters` on models plus custom filter classes in `app/Models/Filters`.
  - `spatie/eloquent-sortable` — `order_column` on task groups/tasks; drag-reorder hits dedicated `reorder`/`move` endpoints.
  - `owen-it/laravel-auditing` — audited models feed the Activity page.
- **Side effects** happen in Observers (`app/Observers` for Task, TaskUser, Comment, Project) and event listeners (e.g. `UserCreated` → email with credentials). Notifications (`app/Notifications`) are queued (see `App\Enums\Queue`) and broadcast over Pusher websockets.
- **Tasks**: many-to-many assignees via `TaskUser` pivot model, priorities (`TaskPriority`), labels, subscribers, time logs (manual + timer), attachments, and pricing type (hourly vs fixed, `App\Enums\PricingType`).

### Authentication / SSO

`App\Http\Requests\Auth\LoginRequest::authenticate()` first attempts **PAGASA SSO** (`POST https://sso.pagasa.co/api/v1/auth` with the submitted credentials). On success it matches a user by `active_directory_guid`, falls back to matching by email (backfilling the GUID), and otherwise auto-creates a user with the `Team Member` role via `CreateUser`. Only if SSO fails does it fall back to local `Auth::attempt`. Google Socialite login also exists.

### Frontend (`resources/js/`)

- Inertia pages in `pages/` mirror controller names (`Inertia::render('Projects/Index')`). `@/` aliases `resources/js` (see `jsconfig.json`).
- Routing uses Ziggy's `route()` helper — Laravel route names work in JS.
- **State**: zustand stores in `hooks/store/` (`useTasksStore`, `useTaskGroupsStore`, `useTaskDrawerStore`, `useTaskFiltersStore`, `useNotificationsStore`); mutations use immer. Task board drag-and-drop uses `@hello-pangea/dnd`.
- **Auth/permissions**: `HandleInertiaRequests` shares `auth.user` (with roles + flattened permission names), latest notifications, roles list, and flash messages. Components check access with `useAuthorization()`'s `can('permission name')` / `isAdmin()`.
- **Real-time**: `useWebSockets` + Laravel Echo/Pusher for live task/notification updates.
- Rich text (task descriptions, comments, mentions) is Tiptap via `components/RichTextEditor`.

## Notes

- The deployed branch is `production`; `main` is the default branch for PRs.
- Prettier is the JS formatter and Pint the PHP formatter — run them rather than hand-formatting.
