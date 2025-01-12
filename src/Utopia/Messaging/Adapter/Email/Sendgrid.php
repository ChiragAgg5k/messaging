<?php

namespace Utopia\Messaging\Adapter\Email;

use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Response;

class Sendgrid extends EmailAdapter
{
    protected const NAME = 'Sendgrid';

    /**
     * @param  string  $apiKey Your Sendgrid API key to authenticate with the API.
     * @param  bool  $sendInBatch Whether to send emails in batch or individually
     * @return void
     */
    public function __construct(
        private string $apiKey,
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
     * Get max messages per request.
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
        $response = new Response($this->getType());

        if ($this->sendInBatch) {
            return $this->processBatch($message, $response);
        }

        return $this->processIndividual($message, $response);
    }

    /**
     * Process emails in batch mode
     */
    private function processBatch(EmailMessage $message, Response $response): array
    {
        $personalizations = [
            [
                'to' => \array_map(
                    fn ($to) => ['email' => $to],
                    $message->getTo()
                ),
                'subject' => $message->getSubject(),
            ],
        ];

        $this->addCCAndBCC($message, $personalizations[0]);
        $attachments = $this->prepareAttachments($message);

        $body = $this->prepareRequestBody($message, $personalizations, $attachments);
        $result = $this->sendRequest($body);

        $this->handleResponse($result, $message, $response);

        return $response->toArray();
    }

    /**
     * Process emails individually
     */
    private function processIndividual(EmailMessage $message, Response $response): array
    {
        $attachments = $this->prepareAttachments($message);

        foreach ($message->getTo() as $recipient) {
            $personalizations = [
                [
                    'to' => [['email' => $recipient]],
                    'subject' => $message->getSubject(),
                ],
            ];

            $this->addCCAndBCC($message, $personalizations[0]);

            $body = $this->prepareRequestBody($message, $personalizations, $attachments);
            $result = $this->sendRequest($body);

            if ($result['statusCode'] === 202) {
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
     * Add CC and BCC recipients to personalizations
     */
    private function addCCAndBCC(EmailMessage $message, array &$personalization): void
    {
        if (!\is_null($message->getCC())) {
            foreach ($message->getCC() as $cc) {
                if (!empty($cc['name'])) {
                    $personalization['cc'][] = [
                        'name' => $cc['name'],
                        'email' => $cc['email'],
                    ];
                } else {
                    $personalization['cc'][] = [
                        'email' => $cc['email'],
                    ];
                }
            }
        }

        if (!\is_null($message->getBCC())) {
            foreach ($message->getBCC() as $bcc) {
                if (!empty($bcc['name'])) {
                    $personalization['bcc'][] = [
                        'name' => $bcc['name'],
                        'email' => $bcc['email'],
                    ];
                } else {
                    $personalization['bcc'][] = [
                        'email' => $bcc['email'],
                    ];
                }
            }
        }
    }

    /**
     * Prepare attachments for the request
     */
    private function prepareAttachments(EmailMessage $message): array
    {
        $attachments = [];

        if (!\is_null($message->getAttachments())) {
            $size = 0;

            foreach ($message->getAttachments() as $attachment) {
                $size += \filesize($attachment->getPath());
            }

            if ($size > self::MAX_ATTACHMENT_BYTES) {
                throw new \Exception('Attachments size exceeds the maximum allowed size of 25MB');
            }

            foreach ($message->getAttachments() as $attachment) {
                $attachments[] = [
                    'content' => \base64_encode(\file_get_contents($attachment->getPath())),
                    'filename' => $attachment->getName(),
                    'type' => $attachment->getType(),
                    'disposition' => 'attachment',
                ];
            }
        }

        return $attachments;
    }

    /**
     * Prepare the request body
     */
    private function prepareRequestBody(EmailMessage $message, array $personalizations, array $attachments): array
    {
        $body = [
            'personalizations' => $personalizations,
            'reply_to' => [
                'name' => $message->getReplyToName(),
                'email' => $message->getReplyToEmail(),
            ],
            'from' => [
                'name' => $message->getFromName(),
                'email' => $message->getFromEmail(),
            ],
            'content' => [
                [
                    'type' => $message->isHtml() ? 'text/html' : 'text/plain',
                    'value' => $message->getContent(),
                ],
            ],
        ];

        if (!empty($attachments)) {
            $body['attachments'] = $attachments;
        }

        return $body;
    }

    /**
     * Send the request to Sendgrid API
     */
    private function sendRequest(array $body): array
    {
        return $this->request(
            method: 'POST',
            url: 'https://api.sendgrid.com/v3/mail/send',
            headers: [
                'Authorization: Bearer '.$this->apiKey,
                'Content-Type: application/json',
            ],
            body: $body,
        );
    }

    /**
     * Handle the API response
     */
    private function handleResponse(array $result, EmailMessage $message, Response $response): void
    {
        if ($result['statusCode'] === 202) {
            $response->setDeliveredTo(\count($message->getTo()));
            foreach ($message->getTo() as $to) {
                $response->addResult($to);
            }
        } else {
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

        if (!\is_null($result['response']['errors'][0]['message'] ?? null)) {
            return $result['response']['errors'][0]['message'];
        }

        return 'Unknown error';
    }
}
