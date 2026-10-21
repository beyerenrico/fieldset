<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Domains;

/**
 * Public suffixes an organization can never claim as an SSO domain (ADR 0036). Every single-label name
 * (a TLD) is one; multi-label suffixes come from this curated excerpt of the Public Suffix List
 * (common second-level registries and shared hosting domains). The DNS TXT proof is the actual control:
 * nobody but the registry can publish a record at `_fieldset-verification.co.uk`.
 */
final class PublicSuffixes
{
    /** @var list<string> */
    private const array MULTI_LABEL = [
        // Second-level registries
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'ac.uk', 'gov.uk', 'nhs.uk', 'sch.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'asn.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'govt.nz', 'ac.nz',
        'co.jp', 'ne.jp', 'or.jp', 'ac.jp', 'go.jp',
        'co.kr', 'or.kr', 'ac.kr', 'go.kr',
        'com.cn', 'net.cn', 'org.cn', 'gov.cn', 'edu.cn',
        'com.hk', 'org.hk', 'com.tw', 'org.tw', 'com.sg', 'edu.sg', 'gov.sg', 'com.my',
        'co.in', 'net.in', 'org.in', 'firm.in', 'gen.in', 'ind.in', 'ac.in', 'gov.in',
        'co.za', 'org.za', 'gov.za', 'ac.za', 'web.za',
        'com.br', 'net.br', 'org.br', 'gov.br', 'com.mx', 'org.mx', 'gob.mx', 'com.ar', 'gob.ar', 'com.co', 'com.pe',
        'com.tr', 'org.tr', 'gov.tr', 'co.il', 'org.il', 'ac.il', 'com.ua', 'org.ua', 'com.pl', 'org.pl', 'net.pl',
        'co.at', 'or.at', 'gv.at', 'ac.at', 'com.es', 'org.es', 'gob.es', 'com.pt', 'com.gr', 'co.it',
        'com.ru', 'org.ru', 'com.de', 'eu.com', 'uk.com', 'us.com', 'de.com',
        // Shared hosting and platform domains (private section of the PSL)
        'github.io', 'gitlab.io', 'herokuapp.com', 'vercel.app', 'netlify.app', 'pages.dev', 'workers.dev',
        'web.app', 'firebaseapp.com', 'appspot.com', 'blogspot.com', 'azurewebsites.net', 'cloudapp.net',
        'azurestaticapps.net', 'cloudfront.net', 'amazonaws.com', 'elasticbeanstalk.com', 'onrender.com',
        'fly.dev', 'glitch.me', 'ngrok.io', 'ngrok-free.app', 'repl.co', 'wordpress.com', 'wixsite.com',
        'squarespace.com', 'myshopify.com', 'onmicrosoft.com', 'sharepoint.com', 'dyndns.org', 'no-ip.org',
        'duckdns.org', 'nip.io', 'sslip.io',
    ];

    /** Expects a normalized (lower-case, punycode) name. */
    public static function isPublicSuffix(string $domain): bool
    {
        return ! str_contains($domain, '.') || in_array($domain, self::MULTI_LABEL, true);
    }
}
