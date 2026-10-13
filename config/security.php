<?php

define(
    'PASSWORD_SALT',
    'ABSENSIKU_V4_2026'
);


function hashPassword($password)
{
    return hash(
        'sha256',
        PASSWORD_SALT . $password
    );
}


function verifyPassword(
    $password,
    $hash
) {
    if (is_string($hash) && (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$'))) {
        if (password_verify($password, $hash)) {
            return true;
        }
    }

    return hash_equals(
        $hash,
        hashPassword($password)
    );
}


function e($value)
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}