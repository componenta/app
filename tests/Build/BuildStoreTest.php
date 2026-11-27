<?php

declare(strict_types=1);

use Componenta\App\Build\ApplicationBuildOrchestrator;
use Componenta\App\Build\ApplicationBuilderInterface;
use Componenta\App\Build\BuildContext;
use Componenta\App\Build\BuildStore;

function removeBuildStoreTestRoot(string $root): void
{
    if (!is_dir($root)) { return; }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

it('publishes complete generations and pins readers across rebuild and clean', function (): void {
    $root = sys_get_temp_dir() . '/app_build_store_' . bin2hex(random_bytes(8));
    $store = new BuildStore($root, 'release-a');
    try {
        expect($store->load())->toBeNull();
        $first = $store->begin();
        $first->write('test.routes', ['route' => 'old']);
        $first->write('test.commands', ['command' => 'old'], 2);
        expect($store->load())->toBeNull();
        $pinned = $store->publish($first);
        expect($pinned->read('test.routes'))->toBe(['route' => 'old'])
            ->and($pinned->read('test.commands'))->toBeNull()
            ->and($pinned->read('test.commands', 2))->toBe(['command' => 'old']);

        $second = $store->begin();
        $second->write('test.routes', ['route' => 'new']);
        $second->skip('test.commands', 'contains a runtime closure');
        expect($store->load()->read('test.routes'))->toBe(['route' => 'old']);
        $published = $store->publish($second);
        expect($published->generation)->not->toBe($pinned->generation)
            ->and($store->load()->read('test.routes'))->toBe(['route' => 'new'])
            ->and($published->read('test.commands'))->toBeNull()
            ->and($published->skipped())->toBe(['test.commands' => 'contains a runtime closure'])
            ->and($pinned->read('test.routes'))->toBe(['route' => 'old']);

        $store->clean();
        $store->clean();
        expect($store->load())->toBeNull()
            ->and($pinned->read('test.routes'))->toBe(['route' => 'old'])
            ->and($published->read('test.routes'))->toBe(['route' => 'new']);
    } finally { removeBuildStoreTestRoot($root); }
});

it('keeps the old published generation when a later participant fails', function (): void {
    $root = sys_get_temp_dir() . '/app_build_failure_' . bin2hex(random_bytes(8));
    $store = new BuildStore($root);
    try {
        $old = $store->begin();
        $old->write('test.one', ['old']);
        $published = $store->publish($old);
        $failure = new DomainException('second failed');
        $first = new class implements ApplicationBuilderInterface {
            public ?BuildContext $context = null;
            public function build(BuildContext $build): void
            {
                $this->context = $build;
                $build->write('test.one', ['new']);
            }
        };
        $second = new class($failure) implements ApplicationBuilderInterface {
            public function __construct(private Throwable $failure) {}
            public function build(BuildContext $build): void { throw $this->failure; }
        };
        $orchestrator = new ApplicationBuildOrchestrator([$first, $second], $store);
        try { $orchestrator->build(); test()->fail('Failure must propagate.'); }
        catch (DomainException $caught) { expect($caught)->toBe($failure); }
        expect($store->load()->generation)->toBe($published->generation)
            ->and($store->load()->read('test.one'))->toBe(['old']);
        expect(fn () => $first->context->write('test.late', []))->toThrow(LogicException::class, 'closed');
    } finally { removeBuildStoreTestRoot($root); }
});

it('preserves an artifact named manifest through publication and rebuild', function (array $required): void {
    $root = sys_get_temp_dir() . '/app_build_manifest_artifact_' . bin2hex(random_bytes(8));
    $store = new BuildStore($root, requiredArtifacts: $required);
    try {
        $first = $store->begin();
        $first->write('manifest', ['application' => 'first']);
        $pinned = $store->publish($first);
        expect($pinned->read('manifest'))->toBe(['application' => 'first'])
            ->and($store->load()->read('manifest'))->toBe(['application' => 'first']);

        $second = $store->begin();
        $second->write('manifest', ['application' => 'second']);
        $store->publish($second);
        expect($store->load()->read('manifest'))->toBe(['application' => 'second'])
            ->and($pinned->read('manifest'))->toBe(['application' => 'first']);
    } finally { removeBuildStoreTestRoot($root); }
})->with([
    'optional artifact' => [[]],
    'required artifact' => [['manifest']],
]);

it('rejects duplicate and unsafe artifact IDs and closes published contexts', function (): void {
    $root = sys_get_temp_dir() . '/app_build_context_' . bin2hex(random_bytes(8));
    $store = new BuildStore($root);
    try {
        $build = $store->begin();
        foreach (['../escape', '/absolute', 'a/b', 'a..b', ''] as $id) {
            expect(fn () => $build->write($id, []))->toThrow(InvalidArgumentException::class);
        }
        expect(fn () => $build->write('test.bad', [], 0))->toThrow(InvalidArgumentException::class);
        $build->write('test.same', []);
        expect(fn () => $build->skip('test.same', 'duplicate'))->toThrow(LogicException::class);
        $build->skip('test.skipped', 'reason');
        expect(fn () => $build->write('test.skipped', []))->toThrow(LogicException::class);
        $store->publish($build);
        expect(fn () => $build->write('test.after', []))->toThrow(LogicException::class)
            ->and(fn () => $store->publish($build))->toThrow(LogicException::class);
    } finally { removeBuildStoreTestRoot($root); }
});

it('allows explicit skip after a synchronous portability failure', function (): void {
    $root = sys_get_temp_dir() . '/app_build_export_' . bin2hex(random_bytes(8));
    $store = new BuildStore($root);
    $resource = fopen('php://memory', 'r+');
    try {
        $build = $store->begin();
        expect(fn () => $build->write('test.runtime', ['resource' => $resource]))->toThrow(Componenta\VarExport\Exception\ExportException::class);
        $build->skip('test.runtime', 'resource is runtime-owned');
        $prepared = $store->publish($build);
        expect($prepared->read('test.runtime'))->toBeNull()
            ->and($prepared->skipped())->toBe(['test.runtime' => 'resource is runtime-owned']);
    } finally { fclose($resource); removeBuildStoreTestRoot($root); }
});

it('rejects copying a build write capability without closing the original', function (Closure $copy, string $error): void {
    $root = sys_get_temp_dir() . '/app_build_copy_' . bin2hex(random_bytes(8));
    $store = new BuildStore($root);
    try {
        $build = $store->begin();
        expect(fn () => $copy($build))->toThrow($error);

        $build->write('test.data', ['value' => 'original']);
        $prepared = $store->publish($build);
        expect($prepared->read('test.data'))->toBe(['value' => 'original'])
            ->and(fn () => $build->write('test.after', []))->toThrow(LogicException::class, 'closed');
    } finally { removeBuildStoreTestRoot($root); }
})->with([
    'cloning' => [static fn (BuildContext $build): BuildContext => clone $build, Error::class],
    'serialization' => [static fn (BuildContext $build): string => serialize($build), LogicException::class],
    'unserialization' => [static fn (BuildContext $build): mixed => unserialize(sprintf(
        'O:%d:"%s":0:{}', strlen(BuildContext::class), BuildContext::class,
    )), LogicException::class],
]);

it('enforces explicit required artifacts without changing the prior publication', function (): void {
    $root = sys_get_temp_dir() . '/app_build_required_' . bin2hex(random_bytes(8));
    $store = new BuildStore($root, 'release-a', ['test.required']);
    try {
        expect(fn () => $store->load())->toThrow(RuntimeException::class, 'required');
        $first = $store->begin();
        $first->write('test.required', [], 3);
        $published = $store->publish($first);
        expect(fn () => $published->read('test.required', 1))->toThrow(RuntimeException::class, 'incompatible schema');
        $next = $store->begin();
        $next->skip('test.required', 'not portable');
        expect(fn () => $store->publish($next))->toThrow(RuntimeException::class, 'test.required');
        expect($store->load()->generation)->toBe($published->generation);
        unlink($root . '/generations/' . $published->generation . '/test.required.php');
        expect(fn () => $store->load())->toThrow(RuntimeException::class, 'test.required');
    } finally { removeBuildStoreTestRoot($root); }
});

it('binds explicit release identities and rejects malformed manifests without scanning source', function (): void {
    $root = sys_get_temp_dir() . '/app_build_identity_' . bin2hex(random_bytes(8));
    try {
        $store = new BuildStore($root, 'release-a');
        $prepared = $store->publish($store->begin());
        expect((new BuildStore($root, 'release-a'))->load()->generation)->toBe($prepared->generation)
            ->and((new BuildStore($root, 'release-b'))->load())->toBeNull()
            ->and((new BuildStore($root))->load())->toBeNull();
        $manifest = $root . '/generations/' . $prepared->generation . '/_manifest.php';
        file_put_contents($manifest, '<?php return ["version" => 2, "generation" => "' . $prepared->generation . '", "identity" => "release-a", "artifacts" => ["../escape" => ["state" => "written", "schema" => 1]]];');
        expect($store->load())->toBeNull();
        file_put_contents($root . '/current.json', '{"version":2,"generation":"../../escape"}');
        expect($store->load())->toBeNull();
    } finally { removeBuildStoreTestRoot($root); }
});

it('cleans without loading the pointer and refuses a directory in its place', function (): void {
    $root = sys_get_temp_dir() . '/app_build_clean_' . bin2hex(random_bytes(8));
    mkdir($root, 0700, true);
    $store = new BuildStore($root);
    try {
        file_put_contents($root . '/current.json', '<?php throw new LogicException("must not execute");');
        $store->clean();
        mkdir($root . '/current.json');
        file_put_contents($root . '/current.json/keep', 'preserved');
        expect(fn () => $store->clean())->toThrow(RuntimeException::class)
            ->and(file_get_contents($root . '/current.json/keep'))->toBe('preserved');
    } finally { removeBuildStoreTestRoot($root); }
});

it('rejects the earlier publication format until the application is rebuilt', function (): void {
    $root = sys_get_temp_dir() . '/app_build_old_format_' . bin2hex(random_bytes(8));
    try {
        $store = new BuildStore($root);
        $build = $store->begin();
        $build->write('test.data', ['value' => 'published']);
        $pinned = $store->publish($build);
        file_put_contents($root . '/current.json', json_encode([
            'version' => 1,
            'generation' => $pinned->generation,
        ], JSON_THROW_ON_ERROR));

        expect($store->load())->toBeNull()
            ->and(fn () => (new BuildStore($root, requiredArtifacts: ['test.data']))->load())
            ->toThrow(RuntimeException::class, 'required')
            ->and($pinned->read('test.data'))->toBe(['value' => 'published']);
    } finally { removeBuildStoreTestRoot($root); }
});

it('observes another process publication with OPcache timestamp validation disabled', function (): void {
    if (!extension_loaded('Zend OPcache')) { test()->markTestSkipped('Requires OPcache.'); }
    $root = sys_get_temp_dir() . '/app_build_opcache_' . bin2hex(random_bytes(8));
    try {
        $process = proc_open([
            PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.file_update_protection=0',
            '-d', 'opcache.validate_timestamps=0', __DIR__ . '/../Fixtures/cross-process-build.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php', $root,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $errors)
            ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
                'freshGenerationMatches' => true,
                'new' => ['value' => 'new'],
                'pinned' => ['value' => 'old'],
            ]);
    } finally { removeBuildStoreTestRoot($root); }
});

