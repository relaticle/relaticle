<?php

declare(strict_types=1);

namespace Tests\Helpers;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Laravel\Passkeys\Support\WebAuthn;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * A P-256 authenticator that really signs, so a rejection proves the step under test
 * and not a malformed payload.
 */
final class PasskeyAssertionFixture
{
    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    public static function registration(string $rpId, string $origin, string $challenge): array
    {
        $assertion = self::build($rpId, $origin, $challenge);
        $record = WebAuthn::fromJson(json_encode($assertion['storedCredential'], JSON_THROW_ON_ERROR), CredentialRecord::class);
        $credentialId = $assertion['credentialId'];
        $authenticatorData = hash('sha256', $rpId, true)."\x45".pack('N', 0)
            .str_repeat("\0", 16).pack('n', strlen($credentialId)).$credentialId.$record->credentialPublicKey;

        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authenticatorData));

        return [
            'id' => self::base64Url($credentialId),
            'rawId' => self::base64Url($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64Url(json_encode([
                    'type' => 'webauthn.create',
                    'challenge' => self::base64Url($challenge),
                    'origin' => $origin,
                ], JSON_THROW_ON_ERROR)),
                'attestationObject' => self::base64Url((string) $attestation),
            ],
        ];
    }

    /**
     * @return array{credentialId: string, storedCredential: array<string, mixed>, payload: array<string, mixed>}
     */
    public static function build(
        string $rpId,
        string $origin,
        string $challenge,
        ?string $credentialId = null,
        bool $userVerified = true,
    ): array {
        $credentialId ??= random_bytes(16);

        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = openssl_pkey_get_details($key);
        $x = str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $coseKey = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($y));

        $credentialRecord = CredentialRecord::create(
            $credentialId,
            'public-key',
            [],
            'none',
            EmptyTrustPath::create(),
            Uuid::v4(),
            (string) $coseKey,
            random_bytes(16),
            0,
        );

        $authenticatorData = hash('sha256', $rpId, true).($userVerified ? "\x05" : "\x01")."\x00\x00\x00\x00";

        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => self::base64Url($challenge),
            'origin' => $origin,
        ], JSON_THROW_ON_ERROR);

        openssl_sign($authenticatorData.hash('sha256', $clientDataJson, true), $signature, $key, OPENSSL_ALGO_SHA256);

        return [
            'credentialId' => $credentialId,
            'storedCredential' => json_decode(WebAuthn::toJson($credentialRecord), true, flags: JSON_THROW_ON_ERROR),
            'payload' => [
                'credential' => [
                    'id' => self::base64Url($credentialId),
                    'rawId' => self::base64Url($credentialId),
                    'type' => 'public-key',
                    'response' => [
                        'clientDataJSON' => self::base64Url($clientDataJson),
                        'authenticatorData' => self::base64Url($authenticatorData),
                        'signature' => self::base64Url($signature),
                    ],
                ],
                'remember' => true,
            ],
        ];
    }
}
