<?php

declare(strict_types=1);

namespace Snicco\Component\BetterWPDB;

use Closure;
use Generator;
use InvalidArgumentException;
use LogicException;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;
use ReflectionException;
use RuntimeException;
use Snicco\Component\BetterWPDB\Exception\NoMatchingRowFound;
use Snicco\Component\BetterWPDB\Exception\QueryException;
use Throwable;

use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function microtime;
use function mysqli_report;
use function rtrim;
use function sprintf;
use function str_repeat;
use function strtr;

use const MYSQLI_ASSOC;
use const MYSQLI_OPT_INT_AND_FLOAT_NATIVE;

final class BetterWPDB
{
    private mysqli $mysqli;

    private ?string $original_sql_mode = null;

    private bool $in_transaction = false;

    private bool $is_handling_errors = false;

    private QueryLogger $logger;

    public function __construct(mysqli $mysqli, ?QueryLogger $logger = null)
    {
        $this->mysqli = $mysqli;
        $this->logger = $logger ?: new class() implements QueryLogger {
            public function log(QueryInfo $info): void
            {
                // do nothing
            }
        };
    }

    /**
     * @throws ReflectionException
     */
    public static function fromWpdb(?QueryLogger $logger = null): self
    {
        return new self(MysqliFactory::fromWpdbConnection(), $logger);
    }

    /**
     * @param non-empty-string   $sql
     * @param array<scalar|null> $bindings
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     */
    public function preparedQuery(string $sql, array $bindings = []): mysqli_stmt
    {
        return $this->runWithErrorHandling(function () use ($sql, $bindings): mysqli_stmt {
            $bindings = $this->convertBindings($bindings);

            try {
                $stmt = $this->createPreparedStatement($sql, $bindings);
            } catch (mysqli_sql_exception $e) {
                throw QueryException::fromMysqliE($sql, $bindings, $e);
            }

            $start = microtime(true);

            try {
                $stmt->execute();
            } catch (mysqli_sql_exception $e) {
                throw QueryException::fromMysqliE($sql, $bindings, $e);
            }

            $end = microtime(true);
            $this->log(new QueryInfo($start, $end, $sql, $bindings));

            return $stmt;
        });
    }

    /**
     * @param non-empty-string $sql
     *
     * @throws QueryException
     *
     * @return mysqli_result|true {@see mysqli::query()}
     */
    public function unprepared(string $sql)
    {
        return $this->runWithErrorHandling(function () use ($sql) {
            $start = microtime(true);

            try {
                /** @var mysqli_result|true $res */
                $res = $this->mysqli->query($sql);
            } catch (mysqli_sql_exception $e) {
                throw QueryException::fromMysqliE($sql, [], $e);
            }

            $end = microtime(true);
            $this->log(new QueryInfo($start, $end, $sql, []));

            return $res;
        });
    }

    /**
     * Runs the callback inside a database transaction that automatically
     * commits on success and rolls back if any errors happen.
     *
     * @template T
     *
     * @param Closure(BetterWPDB):T $callback
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     *
     * @psalm-return  T
     */
    public function transactional(Closure $callback)
    {
        if ($this->in_transaction) {
            throw new LogicException('Nested transactions are currently not supported.');
        }

        return $this->runWithErrorHandling(function () use ($callback) {
            try {
                $this->in_transaction = true;

                $start = microtime(true);

                try {
                    $this->mysqli->begin_transaction();
                } // @codeCoverageIgnoreStart
                catch (mysqli_sql_exception $e) {
                    throw QueryException::fromMysqliE('START TRANSACTION', [], $e);
                }

                /** @codeCoverageIgnoreEnd */
                $end = microtime(true);

                $this->log(new QueryInfo($start, $end, 'START TRANSACTION', []));

                $res = $callback($this);

                $start = microtime(true);

                try {
                    $this->mysqli->commit();
                } // @codeCoverageIgnoreStart
                catch (mysqli_sql_exception $e) {
                    throw QueryException::fromMysqliE('COMMIT', [], $e);
                }

                /** @codeCoverageIgnoreEnd */
                $end = microtime(true);

                $this->log(new QueryInfo($start, $end, 'COMMIT', []));

                $this->in_transaction = false;

                return $res;
            } catch (Throwable $e) {
                $this->mysqli->rollback();
                $this->in_transaction = false;

                throw $e;
            }
        });
    }

