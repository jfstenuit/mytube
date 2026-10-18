<?php
// Minimal SMTP server for end-to-end tests: accepts every message and stores it as
// <dir>/NNNN.eml (first line: "X-Rcpt: <recipients>"). Never delivers anything.
//
// Usage: php tests/bin/mail-sink.php <port> <dir>

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} <port> <dir>\n");
    exit(1);
}
[, $port, $dir] = $argv;
@mkdir($dir, 0777, true);

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Cannot listen on port $port: $errstr\n");
    exit(1);
}
fwrite(STDOUT, "ready\n");

$counter = count(glob("$dir/*.eml") ?: []);
while ($client = @stream_socket_accept($server, -1)) {
    stream_set_timeout($client, 30);
    $send = fn (string $line) => fwrite($client, "$line\r\n");
    $send('220 mytube-test-sink ready');
    $recipients = [];
    while (($line = fgets($client)) !== false) {
        $verb = strtoupper(substr(trim($line), 0, 4));
        if ($verb === 'EHLO') {
            $send('250-mytube-test-sink');
            $send('250 8BITMIME');
        } elseif ($verb === 'MAIL') {
            $recipients = [];
            $send('250 OK');
        } elseif ($verb === 'RCPT') {
            $recipients[] = trim(substr(trim($line), 8));
            $send('250 OK');
        } elseif ($verb === 'DATA') {
            $send('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($l = fgets($client)) !== false && rtrim($l, "\r\n") !== '.') {
                $data .= str_starts_with($l, '..') ? substr($l, 1) : $l;
            }
            $counter++;
            file_put_contents(sprintf('%s/%04d.eml', $dir, $counter), 'X-Rcpt: ' . implode(',', $recipients) . "\r\n" . $data);
            $send('250 OK queued');
        } elseif ($verb === 'QUIT') {
            $send('221 bye');
            break;
        } else {
            $send('250 OK');
        }
    }
    fclose($client);
}
