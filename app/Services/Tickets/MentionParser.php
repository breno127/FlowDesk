<?php

namespace App\Services\Tickets;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Extrai @menções (por username) de notas internas.
 */
class MentionParser
{
    private const PATTERN = '/(?<![\w.@])@([A-Za-z0-9][A-Za-z0-9._-]{1,63})/u';

    /** @return list<string> */
    public function handles(string $body): array
    {
        preg_match_all(self::PATTERN, strip_tags($body), $matches);

        return array_values(array_unique(array_map(
            fn (string $h) => mb_strtolower(rtrim($h, '.-_')),
            $matches[1] ?? []
        )));
    }

    /** @return Collection<int, User> */
    public function resolve(string $body): Collection
    {
        $handles = $this->handles($body);

        if ($handles === []) {
            return collect();
        }

        return User::query()->agents()->whereIn('username', $handles)->get();
    }
}
