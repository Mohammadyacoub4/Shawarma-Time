<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class TemporaryPasswordManager
{
    private const CACHE_PREFIX = 'temp_password_token:';
    private const TTL_SECONDS = 300; // 5 minutes

    /**
     * Store an encrypted temporary password bound to the given admin and employee.
     * Returns an unguessable one-time retrieval token.
     */
    public function store(User $admin, User $employee, string $plaintextPassword): string
    {
        $token = Str::random(40);
        $payload = [
            'admin_id' => $admin->id,
            'employee_id' => $employee->id,
            'ciphertext' => Crypt::encryptString($plaintextPassword),
        ];

        Cache::put(self::CACHE_PREFIX . $token, $payload, self::TTL_SECONDS);

        return $token;
    }

    /**
     * Atomically retrieve and delete the temporary password, verifying admin and employee ownership.
     */
    public function retrieveOnce(User $admin, User $employee, string $token): ?string
    {
        $cacheKey = self::CACHE_PREFIX . $token;
        $lockKey = $cacheKey . ':lock';

        try {
            $lock = Cache::lock($lockKey, 5);
        } catch (\Throwable) {
            $lock = null;
        }

        if (! $lock) {
            return $this->extractAndValidatePayload($cacheKey, $admin, $employee);
        }

        try {
            $result = $lock->get(function () use ($cacheKey, $admin, $employee) {
                return $this->extractAndValidatePayload($cacheKey, $admin, $employee);
            });

            return is_string($result) ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractAndValidatePayload(string $cacheKey, User $admin, User $employee): ?string
    {
        $payload = Cache::pull($cacheKey);

        if (! is_array($payload)) {
            return null;
        }

        if (($payload['admin_id'] ?? null) !== $admin->id || ($payload['employee_id'] ?? null) !== $employee->id) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($payload['ciphertext'] ?? '');
            return is_string($decrypted) ? $decrypted : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

