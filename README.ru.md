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

`run()` меняет рабочий каталог на корень проекта, загружает `config/container.php`, проверяет возвращённый PSR-11 контейнер и передаёт запуск в `Runner`. Runner получает приложение нужной области через `AppFactory`, создаёт отдельный целевой объект загрузки, выполняет загрузчики и только после их завершения запускает приложение.

Провайдер интеграции регистрирует значение области и идентификатор сервиса контейнера в `ConfigKey::APPLICATIONS`:

~~~php
use Componenta\App\ConfigKey;
use Componenta\App\Scope;

return [
    ConfigKey::APPLICATIONS => [
        Scope::HTTP->value => MyHttpApplication::class,
    ],
];
~~~

Сервис должен реализовывать `AppInterface`. Значение области используется как точный ключ, поэтому для пользовательской области нужна собственная регистрация. Фабрика разрешает только выбранный сервис. Адаптер целевого объекта загрузки остаётся самостоятельной точкой расширения: он оборачивает приложение и предоставляет только операции настройки, не возвращает приложение и не раскрывает метод `run()`. Загрузчики получают объект через `BootContext::target(Contract::class)`. Контейнер остаётся доверенной инфраструктурой приложения, а не границей безопасности или изоляции.

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

Билдеры получают свежий общий источник через DI под ключом `ConfigKey::DISCOVERY_SOURCE`. При использовании карты в runtime этот источник остаётся ленивой фабрикой до запроса билдера. Для следующего запуска со свежим поиском выполните `app:clean`, затем отдельным процессом `app:build`. При устаревшей карте, мешающей загрузке, установите `APP_DISCOVERY_SOURCE=1` для запуска с исходниками независимо от существующих артефактов.

Карты поиска классов и boot-методов содержат версию формата и общий идентификатор поколения сборки. Карта boot-методов другого поколения игнорируется; это предотвращает применение несовместимых метаданных после прерванной сборки. Старые карты App без версии игнорируются и могут быть пересобраны. Билдеры интеграций могут записывать карты с привязкой к поколению через `PhpMapFile::write($file, $payload, $generation)` и читать их через `PhpMapFile::read($file, $expectedGeneration)`. Поколение сборки предоставляется сервисом `ConfigKey::BUILD_GENERATION`, поколение выбранной готовой карты — сервисом `ConfigKey::DISCOVERY_GENERATION`, если он зарегистрирован. Каждый пакет самостоятельно определяет формат полезной нагрузки.

PHP-карты — исполняемые доверенные артефакты развёртывания. Храните их за пределами публичной директории в каталогах, доступных на запись только доверенным пользователям. Не загружайте карты из пользовательских данных. Чтение отклоняет символьные ссылки на файлы карт и файлы с правом записи для группы или остальных пользователей на POSIX. Непригодные карты, выводящие посторонний текст, отклоняются без передачи этого текста наружу. Публикуйте завершённую сборку до запуска или перезапуска воркеров.

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

Упорядоченный список `ConfigKey::BOOTLOADERS` содержит уникальные имена классов загрузчиков. Провайдер проверяет все регистрации и разрешает сервисы до начала исполнения, затем один раз проверяет `supports()` у каждого кандидата в порядке регистрации. `BootloaderInterface::boot()` получает `BootContext` с текущей областью запуска, типизированным доступом к целевому объекту и `ContainerValue`. При непосредственном вызове `boot()` вызывающий код обязан предварительно проверить применимость. Базовый `Bootloader` поддерживает метод `__invoke()` с внедрением параметров. `DateTimeBootloader` устанавливает `APP_TIMEZONE` (по умолчанию UTC) для HTTP, CLI и WebSocket; неверная временная зона прерывает загрузку.

`ClassDiscoveryBootloader` передаёт рабочий итератор классов в `ClassListenerNotifier`. Слушатели обрабатывают источник, выбранный ConfigFactory.

`#[Boot]` помечает публичные методы запуска. `BootMethodInvocation` собирает их один раз для каждого обнаруженного объявления и при финализации передаёт в `BootInvocationRunner`, который выполняет методы по убыванию приоритета. Повторяемые атрибуты одного метода сохраняют заявленную семантику. В начале финализации слушатель освобождает собранные метаданные и не допускает повторного поиска. Явные параметры могут содержать обычные значения и DI-метаданные: `EntryId` для сервиса, `Config` для настройки и `Env` для значения окружения.

При настроенном discovery `BootBuilder` записывает `var/cache/build/boot.php`: имя класса → упорядоченный список публичных методов с `#[Boot]`, включая наследуемые. Атрибуты и вложенные объекты параметров создаются нативно при каждом запуске. Карта сокращает поиск методов и исключает автозагрузку объявлений без boot-методов; при отсутствии, неверной структуре или несовпадающем поколении используется исходный путь. При выборе исходного discovery старые boot-метаданные игнорируются.

## Пути кеша

Конкретные билдеры получают пути своих артефактов через фабрики с помощью `PathResolverInterface`. Путь discovery-карты задаёт `ConfigKey::DISCOVERY_MAP_FILE`. Каждый билдер с опциональным контрактом очистки удаляет собственные артефакты.

## Пакеты интеграции

- `componenta/app-console` предоставляет Symfony Console и реестр команд.
- `componenta/app-http` предоставляет HTTP-приложение.
- `componenta/websocket-app` предоставляет область запуска WebSocket.
- `componenta/cqrs-app` добавляет CQRS discovery, рабочие карты и билдер.
- `componenta/interceptor-app` добавляет discovery метаданных интерцепторов и билдер.

Runtime-библиотеки могут использоваться самостоятельно; app-пакеты подключают конфигурацию, discovery, билдеры и запуск приложения.

## Измерение производительности

Установите зависимости разработки и запустите PHPBench:

```sh
composer bench
vendor/bin/phpbench run --filter=StartupBench --report=aggregate
vendor/bin/phpbench run --filter=RuntimeBench --report=aggregate
```

Набор измеряет создание конфигурации и контейнера, поиск объявлений, выполнение загрузки и полный запуск для 300 и 1000 объявлений в исходном и подготовленном режимах; сборку и очистку артефактов; разрешение приложения, создание целевого объекта, выбор загрузчиков, разрешение параметров boot-методов и запуск без поиска классов в прогретом контейнере. Подготовка данных и проверки результата вынесены за границы измерения. Готовые карты создаются штатными билдерами в отдельном PHP-процессе, поэтому классы не прогреваются в измеряемом процессе. Для холодных сценариев используется одно выполнение на изолированную итерацию, для прогретых — многократные выполнения. По умолчанию отключены CLI OPcache и Xdebug. Сводный отчёт содержит время, память и разброс; в CI используется сокращённое число итераций без порогов скорости.

Для сохранения базового результата используйте `vendor/bin/phpbench run --tag=baseline --report=aggregate`, для сравнения следующего запуска на той же машине — `vendor/bin/phpbench run --ref=baseline --report=aggregate`. Синтетические измерения не включают время запуска процесса PHP и ввод-вывод реального HTTP-приложения; это не измерение TTFB.
