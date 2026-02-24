# Anagami Finance View (AFV) – модул „Планиране на бюджети“

Модул **Планиране на бюджети** (модулен флаг `databackd_planning`) на платформата **Anagami Finance View (AFV)** – мултитенантна SaaS платформа за управление, обработка и анализ на финансовите данни на малки и средни предприятия, изградена с Laravel (Blade, Bootstrap 4, jQuery).

> **Частно / вътрешно ползване.** Репото съдържа само изнесената част от кода на AFV, свързана с планирането. То не е самостоятелно приложение – работи само интегрирано в Laravel проекта на AFV (виж „Зависимости“). Не е предназначено за публично разпространение.

Кодовите идентификатори са запазени непроменени спрямо оригиналния проект: namespaces `...\Databackd\...`, папки `routes/databackd/`, `resources/views/databackd/`, lang файлове `databackd-*.php`, helpers `databackd_*()` и модулен флаг `databackd_planning`.

---

## Какво прави модулът

Модулът е част от пакетите на SaaS платформата – достъпен е за потребители с роля `admin` и `employee` (не и за `client`), когато модулът `databackd_planning` е включен в пакета на компанията. Включва три екрана в менюто, група „Планиране“ (подменюта с номера 40, 41 и 42):

### 1. Бюджети (`databackd/planning/budgets`)

- Списък на годишните бюджети с филтри по година, версия (чернова / одобрен) и юридическо лице (или „цялата група“), обобщени бройки (общо, чернови, одобрени, години) и суми приходи / разходи / печалба по бюджет.
- Създаване на бюджет по три начина (`BudgetFactory`, `StoreBudget`):
  - **празен** бюджет без редове;
  - **от реалните данни** на избрана година, с коригиращ процент (`adjust_pct`, от -100 до 1000); пропуснатите месеци могат да се запълват (`fill_gaps`) – от същия месец на предходната година, а иначе със средното на сметката за месеците с данни. Използват се само приходни и разходни сметки, а в бюджета влизат само положителни суми;
  - **от копие на друг бюджет**.
- Атрибути на бюджета: име, година, юридическо лице (или цялата група при `entity_id = null`), сценарий (`base` / `optimistic` / `pessimistic`), валута, бележки.
- Дублиране на бюджет, **одобряване** (версия `draft` -> `approved`, с отбелязване на кой и кога го е одобрил) и изтриване – само на чернови.
- Бюджет, създаден от реални данни, трябва да е във валутата на компанията (реалните суми не се конвертират) – валидира се в `StoreBudget`.

### 2. Редактор на бюджет (`databackd/planning/budget-editor`)

- Електронна таблица (мрежа) „приходни и разходни сметки x 12 месеца“ за един бюджет.
- Работи на ниво без аналитични разрези (регион / продуктова група / разходен център = NULL). Сметки, които вече имат редове с разрези, се показват като сума и са само за четене.
- Нулева или празна клетка се записва като „няма ред“ (ред без разрез със сума 0 се изтрива). Одобреният бюджет е само за четене. Неактивните сметки се показват (и могат да се редактират) само докато бюджетът има редове по тях.
- Пълно запазване (`save`) и автоматично запазване на една клетка (`cell`) с проверка за **конфликти при едновременна работа**: браузърът може да подаде сумата, която смята за записана (`expected`); при разминаване клетката не се записва, а се връща текущата стойност. Записите са в транзакция със заключване на реда на бюджета.

### 3. Бюджет спрямо реално – анализ на отклоненията (`databackd/planning/variance`)

- Сравнение на бюджет с реалните обороти (приходни и разходни сметки от финансовите записи) по сметка, за избран месец, от началото на годината до месеца (YTD) или за цялата година.
- Филтри по аналитични разрези: регион, продуктова група, разходен център.
- Показатели (KPI), таблица с отклонения (абсолютно и в проценти, с обозначение дали отклонението е благоприятно – приходи над плана или разходи под плана), месечна диаграма бюджет / реално и „най-големи отклонения“.
- Предупреждения за непълно сравнение: още няма реални данни, месеци след последните реални данни, текущ незавършен месец, разрез без бюджетни редове, бюджет в друга валута.
- **Експорт в Excel** на таблицата (`VarianceExport`, с неутрализиране на формули).

### 4. Прогнози (`databackd/planning/forecasts`)

- Прогноза на месечен показател: приходи, разходи, печалба, парични средства, отделна сметка или продуктова група; за цялата група или за юридическо лице.
- История – последните 18 приключили месеца (текущият месец се изключва до последния си ден); водещите месеци без данни се изрязват (`trimLeadingZeros`), за да не изкривяват тренда.
- Методи (`ForecastService`): `linear` (най-малки квадрати), `moving_average` (пълзяща средна 3 или 6 месеца), `seasonal` (ниво x сезонен индекс на последните 12 месеца), `manual` (месечен % растеж със зададен коридор). Хоризонт 3, 6 или 12 месеца.
- Коридор около прогнозата (стандартно отклонение на остатъците; при `manual` – зададен %). Стойностите не се ограничават отдолу (печалбата и парите могат да са отрицателни).
- **Сценарии** (базов, оптимистичен, песимистичен) с годишно изменение на приходите и разходите.
- Запазване на дефиниция на прогноза (`fin_forecasts`) – записва се само дефиницията, числата се преизчисляват при отваряне.
- Предупреждения: кратка история, сезонност без пълна година и др.
- Конзолна команда `php artisan databackd:check-forecasts` – самопроверка на методите върху синтетични редове (без база данни).

