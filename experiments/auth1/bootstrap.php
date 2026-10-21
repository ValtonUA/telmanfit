<?php

$vendor = getenv('AUTH1_VENDOR') ?: dirname(__DIR__, 2).'/vendor';
$loader = require $vendor.'/autoload.php';
$upstream = getenv('AUTH1_UPSTREAM');
if (! $upstream) {
    throw new RuntimeException('Вкажіть AUTH1_UPSTREAM: ізольовані джерела sources.json.');
}
foreach (json_decode(file_get_contents(__DIR__.'/sources.json'), true, flags: JSON_THROW_ON_ERROR) as $package) {
    foreach ($package['paths'] as $file) {
        $path = $upstream.'/'.basename($package['repo']).'/'.$file['path'];
        $content = file_get_contents($path);
        if (sha1('blob '.strlen($content)."\0".$content) !== $file['sha']) {
            throw new RuntimeException('Upstream Git blob mismatch: '.$file['path']);
        }
    }
}
$loader->addPsr4('Laravel\\Sanctum\\', $upstream.'/sanctum/src');
$loader->addPsr4('Laravel\\Fortify\\', $upstream.'/fortify/src');
