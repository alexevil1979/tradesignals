<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

[$script, $username, $password] = array_pad($argv, 3, null);
if (!is_string($username) || !is_string($password) || $username === '' || mb_strlen($password) < 12) {
    fwrite(STDERR, "Использование: php bin/create_admin.php <логин> <пароль_минимум_12_символов>\n");
    exit(1);
}

$statement = $pdo->prepare(
    'INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), is_active = 1'
);
$statement->execute([
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);

echo "Администратор {$username} создан или обновлён.\n";
