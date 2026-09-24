<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScannerUserRequest;
use App\Http\Requests\UpdateScannerUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => User::query()->where('role', User::ROLE_SCANNER)->latest()->get(),
        ]);
    }

    public function store(StoreScannerUserRequest $request): JsonResponse
    {
        $user = User::create([
            ...$request->validated(),
            'role' => User::ROLE_SCANNER,
        ]);

        return response()->json(['data' => $user, 'message' => 'Scanner created successfully.'], 201);
    }

    public function update(UpdateScannerUserRequest $request, User $user): JsonResponse
    {
        $this->ensureScanner($user);

        $data = $request->validated();
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json(['data' => $user->refresh(), 'message' => 'Scanner updated successfully.']);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->ensureScanner($user);
        $user->delete();

        return response()->json(['message' => 'Scanner deleted successfully.']);
    }

    private function ensureScanner(User $user): void
    {
        abort_unless($user->isScanner(), 403);
    }
}
