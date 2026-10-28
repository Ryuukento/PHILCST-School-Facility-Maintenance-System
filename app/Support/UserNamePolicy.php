<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UserNamePolicy
{
    public static function whitespace(string $value): string
    {
        $value = \Normalizer::normalize($value, \Normalizer::FORM_C);
        return trim(preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? '');
    }

    public static function suffix(mixed $value): string
    {
        if ($value === null || $value === '') return '';
        if (is_string($value)) {
            $value = self::whitespace($value);
            $key = mb_strtolower($value);
            $allowed = ['' => '', 'jr' => 'Jr.', 'jr.' => 'Jr.', 'sr' => 'Sr.', 'sr.' => 'Sr.',
                'ii' => 'II', 'iii' => 'III', 'iv' => 'IV', 'v' => 'V'];
            if (array_key_exists($key, $allowed)) return $allowed[$key];
        }
        throw ValidationException::withMessages(['suffix' => 'Choose None, Jr., Sr., II, III, IV, or V.']);
    }

    public static function normalize(string $value): string
    {
        $value = preg_replace('/ *, */u', ', ', self::whitespace($value));
        // Recognize only supported trailing suffix spellings. Never guess a
        // surname boundary or rewrite an unknown legacy suffix/name token.
        return preg_replace_callback('/(?<= )(jr\.?|sr\.?|ii|iii|iv|v)$/iu',
            static fn (array $match): string => self::suffix($match[1]), $value);
    }

    public static function rules(): array
    {
        return ['required', 'string', 'min:2', 'max:255', "regex:/^(?=.*\\p{L})[\\p{L}\\p{M} .,'’\\-]+$/u"];
    }

    public static function fullName(mixed $value): string
    {
        Validator::make(['full_name' => $value], ['full_name' => ['required', 'string']])->validate();
        $name = self::normalize($value);
        Validator::make(['full_name' => $name], ['full_name' => self::rules()])->validate();
        return $name;
    }

    public static function forCreation(array $input): string
    {
        if (!array_intersect(['last_name', 'first_name', 'middle_initial', 'suffix'], array_keys($input))) {
            return self::fullName($input['full_name'] ?? null);
        }
        $parts = $input;
        foreach (['last_name', 'first_name', 'middle_initial'] as $field) {
            if (isset($parts[$field]) && is_string($parts[$field])) $parts[$field] = self::whitespace($parts[$field]);
        }
        $partRules = ['required', 'string', 'max:255', "regex:/^(?=.*\\p{L})[\\p{L}\\p{M} .'’\\-]+$/u"];
        $parts = Validator::make($parts, [
            'last_name' => $partRules, 'first_name' => $partRules,
            'middle_initial' => ['nullable', 'string', 'regex:/^\p{L}\p{M}*\.?$/u'],
        ])->validate();
        $initial = !empty($parts['middle_initial']) ? mb_strtoupper(rtrim($parts['middle_initial'], '.')) . '.' : '';
        $suffix = self::suffix($input['suffix'] ?? '');
        // Structured components own the value; a conflicting full_name is
        // deliberately ignored rather than used to bypass component checks.
        return self::fullName($parts['last_name'] . ', ' . $parts['first_name']
            . ($initial !== '' ? ' ' . $initial : '') . ($suffix !== '' ? ' ' . $suffix : ''));
    }

    public static function forUpdate(mixed $value, string $stored, int $userId): string
    {
        Validator::make(['full_name' => $value], ['full_name' => ['required', 'string']])->validate();
        if (self::whitespace($value) === self::whitespace($stored)) return $stored;
        $name = self::fullName($value);
        self::assertAvailable($name, $userId);
        return $name;
    }

    public static function key(string $name): string
    {
        $name = mb_strtolower(self::normalize($name));
        $parts = explode(',', $name);
        $word = "[\\p{L}\\p{M}'’\\-]+";
        $initial = '\p{L}\p{M}*\.';
        if (count($parts) === 2) {
            [$last, $given] = array_map('trim', $parts);
            // Only the unambiguous supported simple form shares a key with
            // Given [M.] Surname. Compound names retain their comma boundary.
            if (preg_match('/^' . $word . '$/u', $last)
                && preg_match('/^(' . $word . '(?: ' . $initial . ')?)(?: (jr\.|sr\.|ii|iii|iv|v))?$/u', $given, $matches)) {
                return 'simple:' . $matches[1] . '|' . $last . '|' . ($matches[2] ?? '');
            }
            return 'comma:' . $last . '|' . $given;
        }
        if (count($parts) === 1 && preg_match('/^(' . $word . ')(?: (' . $initial . '))? (' . $word . ')(?: (jr\.|sr\.|ii|iii|iv|v))?$/u', $name, $matches)) {
            return 'simple:' . $matches[1] . (!empty($matches[2]) ? ' ' . $matches[2] : '')
                . '|' . $matches[3] . '|' . ($matches[4] ?? '');
        }
        return 'literal:' . $name;
    }

    public static function assertAvailable(string $name, ?int $excludeUserId = null): void
    {
        // This is an application precheck, not a race-proof uniqueness
        // guarantee. No full_name index or broad table lock is introduced.
        $names = DB::table('users')->when($excludeUserId !== null,
            static fn ($query) => $query->where('user_id', '<>', $excludeUserId))->pluck('full_name');
        foreach ($names as $existing) {
            if (self::matches($name, (string) $existing)) {
                throw ValidationException::withMessages(['full_name' => 'An account with this complete name already exists.']);
            }
        }
    }

    public static function matches(string $left, string $right): bool
    {
        if (self::key($left) === self::key($right)) return true;

        $left = mb_strtolower(self::normalize($left));
        $right = mb_strtolower(self::normalize($right));
        $leftComma = str_contains($left, ',');
        $rightComma = str_contains($right, ',');
        // Two explicit component assignments must not be swapped. Two bare
        // multi-word names supply no reliable boundary for a rotation.
        if ($leftComma === $rightComma) return false;

        $parts = explode(',', $leftComma ? $left : $right);
        if (count($parts) !== 2) return false;
        [$surname, $given] = array_map('trim', $parts);
        $partPattern = "/^(?=.*\\p{L})[\\p{L}\\p{M} .'’\\-]+$/u";
        if (!preg_match($partPattern, $surname) || !preg_match($partPattern, $given)) return false;

        $suffix = '';
        if (preg_match('/ (jr\.|sr\.|ii|iii|iv|v)$/u', $given, $match)) {
            $suffix = $match[0];
            $given = substr($given, 0, -strlen($suffix));
        }
        // Move whole established groups only. Given-name order and marked
        // initials remain intact; a recognized suffix stays at the end.
        $bare = $leftComma ? $right : $left;
        return $bare === $given . ' ' . $surname . $suffix
            || $bare === $surname . ' ' . $given . $suffix;
    }
}
