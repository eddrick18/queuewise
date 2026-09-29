<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminStaffController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'staff' => User::query()->where('role', 'staff')->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'email', 'role', 'is_active']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', 'max:72', Password::min(8)],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['prohibited'],
        ]);

        $staff = User::create([
            ...$validated,
            'role' => 'staff',
        ]);

        return response()->json([
            'message' => 'Staff account created successfully.',
            'staff' => $staff,
        ], 201);
    }

    public function update(Request $request, User $staff): JsonResponse
    {
        if ($staff->role !== 'staff') {
            abort(404);
        }

        $this->normalizeEmail($request);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($staff->id)],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'role' => ['prohibited'],
            'password' => ['prohibited'],
            'password_confirmation' => ['prohibited'],
        ]);

        $staff->update($validated);

        return response()->json([
            'message' => 'Staff account updated successfully.',
            'staff' => $staff,
        ]);
    }

    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
    }
}
