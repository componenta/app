<?php

declare(strict_types=1);

namespace Componenta\App\Benchmarks;

use Componenta\App\Config\ComposerPackageConfigProvider;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\ConfigFactory;
use Componenta\App\Config\ConfigFactoryResult;
use Componenta\Config\Environment;
use Componenta\Stdlib\PathResolver;
use LogicException;
use PhpBench\Attributes as Bench;

require_once dirname(__DIR__) . '/Support/Fixture.php';

#[Bench\Revs(1)]
#[Bench\Iterations(7)]
#[Bench\AfterMethods('tearDown')]
final class ConfigProviderBench
{
    private Fixture $fixture;
    private ConfigFactoryResult $result;
    private string $case;

    public function attributeCases(): iterable
    {
        foreach ([300, 1000] as $count) {
            foreach ([false, true] as $prepared) {
                yield ($prepared ? 'prepared-' : 'source-') . $count => [
                    'count' => $count,
                    'prepared' => $prepared,
                ];
            }
        }
    }

    public function packageCases(): iterable
    {
        yield '30-providers' => ['count' => 30];
        yield '100-providers' => ['count' => 100];
    }

    public function setUpAttributes(array $params): void
    {
        BenchmarkState::$autoloads = 0;
        $this->fixture = Fixture::create($params['count'], true);
        if ($params['prepared']) {
            $this->fixture->prepare();
        }
        $this->fixture->registerAutoload();
        $this->case = 'attributes';
    }

    public function setUpPackages(array $params): void
    {
        $this->fixture = Fixture::create(0);
        $contents = '<?php return ' . var_export(array_fill(0, $params['count'], BenchmarkPackageProvider::class), true) . ';';
        if (file_put_contents($this->fixture->root . '/providers.php', $contents) === false) {
            $this->fixture->close();
            throw new LogicException('Cannot write package provider fixture.');
        }
        $this->case = 'packages';
    }

    #[Bench\BeforeMethods('setUpAttributes')]
    #[Bench\ParamProviders('attributeCases')]
    public function benchAttributeComposition(array $params): void
    {
        $this->result = $this->fixture->configuration($params['prepared'], true);
    }

    #[Bench\BeforeMethods('setUpPackages')]
    #[Bench\ParamProviders('packageCases')]
    public function benchComposerPackageComposition(array $params): void
    {
        $this->result = ConfigFactory::create(
            new PathResolver($this->fixture->root),
            new ConfigDefinition([new ComposerPackageConfigProvider($this->fixture->root . '/providers.php')]),
            new Environment([]),
        );
    }

    public function tearDown(array $params): void
    {
        try {
            if ($this->case === 'attributes') {
                if (count($this->result->config->get('bench.discovered')) !== intdiv($params['count'] + 19, 20)
                    || BenchmarkState::$autoloads !== $params['count']) {
                    throw new LogicException('Attribute composition did not discover the expected providers.');
                }
            } elseif ($this->result->config->get('bench.package_contributions') !== array_fill(0, $params['count'], true)) {
                throw new LogicException('Package provider contribution count changed.');
            }
        } finally {
            $this->fixture->close();
        }
    }
}
