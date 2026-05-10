<?php
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.')) as $f) {
    if (!$f->isFile()) continue;
    if (pathinfo($f, PATHINFO_EXTENSION)!=='php') continue;
    $p = str_replace('\\','/',$f->getPathname());
    if (strpos($p,'/vendor/')!==false) continue;
    $out = [];
    $ret = 0;
    exec('php -l '.escapeshellarg($p).' 2>&1', $out, $ret);
    if ($ret!==0) {
        echo 'FILE: '. $p ."\n";
        foreach($out as $l) echo $l."\n";
        echo "\n";
    }
}
