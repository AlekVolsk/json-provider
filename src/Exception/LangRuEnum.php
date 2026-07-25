<?php

declare(strict_types=1);

namespace AV\JsonProvider\Exception;

/**
 * Русская локаль.
 * name  = ключ ошибки (совпадает с именами кейсов во всех остальных локалях).
 * value = строка перевода, плейсхолдеры — позиционные %s.
 */
enum LangRuEnum: string implements LocaleInterface
{
    case FILE_NOT_READABLE = 'Файл хранилища недоступен для чтения: %s';
    case FILE_NOT_WRITABLE = 'Файл хранилища недоступен для записи: %s';
    case LOCK_FAILED = 'Не удалось установить блокировку файла: %s';
    case LOCK_TIMEOUT = 'Не удалось получить блокировку %s на %s '
        . 'за %s с';
    case LOCK_ORDER_VIOLATION = 'Нарушен порядок блокировок: %s (порядок '
        . 'захвата: сначала база, затем таблицы по возрастанию имени, '
        . 'повышение режима запрещено)';
    case WRITE_LOCK_REQUIRED = 'Внутренняя ошибка: %s требует EX-блокировку '
        . 'таблицы "%s"';
    case LOCK_DEPTH_DESYNC = 'Внутренняя ошибка: рассинхронизирована глубина '
        . 'блокировки БД при снятии блокировок';
    case SCHEMA_TRANSFORM_NO_RESULT = 'Внутренняя ошибка: преобразование '
        . 'схемы таблицы "%s" не дало результата';
    case INVALID_JSON = 'Недопустимый формат данных в файле хранилища: %s';
    case TABLE_NOT_FOUND = 'Таблица не найдена в схеме: %s';
    case TABLE_ALREADY_EXISTS = 'Таблица "%s" уже существует';
    case COLUMN_NOT_FOUND = 'В таблице "%s" нет колонки с именем "%s"';
    case TABLE_FILE_EXISTS = 'Файл таблицы уже существует: %s';
    case DATABASE_ALREADY_EXISTS = 'База данных уже существует по пути: %s';
    case INVALID_FILE_NAME = 'Недопустимое имя файла: %s';
    case INVALID_INDEX_FILE_NAME = 'Недопустимое имя файла индекса: %s';
    case INVALID_TABLE_NAME = 'Недопустимое имя таблицы "%s": должно '
        . 'начинаться с буквы, цифры или подчёркивания и содержать только '
        . 'буквы, цифры, подчёркивания и дефисы (не более 64 символов, без '
        . 'точек и разделителей пути)';
    case INVALID_COLUMN_NAME = 'Недопустимое имя колонки "%s": должно '
        . 'начинаться с буквы, цифры или подчёркивания и содержать только '
        . 'буквы, цифры, подчёркивания и дефисы (не более 64 символов, без '
        . 'точек и разделителей пути)';
    case INVALID_INDEX_NAME = 'Недопустимое имя индекса "%s": должно '
        . 'начинаться с буквы, цифры или подчёркивания и содержать только '
        . 'буквы, цифры, подчёркивания и дефисы (не более 64 символов, без '
        . 'точек и разделителей пути)';
    case RESERVED_INDEX_NAME = 'Имя индекса "%s" зарезервировано: префикс '
        . '"_fk_" отведён под служебные FK-индексы движка';
    case INVALID_COLUMN_TYPE = 'Таблица "%s", колонка "%s": неизвестный тип '
        . 'колонки "%s"; допустимые типы — string, int, float, bool, date, '
        . 'time, timez, datetime, datetimez, year, month, day, каждый '
        . 'опционально с суффиксом "|null"';
    case RELATION_ENTRY_INVALID = 'Некорректная запись relation в '
        . 'information_schema.json: %s';
    case RELATION_ACTION_INVALID = 'Некорректное действие %s связи: "%s"; '
        . 'допустимые значения — noAction, cascade, setNull, restrict';
    case RELATION_COLUMN_NOT_FOUND = 'Связь %s(%s) -> %s(%s): колонка "%s" '
        . 'не существует в таблице "%s" (у belongsTo FK-колонка лежит в '
        . 'from-таблице; у hasMany/hasOne — в to-таблице)';
    case RELATION_TYPE_MISMATCH = 'Несовпадение типов связи: FK-колонка '
        . '"%s"."%s" (%s) должна иметь тот же базовый тип, что и целевая '
        . 'колонка "%s"."%s" (%s); суффикс "|null" не учитывается';
    case RELATION_ALREADY_EXISTS = 'Связь %s(%s) -> %s уже объявлена как: '
        . '%s (одно FK-ребро объявляется один раз, в любой из нотаций)';
    case RELATION_NOT_FOUND = 'Связь %s(%s) -> %s не объявлена';
    case RELATION_REFERENCES_NOT_UNIQUE = 'Связь ссылается на неуникальную '
        . 'колонку: "%s"."%s" должна быть первичным ключом либо покрываться '
        . 'одноколоночным уникальным ограничением';
    case RELATION_ON_UPDATE_ON_PK = 'Связь %s -> %s: onUpdate объявлен на '
        . 'неизменяемом первичном ключе "id" и не сработает никогда — '
        . 'уберите onUpdate или сошлитесь на не-PK уникальную колонку';
    case FOREIGN_KEY_SET_NULL_NOT_NULLABLE = 'SET NULL объявлен на '
        . 'FK-колонке "%s"."%s", которая не допускает null — сделайте '
        . 'колонку "|null" или смените действие';
    case FK_BACKING_INDEX_MISSING = 'Служебный FK-индекс для "%s"."%s" '
        . 'отсутствует или не покрывает FK-колонку — выполните repair(), '
        . 'чтобы достроить его';
    case INDEX_ALREADY_EXISTS = 'В таблице "%s" уже есть индекс с именем '
        . '"%s"';
    case UNIQUE_CONSTRAINT_ALREADY_EXISTS = 'В таблице "%s" уже есть '
        . 'уникальное ограничение с именем "%s"';
    case UNIQUE_CONSTRAINT_NOT_FOUND = 'В таблице "%s" нет уникального '
        . 'ограничения с именем "%s"';
    case COLUMN_ALREADY_EXISTS = 'В таблице "%s" уже есть колонка с именем '
        . '"%s"';
    case FOREIGN_KEY_RESTRICT = 'Операция заблокирована RESTRICT: в таблице '
        . '"%s" есть строки, чьё поле "%s" ссылается на затронутые строки '
        . 'таблицы "%s"';
    case RENAME_INCOMPLETE = 'Предыдущий renameTable "%s" -> "%s" не '
        . 'завершился; выполните repair() базы прежде, чем трогать '
        . 'затронутые таблицы';
    case UNIQUE_VIOLATION = 'Нарушение уникального ограничения в таблице "%s" '
        . 'в полях [%s]: %s';
    case INVALID_RECORD = 'Недопустимая запись в таблице "%s": %s';
    case SCHEMA_NOT_FOUND = 'Файл схемы не найден: %s';
    case INVALID_SCHEMA = 'Ошибка схемы хранилища: %s';
    case SCHEMA_SERIALIZE_FAILED = 'Не удалось сериализовать схему';
    case EXTENSION_REQUIRED = 'Для использования адаптера кеширования '
        . 'необходимо PHP-расширение: %s';
    case PK_CONTRACT_VIOLATED = 'Нарушен контракт первичного ключа для таблицы '
        . '"%s": %s';
    case META_ENTRY_MISSING = 'В meta.json нет записи для таблицы "%s" — '
        . 'таблица не зарегистрирована провайдером';
    case META_ENTRY_CORRUPT = 'Запись меты таблицы "%s" повреждена: %s — '
        . 'выполните repair(), чтобы пересчитать счётчики из данных';
    case REORDER_COLUMNS_UNKNOWN = 'reorderColumns для таблицы "%s": '
        . 'неизвестная колонка "%s"';
    case REORDER_COLUMNS_DUPLICATE = 'reorderColumns для таблицы "%s": '
        . 'дубликат колонки "%s"';
    case REORDER_COLUMNS_INCOMPLETE = 'reorderColumns для таблицы "%s": '
        . 'пропущены колонки "%s"';
    case INDEX_NOT_FOUND = 'В таблице "%s" нет индекса с именем "%s"';
    case INDEX_UNRELIABLE = 'Индекс "%s" таблицы "%s" структурно повреждён '
        . 'и не может использоваться: %s';
    case INDEX_KEY_NON_FINITE = 'Ключ индекса для поля "%s": NAN и INF '
        . 'не индексируемы';
    case QUERY_UNKNOWN_COLUMN = 'В таблице "%s" нет колонки "%s" '
        . '(указана в %s)';
    case CONDITION_TYPE_MISMATCH = 'Таблица "%s", колонка "%s", оператор '
        . '%s: значение условия должно быть %s, получено %s';
    case CONDITION_MALFORMED = 'Таблица "%s", колонка "%s", оператор %s: %s';
    case INVALID_SORT_DIRECTION = 'Таблица "%s": недопустимое направление '
        . 'сортировки "%s" (ожидается "asc" или "desc", без учёта регистра)';
    case INVALID_OPERATOR = 'Таблица "%s": неизвестный оператор '
        . 'фильтрации "%s"';
    case LIKE_EVALUATION_FAILED = 'LIKE-шаблон "%s" не удалось '
        . 'вычислить: %s';
    case INVALID_LIMIT = 'Таблица "%s": limit должен быть неотрицательным '
        . 'целым, получено %s';
    case INVALID_OFFSET = 'Таблица "%s": offset должен быть неотрицательным '
        . 'целым, получено %s';
    case MIGRATE_COLUMN_TYPE_CHANGE = 'migrateColumns для таблицы "%s": смена '
        . 'типа колонки "%s" (%s -> %s) не поддерживается; мигрируйте данные '
        . 'отдельно';
    case MIGRATE_COLUMN_NO_DEFAULT = 'migrateColumns для таблицы "%s": нельзя '
        . 'добавить not-null колонку "%s" типа %s в непустую таблицу (нет '
        . 'значения по умолчанию); объявите её nullable';
    case MIGRATE_FIELD_UNKNOWN_COLUMN = 'migrateColumns для таблицы "%s": %s '
        . 'ссылается на неизвестную колонку "%s"';
    case BACKUP_ARCHIVE_EXISTS = 'Файл резервной копии уже существует: %s';
    case BACKUP_DESTINATION_INSIDE_DB = 'Путь резервной копии должен быть вне '
        . 'каталога БД: %s';
    case BACKUP_ARCHIVE_CORRUPT = 'Архив резервной копии повреждён: %s';
    case BACKUP_SCHEMA_MISMATCH = 'Схема резервной копии не совпадает '
        . 'со схемой БД: %s';
    case BACKUP_CHECKSUM_MISMATCH = 'Элемент архива резервной копии "%s" '
        . 'не прошёл проверку контрольной суммы; архив повреждён или '
        . 'подменён';
    case RESTORE_FAILED = 'Восстановление не удалось: %s';
    case TYPE_MISMATCH = 'Таблица "%s", колонка "%s": ожидался тип %s, '
        . 'получен %s';
    case NULL_NOT_ALLOWED = 'Таблица "%s", колонка "%s" не допускает null';
    case REQUIRED_COLUMN_MISSING = 'Таблица "%s": отсутствует обязательная '
        . 'колонка "%s"';
    case NON_FINITE_FLOAT = 'Таблица "%s", колонка "%s": NAN и INF '
        . 'не сохраняемы как float';
    case INVALID_UTF8 = 'Таблица "%s", колонка "%s": строка не является '
        . 'корректной UTF-8';
    case INVALID_TEMPORAL_VALUE = 'Таблица "%s", колонка "%s": некорректное '
        . 'значение %s: "%s"';
    case ZERO_DATE = 'Таблица "%s", колонка "%s": нулевые даты недопустимы: '
        . '"%s"';
    case TEMPORAL_FRACTION_UNSUPPORTED = 'Таблица "%s", колонка "%s": %s не '
        . 'принимает заданную точность долей секунды: "%s"';
    case LIKE_ON_INSTANT_UNSUPPORTED = 'Таблица "%s", колонка "%s": LIKE по '
        . 'колонке datetime/datetimez не поддерживается (хранится в UTC); '
        . 'используйте операторы сравнения или BETWEEN';
    case NUMERIC_PART_OUT_OF_RANGE = 'Таблица "%s", колонка "%s": значение %s '
        . 'вне диапазона: %s';
    case DTO_SCHEMA_MISMATCH = 'DTO %s не соответствует таблице "%s": %s';
    case DTO_NOT_REGISTERED = 'Для таблицы "%s" не зарегистрирован DTO; '
        . 'используйте методы *ByArray или registerDto()';
    case DTO_ALREADY_REGISTERED = 'Для таблицы "%s" уже зарегистрирован DTO '
        . '%s; снимите его через unregister перед привязкой %s';
    case INVALID_ENUM_VALUE = 'Таблица "%s", колонка "%s": хранимое значение '
        . '"%s" не является кейсом %s';
    case DTO_HYDRATION_FAILED = 'Таблица "%s", колонка "%s": не удалось '
        . 'гидрировать DTO — %s';

    public static function translate(string $key, string ...$params): string
    {
        foreach (self::cases() as $case) {
            if ($case->name === $key) {
                return $params !== []
                    ? \sprintf($case->value, ...$params)
                    : $case->value;
            }
        }

        return $key;
    }
}
