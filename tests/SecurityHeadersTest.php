<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$htaccess=(string)file_get_contents($root.'/public/.htaccess');
$bootstrap=(string)file_get_contents($root.'/src/bootstrap.php');

preg_match_all('/^\s*Header\s+always\s+set\s+Content-Security-Policy\s+"([^"]+)"\s*$/mi',$htaccess,$matches);
if(count($matches[1]??[])!==1){
    fwrite(STDERR,"Expected exactly one Apache Content-Security-Policy definition.\n");
    exit(1);
}
$expected="default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; upgrade-insecure-requests";
if(!hash_equals($expected,$matches[1][0])){
    fwrite(STDERR,"Content-Security-Policy differs from the reviewed self-only policy.\n");
    exit(1);
}
if(preg_match('/header\s*\(\s*["\']Content-Security-Policy:/i',$bootstrap)){
    fwrite(STDERR,"PHP must not emit a second Content-Security-Policy header.\n");
    exit(1);
}
foreach(['cdn.jsdelivr.net','fonts.googleapis.com','fonts.gstatic.com'] as $obsolete){
    if(str_contains($htaccess,$obsolete)||str_contains($bootstrap,$obsolete)){
        fwrite(STDERR,"Obsolete CSP origin remains: {$obsolete}\n");
        exit(1);
    }
}
echo "Security header policy tests passed.\n";
