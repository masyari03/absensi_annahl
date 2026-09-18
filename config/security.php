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