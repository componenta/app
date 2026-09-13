# Componenta App

Подготовка конфигурации и запуск Componenta-приложений: провайдеры, области запуска, адаптеры, загрузчики и билдеры.

## Установка

~~~bash
composer require componenta/app
~~~

Требуется PHP 8.4 или новее. Пакет объявляет `Componenta\App\ConfigProvider` в метаданных Composer; `componenta/composer-plugin` добавляет его в сгенерированный список провайдеров.

Провайдер регистрирует `ApplicationBuildOrchestratorFactory`, фабрики приложения и целевого объекта загрузки, `DateTimeBootloader`, `ClassDiscoveryBootloader` и слушатель классов `BootMethodInvocation`. Конкретные HTTP, CLI и WebSocket реализации подключаются через пакеты интеграции.

## Точка входа

У каждой области запуска свой файл, например `public/index.php` или `bin/console.php`:

~~~php
use Componenta\App\Scope;
use Componenta\Stdlib\PathResolver;

use function Componenta\App\run;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

run(Scope::CLI, new PathResolver($root));
~~~

`run()` меняет рабочий каталог на корень проекта, загружает `config/container.php`, проверяет возвращённый PSR-11 контейнер и передаёт запуск в `Runner`. Runner создаёт приложение нужной области через `AppFactory`, подготавливает целевой объект загрузки, выполняет загрузчики и запускает приложение.

## Конфигурация и контейнер

`config/config.php` возвращает `ConfigDefinition`. Провайдеры обрабатываются в порядке регистрации:

~~~php
use Componenta\App\Config\ComposerPackageConfigProvider;
use Componenta\App\Config\ConfigDefinition;
use Componenta\App\Config\DiscoveryDefinition;

return new ConfigDefinition(
    providers: [
        new ComposerPackageConfigProvider(
            $paths->resolve('config/componenta-providers.php'),
        ),
    ],
    discovery: new DiscoveryDefinition(directories: ['src']),
);
~~~

В список добавляются необходимые провайдеры приложения и файлов. Для конфигурации через `#[AsConfig]` подключается `AttributeConfigProvider`. Если `discovery` опущен, обнаружение классов отключено.

В `config/container.php` две части результата подготовки конфигурации передаются в DI:

~~~php
use Componenta\App\Config\ConfigFactory;
use Componenta\DI\ContainerFactory;

$definition = require $paths->resolve('config/config.php');
$result = ConfigFactory::create(paths: $paths, definition: $definition);

return (new ContainerFactory())->create(
    $result->composition->config,
    $result->composition->dependencies,
)->container;
~~~

Runtime `Config` содержит настройки приложения, а `DependencyDefinitions` — регистрации DI. Контейнер получает тот же экземпляр Config. ConfigFactory вызывает провайдеры и в development, и в production.

ConfigFactory регистрирует `PathResolverInterface`. При настроенном discovery один ленивый исходный `ClassIteratorInterface` передаётся discovery-aware провайдерам и регистрируется в DI также под ключом `ConfigKey::DISCOVERY_SOURCE`. Development и production используют одинаковый источник. Несколько билдеров получают его через DI, а их карты загружаются фабриками соответствующих runtime-сервисов.

## Билдеры приложения

Пакет `componenta/app-console` добавляет обычную консольную команду:

~~~bash
php bin/console.php app:build
~~~

Билдер реализует `Componenta\App\Build\ApplicationBuilderInterface`:

~~~php
namespace App\Build;

use Componenta\App\Build\ApplicationBuilderInterface;

final class SearchIndexBuilder implements ApplicationBuilderInterface
{
    public function __construct(private SearchIndexWriter $writer)
    {
    }

    public function build(): void
    {
        $this->writer->rebuild();
    }
}
~~~

`SearchIndexWriter` — сервис приложения, который отвечает за формат индекса и его публикацию. Конструктор подготавливает зависимости; работа выполняется в `build()`.

ConfigProvider пакета или приложения регистрирует ID сервисов:

~~~php
namespace App;

use App\Build\SearchIndexBuilder;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\Config\ConfigProvider as BaseConfigProvider;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getConfig(): array
    {
        return [
            AppConfigKey::BUILDERS => [SearchIndexBuilder::class],
        ];
    }
}
~~~

Билдер может создаваться через автовайринг. Фабрика в `getFactories()` нужна для явной настройки зависимостей, например источника метаданных или пути через `PathResolverInterface`.

`ApplicationBuildOrchestratorFactory` проверяет весь упорядоченный список: ID должны быть уникальными непустыми строками. Затем фабрика получает все сервисы и проверяет реализацию `ApplicationBuilderInterface` до первого вызова билдера. Отсутствующий или пустой список означает успешное завершение.

`ApplicationBuildOrchestrator::build()` вызывает билдеры по порядку. Каждый билдер отвечает за формат своих артефактов, каталоги и атомарную запись. Исключение выходит без подмены и останавливает последующие билдеры; результаты завершённых билдеров сохраняются. Билдеры работают независимо и получают общие исходные данные через зависимости.

BuildCommand получает замыкание, которое разрешает оркестратор из существующего контейнера. Оно вызывается только в `execute()`. Поэтому `list` и `--help` не создают билдеры; обычная подготовка приложения сохраняется. Команда доступна в development и production, в том числе до появления артефактов. Runtime-сервисы могут использовать артефакты или исходные данные; пересборка запускается явно.

## Загрузчики и discovery

`ConfigKey::BOOTLOADERS` содержит сервисы загрузчиков. `BootloaderInterface::boot()` получает `BootContext` с текущей областью запуска, целевым объектом и `ContainerValue`. Базовый `Bootloader` поддерживает метод `__invoke()` с внедрением параметров.

`ClassDiscoveryBootloader` передаёт рабочий итератор классов в `ClassListenerNotifier`. Слушатели обрабатывают источник, выбранный ConfigFactory.

`#[Boot]` помечает публичные методы запуска. `BootMethodInvocation` собирает их и при финализации передаёт в `BootInvocationRunner`, который выполняет методы по убыванию приоритета. Явные параметры могут содержать обычные значения и DI-метаданные: `EntryId` для сервиса, `Config` для настройки и `Env` для значения окружения.

## Пути кеша

`CacheLayout` предоставляет каталоги сборки, разработки и runtime. При bootstrap каталог сборки задаётся `ConfigKey::DEFAULT_CACHE_BUILD_DIR`; `CacheLayout::fromConfig()` читает `ConfigKey::CACHE_DEV_DIR` и `ConfigKey::CACHE_RUNTIME_DIR` для остальных каталогов. Конкретные билдеры получают пути своих артефактов через фабрики.

## Пакеты интеграции

- `componenta/app-console` предоставляет Symfony Console и реестр команд.
- `componenta/app-http` предоставляет HTTP-приложение.
- `componenta/websocket-app` предоставляет область запуска WebSocket.
- `componenta/cqrs-app` добавляет CQRS discovery, рабочие карты и билдер.
- `componenta/interceptor-app` добавляет discovery метаданных интерцепторов и билдер.

Runtime-библиотеки могут использоваться самостоятельно; app-пакеты подключают конфигурацию, discovery, билдеры и запуск приложения.
