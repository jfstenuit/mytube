<?php
namespace App\Core;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Twig\Environment;

/**
 * Sends mail through the SMTP smarthost configured in .env.
 *
 * Messages are Twig templates in templates/mail/ defining the blocks
 * "subject" and "text", and optionally "html".
 */
class Mailer
{
    public function __construct(private Environment $twig)
    {
    }

    public function send(string $toAddress, string $toName, string $template, array $context = []): void
    {
        $tpl = $this->twig->load("mail/$template.twig");
        $context += ['site_name' => Config::get('site.name')];

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = Config::get('smtp.host');
            $mail->Port = Config::get('smtp.port');
            if (Config::get('smtp.user') !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = Config::get('smtp.user');
                $mail->Password = Config::get('smtp.pass');
            }
            $secure = Config::get('smtp.secure');
            if ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                // Plain SMTP explicitly requested: don't attempt opportunistic STARTTLS
                $mail->SMTPAutoTLS = false;
            }

            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom(Config::get('mail.from'), Config::get('mail.from_name'));
            $mail->addAddress($toAddress, $toName);
            $mail->Subject = trim($tpl->renderBlock('subject', $context));
            $text = trim($tpl->renderBlock('text', $context)) . "\n";
            if ($tpl->hasBlock('html', $context)) {
                $mail->isHTML(true);
                $mail->Body = $tpl->renderBlock('html', $context);
                $mail->AltBody = $text;
            } else {
                $mail->Body = $text;
            }
            $mail->send();
        } catch (MailerException $e) {
            throw new RuntimeException('Mail delivery failed: ' . $mail->ErrorInfo, 0, $e);
        }
    }
}
