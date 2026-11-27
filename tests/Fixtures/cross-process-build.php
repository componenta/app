<?php

declare(strict_types=1);

require $argv[1];

$root = $argv[2];
$store = new Componenta\App\Build\BuildStore($root);
$first = $store->begin();
$first->write('test.value', ['value' => 'old']);
$store->publish($first);
$pinned = $store->load();

$script = $root . '/publish.php';
file_put_contents($script, '<?php require ' . var_export($argv[1], true) . '; '
    . '$store = new \\Componenta\\App\\Build\\BuildStore(__DIR__); '
    . '$build = $store->begin(); $build->write("test.value", ["value" => "new"]); '
    . 'echo $store->publish($build)->generation;');
$process = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start publisher.'); }
$published = stream_get_contents($pipes[1]);
$errors = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0) { throw new RuntimeException('Publisher failed: ' . $errors); }
$fresh = (new Componenta\App\Build\BuildStore($root))->load();
echo json_encode([
    'freshGenerationMatches' => $fresh->generation === $published,
    'new' => $fresh->read('test.value'),
    'pinned' => $pinned->read('test.value'),
], JSON_THROW_ON_ERROR);
