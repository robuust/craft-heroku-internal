<?php

namespace robuust\heroku\jobs;

use Craft;
use craft\mail\Message;
use craft\queue\BaseJob;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;
use UnexpectedValueException;
use yii\queue\RetryableJobInterface;

/**
 * Sends a prepared Contact Form notification message.
 */
final class SendContactFormMail extends BaseJob implements RetryableJobInterface
{
    /**
     * The serialized prepared message.
     */
    public string $message = '';

    /**
     * Creates a job from a fully prepared message.
     *
     * @param Message $message
     * @return self
     */
    public static function fromMessage(Message $message): self
    {
        $message = clone $message;
        $email = $message->getSymfonyEmail();
        $attachments = $email->getAttachments();

        if ($attachments) {
            // Materialize file-backed attachments before their temporary files disappear.
            $body = new ReflectionProperty(TextPart::class, 'body');
            $seekable = new ReflectionProperty(TextPart::class, 'seekable');
            $handle = property_exists(DataPart::class, 'handle')
                ? new ReflectionProperty(DataPart::class, 'handle')
                : null;

            foreach ($attachments as $index => $attachment) {
                $attachment = clone $attachment;
                $body->setValue($attachment, $attachment->getBody());
                $seekable->setValue($attachment, null);
                $handle?->setValue($attachment, null);
                $attachments[$index] = $attachment;
            }

            $storedAttachments = new ReflectionProperty(Email::class, 'attachments');
            $legacyAttachments = $storedAttachments->getValue($email);

            // Symfony 5 stores attachment definitions, while newer versions store DataPart objects.
            if (isset($legacyAttachments[0]) && is_array($legacyAttachments[0])) {
                $attachments = array_map(
                    static fn (DataPart $attachment): array => ['part' => $attachment],
                    $attachments,
                );
            }

            $storedAttachments->setValue($email, $attachments);
            (new ReflectionProperty(Email::class, 'cachedBody'))->setValue($email, null);
        }

        return new self([
            'message' => base64_encode(serialize([
                'attributes' => get_object_vars($message),
                'message' => $message,
            ])),
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
        $payload = base64_decode($this->message, true);
        if ($payload !== false) {
            $payload = unserialize($payload, ['allowed_classes' => true]);
        }

        if (!is_array($payload) || !isset($payload['attributes'], $payload['message']) ||
            !is_array($payload['attributes']) || !$payload['message'] instanceof Message) {
            throw new UnexpectedValueException('The queued Contact Form message is invalid.');
        }

        foreach ($payload['attributes'] as $name => $value) {
            $payload['message']->$name = $value;
        }

        return $payload['message'];
    }
}
