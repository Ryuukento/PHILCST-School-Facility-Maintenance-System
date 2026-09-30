<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AssetCodeGenerator
{
    /**
     * Builds the next unique asset code for a room_asset unit, in the form
     * {ITEM-INITIALS}-{ROOM-SLUG}-{SEQ}, e.g. "Air Conditioner" in "Room 4" → AC-R4-02.
     */
    public static function generate(string $itemName, string $roomName): string
    {
        $prefix = self::itemInitials($itemName) . '-' . self::roomSlug($roomName) . '-';
        $seq    = self::nextSequence($prefix);

        return $prefix . str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
    }

    public static function itemInitials(string $itemName): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($itemName)) ?: [], fn ($w) => $w !== ''));

        if (count($words) > 1) {
            $initials = '';
            foreach ($words as $word) {
                $initials .= strtoupper(substr($word, 0, 1));
            }
            return substr($initials, 0, 4);
        }

        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $words[0] ?? ''));
        return substr($letters, 0, 2) ?: 'XX';
    }

    public static function roomSlug(string $roomName): string
    {
        $tokens = array_values(array_filter(preg_split('/\s+/', trim($roomName)) ?: [], fn ($t) => $t !== ''));

        $slug = '';
        foreach ($tokens as $token) {
            if (preg_match('/^\d+$/', $token)) {
                $slug .= $token;
            } else {
                $letters = preg_replace('/[^A-Za-z0-9]/', '', $token);
                $slug   .= strtoupper(substr($letters, 0, 1));
            }
        }

        return $slug !== '' ? $slug : 'RM';
    }

    /**
     * Finds the highest existing sequence number for a given prefix and returns the next one.
     * Guards against collisions when multiple units of the same item are deployed to the same room.
     */
    private static function nextSequence(string $prefix): int
    {
        $existing = DB::table('items')
            ->where('asset_code', 'like', $prefix . '%')
            ->pluck('asset_code');

        $max = 0;
        foreach ($existing as $code) {
            $suffix = substr((string) $code, strlen($prefix));
            if (preg_match('/^(\d+)$/', $suffix, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }
}
