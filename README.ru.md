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

ConfigFactory регистрирует `PathResolverInterface`. При настроенном discovery он выбирает готовую карту `var/cache/build/classes.php` или поиск по исходникам и передаёт выбранный `ClassIteratorInterface` провайдерам и runtime. Без карты исходники читаются при каждом запуске; development-кеш и проверка актуальности файлов не используются. `ClassIterator::fromMap()` восстанавливает объявления, пути и модификаторы из карты без обхода директорий и токенизации.

Билдеры получают свежий общий источник через DI под ключом `ConfigKey::DISCOVERY_SOURCE`. При использовании карты в runtime этот источник остаётся ленивой фабрикой до запроса билдера. Для следующего запуска со свежим discovery выполните `app:clean`, затем отдельным процессом `app:build`.

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

BuildCommand и CleanCommand получают замыкания, которые разрешают оркестратор из существующего контейнера. Они вызываются только в `execute()`. Поэтому `list` и `--help` не создают билдеры; обычная подготовка приложения сохраняется. Обе команды доступны в development и production, в том числе до появления артефактов. Runtime-сервисы могут использовать артефакты или исходные данные; пересборка запускается явно.

Билдер может дополнительно реализовать `Componenta\App\Build\ApplicationBuildCleanerInterface`:

~~~php
interface ApplicationBuildCleanerInterface
{
    public function clean(): void;
}
~~~

`ApplicationBuildOrchestrator::clean()` обходит тот же список в порядке регистрации и вызывает только билдеры с этим интерфейсом. Отдельная регистрация не нужна. Билдер удаляет только собственные артефакты; отсутствие файла считается успешной очисткой. Исключение выходит без подмены и останавливает последующую очистку, уже выполненные действия сохраняются. Пустой список допустим. Оркестратор сам не удаляет файлы.

`app:build` собирает, `app:clean` очищает. Обе команды используют обычные Config и контейнер с существующими картами, если они доступны. Очистка не пересоздаёт текущий контейнер; следующий процесс запускается без удалённых карт. Точка входа не проверяет имя команды.

## Загрузчики и discovery

`ConfigKey::BOOTLOADERS` содержит сервисы загрузчиков. `BootloaderInterface::boot()` получает `BootContext` с текущей областью запуска, целевым объектом и `ContainerValue`. Базовый `Bootloader` поддерживает метод `__invoke()` с внедрением параметров.

`ClassDiscoveryBootloader` передаёт рабочий итератор классов в `ClassListenerNotifier`. Слушатели обрабатывают источник, выбранный ConfigFactory.

`#[Boot]` помечает публичные методы запуска. `BootMethodInvocation` собирает их и при финализации передаёт в `BootInvocationRunner`, который выполняет методы по убыванию приоритета. Явные параметры могут содержать обычные значения и DI-метаданные: `EntryId` для сервиса, `Config` для настройки и `Env` для значения окружения.

При настроенном discovery `BootBuilder` записывает `var/cache/build/boot.php`: имя класса → упорядоченный список публичных методов с `#[Boot]`, включая наследуемые. Атрибуты и вложенные объекты параметров создаются нативно при каждом запуске. Карта сокращает поиск методов; при отсутствии или неверной структуре карты используется исходный путь. При выборе исходного discovery старые boot-метаданные игнорируются.

## Пути кеша

Конкретные билдеры получают пути своих артефактов через фабрики с помощью `PathResolverInterface`. Путь discovery-карты задаёт `ConfigKey::DISCOVERY_MAP_FILE`. Каждый билдер с опциональным контрактом очистки удаляет собственные артефакты.

## Пакеты интеграции

- `componenta/app-console` предоставляет Symfony Console и реестр команд.
- `componenta/app-http` предоставляет HTTP-приложение.
- `componenta/websocket-app` предоставляет область запуска WebSocket.
- `componenta/cqrs-app` добавляет CQRS discovery, рабочие карты и билдер.
- `componenta/interceptor-app` добавляет discovery метаданных интерцепторов и билдер.

Runtime-библиотеки могут использоваться самостоятельно; app-пакеты подключают конфигурацию, discovery, билдеры и запуск приложения.
