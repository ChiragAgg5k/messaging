<?php

namespace Utopia\Messaging\Adapter\Email;

use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Response;

class Mailgun extends EmailAdapter
{
    protected const NAME = 'Mailgun';

    /**
     * @param  string  $apiKey Your Mailgun API key to authenticate with the API.
     * @param  string  $domain Your Mailgun domain to send messages from.
     * @param  bool  $isEU Whether to send messages to the EU region.
     * @param  bool  $sendInBatch Whether to send emails in batch or individually
     */
    public function __construct(
        private string $apiKey,
        private string $domain,
        private bool $isEU = false,
        private bool $sendInBatch = false
    ) {
    }

    /**
     * Get adapter name.
     */
    public function getName(): string
    {
        return static::NAME;
    }

    /**
     * Get adapter description.
     */
    public function getMaxMessagesPerRequest(): int
    {
        return 1000;
    }

    /**
     * {@inheritdoc}
     */
    protected function process(EmailMessage $message): array
    {
        $usDomain = 'api.mailgun.net';
        $euDomain = 'api.eu.mailgun.net';

        $domain = $this->isEU ? $euDomain : $usDomain;
        $response = new Response($this->getType());

        if ($this->sendInBatch) {
            return $this->processBatch($message, $domain, $response);
        }

        return $this->processIndividual($message, $domain, $response);
    }

    /**
     * Process emails in batch mode
     */
    private function processBatch(EmailMessage $message, string $domain, Response $response): array
    {
        $body = [
            'to' => \implode(',', $message->getTo()),
            'from' => "{$message->getFromName()}<{$message->getFromEmail()}>",
            'subject' => $message->getSubject(),
            'text' => $message->isHtml() ? null : $message->getContent(),
            'html' => $message->isHtml() ? $message->getContent() : null,
            'h:Reply-To: '."{$message->getReplyToName()}<{$message->getReplyToEmail()}>",
        ];

        $this->addCCAndBCC($message, $body);
        $isMultipart = $this->addAttachments($message, $body);

        $result = $this->sendRequest($domain, $body, $isMultipart);

        $this->handleResponse($result, $message, $response);

        return $response->toArray();
    }

    /**
     * Process emails individually
     */
    private function processIndividual(EmailMessage $message, string $domain, Response $response): array
    {
        foreach ($message->getTo() as $recipient) {
            $body = [
                'to' => $recipient,
                'from' => "{$message->getFromName()}<{$message->getFromEmail()}>",
                'subject' => $message->getSubject(),
                'text' => $message->isHtml() ? null : $message->getContent(),
                'html' => $message->isHtml() ? $message->getContent() : null,
                'h:Reply-To: '."{$message->getReplyToName()}<{$message->getReplyToEmail()}>",
            ];

            $this->addCCAndBCC($message, $body);
            $isMultipart = $this->addAttachments($message, $body);

            $result = $this->sendRequest($domain, $body, $isMultipart);

            if ($result['statusCode'] >= 200 && $result['statusCode'] < 300) {
                $response->setDeliveredTo(1);
                $response->addResult($recipient);
            } else {
                $errorMessage = $this->getErrorMessage($result);
                $response->addResult($recipient, $errorMessage);
            }
        }

        return $response->toArray();
    }

    /**
     * Add CC and BCC recipients to the request body
     */
    private function addCCAndBCC(EmailMessage $message, array &$body): void
    {
        if (!\is_null($message->getCC())) {
            foreach ($message->getCC() as $cc) {
                if (!empty($cc['email'])) {
                    $ccString = !empty($cc['name'])
                        ? "{$cc['name']}<{$cc['email']}>"
                        : $cc['email'];

                    $body['cc'] = !empty($body['cc'])
                        ? "{$body['cc']},{$ccString}"
                        : $ccString;
                }
            }
        }

        if (!\is_null($message->getBCC())) {
            foreach ($message->getBCC() as $bcc) {
                if (!empty($bcc['email'])) {
                    $bccString = !empty($bcc['name'])
                        ? "{$bcc['name']}<{$bcc['email']}>"
                        : $bcc['email'];

                    $body['bcc'] = !empty($body['bcc'])
                        ? "{$body['bcc']},{$bccString}"
                        : $bccString;
                }
            }
        }
    }

    /**
     * Add attachments to the request body
     */
    private function addAttachments(EmailMessage $message, array &$body): bool
    {
        $isMultipart = false;

        if (!\is_null($message->getAttachments())) {
            $size = 0;

            foreach ($message->getAttachments() as $attachment) {
                $size += \filesize($attachment->getPath());
            }

            if ($size > self::MAX_ATTACHMENT_BYTES) {
                throw new \Exception('Attachments size exceeds the maximum allowed size of ');
            }

            foreach ($message->getAttachments() as $index => $attachment) {
                $isMultipart = true;

                $body["attachment[$index]"] = \curl_file_create(
                    $attachment->getPath(),
                    $attachment->getType(),
                    $attachment->getName(),
                );
            }
        }

        return $isMultipart;
    }

    /**
     * Send the request to Mailgun API
     */
    private function sendRequest(string $domain, array $body, bool $isMultipart): array
    {
        $headers = [
            'Authorization: Basic ' . \base64_encode("api:$this->apiKey"),
        ];

        if ($isMultipart) {
            $headers[] = 'Content-Type: multipart/form-data';
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        return $this->request(
            method: 'POST',
            url: "https://$domain/v3/$this->domain/messages",
            headers: $headers,
            body: $body,
        );
    }

    /**
     * Handle the API response
     */
    private function handleResponse(array $result, EmailMessage $message, Response $response): void
    {
        $statusCode = $result['statusCode'];

        if ($statusCode >= 200 && $statusCode < 300) {
            $response->setDeliveredTo(\count($message->getTo()));
            foreach ($message->getTo() as $to) {
                $response->addResult($to);
            }
        } elseif ($statusCode >= 400 && $statusCode < 500) {
            foreach ($message->getTo() as $to) {
                $errorMessage = $this->getErrorMessage($result);
                $response->addResult($to, $errorMessage);
            }
        }
    }

    /**
     * Get error message from API response
     */
    private function getErrorMessage(array $result): string
    {
        if (\is_string($result['response'])) {
            return $result['response'];
        }

        if (isset($result['response']['message'])) {
            return $result['response']['message'];
        }

        return 'Unknown error';
    }
}
