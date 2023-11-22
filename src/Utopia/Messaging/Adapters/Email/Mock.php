<?php

namespace Utopia\Messaging\Adapters\Email;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\Messaging\Adapters\Email as EmailAdapter;
use Utopia\Messaging\Messages\Email;
use Utopia\Messaging\Response;

class Mock extends EmailAdapter
{
    public function getName(): string
    {
        return 'Mock';
    }

    public function getMaxMessagesPerRequest(): int
    {
        // TODO: Find real value for this
        return 1000;
    }

    /**
     * {@inheritdoc}
     *
     * @throws Exception
     * @throws \Exception
     */
    protected function process(Email $message): string
    {
        $response = new Response(0, 0, $this->getType(), []);
        $mail = new PHPMailer();
        $mail->isSMTP();
        $mail->XMailer = 'Utopia Mailer';
        $mail->Host = 'maildev';
        $mail->Port = 1025;
        $mail->SMTPAuth = false;
        $mail->Username = '';
        $mail->Password = '';
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $message->getSubject();
        $mail->Body = $message->getContent();
        $mail->AltBody = \strip_tags($message->getContent());
        $mail->setFrom($message->getFrom(), 'Utopia');
        $mail->addReplyTo($message->getFrom(), 'Utopia');
        $mail->isHTML($message->isHtml());

        foreach ($message->getTo() as $to) {
            $mail->addAddress($to);
        }

        if (! $mail->send()) {
            $response->setFailure(\count($message->getTo()));
            $response->setDetails([
                'recipient' => '',
                'status' => 'failure',
                'error' => $mail->ErrorInfo,
            ]);
        } else {
            $response->setSuccess(\count($message->getTo()));
        }

        return \json_encode($response->toArray());
    }
}
