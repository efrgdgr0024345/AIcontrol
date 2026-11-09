<?php
declare(strict_types=1);

define('AICONTROL_LOADER_TEST', true);
require dirname(__DIR__) . '/loader.php';
umask(0077);
$count = 0;
function signingCheck(bool $condition, string $label): void
{
    global $count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $count++;
}
function signingRefuses(callable $work, string $label): void
{
    try {
        $work();
    } catch (Throwable) {
        signingCheck(true, $label);
        return;
    }
    signingCheck(false, $label);
}
$temporary = sys_get_temp_dir() . '/aicontrol-signing-test-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
mkdir($temporary . '/first', 0700);
mkdir($temporary . '/second', 0700);
try {
    $first = new AIcontrolSigning($temporary . '/first', null, true);
    $second = new AIcontrolSigning($temporary . '/second', null, true);
    $public = $first->identity()['public_key'];
    signingCheck(!array_key_exists('private_key', $first->identity()), 'public identity has no private key');
    signingCheck($first->identity()['key_id'] !== $second->identity()['key_id'], 'independent installations have different identities');
    $reopened = new AIcontrolSigning($temporary . '/first', $public);
    signingCheck($reopened->identity() === $first->identity(), 'identity survives reload');
    signingCheck((fileperms($temporary . '/first/signing/identity.json') & 0777) === 0600, 'private key permissions');
    $payload = ['event' => 'update.completed', 'source_commit' => str_repeat('a', 40), 'outcome' => 'local_readiness_passed'];
    $proof = $first->record($payload);
    $decoded = AIcontrolSigning::verify($proof, $public);
    signingCheck($decoded !== null && $decoded['source_commit'] === $payload['source_commit'], 'signed receipt verifies');
    $receipt = $temporary . '/first/receipts/' . $decoded['receipt_id'] . '.json';
    signingCheck(AIcontrolInstaller::readJson($receipt) === $proof, 'signed receipt retained');
    signingCheck(AIcontrolSigning::verify($proof, $second->identity()['public_key']) === null, 'wrong pinned key refused');
    $mutated = $proof;
    $mutated['payload'] = base64_encode(AIcontrolSigning::canonical($payload + ['extra' => 'changed']));
    signingCheck(AIcontrolSigning::verify($mutated, $public) === null, 'changed payload refused');
    $mutated = $proof;
    $mutated['signature'] = base64_encode(random_bytes(64));
    signingCheck(AIcontrolSigning::verify($mutated, $public) === null, 'forged signature refused');
    $mutated = $proof;
    $mutated['public_key'] = $second->identity()['public_key'];
    signingCheck(AIcontrolSigning::verify($mutated, $public) === null, 'envelope key substitution refused');
    signingCheck(!sodium_crypto_sign_verify_detached(base64_decode($proof['signature']), base64_decode($proof['payload']), base64_decode($public)), 'signature domain separation');
    signingCheck(AIcontrolSigning::canonical(['z' => 1, 'a' => ['y' => 2, 'b' => 3]]) === AIcontrolSigning::canonical(['a' => ['b' => 3, 'y' => 2], 'z' => 1]), 'canonical nested order');
    signingRefuses(fn() => AIcontrolSigning::canonical(['cost' => 1.5]), 'ambiguous floating point refused');
    $selection = ['sha' => str_repeat('a', 40), 'manifest_hash' => str_repeat('b', 64), 'home' => '/private/panel', 'roots' => ['/public/site'], 'access_mode' => 'host', 'host_identity' => 'host-test', 'checked_at' => time(), 'correlation_id' => str_repeat('c', 32), 'ref' => 'test'];
    $selection['proof'] = $first->record(AIcontrolInstaller::selectionPayload($selection));
    AIcontrolInstaller::verifySelection($selection, $first);
    signingCheck(true, 'checked selection signature verifies');
    foreach (['sha' => str_repeat('d', 40), 'manifest_hash' => str_repeat('d', 64), 'home' => '/different', 'roots' => ['/different'], 'access_mode' => 'local', 'host_identity' => 'other', 'checked_at' => time() + 1, 'correlation_id' => 'other', 'ref' => 'other', 'bootstrap_hash' => str_repeat('d', 64), 'files' => ['changed.php' => str_repeat('e', 64)]] as $field => $value) {
        signingRefuses(fn() => AIcontrolInstaller::verifySelection(array_replace($selection, [$field => $value]), $first), 'changed selection field refused: ' . $field);
    }
    signingRefuses(fn() => new AIcontrolSigning($temporary . '/first', $second->identity()['public_key']), 'changed identity pin refused');
    $keyPath = $temporary . '/first/signing/identity.json';
    chmod($keyPath, 0644);
    clearstatcache();
    signingRefuses(fn() => new AIcontrolSigning($temporary . '/first', $public), 'unsafe key mode refused');
    chmod($keyPath, 0600);
    $key = file_get_contents($keyPath);
    unlink($keyPath);
    signingRefuses(fn() => new AIcontrolSigning($temporary . '/first', $public, true), 'lost key not replaced');
    signingCheck(!file_exists($keyPath), 'missing private key stays missing');
    file_put_contents($keyPath, $key);
    $damaged = json_decode($key, true, 8, JSON_THROW_ON_ERROR);
    $secret = base64_decode($damaged['private_key']);
    $secret[0] = chr(ord($secret[0]) ^ 1);
    $damaged['private_key'] = base64_encode($secret);
    file_put_contents($keyPath, json_encode($damaged, JSON_THROW_ON_ERROR));
    signingRefuses(fn() => new AIcontrolSigning($temporary . '/first', $public), 'damaged private key refused');
    echo "PASS: $count signing checks\n";
} finally {
    unset($first, $second, $reopened, $key, $secret, $damaged);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}
