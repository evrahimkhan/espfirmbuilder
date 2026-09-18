<?php
declare(strict_types=1);
$root=dirname(__DIR__);$workflow=file_get_contents($root.'/.github/workflows/production-monitor.yml');$script=file_get_contents($root.'/bin/synthetic-monitor.sh');
foreach(['schedule:','environment: production-monitor','secrets.ESPFORGE_SYNTHETIC_EMAIL','secrets.ESPFORGE_SYNTHETIC_PASSWORD','permissions:','contents: read','persist-credentials: false'] as $needle)if(!str_contains($workflow,$needle)){fwrite(STDERR,"Production monitor policy is missing {$needle}.\n");exit(1);}
foreach(['mktemp','trap ','--proto \'=https\'','--tlsv1.2','X-CSRF-Token','action=login','api/projects.php','array_key_exists("projects"','action=logout'] as $needle)if(!str_contains($script,$needle)){fwrite(STDERR,"Synthetic monitor is missing {$needle}.\n");exit(1);}
if(preg_match('/lixiy|87654321|dreameg/i',$workflow.$script)){fwrite(STDERR,"A test credential was committed to monitoring configuration.\n");exit(1);}
echo "Authenticated production monitor policy checks passed.\n";
