<?php

namespace Tests\Feature;

use Tests\TestCase;

class CaptchaTest extends TestCase
{
    public function test_correct_answer_is_accepted_only_once(): void
    {
        $challenge = $this->getJson('/api/captcha/challenge')
            ->assertOk()
            ->assertJsonStructure(['challenge_id', 'question'])
            ->json();
        $answer = $this->correctAnswer($challenge);
        $payload = [
            'challenge_id' => $challenge['challenge_id'],
            'answer' => $answer,
            'website' => '',
        ];

        $this->postJson('/api/captcha/verify', $payload)
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->postJson('/api/captcha/verify', $payload)->assertUnprocessable();
    }

    public function test_wrong_answer_and_honeypot_are_rejected(): void
    {
        $challenge = $this->getJson('/api/captcha/challenge')->assertOk()->json();
        $correctAnswer = $this->correctAnswer($challenge);
        $wrongAnswer = $correctAnswer === 18 ? 17 : $correctAnswer + 1;

        $this->postJson('/api/captcha/verify', [
            'challenge_id' => $challenge['challenge_id'],
            'answer' => $wrongAnswer,
            'website' => '',
        ])->assertUnprocessable();

        $honeypotChallenge = $this->getJson('/api/captcha/challenge')->assertOk()->json();

        $this->postJson('/api/captcha/verify', [
            'challenge_id' => $honeypotChallenge['challenge_id'],
            'answer' => $this->correctAnswer($honeypotChallenge),
            'website' => 'https://spam.example',
        ])->assertUnprocessable();
    }

    public function test_challenge_endpoint_limits_requests_per_minute(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->getJson('/api/captcha/challenge')->assertOk();
        }

        $this->getJson('/api/captcha/challenge')->assertTooManyRequests();
    }

    private function correctAnswer(array $challenge): int
    {
        $this->assertSame(1, preg_match('/^(\d+) \+ (\d+)$/', $challenge['question'], $matches));

        return (int) $matches[1] + (int) $matches[2];
    }
}
