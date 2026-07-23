<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Integrity;

/**
 * Categories for IntegrityIssue. Compact, machine-friendly tags used both in
 * report output and in test assertions.
 *
 * Categories are not localized — they are technical identifiers.
 */
enum IssueCategory: string
{
    case TABLE_FILE_MISSING = 'table_file_missing';
    case INDEX_FILE_MISSING = 'index_file_missing';
    case INDEX_FILE_CORRUPT = 'index_file_corrupt';
    case INDEX_DRIFT = 'index_drift';
    case INDEX_UNRELIABLE = 'index_unreliable';
    case INDEX_FORMAT_OUTDATED = 'index_format_outdated';
    case ORPHAN_INDEX_FILE = 'orphan_index_file';
    case ORPHAN_DB_ENTRY = 'orphan_db_entry';
    case RECORD_KEY_ORDER = 'record_key_order';
    case RECORDS_NOT_SORTED_BY_PK = 'records_not_sorted_by_pk';
    case META_ENTRY_MISSING = 'meta_entry_missing';
    case META_LINE_COUNT_DRIFT = 'meta_line_count_drift';
    case META_LAST_ID_DRIFT = 'meta_last_id_drift';
    case META_ORPHAN_ENTRY = 'meta_orphan_entry';
    case TABLE_OPTIMIZED = 'table_optimized';
    case REPAIR_FAILED = 'repair_failed';
}
