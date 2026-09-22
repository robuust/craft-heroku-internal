<?php

namespace robuust\heroku\jobs;

use Craft;
use craft\mail\Message;
use craft\queue\BaseJob;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Part\DataPart;
use UnexpectedValueException;
use yii\queue\RetryableJobInterface;

/**
 * Sends a prepared Contact Form notification message.
 */
final class SendContactFormMail extends BaseJob implements RetryableJobInterface
{
    /**
     * Headers rebuilt through the message's dedicated setters.
     */
    private const MANAGED_HEADERS = [
        'bcc',
        'cc',
        'content-transfer-encoding',
        'content-type',
        'date',
        'from',
        'message-id',
        'mime-version',
        'reply-to',
        'return-path',
        'sender',
        'subject',
        'to',
        'x-priority',
    ];

    /**
     * The prepared message data.
     *
     * @var array<string, mixed>
     */
    public array $message = [];

    /**
     * Creates a job from a fully prepared message.
     *
     * @param Message $message
     * @return self
     */
    public static function fromMessage(Message $message): self
    {
        $date = $message->getDate();

        return new self([
            'message' => [
                'attachments' => self::snapshotAttachments($message),
                'bcc' => $message->getBcc(),
                'cc' => $message->getCc(),
                'charset' => $message->getCharset(),
                'date' => $date?->format(DATE_ATOM),
                'from' => $message->getFrom(),
                'headers' => self::snapshotHeaders($message),
                'htmlBody' => $message->getHtmlBody(),
                'priority' => $message->getPriority(),
                'replyTo' => $message->getReplyTo(),
                'returnPath' => $message->getReturnPath(),
                'sender' => $message->getSender(),
                'subject' => $message->getSubject(),
                'textBody' => $message->getTextBody(),
                'to' => $message->getTo(),
            ],
        ]);
    }

    /**
     * Sends the prepared message through Craft's configured mailer.
     *
     * @param \yii\queue\Queue $queue
     * @return void
     */
    public function execute($queue): void
    {
        if (!Craft::$app->getMailer()->send($this->createMessage())) {
            throw new RuntimeException('The queued Contact Form notification could not be sent.');
        }
    }

    /**
     * Returns the maximum execution time for a delivery attempt.
     *
     * @return int
     */
    public function getTtr(): int
    {
        return 120;
    }

    /**
     * Determines whether another delivery attempt is allowed.
     *
     * @param int $attempt
     * @param \Throwable $error
     * @return bool
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3;
    }

    /**
     * Returns the queue job description.
     *
     * @return string|null
     */
    protected function defaultDescription(): ?string
    {
        return 'Sending Contact Form notification';
    }

    /**
     * Rebuilds the prepared Craft message.
     *
     * @return Message
     */
    private function createMessage(): Message
    {
        $message = (new Message())
            ->setCharset($this->message['charset'])
            ->setFrom($this->message['from'])
            ->setTo($this->message['to'])
            ->setSubject($this->message['subject'])
            ->setTextBody($this->message['textBody'])
            ->setHtmlBody($this->message['htmlBody'])
            ->setPriority($this->message['priority']);

        if ($this->message['replyTo']) {
            $message->setReplyTo($this->message['replyTo']);
        }

        if ($this->message['cc']) {
            $message->setCc($this->message['cc']);
        }

        if ($this->message['bcc']) {
            $message->setBcc($this->message['bcc']);
        }

        if ($this->message['date']) {
            $message->setDate(new DateTimeImmutable($this->message['date']));
        }

        if ($this->message['returnPath']) {
            $message->setReturnPath($this->message['returnPath']);
        }

        if ($this->message['sender']) {
            $message->setSender($this->message['sender']);
        }

        $message->setHeaders($this->message['headers']);

        foreach ($this->message['attachments'] as $attachment) {
            $content = base64_decode($attachment['content'], true);
            if ($content === false) {
                throw new UnexpectedValueException('The queued Contact Form attachment is invalid.');
            }

            $options = [
                'fileName' => $attachment['fileName'],
                'contentType' => $attachment['contentType'],
            ];

            if ($attachment['inline']) {
                $message->embedContent($content, $options);
            } else {
                $message->attachContent($content, $options);
            }
        }

        return $message;
    }

    /**
     * Captures attachment contents and metadata before upload temp files disappear.
     *
     * @param Message $message
     * @return array<int, array{content: string, contentType: string, fileName: string|null, inline: bool}>
     */
    private static function snapshotAttachments(Message $message): array
    {
        return array_map(static function (DataPart $attachment): array {
            $headers = $attachment->getPreparedHeaders();

            return [
                'content' => base64_encode($attachment->getBody()),
                'contentType' => (string)$headers->getHeaderBody('Content-Type'),
                'fileName' => $headers->getHeaderParameter('Content-Disposition', 'filename')
                    ?? $headers->getHeaderParameter('Content-Type', 'name'),
                'inline' => strtolower((string)$headers->getHeaderBody('Content-Disposition')) === 'inline',
            ];
        }, $message->getSymfonyEmail()->getAttachments());
    }

    /**
     * Captures custom headers that are not rebuilt through dedicated setters.
     *
     * @param Message $message
     * @return array<string, string[]>
     */
    private static function snapshotHeaders(Message $message): array
    {
        $headers = [];

        /** @var HeaderInterface $header */
        foreach ($message->getSymfonyEmail()->getHeaders()->all() as $header) {
            if (in_array(strtolower($header->getName()), self::MANAGED_HEADERS, true)) {
                continue;
            }

            $headers[$header->getName()][] = $header->getBodyAsString();
        }

        return $headers;
    }
}