    /**
     * @param non-empty-string                                                             $table
     * @param int|non-empty-array<non-empty-string, int|non-empty-string>|non-empty-string $primary_key
     * @param non-empty-array<non-empty-string, scalar|null>                               $changes     !!! IMPORTANT !!!
     *                                                                                                  Keys of $data MUST never be user provided
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     */
    public function updateByPrimary(string $table, $primary_key, array $changes): int
    {
        /** @psalm-suppress DocblockTypeContradiction */
        if ('' === $primary_key) {
            throw new InvalidArgumentException('$primary_key can not be an empty-string.');
        }

        $primary_key = is_array($primary_key) ? $primary_key : [
            'id' => $primary_key,
        ];

        return $this->update($table, $primary_key, $changes);
    }

    /**
     * @param non-empty-string                               $table
     * @param non-empty-array<non-empty-string, scalar|null> $conditions
     * @param non-empty-array<non-empty-string, scalar|null> $changes    !!! IMPORTANT !!!
     *                                                                   Keys of $data MUST never be user provided
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     */
    public function update(string $table, array $conditions, array $changes): int
    {
        $this->validateTableName($table);
        $this->validateProvidedColumnNames(array_keys($conditions));
        $this->validateProvidedColumnNames(array_keys($changes));

        $table = $this->escIdentifier($table);
        $sql = sprintf('update %s set ', $table);

        $updates = [];
        $bindings = [];

        foreach ($changes as $col_name => $value) {
            $updates[] = $this->escIdentifier($col_name) . ' = ?';
            $bindings[] = $value;
        }

        [$wheres, $where_bindings] = $this->buildWhereArray($conditions);

        $sql .= implode(', ', $updates);
        $sql .= ' where ' . implode(' and ', $wheres);

        $stmt = $this->preparedQuery($sql, [...$bindings, ...$where_bindings]);

        return $stmt->affected_rows;
    }

    /**
     * @param non-empty-string                               $table
     * @param non-empty-array<non-empty-string, scalar|null> $conditions
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     *
     * @return int The number of deleted records
     */
    public function delete(string $table, array $conditions): int
    {
        $this->validateTableName($table);

        $table = $this->escIdentifier($table);
        $sql = sprintf('delete from %s where ', $table);

        [$wheres, $bindings] = $this->buildWhereArray($conditions);

        $sql .= implode(' and ', $wheres);

        $stmt = $this->preparedQuery($sql, $bindings);

        return $stmt->affected_rows;
    }

    /**
     * @param non-empty-string   $sql
     * @param array<scalar|null> $bindings
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     */
    public function select(string $sql, array $bindings): mysqli_result
    {
        return $this->preparedQuery($sql, $bindings)
            ->get_result();
    }

    /**
     * Returns the entire result set as associative array. This method is
     * preferred for small result sets. For large result sets this method will
     * cause memory issues, and it's better to use.
     *
     * {@see BetterWPDB::selectAll()}.
     *
     * @param non-empty-string   $sql
     * @param array<scalar|null> $bindings
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     *
     * @return list<array<string, bool|float|int|string|null>>
     */
    public function selectAll(string $sql, array $bindings): array
    {
        $val = $this->select($sql, $bindings)
            ->fetch_all(MYSQLI_ASSOC);
        /**
         * @var array<array<string, bool|float|int|string|null>> $val
         */
        return array_values($val);
    }

    /**
     * @param non-empty-string   $sql
     * @param array<scalar|null> $bindings
     *
     * @throws InvalidArgumentException
     * @throws NoMatchingRowFound
     * @throws QueryException
     *
     * @return array<string, float|int|string|null> Booleans are returned as (int) 1 or (int) 0
     */
    public function selectRow(string $sql, array $bindings): array
    {
        $stmt = $this->select($sql, $bindings);

        $res = $stmt->fetch_assoc();

        if (! is_array($res)) {
            throw new NoMatchingRowFound('No matching row found', $sql, $bindings);
        }

        /**
         * @var array<string, float|int|string|null> $res
         */
        return $res;
    }

    /**
     * @param non-empty-string   $sql
     * @param array<scalar|null> $bindings
     *
     * @throws InvalidArgumentException
     * @throws NoMatchingRowFound
     * @throws QueryException
     *
     * @return mixed
     */
    public function selectValue(string $sql, array $bindings)
    {
        $res = $this->selectRow($sql, $bindings);

        return array_values($res)[0] ?? null;
    }