it('reads the mutable pointer as data and retains file trust checks', function (): void {
    $root = sys_get_temp_dir() . '/app_build_pointer_' . bin2hex(random_bytes(8));
    $store = new BuildStore($root);
    try {
        $prepared = $store->publish($store->begin());
        $file = $root . '/current.json';
        $valid = file_get_contents($file);
        $GLOBALS['buildPointerExecuted'] = false;
        file_put_contents($file, '<?php $GLOBALS["buildPointerExecuted"] = true; return [];');
        expect($store->load())->toBeNull()->and($GLOBALS['buildPointerExecuted'])->toBeFalse();
        file_put_contents($file, $valid);
        if (DIRECTORY_SEPARATOR === '/') {
            chmod($file, 0666);
            expect($store->load())->toBeNull();
            chmod($file, 0644);
            rename($file, $root . '/pointer-data.json');
            symlink($root . '/pointer-data.json', $file);
            expect($store->load())->toBeNull();
            unlink($file);
            rename($root . '/pointer-data.json', $file);
        }
        expect($store->load()->generation)->toBe($prepared->generation);
    } finally { unset($GLOBALS['buildPointerExecuted']); removeBuildStoreTestRoot($root); }
});

it('keeps required artifact policy independent of references in the caller array', function (): void {
    $root = sys_get_temp_dir() . '/app_build_required_reference_' . bin2hex(random_bytes(8));
    $required = 'test.required';
    $store = new BuildStore($root, requiredArtifacts: [&$required, 'test.required']);
    try {
        $required = 'test.replacement';
        $missing = $store->begin();
        $missing->write('test.replacement', ['value' => 'replacement']);
        expect(fn () => $store->publish($missing))->toThrow(RuntimeException::class, 'test.required');

        $complete = $store->begin();
        $complete->write('test.required', ['value' => 'required']);
        $pinned = $store->publish($complete);
        expect($pinned->read('test.required'))->toBe(['value' => 'required'])
            ->and($store->load()->read('test.required'))->toBe(['value' => 'required']);

        $required = 'test.changed.again';
        expect(fn () => $pinned->read('test.required', 2))->toThrow(RuntimeException::class, 'test.required')
            ->and(fn () => $store->load()->read('test.required', 2))->toThrow(RuntimeException::class, 'test.required');
    } finally { removeBuildStoreTestRoot($root); }
});

