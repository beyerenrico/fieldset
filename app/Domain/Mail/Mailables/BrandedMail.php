<?php

declare(strict_types=1);

namespace App\Domain\Mail\Mailables;

use App\Domain\Documents\Branding\Branding;
use App\Domain\Identity\Models\Organization;
use App\Domain\Mail\Delivery\DeliveryPlan;
use App\Domain\Mail\Delivery\DocumentDelivery;
use App\Domain\Mail\Delivery\DocumentFile;
use App\Domain\Mail\Templates\MailTemplate;
use App\Domain\Mail\Templates\MarkdownRenderer;
use App\Domain\Mail\Templates\TokenReplacer;
use finfo;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Base for all organization mail (§8.8): a tenant-editable subject and Markdown body with tokens,
 * rendered in the organization's branding (logo embedded inline, primary color, company footer),
 * plus documents as attachments or download links. Sent through DeliverMail only — never queued
 * directly — so every mail is logged and gets the organization's sender and transport.
 */
class BrandedMail extends Mailable
{
    public const string LOGO_CID = 'organization-logo@mail.fieldset';

    /** Logo types every mail client displays. */
    private const array LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif'];

    /**
     * @param  array<string, string>  $tokens
     * @param  DeliveryPlan|null  $documents  replaced only by refreshDocumentLinks()
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly MailTemplate $template,
        public readonly array $tokens = [],
        public ?DeliveryPlan $documents = null,
        ?string $locale = null,
    ) {
        if ($locale !== null) {
            $this->locale($locale);
        }
    }

    /** The template to render; subclasses with translated texts resolve it in the mail locale. */
    public function template(): MailTemplate
    {
        return $this->template;
    }

    /**
     * Defaults (organization, first recipient), then caller tokens, then document tokens.
     *
     * @return array<string, string>
     */
    public function allTokens(): array
    {
        $recipient = $this->to[0]['name'] ?? null;

        return [
            'org.name' => $this->organization()->name,
            'recipient.name' => is_string($recipient) ? $recipient : '',
            ...$this->tokens,
            ...($this->documents?->tokens() ?? []),
        ];
    }

    public function subjectLine(): string
    {
        return app(TokenReplacer::class)->plain($this->template()->subject, $this->allTokens());
    }

    /** The subject as the recipient will see it (rendered in the mail's locale). */
    public function localizedSubject(): string
    {
        return $this->withLocale($this->locale, fn (): string => $this->subjectLine());
    }

    /** Sender and reply-to are applied by OrganizationMailer at send time and mirrored here. */
    public function envelope(): Envelope
    {
        $from = $this->from[0] ?? null;

        return new Envelope(
            from: $from === null ? null : new Address($from['address'], $from['name'] ?? null),
            replyTo: array_map(static fn (array $r): Address => new Address($r['address'], $r['name'] ?? null), $this->replyTo),
            subject: $this->subjectLine(),
            using: [$this->embedLogo(...)],
        );
    }

    public function content(): Content
    {
        $replacer = app(TokenReplacer::class);
        $tokens = $this->allTokens();
        $body = $this->template()->body;
        $branding = Branding::forOrganization($this->organization());

        return new Content(view: 'mail.branded', text: 'mail.branded-text', with: [
            'subjectLine' => $this->subjectLine(),
            'bodyHtml' => new HtmlString(app(MarkdownRenderer::class)->toHtml($replacer->markdown($body, $tokens))),
            'bodyText' => $replacer->plain($body, $tokens),
            'layout' => MailLayout::from($branding, $this->logo($branding) === null ? null : self::LOGO_CID),
            'links' => $this->documents->links ?? [],
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(
            static fn (DocumentFile $file): Attachment => Attachment::fromStorageDisk($file->disk, $file->path)
                ->as($file->filename)->withMime($file->mime),
            $this->documents->attachments ?? [],
        );
    }

    /** Extends the links to now + TTL before a delivery attempt (DeliverMail), so tokens and buttons stay valid. */
    public function refreshDocumentLinks(DocumentDelivery $delivery): void
    {
        if ($this->documents instanceof DeliveryPlan) {
            $this->documents = $delivery->refresh($this->documents);
        }
    }

    public function hasDocuments(): bool
    {
        return $this->documents instanceof DeliveryPlan && ! $this->documents->isEmpty();
    }

    protected function organization(): Organization
    {
        return Organization::query()->findOrFail($this->organizationId);
    }

    /** Adds the logo once as an inline part referenced by cid (idempotent per message). */
    private function embedLogo(Email $email): void
    {
        foreach ($email->getAttachments() as $part) {
            if ($part->hasContentId() && $part->getContentId() === self::LOGO_CID) {
                return;
            }
        }

        $logo = $this->logo(Branding::forOrganization($this->organization()));
        if ($logo !== null) {
            $email->addPart(new DataPart($logo['bytes'], 'logo', $logo['mime'])->asInline()->setContentId(self::LOGO_CID));
        }
    }

    /** @return array{bytes: string, mime: string}|null */
    private function logo(Branding $branding): ?array
    {
        if ($branding->logoPath === null) {
            return null;
        }

        $disk = Storage::disk((string) config('documents.disk', 'local')); // where the branding page stores it
        $bytes = $disk->exists($branding->logoPath) ? $disk->get($branding->logoPath) : null;
        $mime = $bytes === null ? null : new finfo(FILEINFO_MIME_TYPE)->buffer($bytes);

        return is_string($bytes) && in_array($mime, self::LOGO_TYPES, true) ? ['bytes' => $bytes, 'mime' => $mime] : null;
    }
}
