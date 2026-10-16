<?php

declare(strict_types=1);

namespace App\Domain\Mail\Mailables;

use App\Domain\Documents\Branding\Branding;

/**
 * View model for the branded mail layout: colors, logo and the company footer (§8.5 branding
 * applied to emails). Colors are validated hex values from Branding; text in a brand color and the
 * label on a brand-colored button use the readable variants (4.5:1, M3 review #14).
 */
final readonly class MailLayout
{
    /** Company details shown in the footer, in this order. */
    private const array FOOTER_KEYS = ['address', 'phone', 'email', 'website', 'vat_id', 'tax_id', 'legal_footer'];

    /** @param  list<string>  $footer */
    public function __construct(
        public string $primaryColor,
        public string $accentColor,
        public string $companyName,
        public ?string $logoCid,
        public array $footer,
        public string $primaryText = '#1d4ed8',
        public string $onPrimary = '#ffffff',
    ) {}

    public static function from(Branding $branding, ?string $logoCid): self
    {
        $footer = [];
        foreach (self::FOOTER_KEYS as $key) {
            $value = $branding->company[$key] ?? null;
            if ($value !== null) {
                $footer[] = in_array($key, ['vat_id', 'tax_id'], true) ? __('mail.layout.'.$key, ['value' => $value]) : $value;
            }
        }

        return new self(
            $branding->primaryColor, $branding->accentColor, $branding->companyName, $logoCid, $footer,
            $branding->primaryText(), $branding->onPrimary(),
        );
    }
}