it('publishes and cleans immutable generations when the OPcache API is restricted', function (): void {
    if (!extension_loaded('Zend OPcache')) { test()->markTestSkipped('Requires OPcache.'); }
    $root = sys_get_temp_dir() . '/app_build_restricted_opcache_' . bin2hex(random_bytes(8));
    try {
        $process = proc_open([
            PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.file_update_protection=0',
            '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.restrict_api=' . $root . '/unavailable-api',
            __DIR__ . '/../Fixtures/restricted-opcache-build.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php', $root,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $errors)
            ->and($errors)->toBe('')
            ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
                'unpublished' => true,
                'pinnedAfterClean' => ['value' => 'first'],
                'newPublicationSelected' => true,
                'newValue' => ['value' => 'second'],
                'pinnedAfterPublish' => ['value' => 'first'],
                'handlerRestored' => true,
            ]);
    } finally { removeBuildStoreTestRoot($root); }
});

it('pins a relative store location before contexts and readers cross working directories', function (): void {
    $root = sys_get_temp_dir() . '/app_build_relative_' . bin2hex(random_bytes(8));
    mkdir($root . '/first', 0700, true);
    mkdir($root . '/second', 0700, true);
    $previousDirectory = getcwd();
    try {
        $otherStore = new BuildStore($root . '/second/cache');
        $other = $otherStore->begin();
        $other->write('test.data', ['value' => 'other application']);
        $otherPublished = $otherStore->publish($other);

        chdir($root . '/first');
        $store = new BuildStore('cache');
        $first = $store->begin();
        chdir($root . '/second');
        $first->write('test.data', ['value' => 'first']);
        $pinned = $store->publish($first);

        expect((new BuildStore($root . '/first/cache'))->load()?->generation)->toBe($pinned->generation)
            ->and($store->load()?->generation)->toBe($pinned->generation);
        chdir($root . '/first');
        $loaded = $store->load();
        chdir($root . '/second');
        expect($pinned->read('test.data'))->toBe(['value' => 'first'])
            ->and($loaded->read('test.data'))->toBe(['value' => 'first']);

        $second = $store->begin();
        $second->write('test.data', ['value' => 'second']);
        $published = $store->publish($second);
        expect((new BuildStore($root . '/first/cache'))->load()?->generation)->toBe($published->generation);
        $store->clean();
        expect((new BuildStore($root . '/first/cache'))->load())->toBeNull()
            ->and($otherStore->load()?->generation)->toBe($otherPublished->generation)
            ->and($pinned->read('test.data'))->toBe(['value' => 'first'])
            ->and($published->read('test.data'))->toBe(['value' => 'second']);
    } finally {
        chdir($previousDirectory);
        removeBuildStoreTestRoot($root);
    }
});

