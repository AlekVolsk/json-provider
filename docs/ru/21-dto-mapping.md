# Отображение в DTO — типизированные объекты

У одного и того же хендла `table()` две поверхности для записей, выбор — по
имени метода:

- **объектные методы** — `insert`, `update`, `selectAll`, `selectOne` —
  говорят типизированными DTO;
- **`*ByArray`-двойники** — `insertByArray`, `updateByArray`,
  `updateByIdByArray`, `selectAllByArray`, `selectOneByArray` — говорят сырыми
  записями `array<string,null|scalar>` и доступны всегда.

Массивная поверхность — фундамент (вся остальная документация использует её);
DTO-отображение — адаптер поверх. Хранение, схема и валидация не меняются: DTO
разворачивается в запись при записи и собирается из неё при чтении.

## Объявление DTO

DTO — обычный иммутабельный `final`-класс. Единственная точка связи с
библиотекой — атрибут `#[JsonProviderRecord]` с именем таблицы: это чистые
метаданные, они не влияют на финальность и иммутабельность. Типы колонок **не
переобъявляются**: их знает схема, PHP-тип свойства лишь говорит, в каком виде
нужно значение в коде.

```php
use AV\JsonProvider\Mapping\Attribute\JsonProviderRecord;

#[JsonProviderRecord('users')]
final class User
{
    public function __construct(
        public int $id,
        public string $email,
        public ?int $age,
        public Status $status,               // backed enum
        public \DateTimeImmutable $createdAt, // колонка datetime
    ) {}
}
```

Отображаются только промо-параметры конструктора. Колонки, которых нет в DTO,
подчиняются правилам массивного ядра (пропуск nullable → `null`, пропуск
non-nullable → ошибка на insert). Чтение — без рефлексии: карта компилируется
один раз при регистрации.

## Регистрация

Привязывайте класс после того, как его таблица создана. Таблица берётся из
атрибута, так что карта `table => class` руками не пишется:

```php
$db->registerDto(User::class, Order::class);
```

Регистрация **сразу компилирует и валидирует** DTO против схемы — имя
свойства↔колонки, PHP-тип ↔ тип колонки, nullability и бэкинг enum должны
совпадать. Любое несоответствие бросает `StorageException`
(`DTO_SCHEMA_MISMATCH`) здесь, а не на первом запросе.

## Соответствие типов

| Тип колонки | Тип свойства DTO |
| - | - |
| `string` / `int` / `float` / `bool` | тот же скаляр (`int` расширяется в `float`-свойство) |
| `date` / `time` / `timez` / `datetime` / `datetimez` | `\DateTimeImmutable` |
| `year` / `month` / `day` | `int` |
| `int` или `string` под enum | `BackedEnum` с совпадающим бэкингом |
| `<type>\|null` | nullable-свойство `?T` |

Temporal-колонки становятся настоящими объектами `DateTimeImmutable`;
конвертация часового пояса (UTC на диске, локаль в коде) — та же, что в массивном
API, см. [Модель схемы → Типы даты/времени](04-schema-model.md#типы-датывремени-и-часовые-пояса).

## Имена — snake_case ↔ camelCase

По умолчанию свойство отображается на snake_case-форму своего имени (`createdAt`
→ `created_at`), поэтому для обычного случая атрибут не нужен. Override нужен
только для действительно нерегулярного имени колонки:

```php
use AV\JsonProvider\Mapping\Attribute\JsonProviderColumn;

public function __construct(
    #[JsonProviderColumn('legacy_title')]
    public string $title,
) {}
```

## Enum

Свойство-backed enum хранится своим бэкинг-значением и собирается при чтении:

- **запись** — хранится `$status->value` (обычная `string`/`int`-колонка);
- **чтение** — `Status::from(<хранимое>)` восстанавливает кейс.

Хранимое значение без подходящего кейса бросает `INVALID_ENUM_VALUE`. Поскольку
nullability DTO обязана совпадать с колонкой, nullable-колонка требует
nullable-свойства enum (`?Status`), и хранимый `null` чисто отображается в
`null`.

> Если меняете контракт DTO (переименовали/убрали кейс enum, ужесточили
> nullability) — мигрируйте существующие значения в базе. Иначе чтение старых
> строк упадёт с `INVALID_ENUM_VALUE` / `DTO_HYDRATION_FAILED`.

## Объектные методы

```php
// insert — возвращает новый id (DTO не мутируется)
$id = $db->table('users')->insert(new User(
    id: 0,                       // игнорируется; назначается провайдером
    email: 'a@b.c',
    age: 30,
    status: Status::Active,
    createdAt: new \DateTimeImmutable(),
));

// selectOne — DTO или null
$user = $db->table('users')->where('id', '=', $id)->selectOne();

// selectAll — одноразовый генератор DTO (ленивая гидрация)
foreach ($db->table('users')->where('age', '>', 18)->selectAll() as $user) {
    // ...
}
// нужен повторно проходимый список? iterator_to_array(...)

// update — перезаписывает строку по собственному id из DTO всеми его полями
// (накопленный where() игнорируется)
$db->table('users')->update($user);
```

`insert` возвращает `int`-id (как и `insertByArray`), а не объект — объект и так
на руках. Частичный патч остаётся на массивной поверхности
(`updateByArray(['age' => 31])`); `update($dto)` — перезапись всей строки, что
ничего лишнего не стоит: запись всегда переписывается целиком.

Вызов объектного метода на таблице без зарегистрированного DTO бросает
`DTO_NOT_REGISTERED` — используйте `*ByArray`-двойник.
