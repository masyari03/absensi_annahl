<?php

$salt = 'ABSENSIKU_V4_2026';

$password = 'admin123';

echo hash('sha256', $salt . $password);