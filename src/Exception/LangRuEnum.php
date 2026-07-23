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
    case INVALID_JSON = 'Недопустимый формат данных в файле хранилища: %s';
    case TABLE_NOT_FOUND = 'Таблица не найдена в схеме: %s';
    case TABLE_ALREADY_EXISTS = 'Таблица "%s" уже существует';
    case COLUMN_NOT_FOUND = 'В таблице "%s" нет колонки с именем "%s"';
    case TABLE_FILE_EXISTS = 'Файл таблицы уже существует: %s';
    case DATABASE_ALREADY_EXISTS = 'База данных уже существует по пути: %s';
    case INVALID_FILE_NAME = 'Недопустимое имя файла: %s';
    case INVALID_INDEX_FILE_NAME = 'Недопустимое имя файла индекса: %s';
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
    case REORDER_COLUMNS_UNKNOWN = 'reorderColumns для таблицы "%s": '
        . 'неизвестная колонка "%s"';
    case REORDER_COLUMNS_DUPLICATE = 'reorderColumns для таблицы "%s": '
        . 'дубликат колонки "%s"';
    case REORDER_COLUMNS_INCOMPLETE = 'reorderColumns для таблицы "%s": '
        . 'пропущены колонки "%s"';
    case INDEX_NOT_FOUND = 'В таблице "%s" нет индекса с именем "%s"';
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
    case RESTORE_FAILED = 'Восстановление не удалось: %s';
    case TYPE_MISMATCH = 'Таблица "%s", колонка "%s": ожидался тип %s, '
        . 'получен %s';
    case NULL_NOT_ALLOWED = 'Таблица "%s", колонка "%s" не допускает null';
    case REQUIRED_COLUMN_MISSING = 'Таблица "%s": отсутствует обязательная '
        . 'колонка "%s"';
    case INVALID_TEMPORAL_VALUE = 'Таблица "%s", колонка "%s": некорректное '
        . 'значение %s: "%s"';
    case ZERO_DATE = 'Таблица "%s", колонка "%s": нулевые даты недопустимы: '
        . '"%s"';
    case NUMERIC_PART_OUT_OF_RANGE = 'Таблица "%s", колонка "%s": значение %s '
        . 'вне диапазона: %s';
    case DTO_SCHEMA_MISMATCH = 'DTO %s не соответствует таблице "%s": %s';
    case DTO_NOT_REGISTERED = 'Для таблицы "%s" не зарегистрирован DTO; '
        . 'используйте методы *ByArray или registerDto()';
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