    /**
     * @param non-empty-string                              $table
     * @param non-empty-array<non-empty-string,scalar|null> $conditions
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     */
    public function exists(string $table, array $conditions): bool
    {
        $this->validateTableName($table);
        $this->validateProvidedColumnNames(array_keys($conditions));

        $table = $this->escIdentifier($table);
        $sql = sprintf('select count(1) from %s where ', $table);

        $bindings = [];
        $wheres = [];

        foreach ($conditions as $col_name => $value) {
            $col_name = $this->escIdentifier($col_name);
            if (null === $value) {
                $wheres[] = sprintf('%s is null', $col_name);
            } else {
                $wheres[] = sprintf('%s = ?', $col_name);
                $bindings[] = $value;
            }
        }

        $sql .= implode(' and ', $wheres) . ' limit 1';

        $result = $this->preparedQuery($sql, $bindings);

        $result->bind_result($found);
        $result->fetch();

        return (int) $found > 0;
    }

    /**
     * This method should be used if you want to iterate over a big number of
     * records.
     *
     * @param non-empty-string       $sql
     * @param array<int,scalar|null> $bindings
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     *
     * @return Generator<array<string,bool|float|int|string|null>>
     */
    public function selectLazy(string $sql, array $bindings): Generator
    {
        $res = $this->select($sql, $bindings);

        while ($row = $res->fetch_assoc()) {
            yield $row;
        }
    }

    /**
     * @param non-empty-string                               $table
     * @param non-empty-array<non-empty-string, scalar|null> $data  !!! IMPORTANT !!!
     *                                                              Keys of $data MUST never be user provided
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     */
    public function insert(string $table, array $data): mysqli_stmt
    {
        $this->validateTableName($table);

        $column_names = array_keys($data);
        $this->validateProvidedColumnNames($column_names);

        $sql = $this->buildInsertSql($table, $column_names);

        return $this->preparedQuery($sql, array_values($data));
    }

    /**
     * Runs a bulk insert of records in a transaction. If any record can't be
     * inserted the entire transaction will be rolled back.
     *
     * @param non-empty-string                              $table
     * @param iterable<array<non-empty-string,scalar|null>> $records !!! IMPORTANT !!!
     *                                                               Keys of $data MUST never be user provided
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     *
     * @return int The number of inserted records
     */
    public function bulkInsert(string $table, iterable $records): int
    {
        $this->validateTableName($table);

        return $this->transactional(function () use ($table, $records): int {
            $stmt = null;
            $sql = null;
            $expected_types = null;
            $inserted = 0;

            foreach ($records as $record) {
                if (empty($record)) {
                    throw new InvalidArgumentException('Each record has to be a non-empty-array.');
                }

                // only create the insert sql once.
                $sql ??= $this->buildInsertSql($table, array_keys($record));

                // only create one prepared statement
                $stmt ??= $this->mysqli->prepare($sql);

                $bindings = $this->convertBindings($record);

                // Retrieve the expected types from the first record.
                if (null === $expected_types) {
                    $expected_types = (string) $this->paramTypes($bindings);
                }

                $record_types = (string) $this->paramTypes($bindings);
                if ($expected_types !== $record_types) {
                    throw new InvalidArgumentException(
                        sprintf(
                            "Records are not of consistent type.\nExpected: [%s] and got [%s] for record %d.",
                            rtrim(
                                strtr($expected_types, [
                                    's' => 'string,',
                                    'd' => 'double,',
                                    'i' => 'integer,',
                                ]),
                                ','
                            ),
                            rtrim(
                                strtr($record_types, [
                                    's' => 'string,',
                                    'd' => 'double,',
                                    'i' => 'integer,',
                                ]),
                                ','
                            ),
                            $inserted + 1
                        )
                    );
                }

                $stmt->bind_param($record_types, ...$bindings);

                $start = microtime(true);
                $stmt->execute();
                $end = microtime(true);

                /** @var array<scalar|null> $bindings */
                $this->log(new QueryInfo($start, $end, $sql, $bindings));

                $inserted += $stmt->affected_rows;
            }

            return $inserted;
        });
    }

    /**
     * @template T
     *
     * @param Closure():T $run_query
     *
     * @psalm-return  T
     */
    private function runWithErrorHandling(Closure $run_query)
    {
        if ($this->is_handling_errors) {
            return $run_query();
        }

        if (! isset($this->original_sql_mode)) {
            $this->original_sql_mode = $this->queryOriginalSqlMode();
        }

        // Turn on error reporting
        $this->mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $res = $this->mysqli->query("SET SESSION sql_mode='TRADITIONAL'");
        if (true !== $res) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Could not set mysql error reporting to traditional.');
            // @codeCoverageIgnoreEnd
        }

