<?php
// Create a user from the command line (e.g. the first administrator).
//
// Usage: php scripts/create-user.php <username> <email> [--admin] [--name="Display Name"]
// The password is read from the terminal without echo. Accounts created here
// are considered verified (no confirmation email).

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\User;

$args = array_slice($argv, 1);
$isAdmin = in_array('--admin', $args, true);
$displayName = '';
$positional = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--name=')) {
        $displayName = substr($arg, 7);
    } elseif (!str_starts_with($arg, '--')) {
        $positional[] = $arg;
    }
}

if (count($positional) !== 2) {
    fwrite(STDERR, "Usage: php {$argv[0]} <username> <email> [--admin] [--name=\"Display Name\"]\n");
    exit(1);
}
[$username, $email] = $positional;

if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
    fwrite(STDERR, "Invalid username: 3-64 characters among letters, digits, '.', '_' and '-'\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address\n");
    exit(1);
}

function readPassword(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $password;
}

$password = readPassword('Password: ');
if (strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters\n");
    exit(1);
}
if (readPassword('Confirm password: ') !== $password) {
    fwrite(STDERR, "Passwords do not match\n");
    exit(1);
}

Config::load(dirname(__DIR__));
$dbh = Database::get();

try {
    $dbh->beginTransaction();
    $sth = $dbh->prepare(
        'INSERT INTO users (username, email, email_verified_at, display_name, password_hash, password_changed_at)
         VALUES (?, ?, NOW(), ?, ?, NOW())'
    );
    $sth->execute([$username, $email, $displayName ?: $username, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $dbh->lastInsertId();

    if ($isAdmin) {
        $dbh->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)')
            ->execute([User::ADMIN_GROUP_ID, $userId]);
    }
    $dbh->commit();
} catch (PDOException $e) {
    $dbh->rollBack();
    // 23000: integrity constraint violation (duplicate username or email)
    $message = $e->getCode() === '23000' ? 'A user with this username or email already exists' : $e->getMessage();
    fwrite(STDERR, "Error: $message\n");
    exit(1);
}

echo "✔ User '$username' created with ID $userId" . ($isAdmin ? ' (admin)' : '') . "\n";
