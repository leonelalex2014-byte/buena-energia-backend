<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CaptchaController extends Controller
{
    public function challenge(): JsonResponse
    {
        $firstNumber = random_int(1, 9);
        $secondNumber = random_int(1, 9);
        $challengeId = (string) Str::uuid();

        Cache::put(
            'checkout-captcha:'.$challengeId,
            $firstNumber + $secondNumber,
            now()->addMinutes(5),
        );

        return response()->json([
            'challenge_id' => $challengeId,
            'question' => "{$firstNumber} + {$secondNumber}",
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'answer' => ['required', 'integer', 'between:2,18'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        if (($validated['website'] ?? '') !== '') {
            return $this->rejectedResponse();
        }

        $expectedAnswer = Cache::pull('checkout-captcha:'.$validated['challenge_id']);

        if (! is_int($expectedAnswer) || $expectedAnswer !== (int) $validated['answer']) {
            return $this->rejectedResponse();
        }

        return response()->json(['verified' => true]);
    }

    private function rejectedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'No pudimos verificar la solicitud. Solicita un nuevo desafío.',
        ], 422);
    }
}