---

## Структура на репото

Запазени са оригиналните относителни пътища, така че съдържанието може да се копира директно върху Laravel проекта на AFV.

```
app/
  Console/Commands/Databackd/CheckForecasts.php
  Exports/Databackd/Planning/VarianceExport.php
  Http/Controllers/Databackd/Planning/   BudgetController, BudgetEditorController, BudgetVarianceController, ForecastController
  Http/Requests/Databackd/Planning/StoreBudget.php
  Models/Databackd/                      FinBudget, FinBudgetLine, FinForecast
  Services/Databackd/Planning/           BudgetActuals, BudgetFactory, ForecastService
database/
  migrations/2026_10_27_500000_create_databackd_planning_tables.php
  seeders/Databackd/Demo/44PlanningDemoSeeder.php
resources/
  lang/{eng,bg}/databackd-planning-*.php        (5 файла на език)
  lang/{eng,bg}/_extract/                       (фрагменти от споделени lang файлове)
  scss/databackd/_planning.scss, _forecasts.scss
  views/databackd/menu/40..42-planning-*.blade.php
  views/databackd/planning/{budgets,budget-editor,variance,forecasts}/
routes/databackd/planning-*.php
```

| Категория | Файлове |
|---|---:|
| Контролери | 4 |
| Модели | 3 |
| Услуги (Services) | 3 |
| Заявки (Requests) | 1 |
| Експорти | 1 |
| Конзолни команди | 1 |
| Миграции | 1 |
| Сийдъри (демо данни) | 1 |
| Маршрути | 4 |
| Изгледи (Blade) – екрани и partial | 6 |
| Изгледи – елементи на менюто (40–42) | 3 |
| Lang файлове (eng + bg) | 10 |
| Lang фрагменти от споделени файлове (`_extract`) | 4 |
| SCSS | 2 |
| Конфигурация на репото (`.gitignore`) | 1 |
| Документация (`README.md`) | 1 |
| **Общо** | **46** |

### Таблици в базата данни

Миграцията `2026_10_27_500000_create_databackd_planning_tables.php` създава:

- `fin_budgets` – бюджет (година, версия `draft|approved`, сценарий, валута, юридическо лице, одобрил / одобрен на);
- `fin_budget_lines` – ред на бюджета (сметка, месец, сума и незадължителни разрези `region_id`, `product_group_id`, `cost_center_id`); уникален индекс по `budget_id`, сметка, разрези и месец – тъй като NULL стойностите не са равни в уникален индекс, редакторът и фабриката записват ръчно (upsert);
- `fin_forecasts` – запазена дефиниция на прогноза (показател, метод, параметри, хоризонт, сценарии).

Всички таблици са с `company_id` (мултитенантност, външен ключ към `companies`).

---

## Зависимости (НЕ са включени в репото)

Модулът разчита на останалата част от приложението AFV и на базовия Laravel проект:

**Модели и данни от модул „Данни“**
- `FinAccount`, `FinEntry`, `FinEntity`, `FinDimension` (разрези: регион, продуктова група, разходен център) и таблиците им (`fin_accounts`, `fin_entries`, `fin_entities`, ...);
- `App\Services\Databackd\FinancialDataService` – заявки към финансовите записи (`query`, `monthlySeries`, `balanceAt`, `CASH_CODES`), източник на реалните данни за отклоненията и историята на прогнозите;
- `App\Exports\Databackd\Concerns\NeutralizesFormulas` (експорт).

**Мултитенантност и основа на приложението**
- `App\Traits\HasCompany` / `CompanyScope` (глобален филтър по `company_id`), `App\Models\BaseModel`, `App\Models\Company`, `App\Models\Currency`, `App\Models\User`;
- `App\Http\Controllers\AccountBaseController`, `App\Helper\Reply`, помощните функции `company()`, `user()`, `user_roles()`, `abort_403()`;
- `App\Http\Middleware\Databackd\RequireModule` – проверка на модулния флаг при всеки маршрут.

**Помощни функции и конфигурация на модулите (споделени файлове)**
- `app/Helper/databackd_modules.php` – каталог на модулите: `databackd_planning => ['admin', 'employee']` и съответствията на менютата `40-planning-budgets`, `41-planning-variance`, `42-planning-forecasts` -> `databackd_planning`; функцията `databackd_module()`;
- миграцията `2026_10_28_090000_add_databackd_modules.php` – регистрира реда `databackd_planning` в таблицата `modules` и го добавя към съществуващите пакети;
- `resources/views/sections/menu.blade.php` – група `planning` със записи `40-planning-budgets`, `41-planning-variance`, `42-planning-forecasts`;
- `app/Providers/RouteServiceProvider::mapDatabackdRoutes()` – зарежда `routes/databackd/*.php` с префикс `account` и middleware за уеб и удостоверяване;
- модулът не ползва наблюдатели (observers), събития или специфична регистрация в `EventServiceProvider`.

