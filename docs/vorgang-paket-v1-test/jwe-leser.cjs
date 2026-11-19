// Unabhängiger Testleser für das vereinbarte JWE-Format. Kein Produktivabholer.
const assert = require('node:assert/strict');
const { webcrypto: { subtle } } = require('node:crypto');
const { readFileSync } = require('node:fs');

async function main() {
    const { jwe, key } = JSON.parse(readFileSync(0, 'utf8'));
    const parts = jwe.split('.');
    assert.equal(parts.length, 5);
    assert.ok(parts.every(part => /^[A-Za-z0-9_-]+$/.test(part)));
    const [header, encryptedKey, iv, ciphertext, tag] = parts.map(part => {
        const bytes = Buffer.from(part, 'base64url');
        assert.equal(bytes.toString('base64url'), part);
        return bytes;
    });
    assert.deepEqual(JSON.parse(header), {
        typ: 'kienzlefon-paket-v1+jwe',
        cty: 'application/zip',
        alg: 'RSA-OAEP-256',
        enc: 'A256GCM',
    });
    assert.equal(iv.length, 12);
    assert.equal(tag.length, 16);
    const rsa = await subtle.importKey('jwk', key, { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['decrypt']);
    const cek = await subtle.decrypt({ name: 'RSA-OAEP' }, rsa, encryptedKey);
    assert.equal(cek.byteLength, 32);
    const aes = await subtle.importKey('raw', cek, 'AES-GCM', false, ['decrypt']);
    const zip = await subtle.decrypt({
        name: 'AES-GCM',
        iv,
        additionalData: Buffer.from(parts[0], 'ascii'),
        tagLength: 128,
    }, aes, Buffer.concat([ciphertext, tag]));
    process.stdout.write(Buffer.from(zip));
}

main().catch(error => {
    process.stderr.write(`JWE-Prüfung fehlgeschlagen (${error.name}).\n`);
    process.exitCode = 1;
});
