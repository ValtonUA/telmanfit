<?php

foreach (file('/tmp/telmanfit-auth4-compose/runtime.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (! preg_match("/^export ([A-Z0-9_]+)='([^']*)'$/D", $line, $match)) {
        throw new RuntimeException('Invalid generated configuration');
    }
    putenv($match[1].'='.$match[2]);
    $_ENV[$match[1]] = $_SERVER[$match[1]] = $match[2];
}
