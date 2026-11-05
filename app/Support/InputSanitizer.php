<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalização de entradas de formulário.
 *
 * O Livewire NÃO passa pelo middleware TrimStrings do Laravel, então espaços
 * (inclusive invisíveis: NBSP, zero-width, BOM — comuns ao colar ou autocompletar)
 * chegavam crus à validação. Aplicado globalmente a TextInput/Textarea no
 * AppServiceProvider e, nos campos com formato próprio, via ->normalizeUsing().
 */
final class InputSanitizer
{
    /** Espaços comuns + invisíveis (NBSP, espaços tipográficos, zero-width, BOM). */
    private const SPACE = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]';

    private const INVISIBLE = '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u';

    /** Remove caracteres invisíveis e espaços nas pontas. Vazio vira null. */
    public static function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = preg_replace(self::INVISIBLE, '', $value) ?? $value;
        $value = preg_replace('/^'.self::SPACE.'+|'.self::SPACE.'+$/u', '', $value) ?? $value;

        return $value === '' ? null : $value;
    }

    /** "Suporte N1 " → "suporte-n1"; com $separator "_" → "suporte_n1". */
    public static function slug(mixed $value, string $separator = '-'): ?string
    {
        $value = self::clean($value);

        if ($value === null) {
            return null;
        }

        $slug = Str::slug((string) $value, $separator);

        return $slug === '' ? (string) $value : $slug;
    }

    /**
     * Aceita "empresa.com.br", "https://www.empresa.com.br/contato" ou "ana@empresa.com.br"
     * e devolve apenas "empresa.com.br".
     */
    public static function domain(mixed $value): ?string
    {
        $value = self::clean($value);

        if ($value === null) {
            return null;
        }

        $value = mb_strtolower((string) $value);

        if (str_contains($value, '@')) {
            $value = Str::afterLast($value, '@');
        }

        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value) ?? $value;
        $value = Str::before(Str::before($value, '/'), '?');
        $value = Str::before($value, ':');

        return Str::startsWith($value, 'www.') ? Str::after($value, 'www.') : $value;
    }

    /** "(11) 99999-9999" → "+5511999999999" (E.164); sem DDI usa o padrão configurado. */
    public static function phone(mixed $value): ?string
    {
        $value = self::clean($value);

        if ($value === null) {
            return null;
        }

        $hasPlus = str_starts_with(ltrim((string) $value), '+');
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if ($digits === '') {
            return (string) $value; // deixa a validação apontar o erro
        }

        if ($hasPlus) {
            return '+'.$digits;
        }

        $country = (string) config('helpdesk.default_country_code', '55');
        $digits = ltrim($digits, '0');

        return '+'.(str_starts_with($digits, $country) && strlen($digits) > 11 ? $digits : $country.$digits);
    }
}