it('preserves absolute filesystem paths containing a symlink followed by a parent segment', function (): void {
    if (DIRECTORY_SEPARATOR !== '/') { test()->markTestSkipped('Requires POSIX directory symlinks.'); }
    $root = sys_get_temp_dir() . '/app_build_absolute_path_' . bin2hex(random_bytes(8));
    mkdir($root . '/target/nested', 0700, true);
    symlink($root . '/target/nested', $root . '/alias');
    try {
        $physicalStore = new BuildStore($root . '/target/cache');
        $build = $physicalStore->begin();
        $build->write('test.data', ['value' => 'native location']);
        $published = $physicalStore->publish($build);
        expect((new BuildStore($root . '/alias/../cache'))->load()?->generation)->toBe($published->generation)
            ->and((new BuildStore($root . '/cache'))->load())->toBeNull();
    } finally { removeBuildStoreTestRoot($root); }
});

it('uses absolute storage without requiring an available current working directory', function (): void {
    if (DIRECTORY_SEPARATOR !== '/') { test()->markTestSkipped('Requires POSIX removal of the current directory.'); }
    $root = sys_get_temp_dir() . '/app_build_missing_cwd_' . bin2hex(random_bytes(8));
    mkdir($root . '/removed', 0700, true);
    $previousDirectory = getcwd();
    try {
        chdir($root . '/removed');
        rmdir($root . '/removed');
        expect(getcwd())->toBeFalse();

        $store = new BuildStore($root . '/cache');
        $build = $store->begin();
        $build->write('test.data', ['value' => 'absolute location']);
        $published = $store->publish($build);
        expect($store->load()?->generation)->toBe($published->generation)
            ->and($published->read('test.data'))->toBe(['value' => 'absolute location']);
    } finally {
        chdir($previousDirectory);
        removeBuildStoreTestRoot($root);
    }
});

