<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Newsletter. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Newsletter;

use Klxm\Newsletter\Engine\Mailjet\Api;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Versandweg „Mailjet (API)“ für E-Mail-Konten des Cores (Extension::mailTransport): jede Mail der Website – Formulare,
 * Bestätigungen, Benachrichtigungen – kann über die Mailjet Send API statt SMTP gehen. Zugang im Konto: Benutzer =
 * API-Schlüssel, Passwort = Secret Key. Kein zusätzliches Composer-Paket (symfony/mailjet-mailer) nötig.
 * Idee aus dem REDAXO-AddOn FriendsOfREDAXO/symfony_mailer (eigene Transporte für API-Dienste).
 */
final class MailjetTransport extends AbstractTransport
{
    public function __construct(private Api $api)
    {
        parent::__construct();
    }

    public static function fromAccount(array $acc): self
    {
        return new self(new Api((string) ($acc['user'] ?? ''), (string) ($acc['pass'] ?? '')));
    }

    protected function doSend(SentMessage $message): void
    {
        $orig = $message->getOriginalMessage();
        if (!$orig instanceof \Symfony\Component\Mime\Message) throw new TransportException('Mailjet (API): nur MIME-Nachrichten, keine Rohdaten.');
        $email = MessageConverter::toEmail($orig);
        $env = $message->getEnvelope();
        $from = $email->getFrom()[0] ?? $env->getSender();
        $headers = [];
        foreach ($email->getHeaders()->all() as $h) {
            $n = $h->getName();
            if (preg_match('~^(X-[A-Za-z0-9-]+|List-[A-Za-z-]+|Precedence)$~', $n) && !preg_match('~^X-MJ-~i', $n)) $headers[$n] = $h->getBodyAsString();
        }
        $custom = $email->getHeaders()->has('X-MJ-CustomID') ? $email->getHeaders()->get('X-MJ-CustomID')->getBodyAsString() : '';
        $att = [];
        foreach ($email->getAttachments() as $part) {
            $disp = $part->getPreparedHeaders()->getHeaderBody('Content-Disposition');
            $att[] = ['type' => $part->getMediaType() . '/' . $part->getMediaSubtype(), 'name' => (string) ($part->getFilename() ?? 'datei'), 'data' => $part->getBody(),
                'cid' => str_starts_with((string) $disp, 'inline') ? (string) ($part->getContentId() ?? '') : ''];
        }
        $to = array_map(fn(Address $a) => $a->getAddress(), $email->getTo() ?: $env->getRecipients());
        $results = [];
        foreach ($to as $i => $addr) {
            $results[] = $this->api->send([[
                'from' => $from->getAddress(), 'from_name' => $from->getName(), 'to' => $addr, 'to_name' => ($email->getTo()[$i] ?? null)?->getName() ?? '',
                'subject' => (string) $email->getSubject(), 'text' => (string) $email->getTextBody(), 'html' => (string) $email->getHtmlBody(),
                'reply_to' => ($email->getReplyTo()[0] ?? null)?->getAddress() ?? '', 'headers' => $headers, 'custom_id' => $custom,
                'cc' => $i === 0 ? array_map(fn(Address $a) => $a->getAddress(), $email->getCc()) : [],
                'bcc' => $i === 0 ? array_map(fn(Address $a) => $a->getAddress(), $email->getBcc()) : [],
                'attachments' => $att,
            ]])[0] ?? ['ok' => false, 'error' => 'Mailjet: keine Antwort'];
        }
        foreach ($results as $r) {
            if (!$r['ok']) throw new TransportException($r['error'] ?: 'Mailjet hat die Nachricht abgelehnt.');
        }
        if (($id = $results[0]['id'] ?? '') !== '') $message->setMessageId($id);
    }

    public function __toString(): string
    {
        return 'mailjet+api://default';
    }
}