        $this->is_handling_errors = true;

        try {
            return $run_query();
        } finally {
            // Turn back to previous error reporting so that shitty wpdb doesn't break.
            $this->mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 0);
            mysqli_report(MYSQLI_REPORT_OFF);
            $this->mysqli->query(sprintf("SET SESSION sql_mode='%s'", $this->original_sql_mode));
            $this->is_handling_errors = false;
        }
    }

    private function queryOriginalSqlMode(): string
    {
        $stmt = $this->mysqli->query('SELECT @@SESSION.sql_mode');
        if (! $stmt instanceof mysqli_result) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Could not determine current mysqli mode.');
            // @codeCoverageIgnoreEnd
        }

        $res = $stmt->fetch_row();
        if (! is_array($res) || ! isset($res[0]) || ! is_string($res[0])) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Could not determine current mysqli mode.');
            // @codeCoverageIgnoreEnd
        }

        return$res[0];
    }

    /**
     * @param non-empty-string   $table
     * @param non-empty-string[] $column_names
     *
     * @psalm-return non-empty-string
     * @psalm-suppress MoreSpecificReturnType
     */
    private function buildInsertSql(string $table, array $column_names): string
    {
        $column_names = array_map(fn ($column_name): string => $this->escIdentifier($column_name), $column_names);
        $columns = implode(',', $column_names);
        $table = $this->escIdentifier($table);
        $placeholders = str_repeat('?,', count($column_names) - 1) . '?';

        /** @psalm-suppress LessSpecificReturnStatement */
        return sprintf('insert into %s (%s) values (%s)', $table, $columns, $placeholders);
    }

    /**
     * @param list<float|int|string|null> $bindings
     */
    private function createPreparedStatement(string $sql, array $bindings): mysqli_stmt
    {
        /** @var mysqli_stmt $stmt */
        $stmt = $this->mysqli->prepare($sql);

        $types = $this->paramTypes($bindings);

        if ($types) {
            $stmt->bind_param($types, ...$bindings);
        }

        return $stmt;
    }

    /**
     * @param non-empty-string $identifier
     */
    private function escIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array<float|int|string|null> $bindings
     */
    private function paramTypes(array $bindings): ?string
    {
        $types = '';
        foreach ($bindings as $binding) {
            if (is_float($binding)) {
                $types .= 'd';
            } elseif (is_int($binding)) {
                $types .= 'i';
            } else {
                $types .= 's';
            }
        }

        return empty($types) ? null : $types;
    }

    private function log(QueryInfo $query_info): void
    {
        $this->logger->log($query_info);
    }

    /**
     * @return list<float|int|string|null>
     */
    private function convertBindings(array $bindings): array
    {
        $b = [];

        foreach ($bindings as $binding) {
            if (! is_scalar($binding) && null !== $binding) {
                throw new InvalidArgumentException('All bindings have to be of type scalar or null.');
            }

            if (is_bool($binding)) {
                $binding = $binding ? 1 : 0;
            }

            $b[] = $binding;
        }

        return $b;
    }

    private function validateTableName(string $table): void
    {
        if ('' === $table) {
            throw new InvalidArgumentException('A table name must be a non-empty-string.');
        }
    }

    private function validateProvidedColumnNames(array $data): void
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Column names can not be an empty array.');
        }

        foreach ($data as $name) {
            if (! is_string($name) || '' === $name) {
                throw new InvalidArgumentException('All column names must be a non-empty-strings.');
            }
        }
    }

    /**
     * @param array<scalar|null> $conditions
     *
     * @return array{0: non-empty-list<string>, 1: list<scalar>}
     */
    private function buildWhereArray(array $conditions): array
    {
        if (empty($conditions)) {
            throw new InvalidArgumentException('Column names can not be an empty array.');
        }

        $wheres = [];
        $bindings = [];
        foreach ($conditions as $col_name => $value) {
            if (! is_string($col_name) || '' === $col_name) {
                throw new InvalidArgumentException('A column name must be a non-empty-string.');
            }

            $col_name = $this->escIdentifier($col_name);
            if (null === $value) {
                $wheres[] = sprintf('%s is null', $col_name);
            } else {
                $wheres[] = sprintf('%s = ?', $col_name);
                $bindings[] = $value;
            }
        }

        return [$wheres, $bindings];
    }
}
