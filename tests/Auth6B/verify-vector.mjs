// Синтетичний public vector: Python canonical bytes → PHP Sodium → Node Ed25519.
import { readFileSync } from 'node:fs';
import { createHash, createPublicKey, verify } from 'node:crypto';
import assert from 'node:assert/strict';
const vector = JSON.parse(readFileSync(new URL('./Fixtures/signature-vector.json', import.meta.url)));
const c = vector.case;
const intent = JSON.stringify({
  domain: 'TelmanFit/recovery/v1', deployment_id: c.deployment_id, restore_epoch: c.restore_epoch,
  case_id: c.id, target_user_id: String(c.target_user_id), target_principal_id: c.target_principal_id,
  initiator_principal_id: c.initiator_principal_id, operator_principal_id: c.operator_principal_id,
  action: c.action, replace_admin_password: c.replace_admin_password, target_role: c.target_role,
  expected_auth_version: String(c.expected_auth_version), replacement_email_mac: c.replacement_email_mac,
  intent_nonce: c.intent_nonce, policy_version: c.policy_version, registry_version: String(c.registry_version),
  created_at: c.created_at, expires_at: c.expires_at, hold_duration_seconds: 86400,
});
assert.equal(intent, vector.intent);
assert.equal(createHash('sha256').update(intent).digest('hex'), c.intent_digest);
const envelope = JSON.stringify({ domain: 'TelmanFit/recovery/approval/v1', intent_digest: c.intent_digest, ...vector.approval });
assert.equal(envelope, vector.envelope);
const key = createPublicKey({ key: Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), Buffer.from(vector.public_key, 'base64')]), format: 'der', type: 'spki' });
assert.equal(verify(null, Buffer.from(envelope), key, Buffer.from(vector.signature, 'base64')), true);
assert.equal(verify(null, Buffer.from(envelope + ' '), key, Buffer.from(vector.signature, 'base64')), false);
console.log('Python/PHP/Node canonical recovery intent and Ed25519 vector PASS');
