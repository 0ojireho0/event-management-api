<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    public function update(UpdatePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => $request->validated('password'),
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