it('keeps selected artifacts on their release when a deployment directory alias changes', function (): void {
    if (DIRECTORY_SEPARATOR !== '/') { test()->markTestSkipped('Requires POSIX directory symlinks.'); }
    $root = sys_get_temp_dir() . '/app_build_release_alias_' . bin2hex(random_bytes(8));
    mkdir($root . '/first', 0700, true);
    mkdir($root . '/second', 0700, true);
    $alias = $root . '/current';
    symlink($root . '/first', $alias);
    $store = new BuildStore($alias . '/cache');
    try {
        $first = $store->begin();
        $first->write('test.data', ['value' => 'first release']);
        $published = $store->publish($first);
        $loaded = $store->load();

        $nextStore = new BuildStore($root . '/second/cache');
        $next = $nextStore->begin();
        $next->write('test.data', ['value' => 'second release']);
        $nextPublished = $nextStore->publish($next);
        unlink($alias);
        symlink($root . '/second', $alias);
        clearstatcache(true);

        expect($published->read('test.data'))->toBe(['value' => 'first release'])
            ->and($loaded->read('test.data'))->toBe(['value' => 'first release'])
            ->and($store->load()?->generation)->toBe($nextPublished->generation)
            ->and($store->load()?->read('test.data'))->toBe(['value' => 'second release']);
        $store->clean();
        expect($nextStore->load())->toBeNull()
            ->and($published->read('test.data'))->toBe(['value' => 'first release'])
            ->and($loaded->read('test.data'))->toBe(['value' => 'first release']);
    } finally { removeBuildStoreTestRoot($root); }
});

it('applies optional and required publication policy when open_basedir denies the store', function (): void {
    $root = sys_get_temp_dir() . '/app_build_restricted_directory_' . bin2hex(random_bytes(8));
    mkdir($root . '/allowed', 0700, true);
    try {
        $store = new BuildStore($root . '/excluded');
        $build = $store->begin();
        $build->write('test.data', ['value' => 'published']);
        $store->publish($build);
        $process = proc_open([
            PHP_BINARY, __DIR__ . '/../Fixtures/restricted-build-directory.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php', $root,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $errors)
            ->and($errors)->toBe('')
            ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
                'optional' => null,
                'optionalError' => null,
                'requiredError' => RuntimeException::class,
                'requiredMessage' => 'A published build matching the deployment identity is required.',
                'callerWarnings' => 0,
                'handlerRestored' => true,
            ]);
    } finally { removeBuildStoreTestRoot($root); }
});

it('exposes the selected artifact policy independently of artifact availability', function (): void {
    $root = sys_get_temp_dir() . '/app_build_policy_' . bin2hex(random_bytes(8));
    $strict = new BuildStore($root, requiredArtifacts: ['test.required']);
    try {
        $build = $strict->begin();
        $build->write('test.required', ['required']);
        $build->write('test.optional', ['optional']);
        $selected = $strict->publish($build);
        $optional = (new BuildStore($root))->load();

        foreach ([$selected, $strict->load()] as $prepared) {
            expect($prepared->isRequired('test.required'))->toBeTrue()
                ->and($prepared->isRequired('test.optional'))->toBeFalse()
                ->and($prepared->isRequired('test.missing'))->toBeFalse();
        }
        expect($optional->isRequired('test.required'))->toBeFalse()
            ->and($optional->read('test.required'))->toBe(['required']);

        $strict->clean();
        expect($selected->isRequired('test.required'))->toBeTrue()
            ->and($selected->read('test.required'))->toBe(['required']);
    } finally { removeBuildStoreTestRoot($root); }
});
