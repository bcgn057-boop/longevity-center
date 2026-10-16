<?php

namespace App\Http\Controllers\Admin;

use App\Accounts\UserAccounts;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\LabPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrators managing accounts: create with a role (and lab partner for
 * lab users), change the name, role or partner, deactivate and reactivate.
 * Authorized on the routes and by UserPolicy; account changes are audited
 * by the user observer.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserAccounts $accounts) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::in(['active', 'deactivated'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $actor = $this->actor($request);

        $users = User::query()
            ->with(['role', 'labPartner:id,name'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.trim($search).'%';
                $query->where(fn (Builder $query) => $query->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->whereRelation('role', 'slug', $role))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->when(($filters['status'] ?? null) === 'deactivated', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user) => $this->present($actor, $user));

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'role' => $filters['role'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'roles' => $this->roleOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/users/Create', [
            'roles' => $this->roleOptions($this->actor($request)),
            'labPartners' => $this->partnerOptions(),
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = $this->accounts->create($request->details());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account created. An email has been sent to :email to set a password.', ['email' => $user->email])]);

        return to_route('admin.users.index');
    }

    public function edit(Request $request, User $user): Response
    {
        $actor = $this->actor($request);

        return Inertia::render('admin/users/Edit', [
            'account' => $this->present($actor, $user),
            'roles' => $this->roleOptions($actor),
            'labPartners' => $this->partnerOptions(),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->accounts->update($this->actor($request), $user, $request->details());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account updated.')]);

        return to_route('admin.users.index');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->accounts->deactivate($this->actor($request), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name can no longer sign in.', ['name' => $user->name])]);

        return back();
    }

    public function reactivate(User $user): RedirectResponse
    {
        $this->accounts->reactivate($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name can sign in again.', ['name' => $user->name])]);

        return back();
    }

    public function invite(User $user): RedirectResponse
    {
        abort_if($user->isDeactivated(), 409, 'Reactivate the account first.');

        $this->accounts->invite($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('A link to set a password has been sent to :email.', ['email' => $user->email])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $actor, User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->slug->value,
            'role_label' => $user->role?->slug->label() ?? 'No role',
            'lab_partner_id' => $user->lab_partner_id,
            'lab_partner' => $user->labPartner?->name,
            'verified' => $user->hasVerifiedEmail(),
            'deactivated' => $user->isDeactivated(),
            'is_you' => $actor->is($user),
            'can' => [
                'update' => Gate::forUser($actor)->allows('update', $user),
                'deactivate' => Gate::forUser($actor)->allows('deactivate', $user) && $actor->isNot($user),
            ],
        ];
    }

    /**
     * Roles, marked with whether the actor may assign each.
     *
     * @return array<int, array{value: string, label: string, assignable: bool}>
     */
    private function roleOptions(?User $actor = null): array
    {
        return array_map(fn (UserRole $role) => [
            'value' => $role->value,
            'label' => $role->label(),
            'assignable' => $actor === null || Gate::forUser($actor)->allows('assignRole', [User::class, $role]),
        ], UserRole::cases());
    }

    /**
     * @return array<int, array{id: int, name: string, tests: array<int, array{code: string, label: string}>}>
     */
    private function partnerOptions(): array
    {
        return LabPartner::query()->active()->orderBy('name')->get()->map->toOption()->all();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
