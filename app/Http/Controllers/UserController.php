<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\Users\ResetPasswordRequest;
use App\Http\Requests\Users\UserRequest;
use App\Models\Department;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * User management (routes behind `can:manage-users`).
 */
class UserController extends Controller
{
    public const PER_PAGE = 15;

    public function __construct(private readonly UserManagementService $users) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $role = Role::tryFrom((string) $request->query('role', ''));

        $users = User::query()
            ->with('department')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%')))
            ->when($role !== null, fn ($q) => $q->where('role', $role->value))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $actor = $request->user();
        $deletionBlocked = $users->getCollection()
            ->mapWithKeys(fn (User $user) => [$user->id => $this->users->deletionBlockedReason($actor, $user)]);

        return view('users.index', [
            'users' => $users,
            'search' => $search,
            'role' => $role,
            'deletionBlocked' => $deletionBlocked,
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new User(['role' => Role::Viewer, 'is_active' => true]));
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = $this->users->create($request->validated());

        return redirect()->route('users.index')
            ->with('status', __('User :name has been created.', ['name' => $user->name]));
    }

    public function edit(Request $request, User $user): View
    {
        return $this->form($request, $user);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->users->update($request->user(), $user, $request->validated());

        return redirect()->route('users.index')
            ->with('status', __('User :name has been updated.', ['name' => $user->name]));
    }

    public function resetPassword(ResetPasswordRequest $request, User $user): RedirectResponse
    {
        // The own password is changed under "My account", which asks for the current
        // password (a reset here would also end the admin's own session).
        if ($request->user()->is($user)) {
            return redirect()->route('account.edit')
                ->with('error', __('Change your own password here, under "My account".'));
        }

        $this->users->resetPassword($user, $request->validated('password'));

        return redirect()->route('users.edit', $user)
            ->with('status', __('The password of :name has been reset.', ['name' => $user->name]));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        try {
            $this->users->delete($request->user(), $user);
        } catch (ValidationException $e) {
            return redirect()->route('users.index')->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('users.index')
            ->with('status', __('User :name has been deleted.', ['name' => $user->name]));
    }

    private function form(Request $request, User $user): View
    {
        return view('users.form', [
            'user' => $user,
            'departments' => $this->departments(),
            'roles' => Role::cases(),
            'editing' => $user->exists,
            'self' => $user->exists && $user->is($request->user()),
            'selectedRole' => old('role', $user->role?->value),
        ]);
    }

    private function departments()
    {
        return Department::orderBy('dept_name')->get(['dept_no', 'dept_name']);
    }
}