**Интерфейс**
- Blade компоненти `x-dbk.card`, `x-dbk.chart`, `x-dbk.empty`, `x-dbk.kpi`, `x-dbk.page-header`, `x-forms.*`, шаблон `layouts.app`;
- `resources/js/databackd-charts.js` (обект `dbkChart`, Chart.js) – диаграмите на отклоненията и прогнозите;
- `resources/scss/main.scss` трябва да импортира `databackd/planning` и `databackd/forecasts`; стиловете използват променливите `--dbk-*` от споделените токени (`_tokens.scss`).

**Споделени lang файлове** – ключовете на модула са извадени като фрагменти в `resources/lang/{eng,bg}/_extract/` (виж по-долу).

**Обратни зависимости** – други модули на AFV ползват класове от този модул: „Известия и прагове“ (`AlertChecker`, `AlertRuleController`) ползват `FinBudget` и `BudgetActuals::variance()`, а `OnboardingService` – `FinBudgetLine`. Затова при премахване на модула те трябва да се адаптират.

### Споделени lang ключове (`_extract`)

| Фрагмент | Целеви файл | Съдържание |
|---|---|---|
| `resources/lang/{eng,bg}/_extract/app.php` | `resources/lang/{eng,bg}/app.php` | `budgetEditor`, `budgets`, `forecasts`, `planning`, `variance` |
| `resources/lang/{eng,bg}/_extract/databackd-packages.php` | `resources/lang/{eng,bg}/databackd-packages.php` | запис `modules.databackd_planning` (име и описание на модула в пакетите) |

Тези файлове **не** са самостоятелни lang файлове – ключовете трябва да се слеят в съответните споделени файлове.

---

## Изисквания

- PHP **>= 8.2**;
- Laravel **12** (`laravel/framework ^12.0` в `composer.json` на AFV);
- `maatwebsite/excel ^3.1` (експорт на таблицата с отклоненията);
- база данни MySQL / MariaDB (в заявките се ползват `MONTH()` и `DATE_FORMAT()`);
- Chart.js чрез `databackd-charts.js` и Bootstrap 4 / jQuery от базовия интерфейс.

## Интеграция

1. Копирайте съдържанието на репото (без `README.md` и папките `_extract`) върху корена на Laravel проекта на AFV – пътищата съвпадат.
2. Слейте фрагментите от `resources/lang/{eng,bg}/_extract/` в споделените `app.php` и `databackd-packages.php`.
3. Уверете се, че споделените файлове са налице и съдържат модула: каталогът в `app/Helper/databackd_modules.php`, групата `planning` в `sections/menu.blade.php`, зареждането на `routes/databackd/` в `RouteServiceProvider`, импортите на `databackd/planning` и `databackd/forecasts` в `resources/scss/main.scss`.
4. Изпълнете миграциите: `php artisan migrate` (таблиците за планиране + регистрацията на модулите).
5. Компилирайте ресурсите (`npm run dev` / `npm run prod`) и изчистете кеша на изгледите (`php artisan view:clear`).
6. Включете модула `databackd_planning` в пакета на компанията (настройки на пакетите) – без флага маршрутите връщат отказ.
7. По желание – демо данни: `44PlanningDemoSeeder` (одобрени бюджети за 2026 по юридически лица и консолидиран групов бюджет, оптимистична чернова за 2027 и три запазени прогнози). Класът е `Database\Seeders\Databackd\Demo\PlanningDemoSeeder` (файл `44PlanningDemoSeeder.php`). Сийдърът е идемпотентен – изтрива и прекрасява бюджетите и прогнозите на компанията; разчита на демо компанията и демо данните (финансови записи) от `DatabackdDemoSeeder` и модул „Данни“. Демо сийдърът за известията (`48AlertsDemoSeeder`) очаква този сийдър да е изпълнен преди него.
8. Самопроверка на прогнозите: `php artisan databackd:check-forecasts`.

## Модулен флаг

`databackd_planning` – включва се по пакет (таблица `modules` / `packages.module_in_package`) и по роля (`admin`, `employee`) чрез настройките на модулите. Проверява се на всеки маршрут чрез `RequireModule::class . ':databackd_planning'`, а менютата 40–42 се показват само при `databackd_module('databackd_planning')`.

## Забележки

- Репото е **частно** и е за вътрешно ползване от екипа на Anagami; не съдържа секрети, пароли, ключове или данни за сървъри.
- Модулът не е изолиран пакет (няма service provider и `composer.json`) – той е изрязан срез от приложението AFV и се поддържа заедно с него.
