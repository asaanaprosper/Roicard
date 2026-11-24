<?php

namespace App\Support;

final class PasswordPolicy
{
    /**
     * Minimum account password policy used by registration and every later
     * password set/reset path.
     *
     * @return array<int, string>
     */
    public static function rules(bool $confirmed = true): array
    {
        $rules = [
            'required',
            'string',
            'min:8',
            'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*[\d\W]).{8,}$/',
        ];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }

    public static function messages(): array
    {
        return [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number or special character.',
            'new_password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number or special character.',
        ];
    }
}
