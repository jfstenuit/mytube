<?php
namespace Tests\Support;

/** Runs tests/bin/mail-sink.php in the background and reads the messages it captured */
class MailSink
{
    /** @var resource|null */
    private $process = null;

    public function __construct(public readonly int $port, private string $dir)
    {
    }

    public function start(): void
    {
        $this->clear();
        $this->process = proc_open(
            [PHP_BINARY, MYTUBE_ROOT . '/tests/bin/mail-sink.php', (string) $this->port, $this->dir],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($this->process) || trim((string) fgets($pipes[1])) !== 'ready') {
            throw new \RuntimeException('The test mail sink could not start on port ' . $this->port);
        }
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function clear(): void
    {
        @mkdir($this->dir, 0777, true);
        foreach (glob($this->dir . '/*.eml') ?: [] as $file) {
            unlink($file);
        }
    }

    /** @return array<int, array{to: string, subject: string, body: string}> oldest first */
    public function messages(): array
    {
        $messages = [];
        foreach (glob($this->dir . '/*.eml') ?: [] as $file) {
            $raw = str_replace("\r\n", "\n", file_get_contents($file));
            [$head, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');
            preg_match('/^X-Rcpt: (.*)$/m', $head, $to);
            preg_match('/^Subject: (.*(?:\n[ \t].*)*)$/m', $head, $subject);
            if (preg_match('/^Content-Transfer-Encoding: quoted-printable$/mi', $head)) {
                $body = quoted_printable_decode($body);
            }
            $messages[] = [
                'to' => trim($to[1] ?? '', '<> '),
                'subject' => iconv_mime_decode(preg_replace('/\n[ \t]+/', ' ', $subject[1] ?? ''), 0, 'UTF-8'),
                'body' => $body,
            ];
        }
        return $messages;
    }

    /** Messages sent to $address */
    public function to(string $address): array
    {
        return array_values(array_filter($this->messages(), fn ($m) => strcasecmp($m['to'], $address) === 0));
    }

    /** First link in a message body starting with $urlPrefix */
    public static function link(array $message, string $urlPrefix): ?string
    {
        return preg_match('#' . preg_quote($urlPrefix, '#') . '\S*#', $message['body'], $m) ? $m[0] : null;
    }
}
