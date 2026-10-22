<?php

namespace Tests\Auth6B;

use App\Services\Auth\Recovery\RecoveryDenied;
use App\Services\Auth\Recovery\RecoverySignatures;
use PHPUnit\Framework\TestCase;

class SignaturesTest extends TestCase
{
    public function test_python_canonical_vector_and_sodium_signature_reject_context_tampering(): void
    {
        $vector = json_decode(file_get_contents(__DIR__.'/Fixtures/signature-vector.json'), true, flags: JSON_THROW_ON_ERROR);
        $case = (object) $vector['case'];
        $a = $vector['approval'];
        $signatures = new RecoverySignatures;
        $this->assertSame($vector['intent'], $signatures->intent($case));
        $this->assertSame($vector['envelope'], $signatures->envelope($case, $a));
        $pair = sodium_crypto_sign_seed_keypair(str_repeat('s', 32));
        $a['signature'] = base64_encode(sodium_crypto_sign_detached($vector['envelope'], sodium_crypto_sign_secretkey($pair)));
        $signatures->verify($case, $a, sodium_crypto_sign_publickey($pair));
        $case->replace_admin_password = false;
        $this->expectException(RecoveryDenied::class);
        $signatures->verify($case, $a, sodium_crypto_sign_publickey($pair));
    }
}
