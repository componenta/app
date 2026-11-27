<?php

declare(strict_types=1);

use Componenta\App\Build\BuildStore;

require $argv[1];

$handler = static fn (): bool => true;
set_error_handler($handler);
try {
    $store = new BuildStore($argv[2]);
    $first = $store->begin();
    $first->write('test.data', ['value' => 'first']);
    $pinned = $store->publish($first);

    $store->clean();
    $unpublished = $store->load() === null;
    $afterClean = $pinned->read('test.data');

    $second = $store->begin();
    $second->write('test.data', ['value' => 'second']);
    $published = $store->publish($second);
    $selected = $store->load();

    $restored = set_error_handler(static fn (): bool => true);
    restore_error_handler();
    echo json_encode([
        'unpublished' => $unpublished,
        'pinnedAfterClean' => $afterClean,
        'newPublicationSelected' => $selected?->generation === $published->generation,
        'newValue' => $selected?->read('test.data'),
        'pinnedAfterPublish' => $pinned->read('test.data'),
        'handlerRestored' => $restored === $handler,
    ], JSON_THROW_ON_ERROR), "\n";
} finally {
    restore_error_handler();
}
