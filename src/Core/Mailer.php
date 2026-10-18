<?php
namespace App\Core;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Twig\Environment;

/**
 * Sends mail through the SMTP smarthost configured in .env.
 *
 * Messages are plain-text Twig templates, templates/mail/NAME.txt.twig (the
 * .txt extension disables HTML escaping), defining the blocks "subject" and "text".
 */
class Mailer
{
    public function __construct(private Environment $twig)
    {
    }

    public function send(string $toAddress, string $toName, string $template, array $context = []): void
    {
        $tpl = $this->twig->load("mail/$template.txt.twig");
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
            $mail->XMailer = ' ';  // don't advertise the mailer library
            $mail->setFrom(Config::get('mail.from'), Config::get('mail.from_name'));
            $mail->addAddress($toAddress, $toName);
            $mail->Subject = trim($tpl->renderBlock('subject', $context));
            $mail->Body = trim($tpl->renderBlock('text', $context)) . "\n";
            $mail->send();
        } catch (MailerException $e) {
            throw new RuntimeException('Mail delivery failed: ' . $mail->ErrorInfo, 0, $e);
        }
    }
}
