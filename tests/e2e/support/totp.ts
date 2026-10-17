import { createHmac } from 'node:crypto';

// RFC 6238 TOTP (HMAC-SHA1, 30 s, 6 digits) for E2E, with node:crypto only. The Field App (Fortify) and
// the admin panel (Filament) check the same secret (ADR 0016).

/** The break-glass Owner of SSO Demo GmbH (database/seeders/SsoDemoSeeder.php). */
export const BREAK_GLASS = {
  email: 'sso-owner@demo.test',
  secret: 'KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU',
} as const;

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
const STEP_SECONDS = 30;

/** RFC 4648 base32 (no padding required, case- and space-insensitive). */
export function base32Decode(input: string): Buffer {
  const clean = input.replace(/[\s=]/g, '').toUpperCase();
  let bits = 0;
  let value = 0;
  const bytes: number[] = [];
  for (const char of clean) {
    const index = ALPHABET.indexOf(char);
    if (index < 0) throw new Error(`invalid base32 character "${char}"`);
    value = (value << 5) | index;
    bits += 5;
    if (bits >= 8) {
      bytes.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
  }
  return Buffer.from(bytes);
}

/** HOTP (RFC 4226) for a counter. */
export function hotp(key: Buffer, counter: number, digits = 6): string {
  const message = Buffer.alloc(8);
  message.writeBigUInt64BE(BigInt(counter));
  const hmac = createHmac('sha1', key).update(message).digest();
  const offset = hmac[hmac.length - 1] & 0x0f;
  const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 10 ** digits;
  return String(code).padStart(digits, '0');
}

/** TOTP (RFC 6238) of a base32 secret at a time in ms. */
export function totp(secret: string, timeMs: number = Date.now(), digits = 6): string {
  return hotp(base32Decode(secret), Math.floor(timeMs / 1000 / STEP_SECONDS), digits);
}

/**
 * A code that stays valid for at least `marginSeconds`: near the end of a window it waits for the next
 * one, so a slow submit never sends an expired code.
 */
export async function freshTotp(secret: string, marginSeconds = 5): Promise<string> {
  const remaining = STEP_SECONDS - (Math.floor(Date.now() / 1000) % STEP_SECONDS);
  if (remaining < marginSeconds) {
    await new Promise((resolve) => setTimeout(resolve, remaining * 1000 + 250));
  }
  return totp(secret);
}
