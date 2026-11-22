<?php
declare(strict_types=1);
namespace ServiFlow\Support;

final class Input
{
    public static function text(mixed $value, int $max, bool $required = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new HttpError(422, 'Confira os dados do formulário.');
        }
        $value = trim($value);
        if (mb_strlen($value) > $max || ($required && $value === '')) {
            throw new HttpError(422, "Preencha os campos obrigatórios e respeite o limite de $max caracteres.");
        }
        return $value;
    }

    public static function int(mixed $value, int $min = 1, int $max = PHP_INT_MAX): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($number === false) {
            throw new HttpError(422, 'Informe um número inteiro válido.');
        }
        return $number;
    }

    public static function choice(mixed $value, array $allowed): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new HttpError(422, 'Selecione uma opção válida.');
        }
        return $value;
    }

    public static function cents(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^(\d{1,10})(?:[.,](\d{1,2}))?$/D', trim($value), $parts)) {
            throw new HttpError(422, 'Informe um valor no formato 1234,56.');
        }
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    public static function date(mixed $value, bool $withTime = false): string
    {
        $format = $withTime ? 'Y-m-d\TH:i' : 'Y-m-d';
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!' . $format, $value) : false;
        if (!$date || $date->format($format) !== $value || (int) $date->format('Y') < 1000) {
            throw new HttpError(422, 'Informe uma data válida.');
        }
        return $date->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
    }

    public static function email(mixed $value): string
    {
        $email = strtolower(self::text($value, 190, true));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, 'Informe um e-mail válido.');
        }
        return $email;
    }

    public static function password(mixed $value): string
    {
        if (!is_string($value) || strlen($value) < 12 || strlen($value) > 72 || str_contains($value, "\0")) {
            throw new HttpError(422, 'A senha precisa ter entre 12 e 72 bytes.');
        }
        return password_hash($value, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }
}
