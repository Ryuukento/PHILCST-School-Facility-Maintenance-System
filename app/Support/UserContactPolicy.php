<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class UserContactPolicy
{
    public const MIGRATION = '2026_10_29_000100_create_user_contact_claims_table';
    public const PHONE_TAKEN = 'This phone number is already registered.';
    public const EMAIL_TAKEN = 'This email address is already registered.';
    public const NOT_READY = 'Contact ownership protection is not installed. Please try again later.';

    public static function phone(?string $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^09[0-9]{9}$/D', $value)) return $value;
        if (preg_match('/^\+?63(9[0-9]{9})$/D', $value, $match)) return '0' . $match[1];
        if (preg_match('/^9[0-9]{9}$/D', $value)) return '0' . $value;
        return null;
    }

    public static function email(?string $value): string
    {
        return mb_strtolower(trim((string) $value), 'UTF-8');
    }

    /** Canonical keys only; never rewrite the user's stored/display contact fields. */
    public static function contacts(array $user): array
    {
        $keys = [];
        if (trim((string) ($user['phone'] ?? '')) !== '') {
            $phone = self::phone($user['phone']);
            if ($phone === null) throw ValidationException::withMessages(['phone' => 'Phone must be a valid Philippine mobile number.']);
            $keys['phone:' . $phone] = ['contact_type' => 'phone', 'normalized_value' => $phone];
        }
        foreach (['email', 'verified_email'] as $column) {
            $email = self::email($user[$column] ?? null);
            if ($email !== '') $keys['email:' . $email] = ['contact_type' => 'email', 'normalized_value' => $email];
        }
        ksort($keys, SORT_STRING);
        return $keys;
    }

    public static function assertReady(): void
    {
        // A failed MariaDB DDL/backfill can leave a table behind. Table existence
        // alone must not enable writes before the migration is fully recorded.
        if (!Schema::hasTable('user_contact_claims') || !Schema::hasTable('migrations')
            || !DB::table('migrations')->where('migration', self::MIGRATION)->exists()) {
            throw ValidationException::withMessages(['contact' => self::NOT_READY]);
        }
    }

    /** Pure preflight/backfill builder: detects conflicts without changing user fields. */
    public static function backfillClaims(iterable $users): array
    {
        $claims = [];
        foreach ($users as $user) {
            $user = (array) $user;
            foreach (self::contacts($user) as $key => $contact) {
                if (isset($claims[$key]) && $claims[$key]['user_id'] !== (int) $user['user_id']) {
                    throw new \RuntimeException('Conflicting contact ownership for user IDs ' . $claims[$key]['user_id'] . ' and ' . $user['user_id'] . '. Resolve separately before migration.');
                }
                $claims[$key] = $contact + ['user_id' => (int) $user['user_id']];
            }
        }
        ksort($claims, SORT_STRING);
        return array_values($claims);
    }

    public static function assertEmailAvailable(string $email, ?int $userId = null): void
    {
        self::assertAvailable(['email' => $email], $userId);
    }

    public static function assertAvailable(array $contacts, ?int $userId): void
    {
        self::assertReady();
        $desired = self::contacts($contacts);
        // Also inspect legacy/base fields: imports or test fixtures must not be
        // silently treated as unowned merely because a claim is missing.
        $columns = array_values(array_intersect(['user_id', 'phone', 'email', 'verified_email'], Schema::getColumnListing('users')));
        $others = DB::table('users')->when($userId !== null, fn ($q) => $q->where('user_id', '<>', $userId))->get($columns);
        foreach ($others as $other) {
            $owned = [];
            $phone = self::phone($other->phone ?? null);
            if ($phone !== null) $owned['phone:' . $phone] = true;
            foreach (['email', 'verified_email'] as $column) {
                $email = self::email($other->{$column} ?? null);
                if ($email !== '') $owned['email:' . $email] = true;
            }
            foreach ($desired as $key => $contact) if (isset($owned[$key])) self::conflict($contact['contact_type']);
        }
        foreach ($desired as $contact) {
            $owner = DB::table('user_contact_claims')->where($contact)->value('user_id');
            if ($owner !== null && (int) $owner !== $userId) self::conflict($contact['contact_type']);
        }
    }

    /** Caller holds the user-row lock (or has just inserted the new account). */
    public static function synchronize(int $userId, array $contacts, callable $save): mixed
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Contact claims require a user transaction.');
        self::assertAvailable($contacts, $userId);
        $desired = self::contacts($contacts);
        $all = $desired;
        foreach (DB::table('user_contact_claims')->where('user_id', $userId)->get() as $claim) {
            $all[$claim->contact_type . ':' . $claim->normalized_value] = ['contact_type' => $claim->contact_type, 'normalized_value' => $claim->normalized_value];
        }
        ksort($all, SORT_STRING);
        $obsolete = [];
        foreach ($all as $key => $contact) {
            if (isset($desired[$key])) {
                try {
                    // A collision performs a no-op on the value, NEVER the owner.
                    // This obtains a current exclusive key lock without a missing-row
                    // SELECT/gap-lock race. All transactions use the same key order.
                    DB::table('user_contact_claims')->upsert([$contact + ['user_id' => $userId]],
                        ['contact_type', 'normalized_value'], ['normalized_value']);
                } catch (QueryException $e) {
                    if (self::uniqueViolation($e)) self::conflict($contact['contact_type']);
                    throw $e;
                }
            }
            $claim = DB::table('user_contact_claims')->where($contact)->lockForUpdate()->first();
            if (isset($desired[$key]) && (!$claim || (int) $claim->user_id !== $userId)) self::conflict($contact['contact_type']);
            if (!isset($desired[$key]) && $claim && (int) $claim->user_id === $userId) $obsolete[] = $claim->id;
        }
        $result = $save();
        // Release old claims only after saving fields/audit, in the SAME transaction.
        if ($obsolete) DB::table('user_contact_claims')->where('user_id', $userId)->whereIn('id', $obsolete)->delete();
        return $result;
    }

    private static function conflict(string $type): never
    {
        throw ValidationException::withMessages([$type === 'phone' ? 'phone' : 'email' => $type === 'phone' ? self::PHONE_TAKEN : self::EMAIL_TAKEN]);
    }

    public static function uniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)
            && (in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 2067], true)
                || str_contains($exception->getMessage(), 'UNIQUE constraint failed'));
    }

    public static function publicMessage(\Throwable $exception): ?string
    {
        if ($exception instanceof ValidationException) {
            foreach (collect($exception->errors())->flatten() as $message) {
                if (in_array($message, [self::PHONE_TAKEN, self::EMAIL_TAKEN, self::NOT_READY], true)) return $message;
            }
        }
        if ($exception instanceof QueryException && self::uniqueViolation($exception)
            && preg_match('/users(?:_|\.)(?:verified_)?email/', $exception->getMessage())) return self::EMAIL_TAKEN;
        return null;
    }
}
