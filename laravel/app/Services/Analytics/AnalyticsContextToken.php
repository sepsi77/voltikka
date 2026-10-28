<?php

namespace App\Services\Analytics;

use JsonException;
use LogicException;

/**
 * Shared HMAC token format for signed analytics context: base64url(JSON).base64url(HMAC).
 *
 * Each signer passes its own purpose string, so a key derived for one event type
 * can never verify a token made for another.
 */
final class AnalyticsContextToken
{
    /** @param array<string, mixed> $payload */
    public function encode(array $payload, string $purpose): string
    {
        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new LogicException('The analytics context cannot be encoded.', previous: $exception);
        }

        $signature = hash_hmac('sha256', $json, $this->signingKey($purpose), true);

        return $this->base64UrlEncode($json).'.'.$this->base64UrlEncode($signature);
    }

    /**
     * Verifies the signature and returns the decoded payload. Callers validate the schema.
     *
     * @return array<string, mixed>
     */
    public function decode(string $token, string $purpose): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            throw new InvalidSignedContext('The signed context format is invalid.');
        }

        [$encodedPayload, $encodedSignature] = $parts;
        $json = $this->base64UrlDecode($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);
        $expectedSignature = hash_hmac('sha256', $json, $this->signingKey($purpose), true);

        if (! hash_equals($expectedSignature, $signature)) {
            throw new InvalidSignedContext('The signed context signature is invalid.');
        }

        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidSignedContext('The signed context payload is invalid.');
        }

        if (! is_array($payload)) {
            throw new InvalidSignedContext('The signed context schema is invalid.');
        }

        return $payload;
    }

    private function signingKey(string $purpose): string
    {
        $applicationKey = (string) config('app.key');

        if ($applicationKey === '') {
            throw new LogicException('APP_KEY is required to sign analytics context.');
        }

        if (str_starts_with($applicationKey, 'base64:')) {
            $decoded = base64_decode(substr($applicationKey, 7), true);

            if ($decoded === false || $decoded === '') {
                throw new LogicException('APP_KEY is not valid base64.');
            }

            $applicationKey = $decoded;
        }

        return hash_hmac('sha256', $purpose, $applicationKey, true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new InvalidSignedContext('The signed context encoding is invalid.');
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);

        if ($decoded === false) {
            throw new InvalidSignedContext('The signed context encoding is invalid.');
        }

        return $decoded;
    }
}
