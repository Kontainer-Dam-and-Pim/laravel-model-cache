<?php

declare(strict_types=1);

namespace Kontainer\ModelCache\Tests\Unit\Support\Sql;

use Kontainer\ModelCache\Support\Sql\DeleteStatement;
use Kontainer\ModelCache\Support\Sql\Dialect;
use Kontainer\ModelCache\Support\Sql\Dialects;
use Kontainer\ModelCache\Support\Sql\GenericDialect;
use Kontainer\ModelCache\Support\Sql\InsertStatement;
use Kontainer\ModelCache\Support\Sql\MergeStatement;
use Kontainer\ModelCache\Support\Sql\Parser;
use Kontainer\ModelCache\Support\Sql\ParserException;
use Kontainer\ModelCache\Support\Sql\TruncateStatement;
use Kontainer\ModelCache\Support\Sql\UpdateStatement;
use Kontainer\ModelCache\Support\Sql\WriteTargets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ported from sqlparser-rs tests/sqlparser_{common,mysql,postgres,sqlite,mssql}.rs (Apache-2.0), commit 8a176d9: the
 * cases that parse a write or a FROM clause, asserting the tables and columns the cache extracts on each dialect that runs
 * them, and that syntax the cache cannot map to tables throws. The `port*` providers cover what the port adds beyond them.
 */
final class ParserTest extends TestCase
{
    private const array ALL = ['mysql', 'pgsql', 'sqlite', 'sqlsrv'];

    private const array ALL_AND_GENERIC = ['generic', ...self::ALL];

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function commonWrites(): iterable
    {
        yield 'parse_insert_values #1' => ['INSERT customer VALUES (1, 2, 3)', self::ALL, 'INSERT customer'];
        yield 'parse_insert_values #2' => ['INSERT INTO customer VALUES (1, 2, 3)', self::ALL, 'INSERT customer'];
        yield 'parse_insert_values #3' => ['INSERT INTO customer VALUES (1, 2, 3), (1, 2, 3)', self::ALL, 'INSERT customer'];
        yield 'parse_insert_values #4' => ['INSERT INTO public.customer VALUES (1, 2, 3)', self::ALL, 'INSERT public.customer'];
        yield 'parse_insert_values #5' => ['INSERT INTO db.public.customer VALUES (1, 2, 3)', self::ALL, 'INSERT db.public.customer'];
        yield 'parse_insert_values #6' => ['INSERT INTO public.customer (id, name, active) VALUES (1, 2, 3)', self::ALL, 'INSERT public.customer'];
        yield 'parse_insert_values #7' => ['INSERT INTO t (id, name, active) VALUE (1, 2, 3)', ['mysql'], 'INSERT t'];
        yield 'parse_insert_values #8' => ['INSERT INTO customer WITH foo AS (SELECT 1) SELECT * FROM foo UNION VALUES (1)', self::ALL, 'INSERT customer'];
        yield 'parse_insert_set #1' => ["INSERT INTO tbl1 SET col1 = 1, col2 = 'abc', col3 = current_date()", ['mysql'], 'INSERT tbl1'];
        yield 'parse_insert_default_values #1' => ['INSERT INTO test_table DEFAULT VALUES', self::ALL, 'INSERT test_table'];
        yield 'parse_insert_default_values #2' => ['INSERT INTO test_table DEFAULT VALUES RETURNING test_column', self::ALL, 'INSERT test_table'];
        yield 'parse_insert_default_values #3' => ['INSERT INTO test_table DEFAULT VALUES ON CONFLICT DO NOTHING', self::ALL, 'INSERT test_table'];
        yield 'parse_insert_select_returning #1' => ['INSERT INTO t SELECT 1 RETURNING 2', self::ALL, 'INSERT t'];
        yield 'parse_insert_select_returning #2' => ['INSERT INTO t SELECT x RETURNING x AS y', self::ALL, 'INSERT t'];
        yield 'parse_insert_select_from_returning #1' => ['INSERT INTO table1 SELECT * FROM table2 RETURNING id', self::ALL, 'INSERT table1'];
        yield 'parse_insert_nested_parenthesized_subquery #1' => ['INSERT INTO t ((SELECT 1))', self::ALL, 'INSERT t'];
        yield 'parse_insert_nested_parenthesized_subquery #2' => ['INSERT INTO t (((SELECT 1)))', self::ALL, 'INSERT t'];
        yield 'parse_insert_sqlite #1' => ['INSERT INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table'];
        yield 'parse_insert_sqlite #2' => ['REPLACE INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table REPLACING ROWS'];
        yield 'parse_insert_sqlite #3' => ['INSERT OR REPLACE INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table REPLACING ROWS'];
        yield 'parse_insert_sqlite #4' => ['INSERT OR ROLLBACK INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table'];
        yield 'parse_insert_sqlite #5' => ['INSERT OR ABORT INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table'];
        yield 'parse_insert_sqlite #6' => ['INSERT OR FAIL INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table'];
        yield 'parse_insert_sqlite #7' => ['INSERT OR IGNORE INTO test_table(id) VALUES(1)', ['sqlite'], 'INSERT test_table'];
        yield 'parse_update #1' => ['UPDATE t SET a = 1, b = 2, c = 3 WHERE d', self::ALL, 'UPDATE t SET a, b, c'];
        yield 'parse_update #2' => ['UPDATE t SET a = 1, a = 2, a = 3', self::ALL, 'UPDATE t SET a, a, a'];
        yield 'parse_update_set_from #1' => ['UPDATE t1 SET name = t2.name FROM (SELECT name, id FROM t1 GROUP BY id) AS t2 WHERE t1.id = t2.id', ['pgsql', 'sqlite', 'sqlsrv'], 'UPDATE t1 SET name'];
        yield 'parse_update_set_from #2' => ['UPDATE T SET a = b FROM U, (SELECT foo FROM V) AS W WHERE 1 = 1', ['pgsql', 'sqlite', 'sqlsrv'], 'UPDATE T SET a'];
        yield 'parse_update_with_table_alias #1' => ["UPDATE users AS u SET u.username = 'new_user' WHERE u.username = 'old_user'", self::ALL, 'UPDATE users SET username'];
        yield 'parse_update_or #2' => ['UPDATE OR ROLLBACK t SET n = n + 1', self::ALL, 'UPDATE t SET n'];
        yield 'parse_update_or #3' => ['UPDATE OR ABORT t SET n = n + 1', self::ALL, 'UPDATE t SET n'];
        yield 'parse_update_or #4' => ['UPDATE OR FAIL t SET n = n + 1', self::ALL, 'UPDATE t SET n'];
        yield 'parse_update_or #5' => ['UPDATE OR IGNORE t SET n = n + 1', self::ALL, 'UPDATE t SET n'];
        yield 'parse_delete_statement #1' => ['DELETE FROM "table"', self::ALL, 'DELETE table'];
        yield 'parse_delete_statement_for_multi_tables #1' => ['DELETE schema1.table1, schema2.table2 FROM schema1.table1 JOIN schema2.table2 ON schema2.table2.col1 = schema1.table1.col1 WHERE schema2.table2.col2 = 1', self::ALL, 'DELETE schema1.table1, schema2.table2'];
        yield 'parse_delete_statement_for_multi_tables_with_using #1' => ['DELETE FROM schema1.table1, schema2.table2 USING schema1.table1 JOIN schema2.table2 ON schema2.table2.pk = schema1.table1.col1 WHERE schema2.table2.col2 = 1', self::ALL, 'DELETE schema1.table1, schema2.table2'];
        yield 'parse_where_delete_statement #1' => ['DELETE FROM foo WHERE name = 5', self::ALL, 'DELETE foo'];
        yield 'parse_where_delete_with_alias_statement #1' => ['DELETE FROM basket AS a USING basket AS b WHERE a.id < b.id', self::ALL, 'DELETE basket'];
        yield 'parse_merge #1' => ["MERGE INTO s.bar AS dest USING (SELECT * FROM s.foo) AS stg ON dest.D = stg.D AND dest.E = stg.E WHEN NOT MATCHED THEN INSERT (A, B, C) VALUES (stg.A, stg.B, stg.C) WHEN MATCHED AND dest.A = 'a' THEN UPDATE SET dest.F = stg.F, dest.G = stg.G WHEN MATCHED THEN DELETE", self::ALL, 'MERGE s.bar'];
        yield 'parse_merge #2' => ["MERGE s.bar AS dest USING (SELECT * FROM s.foo) AS stg ON dest.D = stg.D AND dest.E = stg.E WHEN NOT MATCHED THEN INSERT (A, B, C) VALUES (stg.A, stg.B, stg.C) WHEN MATCHED AND dest.A = 'a' THEN UPDATE SET dest.F = stg.F, dest.G = stg.G WHEN MATCHED THEN DELETE", self::ALL, 'MERGE s.bar'];
        yield 'parse_merge #3' => ['MERGE INTO s.bar AS dest USING newArrivals AS S ON (1 > 1) WHEN NOT MATCHED THEN INSERT VALUES (stg.A, stg.B, stg.C)', self::ALL, 'MERGE s.bar'];
        yield 'parse_merge #4' => ["MERGE INTO FOO USING FOO_IMPORT ON (FOO.ID = FOO_IMPORT.ID) WHEN MATCHED THEN UPDATE SET FOO.NAME = FOO_IMPORT.NAME WHERE 1 = 1 DELETE WHERE FOO.NAME LIKE '%.DELETE' WHEN NOT MATCHED THEN INSERT (ID, NAME) VALUES (FOO_IMPORT.ID, UPPER(FOO_IMPORT.NAME)) WHERE NOT FOO_IMPORT.NAME LIKE '%.DO_NOT_INSERT'", self::ALL, 'MERGE FOO'];
        yield 'parse_merge #5' => ["MERGE INTO FOO USING FOO_IMPORT ON (FOO.ID = FOO_IMPORT.ID) WHEN NOT MATCHED THEN INSERT (ID, NAME) VALUES (1, 'abc')", self::ALL, 'MERGE FOO'];
        yield 'parse_merge #6' => ["MERGE INTO FOO USING FOO_IMPORT ON (FOO.ID = FOO_IMPORT.ID) WHEN NOT MATCHED THEN INSERT (FOO.ID, FOO.NAME) VALUES (1, 'abc')", self::ALL, 'MERGE FOO'];
        yield 'parse_merge #7' => ["MERGE INTO PLAYGROUND.FOO USING FOO_IMPORT ON (PLAYGROUND.FOO.ID = FOO_IMPORT.ID) WHEN NOT MATCHED THEN INSERT (PLAYGROUND.FOO.ID, PLAYGROUND.FOO.NAME) VALUES (1, 'abc')", self::ALL, 'MERGE PLAYGROUND.FOO'];
        yield 'parse_merge #8' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED THEN UPDATE SET * WHEN NOT MATCHED THEN INSERT *', self::ALL, 'MERGE target'];
        yield 'parse_merge #9' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED AND source.active = 1 THEN UPDATE SET *', self::ALL, 'MERGE target'];
        yield 'parse_merge #10' => ['MERGE INTO target USING source ON target.id = source.id WHEN NOT MATCHED BY TARGET THEN INSERT *', self::ALL, 'MERGE target'];
        yield 'parse_merge #11' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED THEN UPDATE SET * WHEN NOT MATCHED THEN INSERT (a, b) VALUES (source.a, source.b)', self::ALL, 'MERGE target'];
        yield 'parse_merge #12' => ['MERGE INTO t1 AS target USING (SELECT * FROM t2) AS source ON target.id = source.id WHEN MATCHED THEN UPDATE SET * WHEN NOT MATCHED THEN INSERT *', self::ALL, 'MERGE t1'];
        yield 'test_merge_with_returning #1' => ['MERGE INTO wines AS w USING wine_stock_changes AS s ON s.winename = w.winename WHEN NOT MATCHED AND s.stock_delta > 0 THEN INSERT VALUES (s.winename, s.stock_delta) WHEN MATCHED AND w.stock + s.stock_delta > 0 THEN UPDATE SET stock = w.stock + s.stock_delta WHEN MATCHED THEN DELETE RETURNING merge_action(), w.*', self::ALL, 'MERGE wines'];
        yield 'test_merge_with_output #1' => ['MERGE INTO target_table USING source_table ON target_table.id = source_table.oooid WHEN MATCHED THEN UPDATE SET target_table.description = source_table.description WHEN NOT MATCHED THEN INSERT (ID, description) VALUES (source_table.id, source_table.description) OUTPUT inserted.* INTO log_target', ['mysql', 'pgsql', 'sqlite'], 'MERGE target_table'];
        yield 'test_merge_with_output_without_into #1' => ['MERGE INTO a USING b ON a.id = b.id WHEN MATCHED THEN DELETE OUTPUT inserted.*', self::ALL, 'MERGE a'];
        yield 'test_merge_into_using_table #1' => ['MERGE INTO target_table USING source_table ON target_table.id = source_table.oooid WHEN MATCHED THEN UPDATE SET target_table.description = source_table.description WHEN NOT MATCHED THEN INSERT (ID, description) VALUES (source_table.id, source_table.description)', self::ALL, 'MERGE target_table'];
        yield 'test_merge_with_delimiter #1' => ['MERGE INTO target_table USING source_table ON target_table.id = source_table.oooid WHEN MATCHED THEN UPDATE SET target_table.description = source_table.description WHEN NOT MATCHED THEN INSERT (ID, description) VALUES (source_table.id, source_table.description);', self::ALL, 'MERGE target_table'];
        yield 'test_truncate_table_with_on_cluster #1' => ['TRUNCATE TABLE t', self::ALL, 'TRUNCATE t'];
        yield 'parse_update_from_before_select #1' => ['UPDATE t1 FROM (SELECT name, id FROM t1 GROUP BY id) AS t2 SET name = t2.name WHERE t1.id = t2.id', self::ALL, 'UPDATE t1 SET name'];
        yield 'parse_update_from_before_select #2' => ['UPDATE t1 FROM U, (SELECT id FROM V) AS W SET a = b WHERE 1 = 1', self::ALL, 'UPDATE t1 SET a'];
        yield 'parse_update_from_before_select #3' => ['UPDATE t1 FROM (SELECT name, id FROM t1 GROUP BY id) AS t2 SET name = t2.name FROM (SELECT name from t2) AS t2', self::ALL, 'UPDATE t1 SET name'];
        yield 'parse_truncate_only #1' => ['TRUNCATE TABLE employee, ONLY dept', self::ALL, 'TRUNCATE employee, dept'];
        yield 'parse_insert_by_name #1' => ['INSERT INTO target BY NAME SELECT 1 AS a', self::ALL, 'INSERT target'];
        yield 'parse_insert_by_name #2' => ['INSERT INTO target (a) BY NAME SELECT 1 AS a', self::ALL, 'INSERT target'];
        yield 'parse_insert_by_name #3' => ['INSERT INTO TABLE target PARTITION (p = 1) BY NAME SELECT 1 AS a', ['mysql', 'sqlite', 'sqlsrv'], 'INSERT target'];
        yield 'parse_insert_by_name #4' => ['INSERT INTO target AS t BY NAME SELECT 1 AS a', ['pgsql'], 'INSERT target'];
        yield 'parse_table_preserves_quotes_and_trailing_tokens #1' => ['INSERT INTO t TABLE `%mpty`', ['mysql'], 'INSERT t'];
        yield 'parse_singular_value_keyword #1' => ['INSERT INTO t (a) VALUE (1)', ['generic', 'mysql'], 'INSERT t'];
        yield 'parse_singular_value_keyword #3' => ['INSERT INTO t VALUE (1)', ['generic', 'mysql'], 'INSERT t'];
        yield 'insert_into_with_parentheses #1' => ['INSERT INTO t1 (id, name) (SELECT t2.id, t2.name FROM t2)', ['generic'], 'INSERT t1'];
        yield 'insert_into_with_parentheses #3' => ['INSERT INTO t1 (SELECT t2.id, t2.name FROM t2)', ['generic'], 'INSERT t1'];
        yield 'insert_into_with_parentheses #5' => ['INSERT INTO t1 ("select", name) (SELECT t2.name FROM t2)', ['generic'], 'INSERT t1'];
        yield 'test_truncate_table_with_on_cluster #2' => ['TRUNCATE TABLE t ON CLUSTER cluster_name', self::ALL_AND_GENERIC, 'TRUNCATE t'];
        yield 'parse_create_procedure_begin_atomic #1' => ['INSERT INTO t VALUES (1)', self::ALL_AND_GENERIC, 'INSERT t'];
        yield 'parse_multiple_statements #2' => ['DELETE FROM foo;SELECT bar', self::ALL_AND_GENERIC, 'DELETE foo'];
        yield 'parse_multiple_statements #4' => ['INSERT INTO foo VALUES (1);SELECT bar', self::ALL_AND_GENERIC, 'INSERT foo'];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function commonUnsupportedWrites(): iterable
    {
        yield 'parse_replace_into #1' => ['REPLACE INTO public.customer (id, name, active) VALUES (1, 2, 3)', ['pgsql']];
        yield 'parse_update #3' => ['UPDATE t WHERE 1', self::ALL];
        yield 'parse_update_or #1' => ['UPDATE OR REPLACE t SET n = n + 1', self::ALL];
        yield 'parse_delete_without_from_error #1' => ['DELETE "table" WHERE 1', self::ALL];
        yield 'parse_cte_in_data_modification_statements #1' => ['WITH x AS (SELECT 1) UPDATE t SET bar = (SELECT * FROM x)', self::ALL];
        yield 'parse_cte_in_data_modification_statements #2' => ['WITH t (x) AS (SELECT 9) DELETE FROM q WHERE id IN (SELECT x FROM t)', self::ALL];
        yield 'parse_cte_in_data_modification_statements #3' => ['WITH x AS (SELECT 42) INSERT INTO t SELECT foo FROM x', self::ALL];
        yield 'test_merge_with_output #2' => ['MERGE INTO target_table USING source_table ON target_table.id = source_table.oooid WHEN MATCHED THEN UPDATE SET target_table.description = source_table.description WHEN NOT MATCHED THEN INSERT (ID, description) VALUES (source_table.id, source_table.description) OUTPUT inserted.* INTO log_target', ['sqlsrv']];
        yield 'parse_update_deeply_nested_parens_hits_recursion_limits #1' => ["\nUPDATE\n\n\n\n\n\n\n\n\n\n".str_repeat('(', 1000), self::ALL];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function commonReads(): iterable
    {
        yield 'parse_select_with_table_alias_as #1' => ['SELECT a, b, c FROM lineitem AS l (A, B, C)', self::ALL, ['lineitem']];
        yield 'parse_select_with_table_alias_as #2' => ['SELECT a, b, c FROM lineitem l (A, B, C)', self::ALL, ['lineitem']];
        yield 'parse_select_with_table_alias #1' => ['SELECT a, b, c FROM lineitem AS l (A, B, C)', self::ALL, ['lineitem']];
        yield 'parse_limit_is_not_an_alias #1' => ['SELECT id FROM customer LIMIT 1', self::ALL, ['customer']];
        yield 'parse_in_subquery #1' => ['SELECT * FROM customers WHERE segment IN (SELECT segm FROM bar)', self::ALL, ['customers', 'bar']];
        yield 'parse_in_subquery #2' => ['SELECT segm FROM bar', self::ALL, ['bar']];
        yield 'parse_in_table_expression #1' => ['SELECT a IN t FROM t', ['sqlite'], ['t', 't']];
        yield 'parse_in_table_expression #2' => ['SELECT a NOT IN t FROM t', ['sqlite'], ['t', 't']];
        yield 'parse_in_table_expression #3' => ['SELECT a IN main.t FROM t', ['sqlite'], ['main.t', 't']];
        yield 'parse_in_table_expression #4' => ['SELECT a IN f() FROM t', ['sqlite'], ['t']];
        yield 'parse_in_table_expression #5' => ['SELECT a NOT IN f(1, 2) FROM t', ['sqlite'], ['t']];
        yield 'parse_in_table_expression #6' => ['SELECT a IN (1, 2) FROM t', ['sqlite'], ['t']];
        yield 'parse_in_table_expression #7' => ['SELECT a IN (SELECT b FROM u) FROM t', ['sqlite'], ['u', 't']];
        yield 'test_visit_relations_in_table_target #1' => ['SELECT a IN lookup FROM source', ['sqlite'], ['lookup', 'source']];
        yield 'parse_table_function #1' => ["SELECT * FROM TABLE(FUN('1')) AS a", self::ALL, []];
        yield 'parse_select_with_alias_and_column_defs #1' => ['SELECT * FROM jsonb_to_record(\'{"a": "x", "b": 2}\'::JSONB) AS x (a TEXT, b INT)', self::ALL, []];
        yield 'parse_from_advanced #1' => ['SELECT * FROM fn(1, 2) AS foo, schema.bar AS bar WITH (NOLOCK)', self::ALL, ['schema.bar']];
        yield 'parse_nullary_table_valued_function #1' => ['SELECT * FROM fn()', self::ALL, []];
        yield 'parse_implicit_join #1' => ['SELECT * FROM t1, t2', self::ALL, ['t1', 't2']];
        yield 'parse_implicit_join #2' => ['SELECT * FROM t1a NATURAL JOIN t1b, t2a NATURAL JOIN t2b', self::ALL, ['t1a', 't1b', 't2a', 't2b']];
        yield 'parse_cross_join #1' => ['SELECT * FROM t1 CROSS JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_cross_join_constraint #1' => ['SELECT * FROM t1 CROSS JOIN t2 ON a = b', ['mysql'], ['t1', 't2']];
        yield 'parse_cross_join_constraint #2' => ['SELECT * FROM t1 CROSS JOIN t2 USING(a)', ['mysql'], ['t1', 't2']];
        yield 'parse_joins_on #1' => ['SELECT * FROM t1 JOIN t2 AS foo ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #2' => ['SELECT * FROM t1 JOIN t2 foo ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #3' => ['SELECT * FROM t1 JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #4' => ['SELECT * FROM t1 INNER JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #5' => ['SELECT * FROM t1 LEFT JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #6' => ['SELECT * FROM t1 LEFT OUTER JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #7' => ['SELECT * FROM t1 RIGHT JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #8' => ['SELECT * FROM t1 RIGHT OUTER JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #9' => ['SELECT * FROM t1 SEMI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #10' => ['SELECT * FROM t1 LEFT SEMI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #11' => ['SELECT * FROM t1 RIGHT SEMI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #12' => ['SELECT * FROM t1 ANTI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #13' => ['SELECT * FROM t1 LEFT ANTI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #14' => ['SELECT * FROM t1 RIGHT ANTI JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #15' => ['SELECT * FROM t1 FULL JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_on #16' => ['SELECT * FROM t1 GLOBAL FULL JOIN t2 ON c1 = c2', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #1' => ['SELECT * FROM t1 JOIN t2 AS foo USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #2' => ['SELECT * FROM t1 JOIN t2 foo USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #3' => ['SELECT * FROM t1 JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #4' => ['SELECT * FROM t1 INNER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #5' => ['SELECT * FROM t1 LEFT JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #6' => ['SELECT * FROM t1 LEFT OUTER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #7' => ['SELECT * FROM t1 RIGHT JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #8' => ['SELECT * FROM t1 RIGHT OUTER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #9' => ['SELECT * FROM t1 SEMI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #10' => ['SELECT * FROM t1 LEFT SEMI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #11' => ['SELECT * FROM t1 RIGHT SEMI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #12' => ['SELECT * FROM t1 ANTI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #13' => ['SELECT * FROM t1 LEFT ANTI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #14' => ['SELECT * FROM t1 RIGHT ANTI JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #15' => ['SELECT * FROM t1 FULL JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_joins_using #16' => ['SELECT * FROM tbl1 AS t1 JOIN tbl2 AS t2 USING(t2.col1)', self::ALL, ['tbl1', 'tbl2']];
        yield 'parse_natural_join #1' => ['SELECT * FROM t1 NATURAL JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #2' => ['SELECT * FROM t1 NATURAL INNER JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #3' => ['SELECT * FROM t1 NATURAL LEFT JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #4' => ['SELECT * FROM t1 NATURAL LEFT OUTER JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #5' => ['SELECT * FROM t1 NATURAL RIGHT JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #6' => ['SELECT * FROM t1 NATURAL RIGHT OUTER JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #7' => ['SELECT * FROM t1 NATURAL FULL JOIN t2', self::ALL, ['t1', 't2']];
        yield 'parse_natural_join #8' => ['SELECT * FROM t1 NATURAL JOIN t2 AS t3', self::ALL, ['t1', 't2']];
        yield 'parse_complex_join #1' => ['SELECT c1, c2 FROM t1, t4 JOIN t2 ON t2.c = t1.c LEFT JOIN t3 USING(q, c) WHERE t4.c = t1.c', self::ALL, ['t1', 't4', 't2', 't3']];
        yield 'parse_join_nesting #1' => ['SELECT * FROM a NATURAL JOIN (b NATURAL JOIN (c NATURAL JOIN d NATURAL JOIN e)) NATURAL JOIN (f NATURAL JOIN (g NATURAL JOIN h))', self::ALL, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']];
        yield 'parse_join_nesting #2' => ['SELECT * FROM (a NATURAL JOIN b) NATURAL JOIN c', self::ALL, ['a', 'b', 'c']];
        yield 'parse_join_nesting #3' => ['SELECT * FROM (((a NATURAL JOIN b)))', self::ALL, ['a', 'b']];
        yield 'parse_join_nesting #4' => ['SELECT * FROM a NATURAL JOIN (((b NATURAL JOIN c)))', self::ALL, ['a', 'b', 'c']];
        yield 'parse_join_nesting #5' => ['SELECT * FROM (a NATURAL JOIN b) AS c', self::ALL, ['a', 'b']];
        yield 'parse_join_syntax_variants #1' => ['SELECT c1 FROM t1 JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #2' => ['SELECT c1 FROM t1 INNER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #3' => ['SELECT c1 FROM t1 LEFT JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #4' => ['SELECT c1 FROM t1 LEFT OUTER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #5' => ['SELECT c1 FROM t1 RIGHT JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #6' => ['SELECT c1 FROM t1 RIGHT OUTER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #7' => ['SELECT c1 FROM t1 FULL OUTER JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_join_syntax_variants #8' => ['SELECT c1 FROM t1 FULL JOIN t2 USING(c1)', self::ALL, ['t1', 't2']];
        yield 'parse_ctes #1' => ['WITH a AS (SELECT 1 AS foo), b AS (SELECT 2 AS bar) SELECT foo + bar FROM a, b', self::ALL, ['a', 'b']];
        yield 'parse_ctes #2' => ['SELECT (WITH a AS (SELECT 1 AS foo), b AS (SELECT 2 AS bar) SELECT foo + bar FROM a, b)', self::ALL, ['a', 'b']];
        yield 'parse_ctes #3' => ['SELECT * FROM (WITH a AS (SELECT 1 AS foo), b AS (SELECT 2 AS bar) SELECT foo + bar FROM a, b)', self::ALL, ['a', 'b']];
        yield 'parse_ctes #4' => ['WITH outer_cte AS (WITH a AS (SELECT 1 AS foo), b AS (SELECT 2 AS bar) SELECT foo + bar FROM a, b) SELECT * FROM outer_cte', self::ALL, ['a', 'b', 'outer_cte']];
        yield 'parse_derived_tables #1' => ['SELECT a.x, b.y FROM (SELECT x FROM foo) AS a CROSS JOIN (SELECT y FROM bar) AS b', self::ALL, ['foo', 'bar']];
        yield 'parse_derived_tables #2' => ['SELECT a.x, b.y FROM (SELECT x FROM foo) AS a (x) CROSS JOIN (SELECT y FROM bar) AS b (y)', self::ALL, ['foo', 'bar']];
        yield 'parse_derived_tables #3' => ['SELECT * FROM (((SELECT 1)))', self::ALL, []];
        yield 'parse_derived_tables #4' => ['SELECT * FROM t NATURAL JOIN (((SELECT 1)))', self::ALL, ['t']];
        yield 'parse_derived_tables #5' => ['SELECT * FROM (((SELECT 1) UNION (SELECT 2)) AS t1 NATURAL JOIN t2)', self::ALL, ['t2']];
        yield 'parse_exists_subquery #1' => ['SELECT * FROM t WHERE EXISTS (SELECT 1)', self::ALL, ['t']];
        yield 'parse_exists_subquery #2' => ['SELECT * FROM t WHERE NOT EXISTS (SELECT 1)', self::ALL, ['t']];
        yield 'parse_exists_subquery #3' => ['SELECT * FROM t WHERE EXISTS (WITH u AS (SELECT 1) SELECT * FROM u)', self::ALL, ['t', 'u']];
        yield 'parse_offset #1' => ['SELECT foo FROM bar OFFSET 2 ROWS', self::ALL, ['bar']];
        yield 'parse_offset #2' => ['SELECT foo FROM bar WHERE foo = 4 OFFSET 2 ROWS', self::ALL, ['bar']];
        yield 'parse_offset #3' => ['SELECT foo FROM bar ORDER BY baz OFFSET 2 ROWS', self::ALL, ['bar']];
        yield 'parse_offset #4' => ['SELECT foo FROM bar WHERE foo = 4 ORDER BY baz OFFSET 2 ROWS', self::ALL, ['bar']];
        yield 'parse_offset #5' => ['SELECT foo FROM (SELECT * FROM bar OFFSET 2 ROWS) OFFSET 2 ROWS', self::ALL, ['bar']];
        yield 'parse_fetch #1' => ['SELECT foo FROM bar FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #2' => ['SELECT foo FROM bar FETCH FIRST ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #3' => ['SELECT foo FROM bar WHERE foo = 4 FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #4' => ['SELECT foo FROM bar ORDER BY baz FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #5' => ['SELECT foo FROM bar WHERE foo = 4 ORDER BY baz FETCH FIRST 2 ROWS WITH TIES', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #6' => ['SELECT foo FROM bar FETCH FIRST 50 PERCENT ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #7' => ['SELECT foo FROM bar WHERE foo = 4 ORDER BY baz OFFSET 2 ROWS FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #8' => ['SELECT foo FROM (SELECT * FROM bar FETCH FIRST 2 ROWS ONLY) FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'parse_fetch #9' => ['SELECT foo FROM (SELECT * FROM bar OFFSET 2 ROWS FETCH FIRST 2 ROWS ONLY) OFFSET 2 ROWS FETCH FIRST 2 ROWS ONLY', ['mysql', 'pgsql', 'sqlsrv'], ['bar']];
        yield 'lateral_derived #1' => ['SELECT * FROM customer LEFT JOIN (SELECT * FROM orders WHERE orders.customer = customer.id LIMIT 3) AS orders ON 1', self::ALL, ['customer', 'orders']];
        yield 'lateral_derived #2' => ['SELECT * FROM customer LEFT JOIN LATERAL (SELECT * FROM orders WHERE orders.customer = customer.id LIMIT 3) AS orders ON 1', self::ALL, ['customer', 'orders']];
        yield 'lateral_function #1' => ['SELECT * FROM customer LEFT JOIN LATERAL generate_series(1, customer.id)', self::ALL, ['customer']];
        yield 'test_lock_table #1' => ["SELECT * FROM student WHERE id = '1' FOR UPDATE OF school", self::ALL, ['student']];
        yield 'test_lock_table #2' => ["SELECT * FROM student WHERE id = '1' FOR SHARE OF school", self::ALL, ['student']];
        yield 'test_lock_table #3' => ["SELECT * FROM student WHERE id = '1' FOR SHARE OF school FOR UPDATE OF student", self::ALL, ['student']];
        yield 'test_lock #1' => ["SELECT * FROM student WHERE id = '1' FOR UPDATE", self::ALL, ['student']];
        yield 'test_lock #2' => ["SELECT * FROM student WHERE id = '1' FOR SHARE", self::ALL, ['student']];
        yield 'test_lock_nonblock #1' => ["SELECT * FROM student WHERE id = '1' FOR UPDATE OF school SKIP LOCKED", self::ALL, ['student']];
        yield 'test_lock_nonblock #2' => ["SELECT * FROM student WHERE id = '1' FOR SHARE OF school NOWAIT", self::ALL, ['student']];
        yield 'parse_select_table_with_index_hints #1' => ['SELECT * FROM t1 USE INDEX (i1) IGNORE INDEX FOR ORDER BY (i2) ORDER BY a', ['mysql'], ['t1']];
        yield 'parse_select_table_with_index_hints #2' => ['SELECT * FROM t1 USE INDEX (i1) USE INDEX (i1, i1)', ['mysql'], ['t1']];
        yield 'parse_select_table_with_index_hints #3' => ['SELECT * FROM t1 USE INDEX () IGNORE INDEX (i2) USE INDEX (i1) USE INDEX (i2)', ['mysql'], ['t1']];
        yield 'parse_select_table_with_index_hints #4' => ['SELECT * FROM t1 FORCE INDEX FOR JOIN (i2)', ['mysql'], ['t1']];
        yield 'parse_select_table_with_index_hints #5' => ['SELECT * FROM t1 IGNORE INDEX FOR JOIN (i2)', ['mysql'], ['t1']];
        yield 'parse_select_table_with_index_hints #6' => ['SELECT * FROM t USE INDEX (index1) IGNORE INDEX FOR ORDER BY (index1) IGNORE INDEX FOR GROUP BY (index1) WHERE A = B', ['mysql'], ['t']];
        yield 'parse_select_table_with_index_hints #7' => ['SELECT * FROM T USE LIMIT 1', ['pgsql', 'sqlite', 'sqlsrv'], ['T']];
        yield 'parse_non_latin_identifiers #1' => ['SELECT a.説明 FROM test.public.inter01 AS a', self::ALL, ['test.public.inter01']];
        yield 'parse_non_latin_identifiers #2' => ['SELECT a.説明 FROM inter01 AS a, inter01_transactions AS b WHERE a.説明 = b.取引 GROUP BY a.説明', self::ALL, ['inter01', 'inter01_transactions']];
        yield 'parse_non_latin_identifiers #3' => ['SELECT 説明, hühnervögel, garçon, Москва, 東京 FROM inter01', self::ALL, ['inter01']];
        yield 'parse_non_latin_identifiers #4' => ['SELECT 💝 FROM table1', self::ALL, ['table1']];
        yield 'parse_connect_by #1' => ["SELECT employee_id, manager_id, title FROM employees START WITH title = 'president' CONNECT BY manager_id = PRIOR employee_id ORDER BY employee_id", ['sqlsrv'], ['employees']];
        yield 'parse_connect_by #2' => ["SELECT employee_id, manager_id, title FROM employees CONNECT BY manager_id = PRIOR employee_id START WITH title = 'president' ORDER BY employee_id", ['sqlsrv'], ['employees']];
        yield 'parse_connect_by #3' => ["SELECT employee_id, manager_id, title FROM employees WHERE employee_id <> 42 START WITH title = 'president' CONNECT BY manager_id = PRIOR employee_id ORDER BY employee_id", ['sqlsrv'], ['employees']];
        yield 'parse_connect_by #4' => ['SELECT child, parent FROM t CONNECT BY NOCYCLE parent = PRIOR child', ['sqlsrv'], ['t']];
        yield 'parse_connect_by #5' => ['SELECT 0 FROM t WHERE 1 = 1 CONNECT BY 2 = 2 GROUP BY 3', ['sqlsrv'], ['t']];
        yield 'parse_connect_by #6' => ["SELECT 0 FROM t WHERE 1 = 1 START WITH 'a' = 'a' CONNECT BY 2 = 2 GROUP BY 3", ['sqlsrv'], ['t']];
        yield 'parse_connect_by #7' => ["SELECT 0 FROM t WHERE 1 = 1 CONNECT BY 2 = 2 START WITH 'a' = 'a' GROUP BY 3", ['sqlsrv'], ['t']];
        yield 'test_xmltable #1' => ["SELECT * FROM XMLTABLE('/root' PASSING data COLUMNS element TEXT)", self::ALL, []];
        yield 'test_xmltable #2' => ["SELECT y FROM XMLTABLE('/X' PASSING '<X><y>z</y></X>' COLUMNS y TEXT)", self::ALL, []];
        yield 'test_xmltable #3' => ["SELECT y FROM XMLTABLE((SELECT '/X') PASSING (SELECT CAST('<X><y>z</y></X>' AS xml)) COLUMNS y TEXT PATH (SELECT 'y'))", self::ALL, []];
        yield 'test_xmltable #4' => ["SELECT y FROM XMLTABLE('/X' PASSING '<X></X>' COLUMNS y TEXT NOT NULL)", self::ALL, []];
        yield 'test_xmltable #5' => ["SELECT * FROM XMLTABLE('/root/row' PASSING xmldata COLUMNS id INT PATH '@id', name TEXT PATH 'name/text()', value FLOAT PATH 'value')", self::ALL, []];
        yield 'test_xmltable #6' => ["SELECT * FROM XMLTABLE('//ROWS/ROW' PASSING data COLUMNS row_num FOR ORDINALITY, id INT PATH '@id', name TEXT PATH 'NAME' DEFAULT 'unnamed')", self::ALL, []];
        yield 'test_xmltable #7' => ['SELECT xmltable.* FROM xmldata, XMLTABLE(\'//ROWS/ROW\' PASSING data COLUMNS id INT PATH \'@id\', ordinality FOR ORDINALITY, "COUNTRY_NAME" TEXT, country_id TEXT PATH \'COUNTRY_ID\', size_sq_km FLOAT PATH \'SIZE[@unit = "sq_km"]\', size_other TEXT PATH \'concat(SIZE[@unit!="sq_km"], " ", SIZE[@unit!="sq_km"]/@unit)\', premier_name TEXT PATH \'PREMIER_NAME\' DEFAULT \'not specified\')', self::ALL, ['xmldata']];
        yield 'test_xmltable #8' => ['SELECT X.* FROM T1, XMLTABLE(\'$CUSTLIST/customers/customerinfo\' COLUMNS "Cid" BIGINT PATH \'@Cid\', "Info" XML PATH \'document{.}\', "History" XML PATH \'NULL\') AS X', self::ALL, ['T1']];
        yield 'test_xmltable #9' => ['SELECT xmltable.* FROM XMLTABLE(XMLNAMESPACES(\'http://example.com/myns\' AS x, \'http://example.com/b\' AS "B"), \'/x:example/x:item\' PASSING (SELECT data FROM xmldata) COLUMNS foo INT PATH \'@foo\', bar INT PATH \'@B:bar\')', self::ALL, ['xmldata']];
        yield 'test_qualified_keyword_function_calls #1' => ["SELECT pg_catalog.overlay('abc' PLACING 'xy' FROM 1)", ['pgsql'], []];
        yield 'test_qualified_keyword_function_calls #2' => ['SELECT t.interval FROM tbl t', ['pgsql'], ['tbl']];
        yield 'test_table_sample #1' => ['SELECT * FROM tbl AS t TABLESAMPLE BERNOULLI (50)', self::ALL, ['tbl']];
        yield 'test_table_sample #2' => ['SELECT * FROM tbl AS t TABLESAMPLE SYSTEM (50)', self::ALL, ['tbl']];
        yield 'test_table_sample #3' => ['SELECT * FROM tbl AS t TABLESAMPLE SYSTEM (50) REPEATABLE (10)', self::ALL, ['tbl']];
        yield 'parse_subquery_limit #1' => ['SELECT t1_id, t1_name FROM t1 WHERE t1_id IN (SELECT t2_id FROM t2 WHERE t1_name = t2_name LIMIT 10)', self::ALL, ['t1', 't2']];
        yield 'join_precedence #1' => ['SELECT * FROM t1 NATURAL JOIN t5 INNER JOIN t0 ON (t0.v1 + t5.v0) > 0 WHERE t0.v1 = t1.v0', self::ALL, ['t1', 't5', 't0']];
        yield 'parse_table_preserves_quotes_and_trailing_tokens #2' => ['SELECT * FROM (TABLE old_table ORDER BY x)', ['pgsql'], ['old_table']];
        yield 'quoted_on_allowed_as_table_name #1' => ['SELECT * FROM "ON"', ['pgsql', 'sqlite', 'sqlsrv'], ['ON']];
        yield 'quoted_on_allowed_as_table_name #2' => ['SELECT * FROM `ON`', ['mysql', 'sqlite'], ['ON']];
        yield 'parse_in_list_leading_subquery #1' => ['SELECT * FROM t WHERE x IN ((SELECT a FROM u), 1)', self::ALL, ['t', 'u']];
        yield 'parse_in_list_leading_subquery #2' => ['SELECT * FROM t WHERE x NOT IN (((SELECT a FROM u)), 1)', self::ALL, ['t', 'u']];
        yield 'parse_in_list_leading_subquery #3' => ['SELECT * FROM t WHERE x IN ((SELECT a FROM u) + 1, 2)', self::ALL, ['t', 'u']];
        yield 'parse_in_list_leading_subquery #4' => ['SELECT * FROM t WHERE x IN ((SELECT a FROM u))', self::ALL, ['t', 'u']];
        yield 'parse_in_list_leading_subquery #5' => ['(SELECT a FROM u)', self::ALL, ['u']];
        yield 'parse_in_list_leading_subquery #6' => ['SELECT * FROM t WHERE x IN ((SELECT a FROM u)[1], 2)', ['pgsql'], ['t', 'u']];
        yield 'parse_string_literal_alias #2' => ["SELECT a FROM t AS 'y'", ['sqlite', 'sqlsrv'], ['t']];
        yield 'parse_string_literal_alias #4' => ["SELECT a FROM t 'y'", ['sqlite', 'sqlsrv'], ['t']];
        yield 'parse_string_literal_alias_per_engine #2' => ["SELECT a FROM t AS 'y'", ['sqlite'], ['t']];
        yield 'parse_string_literal_alias_per_engine #4' => ["SELECT a FROM t 'y'", ['sqlite'], ['t']];
        yield 'parse_delimited_identifiers #1' => ['SELECT "alias"."bar baz", "myfun"(), "simple id" AS "column alias" FROM "a table" AS "alias"', ['pgsql', 'sqlsrv'], ['a table']];
        yield 'test_nested_join_without_parentheses #1' => ['SELECT DISTINCT p.product_id FROM orders AS o INNER JOIN customers AS c INNER JOIN products AS p ON p.customer_id = c.customer_id ON c.order_id = o.order_id', ['mysql', 'pgsql'], ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #2' => ['SELECT DISTINCT p.product_id FROM orders AS o INNER JOIN (customers AS c INNER JOIN products AS p ON p.customer_id = c.customer_id) ON c.order_id = o.order_id', self::ALL, ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #3' => ['SELECT DISTINCT p.product_id FROM orders AS o JOIN customers AS c JOIN products AS p ON p.customer_id = c.customer_id ON c.order_id = o.order_id', ['mysql', 'pgsql'], ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #4' => ['SELECT DISTINCT p.product_id FROM orders AS o JOIN (customers AS c JOIN products AS p ON p.customer_id = c.customer_id) ON c.order_id = o.order_id', self::ALL, ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #5' => ['SELECT DISTINCT p.product_id FROM orders AS o LEFT JOIN customers AS c LEFT JOIN products AS p ON p.customer_id = c.customer_id ON c.order_id = o.order_id', ['mysql', 'pgsql'], ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #6' => ['SELECT DISTINCT p.product_id FROM orders AS o LEFT JOIN (customers AS c LEFT JOIN products AS p ON p.customer_id = c.customer_id) ON c.order_id = o.order_id', self::ALL, ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #7' => ['SELECT DISTINCT p.product_id FROM orders AS o RIGHT JOIN customers AS c RIGHT JOIN products AS p ON p.customer_id = c.customer_id ON c.order_id = o.order_id', ['mysql', 'pgsql'], ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #8' => ['SELECT DISTINCT p.product_id FROM orders AS o RIGHT JOIN (customers AS c RIGHT JOIN products AS p ON p.customer_id = c.customer_id) ON c.order_id = o.order_id', self::ALL, ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #9' => ['SELECT DISTINCT p.product_id FROM orders AS o FULL JOIN customers AS c FULL JOIN products AS p ON p.customer_id = c.customer_id ON c.order_id = o.order_id', ['mysql', 'pgsql'], ['orders', 'customers', 'products']];
        yield 'test_nested_join_without_parentheses #10' => ['SELECT DISTINCT p.product_id FROM orders AS o FULL JOIN (customers AS c FULL JOIN products AS p ON p.customer_id = c.customer_id) ON c.order_id = o.order_id', self::ALL, ['orders', 'customers', 'products']];
        yield 'parse_function_object_name #1' => ['SELECT a.b.c.d(1, 2, 3) FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'parse_insert_values #9' => ['INSERT INTO t (id, name, active) VALUE (1, 2, 3)', ['generic', 'mysql'], []];
        yield 'parse_insert_values #10' => ['INSERT INTO customer WITH foo AS (SELECT 1) SELECT * FROM foo UNION VALUES (1)', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_singular_value_keyword #2' => ['INSERT INTO t (a) VALUE (1)', ['generic', 'mysql'], []];
        yield 'parse_singular_value_keyword #4' => ['INSERT INTO t VALUE (1)', ['generic', 'mysql'], []];
        yield 'parse_singular_value_keyword #5' => ['WITH c AS (VALUE (1)) SELECT * FROM c', ['generic', 'mysql'], ['c']];
        yield 'parse_insert_set #2' => ['INSERT INTO tbl1 SET col1 = 1, col2 = \'abc\', col3 = current_date()', ['mysql'], []];
        yield 'parse_insert_default_values #4' => ['INSERT INTO test_table DEFAULT VALUES', self::ALL_AND_GENERIC, []];
        yield 'parse_insert_default_values #5' => ['INSERT INTO test_table DEFAULT VALUES RETURNING test_column', self::ALL_AND_GENERIC, []];
        yield 'parse_insert_default_values #6' => ['INSERT INTO test_table DEFAULT VALUES ON CONFLICT DO NOTHING', self::ALL_AND_GENERIC, []];
        yield 'parse_insert_select_returning #3' => ['INSERT INTO t SELECT 1 RETURNING 2', self::ALL_AND_GENERIC, []];
        yield 'parse_insert_select_returning #4' => ['INSERT INTO t SELECT x RETURNING x AS y', self::ALL_AND_GENERIC, []];
        yield 'parse_insert_select_from_returning #2' => ['INSERT INTO table1 SELECT * FROM table2 RETURNING id', self::ALL_AND_GENERIC, ['table2']];
        yield 'parse_insert_nested_parenthesized_subquery #3' => ['INSERT INTO t ((SELECT 1))', self::ALL_AND_GENERIC, []];
        yield 'parse_insert_nested_parenthesized_subquery #4' => ['INSERT INTO t (((SELECT 1)))', self::ALL_AND_GENERIC, []];
        yield 'parse_update #4' => ['UPDATE t SET a = 1, b = 2, c = 3 WHERE d', self::ALL_AND_GENERIC, []];
        yield 'parse_update #5' => ['UPDATE t SET a = 1, a = 2, a = 3', self::ALL_AND_GENERIC, []];
        yield 'parse_update_set_from #3' => ['UPDATE t1 SET name = t2.name FROM (SELECT name, id FROM t1 GROUP BY id) AS t2 WHERE t1.id = t2.id', ['generic', 'pgsql', 'sqlite', 'sqlsrv'], ['t1']];
        yield 'parse_update_set_from #4' => ['UPDATE T SET a = b FROM U, (SELECT foo FROM V) AS W WHERE 1 = 1', ['generic', 'pgsql', 'sqlite', 'sqlsrv'], ['U', 'V']];
        yield 'parse_update_with_table_alias #2' => ['UPDATE users AS u SET u.username = \'new_user\' WHERE u.username = \'old_user\'', self::ALL_AND_GENERIC, []];
        yield 'parse_delete_statement #2' => ['DELETE FROM "table"', self::ALL_AND_GENERIC, ['table']];
        yield 'parse_delete_statement_for_multi_tables #2' => ['DELETE schema1.table1, schema2.table2 FROM schema1.table1 JOIN schema2.table2 ON schema2.table2.col1 = schema1.table1.col1 WHERE schema2.table2.col2 = 1', self::ALL, ['schema1.table1', 'schema2.table2']];
        yield 'parse_delete_statement_for_multi_tables_with_using #2' => ['DELETE FROM schema1.table1, schema2.table2 USING schema1.table1 JOIN schema2.table2 ON schema2.table2.pk = schema1.table1.col1 WHERE schema2.table2.col2 = 1', self::ALL_AND_GENERIC, ['schema1.table1', 'schema2.table2', 'schema2.table2']];
        yield 'parse_where_delete_statement #2' => ['DELETE FROM foo WHERE name = 5', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_where_delete_with_alias_statement #2' => ['DELETE FROM basket AS a USING basket AS b WHERE a.id < b.id', self::ALL_AND_GENERIC, ['basket']];
        yield 'parse_simple_select #1' => ['SELECT id, fname, lname FROM customer WHERE id = 1 LIMIT 5', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_limit #1' => ['SELECT * FROM user LIMIT 1', self::ALL_AND_GENERIC, ['user']];
        yield 'parse_select_distinct #1' => ['SELECT DISTINCT name FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_distinct_two_fields #1' => ['SELECT DISTINCT name, id FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_distinct_tuple #1' => ['SELECT DISTINCT (name, id) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_outer_join_operator #1' => ['SELECT 1 FROM T WHERE a = b (+)', ['sqlsrv'], ['T']];
        yield 'parse_outer_join_operator #2' => ['SELECT 1 FROM T WHERE t1.c1 = t2.c2.d3 (+)', ['sqlsrv'], ['T']];
        yield 'parse_select_distinct_on #1' => ['SELECT DISTINCT ON (album_id) name FROM track ORDER BY album_id, milliseconds', ['generic', 'pgsql', 'sqlite', 'sqlsrv'], ['track']];
        yield 'parse_select_distinct_on #2' => ['SELECT DISTINCT ON () name FROM track ORDER BY milliseconds', ['generic', 'pgsql', 'sqlite', 'sqlsrv'], ['track']];
        yield 'parse_select_distinct_on #3' => ['SELECT DISTINCT ON (album_id, milliseconds) name FROM track', ['generic', 'pgsql', 'sqlite', 'sqlsrv'], ['track']];
        yield 'parse_select_all #1' => ['SELECT ALL name FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_into #1' => ['SELECT * INTO table0 FROM table1', self::ALL_AND_GENERIC, ['table1']];
        yield 'parse_select_into #2' => ['SELECT * INTO TEMPORARY UNLOGGED TABLE table0 FROM table1', self::ALL_AND_GENERIC, ['table1']];
        yield 'parse_select_into #3' => ['SELECT a, b INTO foo.bar, bar.baz FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_select_into #4' => ['SELECT a, b, c INTO p, q, r FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_select_into #5' => ['SELECT a, b INTO :h1, :h2 FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_select_wildcard #1' => ['SELECT * FROM foo', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_select_wildcard #2' => ['SELECT foo.* FROM foo', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_select_wildcard #3' => ['SELECT myschema.mytable.* FROM myschema.mytable', self::ALL_AND_GENERIC, ['myschema.mytable']];
        yield 'parse_count_wildcard #1' => ['SELECT COUNT(*) FROM orders WHERE id = 10', self::ALL_AND_GENERIC, ['orders']];
        yield 'parse_count_wildcard #2' => ['SELECT COUNT(Employee.*) FROM orders JOIN Employee ON orders.employee = Employee.id', self::ALL_AND_GENERIC, ['orders', 'Employee']];
        yield 'parse_column_aliases #1' => ['SELECT a.col + 1 AS newname FROM foo AS a', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_column_aliases #2' => ['SELECT a.col + 1 newname FROM foo AS a', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_select_wildcard_with_alias #1' => ['SELECT t.* AS all_cols FROM t', ['pgsql'], ['t']];
        yield 'parse_select_wildcard_with_alias #2' => ['SELECT t.* all_cols FROM t', ['pgsql'], ['t']];
        yield 'parse_select_wildcard_with_alias #3' => ['SELECT t.* all_cols, other_col FROM t', ['pgsql'], ['t']];
        yield 'parse_select_wildcard_with_alias #4' => ['SELECT * AS all_cols FROM t', ['pgsql'], ['t']];
        yield 'parse_select_wildcard_with_alias #5' => ['SELECT * all_cols FROM t', ['pgsql'], ['t']];
        yield 'parse_select_wildcard_with_alias #6' => ['SELECT a.id, b.* AS b_cols FROM a JOIN b ON (a.id = b.a_id)', ['pgsql'], ['a', 'b']];
        yield 'parse_select_wildcard_with_alias #7' => ['SELECT a.id, b.* b_cols FROM a JOIN b ON (a.id = b.a_id)', ['pgsql'], ['a', 'b']];
        yield 'parse_select_count_wildcard #1' => ['SELECT COUNT(*) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_count_distinct #1' => ['SELECT COUNT(DISTINCT +x) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_count_distinct #2' => ['SELECT COUNT(ALL +x) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_count_distinct #3' => ['SELECT COUNT(+x) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_not #1' => ['SELECT id FROM customer WHERE NOT salary = \'\'', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_collate #1' => ['SELECT name COLLATE "de_DE" FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_collate_after_parens #1' => ['SELECT (name) COLLATE "de_DE" FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_string_predicate #1' => ['SELECT id, fname, lname FROM customer WHERE salary <> \'Not Provided\' AND salary <> \'\'', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_projection_nested_type #1' => ['SELECT customer.address.state FROM foo', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_escaped_single_quote_string_predicate_with_escape #1' => ['SELECT id, fname, lname FROM customer WHERE salary <> \'Jim\'\'s salary\'', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_null_like #1' => ['SELECT column1 LIKE NULL AS col_null, NULL LIKE column1 AS null_col FROM customers', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_in_union #1' => ['SELECT * FROM customers WHERE segment IN ((SELECT segm FROM bar) UNION (SELECT segm FROM bar2))', self::ALL_AND_GENERIC, ['customers', 'bar', 'bar2']];
        yield 'parse_in_union #2' => ['(SELECT segm FROM bar) UNION (SELECT segm FROM bar2)', self::ALL_AND_GENERIC, ['bar', 'bar2']];
        yield 'parse_between_with_expr #1' => ['SELECT * FROM t WHERE 1 BETWEEN 1 + 2 AND 3 + 4 IS NULL', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_between_with_expr #2' => ['SELECT * FROM t WHERE 1 = 1 AND 1 + x BETWEEN 1 AND 2', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_select_order_by_limit #1' => ['SELECT id, fname, lname FROM customer WHERE id < 5 ORDER BY lname ASC, fname DESC LIMIT 2', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_order_by_nulls_order #1' => ['SELECT id, fname, lname FROM customer WHERE id < 5 ORDER BY lname ASC NULLS FIRST, fname DESC NULLS LAST LIMIT 2', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_aggregate_order_by_using_operator #1' => ['SELECT aggfns(DISTINCT a, a, c ORDER BY c USING ~<~, a) FROM t', ['pgsql'], ['t']];
        yield 'parse_order_by_using_operator_syntax #1' => ['SELECT a FROM t ORDER BY a USING OPERATOR(<)', ['pgsql'], ['t']];
        yield 'parse_order_by_using_operator_syntax #2' => ['SELECT a FROM t ORDER BY a USING OPERATOR(pg_catalog.<) NULLS LAST', ['pgsql'], ['t']];
        yield 'parse_select_group_by #1' => ['SELECT id, fname, lname FROM customer GROUP BY lname, fname', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_group_by #2' => ['SELECT id, fname, lname FROM customer GROUP BY (lname, fname)', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_group_by_all #1' => ['SELECT id, fname, lname, SUM(order) FROM customer GROUP BY ALL', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_group_by_special_grouping_sets #1' => ['SELECT a, b, SUM(c) FROM tab1 GROUP BY a, b GROUPING SETS ((a, b), (a), (b), ())', self::ALL_AND_GENERIC, ['tab1']];
        yield 'parse_group_by_grouping_sets_single_values #1' => ['SELECT a, b, SUM(c) FROM tab1 GROUP BY a, b GROUPING SETS ((a, b), a, (b), c, ())', self::ALL_AND_GENERIC, ['tab1']];
        yield 'parse_select_having #1' => ['SELECT foo FROM bar GROUP BY foo HAVING COUNT(*) > 1', self::ALL_AND_GENERIC, ['bar']];
        yield 'parse_select_qualify #1' => ['SELECT i, p, o FROM qt QUALIFY ROW_NUMBER() OVER (PARTITION BY p ORDER BY o) = 1', self::ALL_AND_GENERIC, ['qt']];
        yield 'parse_select_qualify #2' => ['SELECT i, p, o, ROW_NUMBER() OVER (PARTITION BY p ORDER BY o) AS row_num FROM qt QUALIFY row_num = 1', self::ALL_AND_GENERIC, ['qt']];
        yield 'parse_limit_accepts_all #1' => ['SELECT id, fname, lname FROM customer WHERE id = 1 LIMIT ALL', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_limit_accepts_all #2' => ['SELECT id, fname, lname FROM customer WHERE id = 1 LIMIT ALL OFFSET 1', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_limit_accepts_all #3' => ['SELECT id, fname, lname FROM customer WHERE id = 1 OFFSET 1 LIMIT ALL', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #1' => ['SELECT CAST(id AS BIGINT) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #2' => ['SELECT CAST(id AS TINYINT) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #3' => ['SELECT CAST(id AS MEDIUMINT) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #4' => ['SELECT CAST(id AS NUMERIC) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #5' => ['SELECT CAST(id AS DEC) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #6' => ['SELECT CAST(id AS DECIMAL) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #7' => ['SELECT CAST(id AS NVARCHAR(50)) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #8' => ['SELECT CAST(id AS CLOB) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #9' => ['SELECT CAST(id AS CLOB(50)) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #10' => ['SELECT CAST(id AS BINARY(50)) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #11' => ['SELECT CAST(id AS VARBINARY(50)) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #12' => ['SELECT CAST(id AS BLOB) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #13' => ['SELECT CAST(id AS BLOB(50)) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_cast #14' => ['SELECT CAST(details AS JSONB) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_try_cast #1' => ['SELECT TRY_CAST(id AS BIGINT) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_try_cast #2' => ['SELECT TRY_CAST(id AS NUMERIC) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_try_cast #3' => ['SELECT TRY_CAST(id AS DEC) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_try_cast #4' => ['SELECT TRY_CAST(id AS DECIMAL) FROM customer', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_extract #1' => ['SELECT EXTRACT(YEAR FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #2' => ['SELECT EXTRACT(year from d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #3' => ['SELECT EXTRACT(MONTH FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #4' => ['SELECT EXTRACT(WEEK FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #5' => ['SELECT EXTRACT(DAY FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #6' => ['SELECT EXTRACT(DAYOFWEEK FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #7' => ['SELECT EXTRACT(DAYOFYEAR FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #8' => ['SELECT EXTRACT(DATE FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #9' => ['SELECT EXTRACT(DATETIME FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #10' => ['SELECT EXTRACT(HOUR FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #11' => ['SELECT EXTRACT(MINUTE FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #12' => ['SELECT EXTRACT(SECOND FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #13' => ['SELECT EXTRACT(MILLISECOND FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #14' => ['SELECT EXTRACT(MICROSECOND FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #15' => ['SELECT EXTRACT(NANOSECOND FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #16' => ['SELECT EXTRACT(CENTURY FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #17' => ['SELECT EXTRACT(DECADE FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #18' => ['SELECT EXTRACT(DOW FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #19' => ['SELECT EXTRACT(DOY FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #20' => ['SELECT EXTRACT(EPOCH FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #21' => ['SELECT EXTRACT(ISODOW FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #22' => ['SELECT EXTRACT(ISOWEEK FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #23' => ['SELECT EXTRACT(ISOYEAR FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #24' => ['SELECT EXTRACT(JULIAN FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #25' => ['SELECT EXTRACT(MICROSECONDS FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #26' => ['SELECT EXTRACT(MILLENIUM FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #27' => ['SELECT EXTRACT(MILLENNIUM FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #28' => ['SELECT EXTRACT(MILLISECONDS FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #29' => ['SELECT EXTRACT(QUARTER FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #30' => ['SELECT EXTRACT(TIMEZONE FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #31' => ['SELECT EXTRACT(TIMEZONE_ABBR FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #32' => ['SELECT EXTRACT(TIMEZONE_HOUR FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #33' => ['SELECT EXTRACT(TIMEZONE_MINUTE FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #34' => ['SELECT EXTRACT(TIMEZONE_REGION FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_extract #35' => ['SELECT EXTRACT(TIME FROM d)', self::ALL_AND_GENERIC, []];
        yield 'parse_ceil_number #1' => ['SELECT CEIL(float_column) FROM my_table', self::ALL_AND_GENERIC, ['my_table']];
        yield 'parse_floor_number #1' => ['SELECT FLOOR(float_column) FROM my_table', self::ALL_AND_GENERIC, ['my_table']];
        yield 'parse_ceil_number_scale #1' => ['SELECT CEIL(float_column, 3) FROM my_table', self::ALL_AND_GENERIC, ['my_table']];
        yield 'parse_floor_number_scale #1' => ['SELECT FLOOR(float_column, 3) FROM my_table', self::ALL_AND_GENERIC, ['my_table']];
        yield 'parse_ceil_datetime #1' => ['SELECT CEIL(d TO HOUR) FROM df', self::ALL_AND_GENERIC, ['df']];
        yield 'parse_ceil_datetime #2' => ['SELECT CEIL(d TO MINUTE) FROM df', self::ALL_AND_GENERIC, ['df']];
        yield 'parse_ceil_datetime #3' => ['SELECT CEIL(d TO SECOND) FROM df', self::ALL_AND_GENERIC, ['df']];
        yield 'parse_ceil_datetime #4' => ['SELECT CEIL(d TO MILLISECOND) FROM df', self::ALL_AND_GENERIC, ['df']];
        yield 'parse_floor_datetime #1' => ['SELECT FLOOR(d TO HOUR) FROM df', self::ALL_AND_GENERIC, ['df']];
        yield 'parse_floor_datetime #2' => ['SELECT FLOOR(d TO MINUTE) FROM df', self::ALL_AND_GENERIC, ['df']];
        yield 'parse_floor_datetime #3' => ['SELECT FLOOR(d TO SECOND) FROM df', self::ALL_AND_GENERIC, ['df']];
        yield 'parse_floor_datetime #4' => ['SELECT FLOOR(d TO MILLISECOND) FROM df', self::ALL_AND_GENERIC, ['df']];
        yield 'parse_listagg #1' => ['SELECT LISTAGG(dateid ON OVERFLOW TRUNCATE N\'...\' WITH COUNT)', ['generic', 'mysql', 'pgsql', 'sqlsrv'], []];
        yield 'parse_listagg #2' => ['SELECT LISTAGG(dateid ON OVERFLOW TRUNCATE X\'deadbeef\' WITH COUNT)', self::ALL_AND_GENERIC, []];
        yield 'parse_aggregate_order_by_with_empty_args #1' => ['SELECT count(ORDER BY a) FROM t', ['generic', 'sqlite'], ['t']];
        yield 'parse_aggregate_order_by_with_empty_args #2' => ['SELECT count(ORDER BY a ASC, b DESC) FROM t', ['generic', 'sqlite'], ['t']];
        yield 'parse_aggregate_order_by_with_empty_args #3' => ['SELECT count(ALL ORDER BY a) FROM t', ['generic', 'sqlite'], ['t']];
        yield 'parse_window_function_null_treatment_arg #1' => ['SELECT FIRST_VALUE(a IGNORE NULLS) OVER (), FIRST_VALUE(b RESPECT NULLS) OVER () FROM mytable', ['generic'], ['mytable']];
        yield 'parse_create_table_as #1' => ['SELECT * FROM a', self::ALL_AND_GENERIC, ['a']];
        yield 'parse_named_argument_function #1' => ['SELECT FUN(a => \'1\', b => \'2\') FROM foo', ['generic', 'mysql', 'sqlite'], ['foo']];
        yield 'parse_window_functions #1' => ['SELECT row_number() OVER (ORDER BY dt DESC), sum(foo) OVER (PARTITION BY a, b ORDER BY c, d ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW), avg(bar) OVER (ORDER BY a RANGE BETWEEN 1 PRECEDING AND 1 FOLLOWING), sum(bar) OVER (ORDER BY a RANGE BETWEEN INTERVAL \'1\' DAY PRECEDING AND INTERVAL \'1 MONTH\' FOLLOWING), COUNT(*) OVER (ORDER BY a RANGE BETWEEN INTERVAL \'1 DAY\' PRECEDING AND INTERVAL \'1 DAY\' FOLLOWING), max(baz) OVER (ORDER BY a ROWS UNBOUNDED PRECEDING), sum(qux) OVER (ORDER BY a GROUPS BETWEEN 1 PRECEDING AND 1 FOLLOWING) FROM foo', ['generic', 'pgsql', 'sqlite', 'sqlsrv'], ['foo']];
        yield 'parse_named_window_functions #1' => ['SELECT row_number() OVER (w ORDER BY dt DESC), sum(foo) OVER (win PARTITION BY a, b ORDER BY c, d ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) FROM foo WINDOW w AS (PARTITION BY x), win AS (ORDER BY y)', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_named_window_functions #2' => ['SELECT FIRST_VALUE(x) OVER (w ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS first, FIRST_VALUE(x) OVER (ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS last, SUM(y) OVER (win PARTITION BY x) AS last FROM EMPLOYEE WINDOW w AS (PARTITION BY x), win AS (w ORDER BY y)', ['generic', 'mysql', 'pgsql'], ['EMPLOYEE']];
        yield 'parse_window_clause #1' => ['SELECT * FROM mytable WINDOW window1 AS (ORDER BY 1 ASC, 2 DESC, 3 NULLS FIRST), window2 AS (window1), window3 AS (PARTITION BY a, b, c), window4 AS (ROWS UNBOUNDED PRECEDING), window5 AS (window1 PARTITION BY a), window6 AS (window1 ORDER BY a), window7 AS (window1 ROWS UNBOUNDED PRECEDING), window8 AS (window1 PARTITION BY a ORDER BY b ROWS UNBOUNDED PRECEDING) ORDER BY C3', self::ALL_AND_GENERIC, ['mytable']];
        yield 'test_parse_named_window #1' => ['SELECT MIN(c12) OVER window1 AS min1, MAX(c12) OVER window2 AS max1 FROM aggregate_test_100 WINDOW window1 AS (ORDER BY C12), window2 AS (PARTITION BY C11) ORDER BY C3', self::ALL_AND_GENERIC, ['aggregate_test_100']];
        yield 'parse_window_and_qualify_clause #1' => ['SELECT MIN(c12) OVER window1 AS min1 FROM aggregate_test_100 QUALIFY ROW_NUMBER() OVER my_window WINDOW window1 AS (ORDER BY C12), window2 AS (PARTITION BY C11) ORDER BY C3', self::ALL_AND_GENERIC, ['aggregate_test_100']];
        yield 'parse_window_and_qualify_clause #2' => ['SELECT MIN(c12) OVER window1 AS min1 FROM aggregate_test_100 WINDOW window1 AS (ORDER BY C12), window2 AS (PARTITION BY C11) QUALIFY ROW_NUMBER() OVER my_window ORDER BY C3', self::ALL_AND_GENERIC, ['aggregate_test_100']];
        yield 'parse_window_clause_named_window #1' => ['SELECT * FROM mytable WINDOW window1 AS window2', ['generic'], ['mytable']];
        yield 'parse_aggregate_with_group_by #1' => ['SELECT a, COUNT(1), MIN(b), MAX(b) FROM foo GROUP BY a', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_aggregate_with_where_filter #1' => ['SELECT COUNT(* WHERE x > 1) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_aggregate_with_where_filter #2' => ['SELECT SUM(x WHERE y > 0) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_aggregate_with_where_filter #3' => ['SELECT ARRAY_AGG(x WHERE x > 100 ORDER BY x DESC) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_aggregate_with_where_filter #4' => ['SELECT ARRAY_AGG(a WHERE b > 0 AND c < 10) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_aggregate_with_where_filter #5' => ['SELECT SUM(salary WHERE dept = 1) FROM emp', self::ALL_AND_GENERIC, ['emp']];
        yield 'parse_interval_and_or_xor #1' => ['SELECT col FROM test WHERE d3_date > d1_date + INTERVAL \'5 days\' AND d2_date > d1_date + INTERVAL \'3 days\'', ['generic', 'pgsql', 'sqlite', 'sqlsrv'], ['test']];
        yield 'parse_interval_and_or_xor #2' => ['SELECT col FROM test WHERE d3_date > d1_date + INTERVAL \'5 days\' OR d2_date > d1_date + INTERVAL \'3 days\'', ['generic', 'pgsql', 'sqlite', 'sqlsrv'], ['test']];
        yield 'parse_interval_and_or_xor #3' => ['SELECT col FROM test WHERE d3_date > d1_date + INTERVAL \'5 days\' XOR d2_date > d1_date + INTERVAL \'3 days\'', ['generic', 'pgsql', 'sqlite', 'sqlsrv'], ['test']];
        yield 'parse_at_timezone #1' => ['SELECT FROM_UNIXTIME(0) AT TIME ZONE \'UTC-06:00\' FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_at_timezone #2' => ['SELECT DATE_FORMAT(FROM_UNIXTIME(0) AT TIME ZONE \'UTC-06:00\', \'%Y-%m-%dT%H\') AS "hour" FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_simple_math_expr_plus #1' => ['SELECT a + b, 2 + a, 2.5 + a, a_f + b_f, 2 + a_f, 2.5 + a_f FROM c', self::ALL_AND_GENERIC, ['c']];
        yield 'parse_simple_math_expr_minus #1' => ['SELECT a - b, 2 - a, 2.5 - a, a_f - b_f, 2 - a_f, 2.5 - a_f FROM c', self::ALL_AND_GENERIC, ['c']];
        yield 'parse_searched_case_expr #1' => ['SELECT CASE WHEN bar IS NULL THEN \'null\' WHEN bar = 0 THEN \'=0\' WHEN bar >= 0 THEN \'>=0\' ELSE \'<0\' END FROM foo', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_cte_renamed_columns #1' => ['WITH cte (col1, col2) AS (SELECT foo, bar FROM baz) SELECT * FROM cte', self::ALL_AND_GENERIC, ['baz', 'cte']];
        yield 'parse_recursive_cte #1' => ['SELECT 1 UNION ALL SELECT val + 1 FROM nums WHERE val < 10', self::ALL_AND_GENERIC, ['nums']];
        yield 'parse_cte_in_data_modification_statements #4' => ['WITH x AS (SELECT 1) UPDATE t SET bar = (SELECT * FROM x)', self::ALL_AND_GENERIC, ['x']];
        yield 'parse_cte_in_data_modification_statements #5' => ['WITH t (x) AS (SELECT 9) DELETE FROM q WHERE id IN (SELECT x FROM t)', self::ALL_AND_GENERIC, ['q', 't']];
        yield 'parse_cte_in_data_modification_statements #6' => ['WITH x AS (SELECT 42) INSERT INTO t SELECT foo FROM x', self::ALL_AND_GENERIC, ['x']];
        yield 'parse_union_except_intersect_minus #1' => ['WITH cte AS (SELECT 1 AS foo) (SELECT foo FROM cte ORDER BY 1 LIMIT 1)', self::ALL_AND_GENERIC, ['cte']];
        yield 'parse_union_except_intersect_minus #2' => ['SELECT foo FROM tab UNION SELECT bar FROM TAB', self::ALL_AND_GENERIC, ['tab', 'TAB']];
        yield 'parse_union_except_intersect_minus #3' => ['(SELECT * FROM new EXCEPT SELECT * FROM old) UNION ALL (SELECT * FROM old EXCEPT SELECT * FROM new) ORDER BY 1', self::ALL_AND_GENERIC, ['new', 'old', 'old', 'new']];
        yield 'parse_union_except_intersect_minus #4' => ['(SELECT * FROM new EXCEPT DISTINCT SELECT * FROM old) UNION DISTINCT (SELECT * FROM old EXCEPT DISTINCT SELECT * FROM new) ORDER BY 1', self::ALL_AND_GENERIC, ['new', 'old', 'old', 'new']];
        yield 'parse_values #1' => ['SELECT * FROM (VALUES (1), (2), (3))', self::ALL_AND_GENERIC, []];
        yield 'parse_values #2' => ['SELECT * FROM (VALUES (1), (2), (3)), (VALUES (1, 2, 3))', self::ALL_AND_GENERIC, []];
        yield 'parse_values #3' => ['SELECT * FROM (VALUES (1)) UNION VALUES (1)', self::ALL_AND_GENERIC, []];
        yield 'parse_values #4' => ['SELECT * FROM (VALUES ROW(1, NULL, \'a\'), ROW(2, NULL, \'b\')) AS t (a, b, c)', self::ALL_AND_GENERIC, []];
        yield 'parse_substring #1' => ['SELECT SUBSTRING(\'1\' FROM 1)', self::ALL_AND_GENERIC, []];
        yield 'parse_substring #2' => ['SELECT SUBSTRING(\'1\' FROM 1 FOR 3)', self::ALL_AND_GENERIC, []];
        yield 'parse_substring #3' => ['SELECT SUBSTRING(\'foo\' FROM 1 FOR 2) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_substring #4' => ['SELECT SUBSTR(\'foo\' FROM 1 FOR 2) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_substring #5' => ['SELECT SUBSTR(\'foo\', 1, 2) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_overlay #1' => ['SELECT OVERLAY(\'abccccde\' PLACING \'abc\' FROM 3)', self::ALL_AND_GENERIC, []];
        yield 'parse_overlay #2' => ['SELECT OVERLAY(\'abccccde\' PLACING \'abc\' FROM 3 FOR 12)', self::ALL_AND_GENERIC, []];
        yield 'parse_overlay #3' => ['SELECT OVERLAY(\'abcdef\' PLACING name FROM 3 FOR id + 1) FROM CUSTOMERS', self::ALL_AND_GENERIC, ['CUSTOMERS']];
        yield 'parse_trim #1' => ['SELECT TRIM(BOTH \'xyz\' FROM \'xyzfooxyz\')', self::ALL_AND_GENERIC, []];
        yield 'parse_trim #2' => ['SELECT TRIM(LEADING \'xyz\' FROM \'xyzfooxyz\')', self::ALL_AND_GENERIC, []];
        yield 'parse_trim #3' => ['SELECT TRIM(TRAILING \'xyz\' FROM \'xyzfooxyz\')', self::ALL_AND_GENERIC, []];
        yield 'parse_trim #4' => ['SELECT TRIM(\'xyz\' FROM \'xyzfooxyz\')', self::ALL_AND_GENERIC, []];
        yield 'parse_trim #5' => ['SELECT TRIM(BOTH FROM \'yxTomxx\', \'xyz\')', ['generic', 'pgsql', 'sqlite'], []];
        yield 'parse_fetch_variations #1' => ['SELECT foo FROM bar FETCH FIRST 10 ROW ONLY', self::ALL_AND_GENERIC, ['bar']];
        yield 'parse_fetch_variations #2' => ['SELECT foo FROM bar FETCH NEXT 10 ROW ONLY', self::ALL_AND_GENERIC, ['bar']];
        yield 'parse_fetch_variations #3' => ['SELECT foo FROM bar FETCH NEXT 10 ROWS WITH TIES', self::ALL_AND_GENERIC, ['bar']];
        yield 'parse_fetch_variations #4' => ['SELECT foo FROM bar FETCH NEXT ROWS WITH TIES', self::ALL_AND_GENERIC, ['bar']];
        yield 'parse_double_colon_cast_at_timezone #1' => ['SELECT \'2001-01-01T00:00:00.000Z\'::TIMESTAMP AT TIME ZONE \'Europe/Brussels\' FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_merge #13' => ['MERGE INTO s.bar AS dest USING (SELECT * FROM s.foo) AS stg ON dest.D = stg.D AND dest.E = stg.E WHEN NOT MATCHED THEN INSERT (A, B, C) VALUES (stg.A, stg.B, stg.C) WHEN MATCHED AND dest.A = \'a\' THEN UPDATE SET dest.F = stg.F, dest.G = stg.G WHEN MATCHED THEN DELETE', self::ALL_AND_GENERIC, ['s.foo']];
        yield 'parse_merge #14' => ['MERGE s.bar AS dest USING (SELECT * FROM s.foo) AS stg ON dest.D = stg.D AND dest.E = stg.E WHEN NOT MATCHED THEN INSERT (A, B, C) VALUES (stg.A, stg.B, stg.C) WHEN MATCHED AND dest.A = \'a\' THEN UPDATE SET dest.F = stg.F, dest.G = stg.G WHEN MATCHED THEN DELETE', self::ALL_AND_GENERIC, ['s.foo']];
        yield 'parse_merge #15' => ['MERGE INTO s.bar AS dest USING newArrivals AS S ON (1 > 1) WHEN NOT MATCHED THEN INSERT VALUES (stg.A, stg.B, stg.C)', self::ALL_AND_GENERIC, []];
        yield 'parse_merge #16' => ['MERGE INTO FOO USING FOO_IMPORT ON (FOO.ID = FOO_IMPORT.ID) WHEN MATCHED THEN UPDATE SET FOO.NAME = FOO_IMPORT.NAME WHERE 1 = 1 DELETE WHERE FOO.NAME LIKE \'%.DELETE\' WHEN NOT MATCHED THEN INSERT (ID, NAME) VALUES (FOO_IMPORT.ID, UPPER(FOO_IMPORT.NAME)) WHERE NOT FOO_IMPORT.NAME LIKE \'%.DO_NOT_INSERT\'', self::ALL_AND_GENERIC, []];
        yield 'parse_merge #17' => ['MERGE INTO FOO USING FOO_IMPORT ON (FOO.ID = FOO_IMPORT.ID) WHEN NOT MATCHED THEN INSERT (ID, NAME) VALUES (1, \'abc\')', self::ALL_AND_GENERIC, []];
        yield 'parse_merge #18' => ['MERGE INTO FOO USING FOO_IMPORT ON (FOO.ID = FOO_IMPORT.ID) WHEN NOT MATCHED THEN INSERT (FOO.ID, FOO.NAME) VALUES (1, \'abc\')', self::ALL_AND_GENERIC, []];
        yield 'parse_merge #19' => ['MERGE INTO PLAYGROUND.FOO USING FOO_IMPORT ON (PLAYGROUND.FOO.ID = FOO_IMPORT.ID) WHEN NOT MATCHED THEN INSERT (PLAYGROUND.FOO.ID, PLAYGROUND.FOO.NAME) VALUES (1, \'abc\')', self::ALL_AND_GENERIC, []];
        yield 'parse_merge #20' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED THEN UPDATE SET * WHEN NOT MATCHED THEN INSERT *', self::ALL_AND_GENERIC, []];
        yield 'parse_merge #21' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED AND source.active = 1 THEN UPDATE SET *', self::ALL_AND_GENERIC, []];
        yield 'parse_merge #22' => ['MERGE INTO target USING source ON target.id = source.id WHEN NOT MATCHED BY TARGET THEN INSERT *', self::ALL_AND_GENERIC, []];
        yield 'parse_merge #23' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED THEN UPDATE SET * WHEN NOT MATCHED THEN INSERT (a, b) VALUES (source.a, source.b)', self::ALL_AND_GENERIC, []];
        yield 'test_merge_in_cte #1' => ['WITH x AS (MERGE INTO t USING (VALUES (1)) ON 1 = 1 WHEN MATCHED THEN DELETE RETURNING *) SELECT * FROM x', self::ALL_AND_GENERIC, ['x']];
        yield 'test_merge_with_returning #2' => ['MERGE INTO wines AS w USING wine_stock_changes AS s ON s.winename = w.winename WHEN NOT MATCHED AND s.stock_delta > 0 THEN INSERT VALUES (s.winename, s.stock_delta) WHEN MATCHED AND w.stock + s.stock_delta > 0 THEN UPDATE SET stock = w.stock + s.stock_delta WHEN MATCHED THEN DELETE RETURNING merge_action(), w.*', self::ALL_AND_GENERIC, []];
        yield 'test_merge_with_output #3' => ['MERGE INTO target_table USING source_table ON target_table.id = source_table.oooid WHEN MATCHED THEN UPDATE SET target_table.description = source_table.description WHEN NOT MATCHED THEN INSERT (ID, description) VALUES (source_table.id, source_table.description) OUTPUT inserted.* INTO log_target', self::ALL_AND_GENERIC, []];
        yield 'test_merge_with_output_without_into #2' => ['MERGE INTO a USING b ON a.id = b.id WHEN MATCHED THEN DELETE OUTPUT inserted.*', self::ALL_AND_GENERIC, []];
        yield 'test_merge_into_using_table #2' => ['MERGE INTO target_table USING source_table ON target_table.id = source_table.oooid WHEN MATCHED THEN UPDATE SET target_table.description = source_table.description WHEN NOT MATCHED THEN INSERT (ID, description) VALUES (source_table.id, source_table.description)', self::ALL_AND_GENERIC, []];
        yield 'test_merge_with_delimiter #2' => ['MERGE INTO target_table USING source_table ON target_table.id = source_table.oooid WHEN MATCHED THEN UPDATE SET target_table.description = source_table.description WHEN NOT MATCHED THEN INSERT (ID, description) VALUES (source_table.id, source_table.description);', self::ALL_AND_GENERIC, []];
        yield 'test_placeholder #1' => ['SELECT * FROM student WHERE id = $Id1', ['generic', 'pgsql', 'sqlsrv'], ['student']];
        yield 'test_placeholder #2' => ['SELECT * FROM student LIMIT $1 OFFSET $2', ['generic', 'pgsql', 'sqlsrv'], ['student']];
        yield 'test_placeholder #3' => ['SELECT * FROM student WHERE id = ?', ['generic', 'pgsql', 'sqlsrv'], ['student']];
        yield 'parse_offset_and_limit #1' => ['SELECT foo FROM bar LIMIT 1 OFFSET 2', self::ALL_AND_GENERIC, ['bar']];
        yield 'parse_offset_and_limit #2' => ['SELECT foo FROM bar OFFSET 2 LIMIT 1', self::ALL_AND_GENERIC, ['bar']];
        yield 'parse_offset_and_limit #3' => ['SELECT foo FROM bar LIMIT 2, 1', ['generic', 'mysql', 'sqlite'], ['bar']];
        yield 'parse_offset_and_limit #4' => ['SELECT foo FROM bar LIMIT 1 + 2 OFFSET 3 * 4', self::ALL_AND_GENERIC, ['bar']];
        yield 'parse_offset_and_limit #5' => ['SELECT foo FROM bar OFFSET 2', self::ALL_AND_GENERIC, ['bar']];
        yield 'parse_is_boolean #1' => ['SELECT f FROM foo WHERE field IS TRUE', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_is_boolean #2' => ['SELECT f FROM foo WHERE field IS NOT TRUE', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_is_boolean #3' => ['SELECT f FROM foo WHERE field IS FALSE', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_is_boolean #4' => ['SELECT f FROM foo WHERE field IS NOT FALSE', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_is_boolean #5' => ['SELECT f FROM foo WHERE field IS NORMALIZED', ['generic', 'mysql', 'pgsql', 'sqlsrv'], ['foo']];
        yield 'parse_is_boolean #6' => ['SELECT f FROM foo WHERE field IS NFC NORMALIZED', ['generic', 'mysql', 'pgsql', 'sqlsrv'], ['foo']];
        yield 'parse_is_boolean #7' => ['SELECT f FROM foo WHERE field IS NFD NORMALIZED', ['generic', 'mysql', 'pgsql', 'sqlsrv'], ['foo']];
        yield 'parse_is_boolean #8' => ['SELECT f FROM foo WHERE field IS NOT NORMALIZED', ['generic', 'mysql', 'pgsql', 'sqlsrv'], ['foo']];
        yield 'parse_is_boolean #9' => ['SELECT f FROM foo WHERE field IS NOT NFKC NORMALIZED', ['generic', 'mysql', 'pgsql', 'sqlsrv'], ['foo']];
        yield 'parse_is_boolean #10' => ['SELECT f FROM foo WHERE field IS UNKNOWN', ['generic', 'mysql', 'pgsql', 'sqlsrv'], ['foo']];
        yield 'parse_is_boolean #11' => ['SELECT f FROM foo WHERE field IS NOT UNKNOWN', ['generic', 'mysql', 'pgsql', 'sqlsrv'], ['foo']];
        yield 'parse_cache_table #1' => ['SELECT a, b, c FROM foo', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_binary_operators_without_whitespace #1' => ['SELECT field+1000 FROM tbl1', self::ALL_AND_GENERIC, ['tbl1']];
        yield 'parse_binary_operators_without_whitespace #2' => ['SELECT tbl1.field+tbl2.field FROM tbl1 JOIN tbl2 ON tbl1.id = tbl2.entity_id', self::ALL_AND_GENERIC, ['tbl1', 'tbl2']];
        yield 'parse_binary_operators_without_whitespace #3' => ['SELECT field-1000 FROM tbl1', self::ALL_AND_GENERIC, ['tbl1']];
        yield 'parse_binary_operators_without_whitespace #4' => ['SELECT tbl1.field-tbl2.field FROM tbl1 JOIN tbl2 ON tbl1.id = tbl2.entity_id', self::ALL_AND_GENERIC, ['tbl1', 'tbl2']];
        yield 'parse_binary_operators_without_whitespace #5' => ['SELECT field*1000 FROM tbl1', self::ALL_AND_GENERIC, ['tbl1']];
        yield 'parse_binary_operators_without_whitespace #6' => ['SELECT tbl1.field*tbl2.field FROM tbl1 JOIN tbl2 ON tbl1.id = tbl2.entity_id', self::ALL_AND_GENERIC, ['tbl1', 'tbl2']];
        yield 'parse_binary_operators_without_whitespace #7' => ['SELECT field/1000 FROM tbl1', self::ALL_AND_GENERIC, ['tbl1']];
        yield 'parse_binary_operators_without_whitespace #8' => ['SELECT tbl1.field/tbl2.field FROM tbl1 JOIN tbl2 ON tbl1.id = tbl2.entity_id', self::ALL_AND_GENERIC, ['tbl1', 'tbl2']];
        yield 'parse_binary_operators_without_whitespace #9' => ['SELECT field%1000 FROM tbl1', self::ALL_AND_GENERIC, ['tbl1']];
        yield 'parse_binary_operators_without_whitespace #10' => ['SELECT tbl1.field%tbl2.field FROM tbl1 JOIN tbl2 ON tbl1.id = tbl2.entity_id', self::ALL_AND_GENERIC, ['tbl1', 'tbl2']];
        yield 'parse_connect_by #8' => ['SELECT prior FROM some_table', self::ALL_AND_GENERIC, ['some_table']];
        yield 'test_group_by_grouping_sets_bare_columns #1' => ['SELECT a, b FROM t GROUP BY GROUPING SETS (a, b, c)', ['generic', 'pgsql'], ['t']];
        yield 'test_group_by_grouping_sets_bare_columns #2' => ['SELECT a, b FROM t GROUP BY GROUPING SETS ((a, b), c)', ['generic', 'pgsql'], ['t']];
        yield 'test_select_wildcard_with_replace #1' => ['SELECT * REPLACE (lower(city) AS city) FROM addresses', ['generic'], ['addresses']];
        yield 'test_select_wildcard_with_replace #2' => ['SELECT * REPLACE (\'widget\' AS item_name) FROM orders', ['generic'], ['orders']];
        yield 'test_select_wildcard_with_replace #3' => ['SELECT * REPLACE (quantity / 2 AS quantity, 3 AS order_id) FROM orders', ['generic'], ['orders']];
        yield 'parse_sized_list #1' => ['SELECT data::FLOAT[1536] FROM embeddings', ['generic', 'pgsql'], ['embeddings']];
        yield 'insert_into_with_parentheses #2' => ['INSERT INTO t1 (id, name) (SELECT t2.id, t2.name FROM t2)', ['generic'], ['t2']];
        yield 'insert_into_with_parentheses #4' => ['INSERT INTO t1 (SELECT t2.id, t2.name FROM t2)', ['generic'], ['t2']];
        yield 'insert_into_with_parentheses #6' => ['INSERT INTO t1 ("select", name) (SELECT t2.name FROM t2)', ['generic'], ['t2']];
        yield 'tests_select_values_without_parens #1' => ['SELECT * FROM VALUES (1, 2), (2,3) AS tbl (id, val)', ['generic'], []];
        yield 'tests_select_values_without_parens_and_set_op #1' => ['SELECT id + 1, name FROM VALUES (1, \'Apple\'), (2, \'Banana\'), (3, \'Orange\') AS fruits (id, name) UNION ALL SELECT 5, \'Strawberry\'', ['generic'], []];
        yield 'parse_select_wildcard_with_except #1' => ['SELECT * EXCEPT (col_a) FROM data', ['generic'], ['data']];
        yield 'parse_select_wildcard_with_except #2' => ['SELECT * EXCEPT (department_id, employee_id) FROM employee_table', ['generic'], ['employee_table']];
        yield 'test_group_by_nothing #1' => ['SELECT count(1) FROM t GROUP BY ()', ['generic', 'pgsql'], ['t']];
        yield 'test_group_by_nothing #2' => ['SELECT name, count(1) FROM t GROUP BY name, ()', ['generic', 'pgsql'], ['t']];
        yield 'test_extract_seconds_ok #1' => ['SELECT EXTRACT(seconds FROM \'2 seconds\'::INTERVAL)', ['generic', 'pgsql'], []];
        yield 'test_truncate_table_with_on_cluster #3' => ['TRUNCATE TABLE t ON CLUSTER cluster_name', self::ALL_AND_GENERIC, []];
        yield 'test_truncate_table_with_on_cluster #4' => ['TRUNCATE TABLE t', self::ALL_AND_GENERIC, []];
        yield 'test_select_where_with_like_or_ilike_any #1' => ['SELECT * FROM x WHERE a ILIKE ANY \'%abc%\'', self::ALL_AND_GENERIC, ['x']];
        yield 'test_select_where_with_like_or_ilike_any #2' => ['SELECT * FROM x WHERE a LIKE ANY \'%abc%\'', self::ALL_AND_GENERIC, ['x']];
        yield 'test_select_where_with_like_or_ilike_any #3' => ['SELECT * FROM x WHERE a ILIKE ANY (\'%Jo%oe%\', \'T%e\')', self::ALL_AND_GENERIC, ['x']];
        yield 'test_select_where_with_like_or_ilike_any #4' => ['SELECT * FROM x WHERE a LIKE ANY (\'%Jo%oe%\', \'T%e\')', self::ALL_AND_GENERIC, ['x']];
        yield 'test_any_some_all_comparison #1' => ['SELECT c1 FROM tbl WHERE c1 = ANY(SELECT c2 FROM tbl)', self::ALL_AND_GENERIC, ['tbl', 'tbl']];
        yield 'test_any_some_all_comparison #2' => ['SELECT c1 FROM tbl WHERE c1 >= ALL(SELECT c2 FROM tbl)', self::ALL_AND_GENERIC, ['tbl', 'tbl']];
        yield 'test_any_some_all_comparison #3' => ['SELECT c1 FROM tbl WHERE c1 <> SOME(SELECT c2 FROM tbl)', self::ALL_AND_GENERIC, ['tbl', 'tbl']];
        yield 'test_any_some_all_comparison #4' => ['SELECT 1 = ANY(WITH x AS (SELECT 1) SELECT * FROM x)', self::ALL_AND_GENERIC, ['x']];
        yield 'test_alias_equal_expr #1' => ['SELECT some_alias = some_column FROM some_table', ['sqlsrv'], ['some_table']];
        yield 'test_alias_equal_expr #2' => ['SELECT some_alias = (a*b) FROM some_table', ['sqlsrv'], ['some_table']];
        yield 'test_alias_equal_expr #3' => ['SELECT some_alias = "from" FROM some_table', ['sqlsrv'], ['some_table']];
        yield 'test_alias_equal_expr #5' => ['SELECT x = (a * b) FROM some_table', ['generic', 'mysql', 'pgsql', 'sqlite'], ['some_table']];
        yield 'parse_method_select #1' => ['SELECT STUFF((SELECT \',\' + name FROM sys.objects FOR XML PATH(\'\'), TYPE).value(\'.\', \'NVARCHAR(MAX)\'), 1, 1, \'\') AS T', self::ALL_AND_GENERIC, ['sys.objects']];
        yield 'test_keywords_as_column_names_after_dot #1' => ['SELECT T.interval, T.case FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'parse_period_underscore_after_whitespace #1' => ['SELECT t._a FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_period_underscore_after_whitespace #2' => ['SELECT t ._a FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_period_underscore_after_whitespace #3' => ['SELECT t . _a FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_qualified_underscore_column_from_comment_hint #1' => ['SELECT t /*!50110 ._col */ FROM t', ['generic', 'mysql'], ['t']];
        yield 'parse_composite_access_expr #1' => ['SELECT f(a).b FROM t WHERE f(a).b IS NOT NULL', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_composite_access_expr #2' => ['SELECT * FROM t WHERE STRUCT(STRUCT(1 AS a, NULL AS b) AS c, NULL AS d).c.a IS NOT NULL', ['generic'], ['t']];
        yield 'parse_select_without_projection #1' => ['SELECT FROM users', ['generic', 'pgsql'], ['users']];
        yield 'parse_update_from_before_select #4' => ['UPDATE t1 FROM (SELECT name, id FROM t1 GROUP BY id) AS t2 SET name = t2.name WHERE t1.id = t2.id', self::ALL_AND_GENERIC, ['t1']];
        yield 'parse_update_from_before_select #5' => ['UPDATE t1 FROM U, (SELECT id FROM V) AS W SET a = b WHERE 1 = 1', self::ALL_AND_GENERIC, ['U', 'V']];
        yield 'test_select_from_first_with_cte #1' => ['WITH test AS (FROM t SELECT a) FROM test SELECT 1', ['generic'], ['t', 'test']];
        yield 'parse_pipe_operator_as #1' => ['SELECT * FROM tbl |> AS new_users', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_select #1' => ['SELECT * FROM tbl |> SELECT id', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_select #2' => ['SELECT * FROM tbl |> SELECT id, name', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_select #3' => ['SELECT * FROM tbl |> SELECT id user_id', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_select #4' => ['SELECT * FROM tbl |> SELECT id AS user_id', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_extend #1' => ['SELECT * FROM tbl |> EXTEND id + 1 AS new_id', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_extend #2' => ['SELECT * FROM tbl |> EXTEND id AS new_id, name AS new_name', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_extend #3' => ['SELECT * FROM tbl |> EXTEND id user_id', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_set #1' => ['SELECT * FROM tbl |> SET id = id + 1', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_set #2' => ['SELECT * FROM tbl |> SET id = id + 1, name = name + \' Doe\'', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_drop #1' => ['SELECT * FROM tbl |> DROP id', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_drop #2' => ['SELECT * FROM tbl |> DROP id, name', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_drop #3' => ['SELECT * FROM tbl |> DROP c |> RENAME a AS x', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_drop #4' => ['SELECT * FROM tbl |> DROP a, b |> SELECT c', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_limit #1' => ['SELECT * FROM tbl |> LIMIT 10', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_limit #2' => ['SELECT * FROM tbl |> LIMIT 10 OFFSET 5', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_limit #3' => ['SELECT * FROM tbl |> LIMIT 10 |> LIMIT 5', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_limit #4' => ['SELECT * FROM tbl |> LIMIT 10 |> WHERE true', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_where #1' => ['SELECT * FROM tbl |> WHERE id = 1', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_where #2' => ['SELECT * FROM tbl |> WHERE id = 1 AND name = \'John\'', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_where #3' => ['SELECT * FROM tbl |> WHERE id = 1 OR name = \'John\'', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_aggregate #1' => ['SELECT * FROM tbl |> AGGREGATE COUNT(*)', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_aggregate #2' => ['SELECT * FROM tbl |> AGGREGATE COUNT(*) total_users', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_aggregate #3' => ['SELECT * FROM tbl |> AGGREGATE COUNT(*) AS total_users', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_aggregate #4' => ['SELECT * FROM tbl |> AGGREGATE COUNT(*), MIN(id)', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_aggregate #5' => ['SELECT * FROM tbl |> AGGREGATE SUM(o_totalprice) AS price, COUNT(*) AS cnt GROUP BY EXTRACT(YEAR FROM o_orderdate) AS year', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_aggregate #6' => ['SELECT * FROM tbl |> AGGREGATE GROUP BY EXTRACT(YEAR FROM o_orderdate) AS year', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_aggregate #7' => ['SELECT * FROM tbl |> AGGREGATE GROUP BY EXTRACT(YEAR FROM o_orderdate)', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_aggregate #8' => ['SELECT * FROM tbl |> AGGREGATE GROUP BY a, b', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_aggregate #9' => ['SELECT * FROM tbl |> AGGREGATE SUM(c) GROUP BY a, b', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_aggregate #10' => ['SELECT * FROM tbl |> AGGREGATE SUM(c) ASC', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_order_by #1' => ['SELECT * FROM tbl |> ORDER BY id ASC', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_order_by #2' => ['SELECT * FROM tbl |> ORDER BY id DESC', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_order_by #3' => ['SELECT * FROM tbl |> ORDER BY id DESC, name ASC', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_tablesample #1' => ['SELECT * FROM tbl |> TABLESAMPLE BERNOULLI (50)', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_tablesample #2' => ['SELECT * FROM tbl |> TABLESAMPLE SYSTEM (50 PERCENT)', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_tablesample #3' => ['SELECT * FROM tbl |> TABLESAMPLE SYSTEM (50) REPEATABLE (10)', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_rename #1' => ['SELECT * FROM tbl |> RENAME old_name AS new_name', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_rename #2' => ['SELECT * FROM tbl |> RENAME id AS user_id, name AS user_name', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_rename #3' => ['SELECT * FROM tbl |> RENAME id user_id', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_union #1' => ['SELECT * FROM tbl |> UNION ALL (SELECT * FROM admins)', ['generic'], ['tbl', 'admins']];
        yield 'parse_pipe_operator_union #2' => ['SELECT * FROM tbl |> UNION DISTINCT (SELECT * FROM admins)', ['generic'], ['tbl', 'admins']];
        yield 'parse_pipe_operator_union #3' => ['SELECT * FROM tbl |> UNION (SELECT * FROM admins)', ['generic'], ['tbl', 'admins']];
        yield 'parse_pipe_operator_union #4' => ['SELECT * FROM tbl |> UNION ALL (SELECT * FROM admins), (SELECT * FROM guests)', ['generic'], ['tbl', 'admins', 'guests']];
        yield 'parse_pipe_operator_union #5' => ['SELECT * FROM tbl |> UNION DISTINCT (SELECT * FROM admins), (SELECT * FROM guests), (SELECT * FROM employees)', ['generic'], ['tbl', 'admins', 'guests', 'employees']];
        yield 'parse_pipe_operator_union #6' => ['SELECT * FROM tbl |> UNION (SELECT * FROM admins), (SELECT * FROM guests)', ['generic'], ['tbl', 'admins', 'guests']];
        yield 'parse_pipe_operator_union #7' => ['SELECT * FROM tbl |> UNION BY NAME (SELECT * FROM admins)', ['generic'], ['tbl', 'admins']];
        yield 'parse_pipe_operator_union #8' => ['SELECT * FROM tbl |> UNION ALL BY NAME (SELECT * FROM admins)', ['generic'], ['tbl', 'admins']];
        yield 'parse_pipe_operator_union #9' => ['SELECT * FROM tbl |> UNION DISTINCT BY NAME (SELECT * FROM admins)', ['generic'], ['tbl', 'admins']];
        yield 'parse_pipe_operator_union #10' => ['SELECT * FROM tbl |> UNION BY NAME (SELECT * FROM admins), (SELECT * FROM guests)', ['generic'], ['tbl', 'admins', 'guests']];
        yield 'parse_pipe_operator_intersect #1' => ['SELECT * FROM tbl |> INTERSECT DISTINCT (SELECT * FROM admins)', ['generic'], ['tbl', 'admins']];
        yield 'parse_pipe_operator_intersect #2' => ['SELECT * FROM tbl |> INTERSECT DISTINCT BY NAME (SELECT * FROM admins)', ['generic'], ['tbl', 'admins']];
        yield 'parse_pipe_operator_intersect #3' => ['SELECT * FROM tbl |> INTERSECT DISTINCT (SELECT * FROM admins), (SELECT * FROM guests)', ['generic'], ['tbl', 'admins', 'guests']];
        yield 'parse_pipe_operator_intersect #4' => ['SELECT * FROM tbl |> INTERSECT DISTINCT BY NAME (SELECT * FROM admins), (SELECT * FROM guests)', ['generic'], ['tbl', 'admins', 'guests']];
        yield 'parse_pipe_operator_except #1' => ['SELECT * FROM tbl |> EXCEPT DISTINCT (SELECT * FROM admins)', ['generic'], ['tbl', 'admins']];
        yield 'parse_pipe_operator_except #2' => ['SELECT * FROM tbl |> EXCEPT DISTINCT BY NAME (SELECT * FROM admins)', ['generic'], ['tbl', 'admins']];
        yield 'parse_pipe_operator_except #3' => ['SELECT * FROM tbl |> EXCEPT DISTINCT (SELECT * FROM admins), (SELECT * FROM guests)', ['generic'], ['tbl', 'admins', 'guests']];
        yield 'parse_pipe_operator_except #4' => ['SELECT * FROM tbl |> EXCEPT DISTINCT BY NAME (SELECT * FROM admins), (SELECT * FROM guests)', ['generic'], ['tbl', 'admins', 'guests']];
        yield 'parse_pipe_operator_call #1' => ['SELECT * FROM tbl |> CALL my_function()', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #2' => ['SELECT * FROM tbl |> CALL process_data(5, \'test\')', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #3' => ['SELECT * FROM tbl |> CALL namespace.function_name(col1, col2, \'literal\')', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #4' => ['SELECT * FROM tbl |> CALL transform_data(col1 + col2)', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #5' => ['SELECT * FROM tbl |> CALL analyze_data(\'param1\', 100, true)', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #6' => ['SELECT * FROM tbl |> CALL tvf1(arg1) AS al', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #7' => ['SELECT * FROM tbl |> CALL process_data(5) AS result_table', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #8' => ['SELECT * FROM tbl |> CALL namespace.func() AS my_alias', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #9' => ['SELECT * FROM tbl |> CALL tvf1(arg1) |> CALL tvf2(arg2, arg3)', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #10' => ['SELECT * FROM tbl |> CALL transform(col1) |> CALL validate() |> CALL process(param)', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #11' => ['SELECT * FROM tbl |> CALL tvf1(arg1) AS step1 |> CALL tvf2(arg2) AS step2', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #12' => ['SELECT * FROM tbl |> CALL preprocess() AS clean_data |> CALL analyze(mode) AS results', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #13' => ['SELECT * FROM tbl |> CALL transform() |> WHERE status = \'active\' |> CALL process(param)', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_call #14' => ['SELECT * FROM tbl |> CALL preprocess() AS clean |> SELECT col1, col2 |> CALL validate()', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_pivot #1' => ['SELECT * FROM tbl |> PIVOT(SUM(amount) FOR quarter IN (\'Q1\', \'Q2\', \'Q3\', \'Q4\'))', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_pivot #2' => ['SELECT * FROM tbl |> PIVOT(AVG(revenue) FOR region IN (\'North\', \'South\', \'East\', \'West\'))', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_pivot #3' => ['SELECT * FROM tbl |> PIVOT(SUM(sales) AS total_sales, COUNT(*) AS num_transactions FOR month IN (\'Jan\', \'Feb\', \'Mar\'))', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_pivot #4' => ['SELECT * FROM tbl |> PIVOT(SUM(amount) FOR product.category IN (\'Electronics\', \'Clothing\'))', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_pivot #5' => ['SELECT * FROM tbl |> WHERE year = 2023 |> PIVOT(SUM(revenue) FOR quarter IN (\'Q1\', \'Q2\', \'Q3\', \'Q4\'))', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_pivot #6' => ['SELECT * FROM tbl |> PIVOT(SUM(sales) FOR quarter IN (\'Q1\', \'Q2\')) AS quarterly_sales', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_pivot #7' => ['SELECT * FROM tbl |> PIVOT(AVG(price) FOR category IN (\'A\', \'B\', \'C\')) AS avg_by_category', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_pivot #8' => ['SELECT * FROM tbl |> PIVOT(COUNT(*) AS transactions, SUM(amount) AS total FOR region IN (\'North\', \'South\')) AS regional_summary', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_pivot #9' => ['SELECT * FROM tbl |> PIVOT(SUM(sales) FOR quarter IN (\'Q1\', \'Q2\')) quarterly_sales', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_pivot #10' => ['SELECT * FROM tbl |> PIVOT(AVG(price) FOR category IN (\'A\', \'B\', \'C\')) avg_by_category', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_unpivot #1' => ['SELECT * FROM tbl |> UNPIVOT(revenue FOR quarter IN (Q1, Q2, Q3, Q4))', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_unpivot #2' => ['SELECT * FROM tbl |> UNPIVOT(value FOR category IN (A, B, C))', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_unpivot #3' => ['SELECT * FROM tbl |> UNPIVOT(measurement FOR metric_type IN (cpu, memory, disk))', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_unpivot #4' => ['SELECT * FROM tbl |> UNPIVOT(amount FOR period IN (jan, feb, mar, apr, may, jun))', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_unpivot #5' => ['SELECT * FROM tbl |> UNPIVOT(score FOR subject IN (math, science, english, history))', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_unpivot #6' => ['SELECT * FROM tbl |> WHERE year = 2023 |> UNPIVOT(revenue FOR quarter IN (Q1, Q2, Q3, Q4))', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_unpivot #7' => ['SELECT * FROM tbl |> UNPIVOT(amount FOR period IN (Q1, Q2)) AS unpivoted_sales', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_unpivot #8' => ['SELECT * FROM tbl |> UNPIVOT(value FOR category IN (A, B, C)) AS transformed_data', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_unpivot #9' => ['SELECT * FROM tbl |> UNPIVOT(measurement FOR metric_type IN (cpu, memory)) AS metric_measurements', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_unpivot #10' => ['SELECT * FROM tbl |> UNPIVOT(amount FOR period IN (Q1, Q2)) unpivoted_sales', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_unpivot #11' => ['SELECT * FROM tbl |> UNPIVOT(value FOR category IN (A, B, C)) transformed_data', ['generic'], ['tbl']];
        yield 'parse_pipe_operator_join #1' => ['SELECT * FROM tbl |> JOIN orders ON users.id = orders.user_id', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #2' => ['SELECT * FROM tbl |> INNER JOIN orders ON users.id = orders.user_id', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #3' => ['SELECT * FROM tbl |> LEFT JOIN orders ON users.id = orders.user_id', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #4' => ['SELECT * FROM tbl |> LEFT OUTER JOIN orders ON users.id = orders.user_id', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #5' => ['SELECT * FROM tbl |> RIGHT JOIN orders ON users.id = orders.user_id', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #6' => ['SELECT * FROM tbl |> RIGHT OUTER JOIN orders ON users.id = orders.user_id', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #7' => ['SELECT * FROM tbl |> FULL JOIN orders ON users.id = orders.user_id', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #8' => ['SELECT * FROM tbl |> FULL OUTER JOIN orders ON users.id = orders.user_id', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #9' => ['SELECT * FROM tbl |> CROSS JOIN orders', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #10' => ['SELECT * FROM tbl |> JOIN orders USING (user_id)', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #11' => ['SELECT * FROM tbl |> LEFT JOIN orders USING (user_id, order_date)', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #12' => ['SELECT * FROM tbl |> JOIN orders o ON users.id = o.user_id', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #13' => ['SELECT * FROM tbl |> LEFT JOIN orders AS o ON users.id = o.user_id', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #14' => ['SELECT * FROM tbl |> JOIN orders ON users.id = orders.user_id AND orders.status = \'active\'', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #15' => ['SELECT * FROM tbl |> LEFT JOIN orders ON users.id = orders.user_id AND orders.amount > 100', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #16' => ['SELECT * FROM tbl |> JOIN orders ON users.id = orders.user_id |> JOIN products ON orders.product_id = products.id', ['generic'], ['tbl', 'orders', 'products']];
        yield 'parse_pipe_operator_join #17' => ['SELECT * FROM tbl |> LEFT JOIN orders ON users.id = orders.user_id |> RIGHT JOIN products ON orders.product_id = products.id', ['generic'], ['tbl', 'orders', 'products']];
        yield 'parse_pipe_operator_join #18' => ['SELECT * FROM tbl |> JOIN orders ON users.id = orders.user_id |> WHERE orders.amount > 100', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #19' => ['SELECT * FROM tbl |> WHERE users.active = true |> LEFT JOIN orders ON users.id = orders.user_id', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_join #20' => ['SELECT * FROM tbl |> JOIN orders ON users.id = orders.user_id |> SELECT users.name, orders.amount', ['generic'], ['tbl', 'orders']];
        yield 'parse_pipe_operator_chained #1' => ['SELECT * FROM tbl |> AGGREGATE SUM(cost) AS total_cost GROUP BY customer_id, state, item_type |> EXTEND COUNT(*) OVER (PARTITION BY customer_id) AS num_orders |> WHERE num_orders > 1 |> AGGREGATE AVG(total_cost) AS average GROUP BY state DESC, item_type ASC', ['generic'], ['tbl']];
        yield 'parse_truncate_only #2' => ['TRUNCATE TABLE employee, ONLY dept', self::ALL_AND_GENERIC, []];
        yield 'join_precedence #2' => ["SELECT *\n         FROM t1\n         NATURAL JOIN t5\n         INNER JOIN t0 ON (t0.v1 + t5.v0) > 0\n         WHERE t0.v1 = t1.v0", self::ALL_AND_GENERIC, ['t1', 't5', 't0']];
        yield 'test_select_exclude #1' => ['SELECT * EXCLUDE c1 FROM test', ['generic'], ['test']];
        yield 'test_select_exclude #2' => ['SELECT * EXCLUDE (c1, c2) FROM test', ['generic'], ['test']];
        yield 'test_select_exclude #3' => ['SELECT * EXCLUDE c1, c2 FROM test', ['generic'], ['test']];
        yield 'test_select_exclude_qualified_names #1' => ['SELECT f.* EXCLUDE (f.account_canonical_id, f.amount) FROM t AS f', ['generic'], ['t']];
        yield 'test_select_exclude_qualified_names #2' => ['SELECT f.* EXCLUDE (account_canonical_id) FROM t AS f', ['generic'], ['t']];
        yield 'test_select_exclude_qualified_names #3' => ['SELECT f.* EXCLUDE (col1, col2) FROM t AS f', ['generic'], ['t']];
        yield 'test_identifier_unicode_support #1' => ['SELECT phoneǤЖשचᎯ⻩☯♜🦄⚛🀄ᚠ⌛🌀 AS tbl FROM customers', ['mysql', 'pgsql'], ['customers']];
        yield 'test_identifier_unicode_start #1' => ['SELECT 💝phone AS 💝 FROM customers', ['mysql', 'pgsql'], ['customers']];
        yield 'parse_odbc_time_date_timestamp #1' => ['SELECT {d \'2025-07-17\'}, category_name FROM categories', self::ALL_AND_GENERIC, ['categories']];
        yield 'parse_odbc_time_date_timestamp #2' => ['SELECT {t \'14:12:01\'}, category_name FROM categories', self::ALL_AND_GENERIC, ['categories']];
        yield 'parse_odbc_time_date_timestamp #3' => ['SELECT {ts \'2025-07-17 14:12:01\'}, category_name FROM categories', self::ALL_AND_GENERIC, ['categories']];
        yield 'parse_adjacent_string_literal_concatenation #1' => ["SELECT * FROM t WHERE col = 'Hello' \n ' ' \t 'World!'", ['mysql'], ['t']];
        yield 'parse_select_parenthesized_wildcard #1' => ['SELECT DISTINCT (*) FROM table1', self::ALL_AND_GENERIC, ['table1']];
        yield 'parse_select_parenthesized_wildcard #2' => ['SELECT DISTINCT(*) FROM table1', self::ALL_AND_GENERIC, ['table1']];
        yield 'parse_semi_structured_data_traversal #1' => ['SELECT a:b FROM t', ['generic'], ['t']];
        yield 'parse_semi_structured_data_traversal #2' => ['SELECT a:"my long object key name" FROM t', ['generic'], ['t']];
        yield 'parse_semi_structured_data_traversal #3' => ['SELECT a:b::INT FROM t', ['generic'], ['t']];
        yield 'parse_semi_structured_data_traversal #4' => ['SELECT a:select, a:from FROM t', ['generic'], ['FROM', 't']];
        yield 'test_wildcard_func_arg #1' => ['SELECT HASH(* EXCLUDE(col1)) FROM t', ['generic'], ['t']];
        yield 'parse_select_item_multi_column_alias #1' => ['SELECT stack(2, \'a\', \'b\', \'c\', \'d\') AS (col1, col2) FROM t', ['generic'], ['t']];
        yield 'parse_non_pg_dialects_keep_xml_names_as_regular_identifiers #1' => ['SELECT xml FROM t', ['mysql', 'sqlite', 'sqlsrv'], ['t']];
        yield 'parse_insert_by_name #5' => ['INSERT INTO target BY NAME SELECT 1 AS a', self::ALL_AND_GENERIC, []];
        yield 'parse_insert_by_name #6' => ['INSERT INTO target (a) BY NAME SELECT 1 AS a', self::ALL_AND_GENERIC, []];
        yield 'parse_insert_by_name #7' => ['INSERT INTO TABLE target PARTITION (p = 1) BY NAME SELECT 1 AS a', ['generic', 'mysql', 'sqlite', 'sqlsrv'], []];
        yield 'parse_insert_by_name #8' => ['INSERT INTO target AS t BY NAME SELECT 1 AS a', ['pgsql'], []];
        yield 'parse_table_preserves_quotes_and_trailing_tokens #3' => ['INSERT INTO t TABLE `%mpty`', ['generic', 'mysql'], []];
        yield 'parse_in_list_leading_subquery #7' => ['SELECT a FROM u', self::ALL_AND_GENERIC, ['u']];
        yield 'parse_window_spec_keyword_base_window_name #1' => ['SELECT count(*) OVER (ORDER BY a) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_window_spec_keyword_base_window_name #2' => ['SELECT count(*) OVER (PARTITION BY a) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_window_spec_keyword_base_window_name #3' => ['SELECT count(*) OVER (ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_qualified_column_named_like_a_literal_prefix #1' => ['SELECT t.u&\'uni\' FROM t', ['generic', 'mysql'], ['t']];
        yield 'parse_create_procedure_begin_atomic #2' => ['INSERT INTO t VALUES (1)', self::ALL_AND_GENERIC, []];
        yield 'parse_ilike #1' => ['SELECT * FROM customers WHERE name ILIKE \'%a\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_ilike #2' => ['SELECT * FROM customers WHERE name ILIKE \'%a\' ESCAPE \'^\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_ilike #3' => ['SELECT * FROM customers WHERE name ILIKE \'%a\' IS NULL', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_ilike #4' => ['SELECT * FROM customers WHERE name NOT ILIKE \'%a\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_ilike #5' => ['SELECT * FROM customers WHERE name NOT ILIKE \'%a\' ESCAPE \'^\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_ilike #6' => ['SELECT * FROM customers WHERE name NOT ILIKE \'%a\' IS NULL', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_like #1' => ['SELECT * FROM customers WHERE name LIKE \'%a\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_like #2' => ['SELECT * FROM customers WHERE name LIKE \'%a\' ESCAPE \'^\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_like #3' => ['SELECT * FROM customers WHERE name LIKE \'%a\' IS NULL', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_like #4' => ['SELECT * FROM customers WHERE name NOT LIKE \'%a\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_like #5' => ['SELECT * FROM customers WHERE name NOT LIKE \'%a\' ESCAPE \'^\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_like #6' => ['SELECT * FROM customers WHERE name NOT LIKE \'%a\' IS NULL', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_similar_to #1' => ['SELECT * FROM customers WHERE name SIMILAR TO \'%a\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_similar_to #2' => ['SELECT * FROM customers WHERE name SIMILAR TO \'%a\' ESCAPE \'^\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_similar_to #3' => ['SELECT * FROM customers WHERE name SIMILAR TO \'%a\' ESCAPE NULL', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_similar_to #4' => ['SELECT * FROM customers WHERE name SIMILAR TO \'%a\' ESCAPE \'^\' IS NULL', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_similar_to #5' => ['SELECT * FROM customers WHERE name NOT SIMILAR TO \'%a\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_similar_to #6' => ['SELECT * FROM customers WHERE name NOT SIMILAR TO \'%a\' ESCAPE \'^\'', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_similar_to #7' => ['SELECT * FROM customers WHERE name NOT SIMILAR TO \'%a\' ESCAPE NULL', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_similar_to #8' => ['SELECT * FROM customers WHERE name NOT SIMILAR TO \'%a\' ESCAPE \'^\' IS NULL', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_in_list #1' => ['SELECT * FROM customers WHERE segment IN (\'HIGH\', \'MED\')', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_in_list #2' => ['SELECT * FROM customers WHERE segment NOT IN (\'HIGH\', \'MED\')', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_in_unnest #1' => ['SELECT * FROM customers WHERE segment IN UNNEST(expr)', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_in_unnest #2' => ['SELECT * FROM customers WHERE segment NOT IN UNNEST(expr)', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_between #1' => ['SELECT * FROM customers WHERE age BETWEEN 25 AND 32', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_between #2' => ['SELECT * FROM customers WHERE age NOT BETWEEN 25 AND 32', self::ALL_AND_GENERIC, ['customers']];
        yield 'parse_select_order_by #1' => ['SELECT id, fname, lname FROM customer WHERE id < 5 ORDER BY lname ASC, fname DESC, id', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_order_by #2' => ['SELECT id, fname, lname FROM customer ORDER BY lname ASC, fname DESC, id', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_order_by_not_support_all #1' => ['SELECT id, ALL FROM customer WHERE id < 5 ORDER BY ALL', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_order_by_not_support_all #2' => ['SELECT id, ALL FROM customer ORDER BY ALL ASC NULLS FIRST', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_select_order_by_not_support_all #3' => ['SELECT id, ALL FROM customer ORDER BY ALL DESC NULLS LAST', self::ALL_AND_GENERIC, ['customer']];
        yield 'parse_scalar_function_in_projection #1' => ['SELECT sqrt(id) FROM foo', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_scalar_function_in_projection #2' => ['SELECT foo(id) FROM foo', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_unnest_in_from_clause #1' => ['SELECT * FROM UNNEST(expr) AS numbers WITH OFFSET', ['generic'], []];
        yield 'parse_unnest_in_from_clause #2' => ['SELECT * FROM UNNEST(expr)', ['generic'], []];
        yield 'parse_unnest_in_from_clause #3' => ['SELECT * FROM UNNEST(expr) WITH OFFSET', ['generic'], []];
        yield 'parse_unnest_in_from_clause #4' => ['SELECT * FROM UNNEST(expr) AS numbers', ['generic'], []];
        yield 'parse_unnest_in_from_clause #5' => ['SELECT * FROM UNNEST(expr) WITH OFFSET AS with_offset_alias', ['generic'], []];
        yield 'parse_recursive_cte #2' => ['WITH RECURSIVE nums (val) AS (SELECT 1 UNION ALL SELECT val + 1 FROM nums WHERE val < 10) SELECT * FROM nums', self::ALL_AND_GENERIC, ['nums', 'nums']];
        yield 'parse_multiple_statements #1' => ['SELECT foo FROM baz;SELECT bar', self::ALL_AND_GENERIC, ['baz']];
        yield 'parse_multiple_statements #3' => ['DELETE FROM foo;SELECT bar', self::ALL_AND_GENERIC, ['foo']];
        yield 'parse_multiple_statements #5' => ['INSERT INTO foo VALUES (1);SELECT bar', self::ALL_AND_GENERIC, []];
        yield 'parse_multiple_statements #6' => ['SELECT foo FROM baz;WITH cte AS (SELECT 1 AS s) SELECT bar', self::ALL_AND_GENERIC, ['baz']];
        yield 'parse_window_rank_function #1' => ['SELECT column1, column2, FIRST_VALUE(column2) OVER (PARTITION BY column1 ORDER BY column2 NULLS LAST) AS column2_first FROM t1', ['generic', 'pgsql', 'sqlsrv'], ['t1']];
        yield 'parse_window_rank_function #2' => ['SELECT column1, column2, FIRST_VALUE(column2) OVER (ORDER BY column2 NULLS LAST) AS column2_first FROM t1', ['generic', 'pgsql', 'sqlsrv'], ['t1']];
        yield 'parse_window_rank_function #3' => ['SELECT col_1, col_2, LAG(col_2) OVER (ORDER BY col_1) FROM t1', ['generic', 'pgsql', 'sqlsrv'], ['t1']];
        yield 'parse_window_rank_function #4' => ['SELECT LAG(col_2, 1, 0) OVER (ORDER BY col_1) FROM t1', ['generic', 'pgsql', 'sqlsrv'], ['t1']];
        yield 'parse_window_rank_function #5' => ['SELECT column1, column2, FIRST_VALUE(column2) IGNORE NULLS OVER (PARTITION BY column1 ORDER BY column2 NULLS LAST) AS column2_first FROM t1', ['sqlsrv'], ['t1']];
        yield 'parse_window_rank_function #6' => ['SELECT column1, column2, FIRST_VALUE(column2) RESPECT NULLS OVER (PARTITION BY column1 ORDER BY column2 NULLS LAST) AS column2_first FROM t1', ['sqlsrv'], ['t1']];
        yield 'parse_window_rank_function #7' => ['SELECT LAG(col_2, 1, 0) IGNORE NULLS OVER (ORDER BY col_1) FROM t1', ['sqlsrv'], ['t1']];
        yield 'parse_window_rank_function #8' => ['SELECT LAG(col_2, 1, 0) RESPECT NULLS OVER (ORDER BY col_1) FROM t1', ['sqlsrv'], ['t1']];
        yield 'parse_array_agg_func #1' => ['SELECT ARRAY_AGG(x ORDER BY x) AS a FROM T', ['generic', 'pgsql', 'sqlsrv'], ['T']];
        yield 'parse_array_agg_func #2' => ['SELECT ARRAY_AGG(x ORDER BY x LIMIT 2) FROM tbl', ['generic', 'pgsql', 'sqlsrv'], ['tbl']];
        yield 'parse_array_agg_func #3' => ['SELECT ARRAY_AGG(DISTINCT x ORDER BY x LIMIT 2) FROM tbl', ['generic', 'pgsql', 'sqlsrv'], ['tbl']];
        yield 'parse_array_agg_func #4' => ['SELECT ARRAY_AGG(x ORDER BY x, y) AS a FROM T', ['generic', 'pgsql', 'sqlsrv'], ['T']];
        yield 'parse_array_agg_func #5' => ['SELECT ARRAY_AGG(x ORDER BY x ASC, y DESC) AS a FROM T', ['generic', 'pgsql', 'sqlsrv'], ['T']];
        yield 'parse_agg_with_order_by #1' => ['SELECT FIRST_VALUE(x ORDER BY x) AS a FROM T', ['generic', 'pgsql', 'sqlsrv'], ['T']];
        yield 'parse_agg_with_order_by #2' => ['SELECT FIRST_VALUE(x ORDER BY x) FROM tbl', ['generic', 'pgsql', 'sqlsrv'], ['tbl']];
        yield 'parse_agg_with_order_by #3' => ['SELECT LAST_VALUE(x ORDER BY x, y) AS a FROM T', ['generic', 'pgsql', 'sqlsrv'], ['T']];
        yield 'parse_agg_with_order_by #4' => ['SELECT LAST_VALUE(x ORDER BY x ASC, y DESC) AS a FROM T', ['generic', 'pgsql', 'sqlsrv'], ['T']];
        yield 'test_compound_expr #1' => ['SELECT abc[1].f1 FROM t', ['generic'], ['t']];
        yield 'test_compound_expr #2' => ['SELECT abc[1].f1.f2 FROM t', ['generic'], ['t']];
        yield 'test_compound_expr #3' => ['SELECT f1.abc[1] FROM t', ['generic'], ['t']];
        yield 'test_compound_expr #4' => ['SELECT f1.f2.abc[1] FROM t', ['generic'], ['t']];
        yield 'test_compound_expr #5' => ['SELECT f1.abc[1].f2 FROM t', ['generic'], ['t']];
        yield 'test_compound_expr #6' => ['SELECT abc[1][-1].a.b FROM t', ['generic'], ['t']];
        yield 'test_compound_expr #7' => ['SELECT abc[1][-1].a.b[1] FROM t', ['generic'], ['t']];
        yield 'test_selective_aggregation #1' => ['SELECT ARRAY_AGG(name) FILTER (WHERE name IS NOT NULL), ARRAY_AGG(name) FILTER (WHERE name LIKE \'a%\') AS agg2 FROM region', ['generic', 'pgsql', 'sqlite'], ['region']];
        yield 'test_group_by_grouping_sets #1' => ['SELECT city, car_model, sum(quantity) AS sum FROM dealer GROUP BY GROUPING SETS ((city, car_model), (city), (car_model), ()) ORDER BY city', ['generic', 'pgsql'], ['dealer']];
        yield 'test_keywords_as_column_names_after_dot #2' => ['SELECT T.interval FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #3' => ['SELECT SUM(x) OVER (PARTITION BY T.interval ORDER BY T.id) FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #4' => ['SELECT T.interval, S.interval FROM T, S WHERE T.interval = S.interval', self::ALL_AND_GENERIC, ['T', 'S']];
        yield 'test_keywords_as_column_names_after_dot #5' => ['SELECT T.case FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #6' => ['SELECT SUM(x) OVER (PARTITION BY T.case ORDER BY T.id) FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #7' => ['SELECT T.case, S.case FROM T, S WHERE T.case = S.case', self::ALL_AND_GENERIC, ['T', 'S']];
        yield 'test_keywords_as_column_names_after_dot #8' => ['SELECT T.cast FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #9' => ['SELECT SUM(x) OVER (PARTITION BY T.cast ORDER BY T.id) FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #10' => ['SELECT T.cast, S.cast FROM T, S WHERE T.cast = S.cast', self::ALL_AND_GENERIC, ['T', 'S']];
        yield 'test_keywords_as_column_names_after_dot #11' => ['SELECT T.extract FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #12' => ['SELECT SUM(x) OVER (PARTITION BY T.extract ORDER BY T.id) FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #13' => ['SELECT T.extract, S.extract FROM T, S WHERE T.extract = S.extract', self::ALL_AND_GENERIC, ['T', 'S']];
        yield 'test_keywords_as_column_names_after_dot #14' => ['SELECT T.trim FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #15' => ['SELECT SUM(x) OVER (PARTITION BY T.trim ORDER BY T.id) FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #16' => ['SELECT T.trim, S.trim FROM T, S WHERE T.trim = S.trim', self::ALL_AND_GENERIC, ['T', 'S']];
        yield 'test_keywords_as_column_names_after_dot #17' => ['SELECT T.substring FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #18' => ['SELECT SUM(x) OVER (PARTITION BY T.substring ORDER BY T.id) FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #19' => ['SELECT T.substring, S.substring FROM T, S WHERE T.substring = S.substring', self::ALL_AND_GENERIC, ['T', 'S']];
        yield 'test_keywords_as_column_names_after_dot #20' => ['SELECT T.left FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #21' => ['SELECT SUM(x) OVER (PARTITION BY T.left ORDER BY T.id) FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #22' => ['SELECT T.left, S.left FROM T, S WHERE T.left = S.left', self::ALL_AND_GENERIC, ['T', 'S']];
        yield 'test_keywords_as_column_names_after_dot #23' => ['SELECT T.right FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #24' => ['SELECT SUM(x) OVER (PARTITION BY T.right ORDER BY T.id) FROM T', self::ALL_AND_GENERIC, ['T']];
        yield 'test_keywords_as_column_names_after_dot #25' => ['SELECT T.right, S.right FROM T, S WHERE T.right = S.right', self::ALL_AND_GENERIC, ['T', 'S']];
        yield 'parse_select_without_projection #2' => ['SELECT * FROM (SELECT) AS t', ['generic', 'pgsql'], []];
        yield 'test_select_from_first #1' => ['FROM capitals', ['generic'], ['capitals']];
        yield 'test_select_from_first #2' => ['FROM capitals SELECT *', ['generic'], ['capitals']];
        yield 'parse_position_in_connect_by_list_after_state_change #1' => ['SELECT * FROM t CONNECT BY x IN (((SELECT POSITION(PRIOR IN (z)))), 1)', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_cte_materialization #1' => ['WITH c AS MATERIALIZED (SELECT 1) SELECT * FROM c', ['pgsql'], ['c']];
        yield 'parse_cte_materialization #2' => ['WITH c AS NOT MATERIALIZED (SELECT 1) SELECT * FROM c', ['pgsql'], ['c']];
        yield 'parse_window_spec_keyword_base_window_name #4' => ['SELECT count(*) OVER (w ORDER BY a) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_window_spec_keyword_base_window_name #5' => ['SELECT count(*) OVER (id ORDER BY a) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_window_spec_keyword_base_window_name #6' => ['SELECT count(*) OVER (part ORDER BY a) FROM t', self::ALL_AND_GENERIC, ['t']];
        yield 'parse_qualified_column_named_like_a_literal_prefix #2' => ['SELECT t.X\'ff\' FROM t', ['generic', 'mysql'], ['t']];
        yield 'parse_qualified_column_named_like_a_literal_prefix #3' => ['SELECT t.B\'01\' FROM t', ['generic', 'mysql'], ['t']];
        yield 'parse_qualified_column_named_like_a_literal_prefix #4' => ['SELECT t.N\'nat\' FROM t', ['generic', 'mysql'], ['t']];
        yield 'parse_qualified_column_named_like_a_literal_prefix #5' => ['SELECT t.R\'raw\' FROM t', ['generic', 'mysql'], ['t']];
        yield 'parse_qualified_column_named_like_a_literal_prefix #6' => ['SELECT t.E\'es\' FROM t', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #1' => ['SELECT * FROM t GROUP BY x WITH ROLLUP', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #2' => ['SELECT * FROM t GROUP BY x WITH CUBE', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #3' => ['SELECT * FROM t GROUP BY x WITH TOTALS', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #4' => ['SELECT * FROM t GROUP BY x WITH ROLLUP WITH CUBE', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #5' => ['SELECT * FROM t GROUP BY a, b WITH ROLLUP', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #6' => ['SELECT * FROM t GROUP BY a, b WITH CUBE', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #7' => ['SELECT * FROM t GROUP BY a, b WITH TOTALS', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #8' => ['SELECT * FROM t GROUP BY a, b WITH ROLLUP WITH CUBE', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #9' => ['SELECT * FROM t GROUP BY ALL WITH ROLLUP', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #10' => ['SELECT * FROM t GROUP BY ALL WITH CUBE', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #11' => ['SELECT * FROM t GROUP BY ALL WITH TOTALS', ['generic', 'mysql'], ['t']];
        yield 'parse_group_by_with_modifier #12' => ['SELECT * FROM t GROUP BY ALL WITH ROLLUP WITH CUBE', ['generic', 'mysql'], ['t']];
        yield 'parse_listagg #3' => ['SELECT LISTAGG(DISTINCT dateid, \', \' ON OVERFLOW TRUNCATE \'%\' WITHOUT COUNT) WITHIN GROUP (ORDER BY id, username)', self::ALL_AND_GENERIC, []];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function commonUnsupportedReads(): iterable
    {
        yield 'parse_invalid_table_name #1' => ['SELECT * FROM db.public..customer', self::ALL];
        yield 'parse_in_table_expression_malformed_targets #1' => ['SELECT a IN t.', ['sqlite']];
        yield 'parse_table_function #2' => ["SELECT * FROM TABLE '1' AS a", self::ALL];
        yield 'parse_table_function #3' => ['SELECT * FROM TABLE (FUN(a) AS a', self::ALL];
        yield 'parse_natural_join #9' => ['SELECT * FROM t1 natural', self::ALL];
        yield 'parse_join_syntax_variants #9' => ['SELECT * FROM a OUTER JOIN b ON 1', self::ALL];
        yield 'lateral_derived #3' => ['SELECT * FROM a LEFT JOIN LATERAL (b CROSS JOIN c)', self::ALL];
        yield 'parse_pivot_table #1' => ["SELECT * FROM monthly_sales AS a PIVOT(SUM(a.amount), SUM(b.amount) AS t, SUM(c.amount) AS u FOR a.MONTH IN (1 AS x, 'two', three AS y)) AS p (c, d) ORDER BY EMPID", self::ALL];
        yield 'parse_pivot_table #2' => ["SELECT * FROM monthly_sales PIVOT(SUM(a.amount) FOR a.MONTH IN ('JAN', 'FEB', 'MAR', 'APR')) AS p (c, d) ORDER BY EMPID", self::ALL];
        yield 'parse_pivot_table #3' => ["SELECT * FROM person PIVOT(SUM(age) AS a, AVG(class) AS c FOR (name, age) IN (('John', 30) AS c1, ('Mike', 40) AS c2))", self::ALL];
        yield 'parse_pivot_table #4' => ["SELECT * FROM t PIVOT(SUM(1) FOR a.abc IN (1 x, 'two' y, three z))", self::ALL];
        yield 'parse_pivot_table #5' => ["SELECT * FROM t PIVOT(SUM(1) FOR a.abc IN (1 AS x, 'two' AS y, three AS z))", self::ALL];
        yield 'parse_pivot_table #6' => ['SELECT * FROM t PIVOT(SUM(1) x, COUNT(42) y FOR a.abc IN (1))', self::ALL];
        yield 'parse_pivot_table #7' => ['SELECT * FROM t PIVOT(SUM(1) AS x, COUNT(42) AS y FOR a.abc IN (1))', self::ALL];
        yield 'parse_unpivot_table #1' => ['SELECT * FROM sales AS s UNPIVOT(quantity FOR quarter IN (Q1, Q2, Q3, Q4)) AS u (product, quarter, quantity)', self::ALL];
        yield 'parse_unpivot_table #2' => ['SELECT * FROM sales UNPIVOT(quantity FOR quarter IN (Q1, Q2, Q3, Q4))', self::ALL];
        yield 'parse_unpivot_table #3' => ['SELECT * FROM sales AS s UNPIVOT EXCLUDE NULLS (quantity FOR quarter IN (Q1, Q2, Q3, Q4)) AS u (product, quarter, quantity)', self::ALL];
        yield 'parse_unpivot_table #4' => ['SELECT * FROM sales AS s UNPIVOT INCLUDE NULLS (quantity FOR quarter IN (Q1, Q2, Q3, Q4)) AS u (product, quarter, quantity)', self::ALL];
        yield 'parse_unpivot_table #5' => ['SELECT * FROM sales AS s UNPIVOT INCLUDE NULLS (quantity FOR quarter IN (Q1 AS Quater1, Q2 AS Quater2, Q3 AS Quater3, Q4 AS Quater4)) AS u (product, quarter, quantity)', self::ALL];
        yield 'parse_pivot_unpivot_table #1' => ["SELECT * FROM census AS c UNPIVOT(population FOR year IN (population_2000, population_2010)) AS u PIVOT(sum(population) FOR year IN ('population_2000', 'population_2010')) AS p", self::ALL];
        yield 'test_match_recognize #1' => ['SELECT * FROM my_table MATCH_RECOGNIZE(PARTITION BY company ORDER BY price_date MEASURES MATCH_NUMBER() AS match_number, FIRST(price_date) AS start_date, LAST(price_date) AS end_date ONE ROW PER MATCH AFTER MATCH SKIP TO LAST row_with_price_increase PATTERN (row_before_decrease row_with_price_decrease+ row_with_price_increase+) DEFINE row_with_price_decrease AS price < LAG(price), row_with_price_increase AS price > LAG(price))', self::ALL];
        yield 'test_match_recognize_patterns #1' => ['SELECT * FROM my_table MATCH_RECOGNIZE(PATTERN (S1+? S2?? S3{2,4}?) DEFINE DUMMY AS 1 = 1)', self::ALL];
        yield 'test_match_recognize_patterns #2' => ['SELECT * FROM my_table MATCH_RECOGNIZE(PATTERN (PERMUTE(A, B, C)) DEFINE DUMMY AS 1 = 1)', self::ALL];
        yield 'test_trailing_commas_in_from #1' => ['SELECT 1, 2 FROM t,', self::ALL];
        yield 'test_trailing_commas_in_from #2' => ['SELECT 1, 2 FROM t1, t2,', self::ALL];
        yield 'test_trailing_commas_in_from #3' => ['SELECT a, FROM b, LIMIT 1', self::ALL];
        yield 'test_trailing_commas_in_from #4' => ['SELECT a FROM b, HAVING COUNT(*) > 1', self::ALL];
        yield 'test_trailing_commas_in_from #5' => ['SELECT a FROM b, WHERE c = 1', self::ALL];
        yield 'test_trailing_commas_in_from #6' => ['SELECT 1, 2 FROM (SELECT * FROM t,),', self::ALL];
        yield 'test_trailing_commas_in_from #7' => ['SELECT 1, 2 FROM (SELECT * FROM t1), (SELECT * FROM t2),', self::ALL];
        yield 'on_keyword_rejected_as_table_name #1' => ['SELECT * FROM ON', self::ALL];
        yield 'on_keyword_rejected_as_table_name #2' => ['SELECT * FROM t JOIN ON', self::ALL];
        yield 'on_keyword_rejected_as_table_name #3' => ['SELECT * FROM t JOIN ON u ON TRUE', self::ALL];
        yield 'parse_stage_table_factor #1' => ['SELECT * FROM @stage', ['pgsql', 'sqlite']];
        yield 'parse_stage_table_factor #2' => ['SELECT * FROM @stage, my_table', ['pgsql', 'sqlite']];
        yield 'parse_string_literal_alias #1' => ["SELECT a FROM t AS 'y'", ['mysql', 'pgsql']];
        yield 'parse_string_literal_alias #3' => ["SELECT a FROM t 'y'", ['mysql', 'pgsql']];
        yield 'parse_string_literal_alias_per_engine #1' => ["SELECT a FROM t AS 'y'", ['mysql', 'pgsql']];
        yield 'parse_string_literal_alias_per_engine #3' => ["SELECT a FROM t 'y'", ['mysql', 'pgsql']];
        yield 'parse_table_factor_paren_chain_no_exponential_blowup #1' => ['SELECT 1 '.str_repeat('FROM '.str_repeat('(', 30), 5), self::ALL];
        yield 'parse_invalid_limit_by #1' => ['SELECT * FROM user BY name', self::ALL_AND_GENERIC];
        yield 'test_eof_after_as #1' => ['SELECT 1 FROM foo AS', self::ALL_AND_GENERIC];
        yield 'lateral_derived #4' => ['SELECT * FROM LATERAL UNNEST ([10,20,30]) as numbers WITH OFFSET;', self::ALL_AND_GENERIC];
        yield 'parse_pivot_table #8' => ['SELECT * FROM t PIVOT(SUM(a) AS 1 FOR b IN (1))', self::ALL_AND_GENERIC];
        yield 'test_alias_equal_expr #4' => ['SELECT a = FROM', ['sqlsrv']];
        yield 'test_no_semicolon_required_between_statements #1' => ["\nSELECT * FROM tbl1\nSELECT * FROM tbl2\n    ", self::ALL_AND_GENERIC];
        yield 'parse_multiple_statements #7' => ['SELECT foo FROM baz SELECT bar', self::ALL_AND_GENERIC];
        yield 'parse_multiple_statements #8' => ['DELETE FROM foo SELECT bar', self::ALL_AND_GENERIC];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function mysqlWrites(): iterable
    {
        yield 'parse_simple_insert #1' => ["INSERT INTO tasks (title, priority) VALUES ('Test Some Inserts', 1), ('Test Entry 2', 2), ('Test Entry 3', 3)", ['mysql'], 'INSERT tasks'];
        yield 'parse_ignore_insert #1' => ["INSERT IGNORE INTO tasks (title, priority) VALUES ('Test Some Inserts', 1)", ['mysql'], 'INSERT tasks'];
        yield 'parse_priority_insert #1' => ["INSERT HIGH_PRIORITY INTO tasks (title, priority) VALUES ('Test Some Inserts', 1)", ['mysql'], 'INSERT tasks'];
        yield 'parse_priority_insert #2' => ["INSERT LOW_PRIORITY INTO tasks (title, priority) VALUES ('Test Some Inserts', 1)", ['mysql'], 'INSERT tasks'];
        yield 'parse_insert_as #1' => ["INSERT INTO `table` (`date`) VALUES ('2024-01-01') AS `alias`", ['mysql'], 'INSERT table'];
        yield 'parse_insert_as #2' => ["INSERT INTO `table` (`date`) VALUES ('2024-01-01') AS `alias` ()", ['mysql'], 'INSERT table'];
        yield 'parse_insert_as #3' => ["INSERT INTO `table` (`id`, `date`) VALUES (1, '2024-01-01') AS `alias` (`mek_id`, `mek_date`)", ['mysql'], 'INSERT table'];
        yield 'parse_replace_insert #1' => ["REPLACE DELAYED INTO tasks (title, priority) VALUES ('Test Some Inserts', 1)", ['mysql'], 'INSERT tasks REPLACING ROWS'];
        yield 'parse_empty_row_insert #1' => ['INSERT INTO tb () VALUES (), ()', ['mysql'], 'INSERT tb'];
        yield 'parse_empty_row_insert #2' => ['INSERT INTO tb VALUES (), ()', ['mysql'], 'INSERT tb'];
        yield 'parse_insert_with_on_duplicate_update #1' => ["INSERT INTO permission_groups (name, description, perm_create, perm_read, perm_update, perm_delete) VALUES ('accounting_manager', 'Some description about the group', true, true, true, true) ON DUPLICATE KEY UPDATE description = VALUES(description), perm_create = VALUES(perm_create), perm_read = VALUES(perm_read), perm_update = VALUES(perm_update), perm_delete = VALUES(perm_delete)", ['mysql'], 'INSERT permission_groups SET description, perm_create, perm_read, perm_update, perm_delete'];
        yield 'parse_insert_with_numeric_prefix_column_name #1' => ['INSERT INTO s1.t1 (123col_$@length123) VALUES (67.654)', ['mysql'], 'INSERT s1.t1'];
        yield 'parse_update_with_joins #1' => ["UPDATE orders AS o JOIN customers AS c ON o.customer_id = c.id SET o.completed = true WHERE c.firstname = 'Peter'", ['mysql'], 'UPDATE orders SET completed'];
        yield 'parse_update_with_order_by #1' => ['UPDATE foo SET bar = false WHERE foo = true ORDER BY foo ASC', ['mysql'], 'UPDATE foo SET bar'];
        yield 'parse_update_with_order_by_and_limit #1' => ['UPDATE foo SET bar = false WHERE foo = true ORDER BY foo ASC LIMIT 10', ['mysql'], 'UPDATE foo SET bar'];
        yield 'parse_delete_with_order_by #1' => ['DELETE FROM customers ORDER BY id DESC', ['mysql'], 'DELETE customers'];
        yield 'parse_delete_with_limit #1' => ['DELETE FROM customers LIMIT 100', ['mysql'], 'DELETE customers'];
        yield 'parse_looks_like_single_line_comment #1' => ['UPDATE account SET balance=balance--1 WHERE account_id=5752', ['mysql'], 'UPDATE account SET balance'];
        yield 'parse_looks_like_single_line_comment #2' => ['UPDATE account SET balance = balance - -1 WHERE account_id = 5752', ['mysql'], 'UPDATE account SET balance'];
        yield 'parse_looks_like_single_line_comment #3' => ['UPDATE account SET balance=balance-- 1 WHERE account_id=5752', ['mysql'], 'UPDATE account SET balance'];
        yield 'parse_looks_like_single_line_comment #4' => ['UPDATE account SET balance = balance WHERE account_id = 5752', ['mysql'], 'UPDATE account SET balance'];
        yield 'test_variable_assignment_using_colon_equal #1' => ["UPDATE products SET price = @new_price := price * 1.1 WHERE category = 'Books'", ['mysql'], 'UPDATE products SET price'];
        yield 'test_optimizer_hints #1' => ['INSERT /*+ RESOURCE_GROUP(Batch) */ INTO t2 VALUES (2)', ['mysql'], 'INSERT t2'];
        yield 'test_optimizer_hints #2' => ["REPLACE /*+ foobar */ INTO test VALUES (1, 'Old', '2014-08-20 18:47:00')", ['mysql'], 'INSERT test REPLACING ROWS'];
        yield 'test_optimizer_hints #3' => ['UPDATE /*+ quux */ table_name SET column1 = 1 WHERE 1 = 1', ['mysql'], 'UPDATE table_name SET column1'];
        yield 'test_optimizer_hints #4' => ['DELETE /*+ foobar */ FROM table_name', ['mysql'], 'DELETE table_name'];
        yield 'test_optimizer_hints #5' => ['INSERT /*abc+ append */ INTO t2 VALUES (2)', ['mysql'], 'INSERT t2'];
        yield 'test_optimizer_hints #6' => ['UPDATE /*abc+ PARALLEL */ table_name SET column1 = 1', ['mysql'], 'UPDATE table_name SET column1'];
        yield 'test_optimizer_hints #7' => ['DELETE /*abc+ ENABLE_DML */ FROM table_name', ['mysql'], 'DELETE table_name'];
        yield 'parse_table_partition_selection #1' => ['UPDATE employees PARTITION (p0) SET salary = 1', ['mysql'], 'UPDATE employees SET salary'];
        yield 'parse_table_partition_selection #2' => ['DELETE FROM employees PARTITION (p0) WHERE id = 1', ['mysql'], 'DELETE employees'];
        yield 'parse_looks_like_single_line_comment #6' => ["\n            UPDATE account SET balance=balance-- 1\n            WHERE account_id=5752\n        ", ['mysql'], 'UPDATE account SET balance'];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function mysqlReads(): iterable
    {
        yield 'parse_json_table #1' => ['SELECT * FROM JSON_TABLE(\'[[1, 2], [3, 4]]\', \'$[*]\' COLUMNS(a INT PATH \'$[0]\', b INT PATH \'$[1]\')) AS t', ['mysql'], []];
        yield 'parse_json_table #2' => ['SELECT * FROM JSON_TABLE(\'["x", "y"]\', \'$[*]\' COLUMNS(a VARCHAR(20) PATH \'$\')) AS t', ['mysql'], []];
        yield 'parse_json_table #3' => ['SELECT * FROM JSON_TABLE(?, \'$[*]\' COLUMNS(a VARCHAR(20) PATH \'$\')) AS t', ['mysql'], []];
        yield 'parse_json_table #4' => ['SELECT * FROM JSON_TABLE(\'{"\'\'": [1,2,3]}\', \'$."\'\'"[*]\' COLUMNS(a VARCHAR(20) PATH \'$\')) AS t', ['mysql'], []];
        yield 'parse_json_table #5' => ['SELECT * FROM JSON_TABLE("[]", "$[*]" COLUMNS(a VARCHAR(20) PATH "$")) AS t', ['mysql'], []];
        yield 'parse_json_table #6' => ['SELECT * FROM JSON_TABLE(\'[{}, {"x":1}]\', \'$[*]\' COLUMNS(x INT EXISTS PATH \'$.x\')) AS t', ['mysql'], []];
        yield 'parse_json_table #7' => ['SELECT * FROM JSON_TABLE(\'[1,2]\', \'$[*]\' COLUMNS(x INT PATH \'$\' ERROR ON ERROR)) AS t', ['mysql'], []];
        yield 'parse_json_table #8' => ['SELECT * FROM JSON_TABLE(\'[1,2]\', \'$[*]\' COLUMNS(x INT PATH \'$\' ERROR ON EMPTY)) AS t', ['mysql'], []];
        yield 'parse_json_table #9' => ['SELECT * FROM JSON_TABLE(\'[1,2]\', \'$[*]\' COLUMNS(x INT PATH \'$\' ERROR ON EMPTY DEFAULT \'0\' ON ERROR)) AS t', ['mysql'], []];
        yield 'parse_json_table #10' => ['SELECT jt.* FROM JSON_TABLE(\'["Alice", "Bob", "Charlie"]\', \'$[*]\' COLUMNS(row_num FOR ORDINALITY, name VARCHAR(50) PATH \'$\')) AS jt', ['mysql'], []];
        yield 'parse_json_table #11' => ['SELECT * FROM JSON_TABLE(\'[ {"a": 1, "b": [11,111]}, {"a": 2, "b": [22,222]}, {"a":3}]\', \'$[*]\' COLUMNS(a INT PATH \'$.a\', NESTED PATH \'$.b[*]\' COLUMNS (b INT PATH \'$\'))) AS jt', ['mysql'], []];
        yield 'parse_json_table #12' => ['SELECT * FROM JSON_TABLE(\'[1,2]\', \'$[*]\' COLUMNS(x INT PATH \'$\' DEFAULT \'0\' ON EMPTY NULL ON ERROR)) AS t', ['mysql'], []];
        yield 'parse_straight_join #1' => ['SELECT a.*, b.* FROM table_a AS a STRAIGHT_JOIN table_b AS b ON a.b_id = b.id', ['mysql'], ['table_a', 'table_b']];
        yield 'parse_straight_join #2' => ['SELECT a.*, b.* FROM table_a STRAIGHT_JOIN table_b AS b ON a.b_id = b.id', ['mysql'], ['table_a', 'table_b']];
        yield 'parse_select_straight_join #1' => ['SELECT STRAIGHT_JOIN * FROM employees e JOIN dept_emp d ON e.emp_no = d.emp_no WHERE d.emp_no = 10001', ['mysql'], ['employees', 'dept_emp']];
        yield 'parse_select_straight_join #2' => ['SELECT STRAIGHT_JOIN e.emp_no, d.dept_no FROM employees e JOIN dept_emp d ON e.emp_no = d.emp_no', ['mysql'], ['employees', 'dept_emp']];
        yield 'parse_select_straight_join #3' => ['SELECT DISTINCT STRAIGHT_JOIN emp_no FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_straight_join #4' => ['SELECT * FROM employees', ['mysql'], ['employees']];
        yield 'test_optimizer_hints #8' => ['SELECT /*+ SET_VAR(target_partitions=1) */ * FROM (SELECT /*+ SET_VAR(target_partitions=8) */ * FROM t1 LIMIT 1) AS dt', ['mysql'], ['t1']];
        yield 'parse_table_partition_selection #3' => ['SELECT * FROM employees PARTITION (p0, p2)', ['mysql'], ['employees']];
        yield 'parse_table_partition_selection #4' => ['SELECT * FROM employees PARTITION (p0) AS e', ['mysql'], ['employees']];
        yield 'parse_table_partition_selection #5' => ['SELECT * FROM employees PARTITION (p0) JOIN departments PARTITION (p1) ON employees.dept_id = departments.id', ['mysql'], ['employees', 'departments']];
        yield 'parse_simple_insert #2' => ['INSERT INTO tasks (title, priority) VALUES (\'Test Some Inserts\', 1), (\'Test Entry 2\', 2), (\'Test Entry 3\', 3)', ['mysql'], []];
        yield 'parse_ignore_insert #2' => ['INSERT IGNORE INTO tasks (title, priority) VALUES (\'Test Some Inserts\', 1)', ['generic', 'mysql'], []];
        yield 'parse_priority_insert #3' => ['INSERT HIGH_PRIORITY INTO tasks (title, priority) VALUES (\'Test Some Inserts\', 1)', ['generic', 'mysql'], []];
        yield 'parse_priority_insert #4' => ['INSERT LOW_PRIORITY INTO tasks (title, priority) VALUES (\'Test Some Inserts\', 1)', ['mysql'], []];
        yield 'parse_insert_as #4' => ['INSERT INTO `table` (`date`) VALUES (\'2024-01-01\') AS `alias`', ['generic', 'mysql'], []];
        yield 'parse_insert_as #5' => ['INSERT INTO `table` (`id`, `date`) VALUES (1, \'2024-01-01\') AS `alias` (`mek_id`, `mek_date`)', ['generic', 'mysql'], []];
        yield 'parse_replace_insert #2' => ['REPLACE DELAYED INTO tasks (title, priority) VALUES (\'Test Some Inserts\', 1)', ['mysql'], []];
        yield 'parse_empty_row_insert #3' => ['INSERT INTO tb () VALUES (), ()', ['mysql'], []];
        yield 'parse_insert_with_on_duplicate_update #2' => ['INSERT INTO permission_groups (name, description, perm_create, perm_read, perm_update, perm_delete) VALUES (\'accounting_manager\', \'Some description about the group\', true, true, true, true) ON DUPLICATE KEY UPDATE description = VALUES(description), perm_create = VALUES(perm_create), perm_read = VALUES(perm_read), perm_update = VALUES(perm_update), perm_delete = VALUES(perm_delete)', ['mysql'], []];
        yield 'parse_select_with_numeric_prefix_column_name #1' => ['SELECT 123col_$@123abc FROM "table"', ['mysql'], ['table']];
        yield 'parse_qualified_identifiers_with_numeric_prefix #1' => ['SELECT t.15to29 FROM my_table AS t', ['mysql'], ['my_table']];
        yield 'parse_qualified_identifiers_with_numeric_prefix #2' => ['SELECT t.15e29 FROM my_table AS t', ['mysql'], ['my_table']];
        yield 'parse_qualified_identifiers_with_numeric_prefix #3' => ['SELECT 15e29 FROM my_table', ['mysql'], ['my_table']];
        yield 'parse_qualified_identifiers_with_numeric_prefix #4' => ['SELECT `15e29` FROM my_table', ['mysql'], ['my_table']];
        yield 'parse_qualified_identifiers_with_numeric_prefix #5' => ['SELECT t.`15e29` FROM my_table AS t', ['mysql'], ['my_table']];
        yield 'parse_select_with_concatenation_of_exp_number_and_numeric_prefix_column #1' => ['SELECT 123e4, 123col_$@123abc FROM "table"', ['mysql'], ['table']];
        yield 'parse_insert_with_numeric_prefix_column_name #2' => ['INSERT INTO s1.t1 (123col_$@length123) VALUES (67.654)', ['mysql'], []];
        yield 'parse_update_with_joins #2' => ['UPDATE orders AS o JOIN customers AS c ON o.customer_id = c.id SET o.completed = true WHERE c.firstname = \'Peter\'', ['mysql'], ['customers']];
        yield 'parse_update_with_order_by #2' => ['UPDATE foo SET bar = false WHERE foo = true ORDER BY foo ASC', ['generic', 'mysql'], []];
        yield 'parse_update_with_order_by_and_limit #2' => ['UPDATE foo SET bar = false WHERE foo = true ORDER BY foo ASC LIMIT 10', ['generic', 'mysql'], []];
        yield 'parse_delete_with_order_by #2' => ['DELETE FROM customers ORDER BY id DESC', ['mysql'], ['customers']];
        yield 'parse_delete_with_limit #2' => ['DELETE FROM customers LIMIT 100', ['mysql'], ['customers']];
        yield 'parse_substring_in_select #1' => ['SELECT DISTINCT SUBSTRING(description, 0, 1) FROM test', ['mysql'], ['test']];
        yield 'parse_limit_my_sql_syntax #1' => ['SELECT id, fname, lname FROM customer LIMIT 10 OFFSET 5', ['generic', 'mysql'], ['customer']];
        yield 'parse_limit_my_sql_syntax #2' => ['SELECT id, fname, lname FROM customer LIMIT 5, 10', ['generic', 'mysql'], ['customer']];
        yield 'parse_limit_my_sql_syntax #3' => ['SELECT * FROM user LIMIT ? OFFSET ?', ['generic', 'mysql'], ['user']];
        yield 'parse_fulltext_expression #1' => ['SELECT * FROM tb WHERE MATCH (c1) AGAINST (\'string\')', ['generic', 'mysql'], ['tb']];
        yield 'parse_fulltext_expression #2' => ['SELECT * FROM tb WHERE MATCH (c1) AGAINST (\'string\' IN NATURAL LANGUAGE MODE)', ['generic', 'mysql'], ['tb']];
        yield 'parse_fulltext_expression #3' => ['SELECT * FROM tb WHERE MATCH (c1) AGAINST (\'string\' IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION)', ['generic', 'mysql'], ['tb']];
        yield 'parse_fulltext_expression #4' => ['SELECT * FROM tb WHERE MATCH (c1) AGAINST (\'string\' IN BOOLEAN MODE)', ['generic', 'mysql'], ['tb']];
        yield 'parse_fulltext_expression #5' => ['SELECT * FROM tb WHERE MATCH (c1) AGAINST (\'string\' WITH QUERY EXPANSION)', ['generic', 'mysql'], ['tb']];
        yield 'parse_fulltext_expression #6' => ['SELECT * FROM tb WHERE MATCH (c1, c2, c3) AGAINST (\'string\')', ['generic', 'mysql'], ['tb']];
        yield 'parse_fulltext_expression #7' => ['SELECT * FROM tb WHERE MATCH (c1) AGAINST (123)', ['generic', 'mysql'], ['tb']];
        yield 'parse_fulltext_expression #8' => ['SELECT * FROM tb WHERE MATCH (c1) AGAINST (NULL)', ['generic', 'mysql'], ['tb']];
        yield 'parse_fulltext_expression #9' => ['SELECT COUNT(IF(MATCH (title, body) AGAINST (\'database\' IN NATURAL LANGUAGE MODE), 1, NULL)) AS count FROM articles', ['generic', 'mysql'], ['articles']];
        yield 'parse_values #5' => ['SELECT a, c FROM (VALUES ROW(1, true, \'a\'), ROW(2, false, \'b\'), ROW(3, false, \'c\')) AS t (a, b, c)', ['mysql'], []];
        yield 'parse_convert_using #1' => ['SELECT CONVERT(\'x\' USING latin1)', ['mysql'], []];
        yield 'parse_convert_using #2' => ['SELECT CONVERT(my_column USING utf8mb4) FROM my_table', ['mysql'], ['my_table']];
        yield 'parse_looks_like_single_line_comment #5' => ['UPDATE account SET balance=balance--1 WHERE account_id=5752', ['mysql'], []];
        yield 'parse_looks_like_single_line_comment #7' => ["\n            UPDATE account SET balance=balance-- 1\n            WHERE account_id=5752\n        ", ['mysql'], []];
        yield 'parse_match_against_with_alias #1' => ['SELECT tbl.ProjectID FROM surveys.tbl1 AS tbl WHERE MATCH (tbl.ReferenceID) AGAINST (\'AAA\' IN BOOLEAN MODE)', ['mysql'], ['surveys.tbl1']];
        yield 'test_variable_assignment_using_colon_equal #2' => ['SELECT @price := price, @tax := price * 0.1 FROM products WHERE id = 1', ['mysql'], ['products']];
        yield 'test_variable_assignment_using_colon_equal #3' => ['UPDATE products SET price = @new_price := price * 1.1 WHERE category = \'Books\'', ['mysql'], []];
        yield 'parse_distinctrow_to_distinct #1' => ['SELECT DISTINCTROW * FROM employees', ['mysql'], ['employees']];
        yield 'parse_distinctrow_to_distinct #2' => ['SELECT HIGH_PRIORITY DISTINCTROW * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers #1' => ['SELECT HIGH_PRIORITY * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers #2' => ['SELECT SQL_SMALL_RESULT * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers #3' => ['SELECT SQL_BIG_RESULT * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers #4' => ['SELECT SQL_BUFFER_RESULT * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers #5' => ['SELECT SQL_NO_CACHE * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers #6' => ['SELECT SQL_CALC_FOUND_ROWS * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers #7' => ['SELECT HIGH_PRIORITY STRAIGHT_JOIN SQL_SMALL_RESULT SQL_BIG_RESULT SQL_BUFFER_RESULT SQL_NO_CACHE SQL_CALC_FOUND_ROWS * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers #8' => ['SELECT DISTINCT HIGH_PRIORITY emp_no FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers #9' => ['SELECT DISTINCT SQL_CALC_FOUND_ROWS emp_no FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers #10' => ['SELECT HIGH_PRIORITY STRAIGHT_JOIN e.emp_no, d.dept_no FROM employees e JOIN dept_emp d ON e.emp_no = d.emp_no', ['mysql'], ['employees', 'dept_emp']];
        yield 'parse_select_modifiers_any_order #1' => ['SELECT HIGH_PRIORITY DISTINCT * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_any_order #2' => ['SELECT SQL_CALC_FOUND_ROWS DISTINCT HIGH_PRIORITY * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_any_order #3' => ['SELECT HIGH_PRIORITY DISTINCT SQL_SMALL_RESULT * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_any_order #4' => ['SELECT ALL * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_any_order #5' => ['SELECT ALL HIGH_PRIORITY * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_any_order #6' => ['SELECT HIGH_PRIORITY ALL * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_any_order #7' => ['SELECT SQL_CALC_FOUND_ROWS ALL HIGH_PRIORITY * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_can_be_repeated #1' => ['SELECT HIGH_PRIORITY HIGH_PRIORITY * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_can_be_repeated #2' => ['SELECT SQL_CALC_FOUND_ROWS SQL_CALC_FOUND_ROWS * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_can_be_repeated #3' => ['SELECT STRAIGHT_JOIN STRAIGHT_JOIN * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_can_be_repeated #4' => ['SELECT SQL_NO_CACHE SQL_NO_CACHE * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_can_be_repeated #5' => ['SELECT HIGH_PRIORITY DISTINCT HIGH_PRIORITY * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_can_be_repeated #6' => ['SELECT SQL_CALC_FOUND_ROWS DISTINCT SQL_CALC_FOUND_ROWS * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_canonical_ordering #1' => ['SELECT SQL_CALC_FOUND_ROWS SQL_NO_CACHE SQL_BUFFER_RESULT SQL_BIG_RESULT SQL_SMALL_RESULT STRAIGHT_JOIN HIGH_PRIORITY * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_canonical_ordering #2' => ['SELECT SQL_NO_CACHE DISTINCT SQL_CALC_FOUND_ROWS * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_canonical_ordering #3' => ['SELECT HIGH_PRIORITY STRAIGHT_JOIN DISTINCT SQL_SMALL_RESULT * FROM employees', ['mysql'], ['employees']];
        yield 'parse_select_modifiers_canonical_ordering #4' => ['SELECT HIGH_PRIORITY ALL STRAIGHT_JOIN * FROM employees', ['mysql'], ['employees']];
        yield 'test_optimizer_hints #9' => ['INSERT /*+ RESOURCE_GROUP(Batch) */ INTO t2 VALUES (2)', ['generic', 'mysql'], []];
        yield 'test_optimizer_hints #10' => ['REPLACE /*+ foobar */ INTO test VALUES (1, \'Old\', \'2014-08-20 18:47:00\')', ['generic', 'mysql'], []];
        yield 'test_optimizer_hints #11' => ['UPDATE /*+ quux */ table_name SET column1 = 1 WHERE 1 = 1', ['generic', 'mysql'], []];
        yield 'test_optimizer_hints #12' => ['DELETE /*+ foobar */ FROM table_name', ['generic', 'mysql'], ['table_name']];
        yield 'test_optimizer_hints #13' => ['INSERT /*abc+ append */ INTO t2 VALUES (2)', ['generic', 'mysql'], []];
        yield 'test_optimizer_hints #14' => ['UPDATE /*abc+ PARALLEL */ table_name SET column1 = 1', ['generic', 'mysql'], []];
        yield 'test_optimizer_hints #15' => ['DELETE /*abc+ ENABLE_DML */ FROM table_name', ['generic', 'mysql'], ['table_name']];
        yield 'parse_group_by_with_rollup #1' => ['SELECT * FROM tbl GROUP BY col1, col2 WITH ROLLUP', ['mysql'], ['tbl']];
        yield 'parse_table_partition_selection #7' => ['UPDATE employees PARTITION (p0) SET salary = 1', ['generic', 'mysql'], []];
        yield 'parse_table_partition_selection #8' => ['DELETE FROM employees PARTITION (p0) WHERE id = 1', ['generic', 'mysql'], ['employees']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function mysqlUnsupportedReads(): iterable
    {
        yield 'parse_table_partition_selection #6' => ['SELECT * FROM employees PARTITION', ['mysql']];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function postgresWrites(): iterable
    {
        yield 'parse_truncate_table #1' => ['TRUNCATE TABLE "users", "orders" RESTART IDENTITY RESTRICT', ['pgsql'], 'TRUNCATE users, orders'];
        yield 'parse_truncate_table #2' => ['TRUNCATE users, orders RESTART IDENTITY', ['pgsql'], 'TRUNCATE users, orders'];
        yield 'parse_pg_on_conflict_target_predicate #1' => ['INSERT INTO t (a, b) VALUES (1, 2) ON CONFLICT(a) WHERE b > 0 DO NOTHING', ['pgsql'], 'INSERT t'];
        yield 'parse_pg_on_conflict_target_predicate #2' => ['INSERT INTO t (a, b) VALUES (1, 2) ON CONFLICT(a) WHERE b > 0 DO UPDATE SET b = EXCLUDED.b', ['pgsql'], 'INSERT t SET b'];
        yield 'parse_pg_on_conflict_target_predicate #3' => ['INSERT INTO t (a, b) VALUES (1, 2) ON CONFLICT(a, c) WHERE b > 0 DO UPDATE SET b = EXCLUDED.b WHERE t.b < 10', ['pgsql'], 'INSERT t SET b'];
        yield 'parse_pg_on_conflict #1' => ["INSERT INTO distributors (did, dname) VALUES (5, 'Gizmo Transglobal'), (6, 'Associated Computing, Inc') ON CONFLICT(did) DO UPDATE SET dname = EXCLUDED.dname", ['pgsql'], 'INSERT distributors SET dname'];
        yield 'parse_pg_on_conflict #2' => ["INSERT INTO distributors (did, dname, area) VALUES (5, 'Gizmo Transglobal', 'Mars'), (6, 'Associated Computing, Inc', 'Venus') ON CONFLICT(did, area) DO UPDATE SET dname = EXCLUDED.dname, area = EXCLUDED.area", ['pgsql'], 'INSERT distributors SET dname, area'];
        yield 'parse_pg_on_conflict #3' => ["INSERT INTO distributors (did, dname) VALUES (5, 'Gizmo Transglobal'), (6, 'Associated Computing, Inc') ON CONFLICT DO NOTHING", ['pgsql'], 'INSERT distributors'];
        yield 'parse_pg_on_conflict #4' => ['INSERT INTO distributors (did, dname, dsize) VALUES (5, \'Gizmo Transglobal\', 1000), (6, \'Associated Computing, Inc\', 1010) ON CONFLICT(did) DO UPDATE SET dname = $1 WHERE dsize > $2', ['pgsql'], 'INSERT distributors SET dname'];
        yield 'parse_pg_on_conflict #5' => ['INSERT INTO distributors (did, dname, dsize) VALUES (5, \'Gizmo Transglobal\', 1000), (6, \'Associated Computing, Inc\', 1010) ON CONFLICT ON CONSTRAINT distributors_did_pkey DO UPDATE SET dname = $1 WHERE dsize > $2', ['pgsql'], 'INSERT distributors SET dname'];
        yield 'parse_pg_returning #1' => ["INSERT INTO distributors (did, dname) VALUES (DEFAULT, 'XYZ Widgets') RETURNING did", ['pgsql'], 'INSERT distributors'];
        yield 'parse_pg_returning #2' => ["UPDATE weather SET temp_lo = temp_lo + 1, temp_hi = temp_lo + 15, prcp = DEFAULT WHERE city = 'San Francisco' AND date = '2003-07-03' RETURNING temp_lo AS lo, temp_hi AS hi, prcp", ['pgsql'], 'UPDATE weather SET temp_lo, temp_hi, prcp'];
        yield 'parse_pg_returning #3' => ["DELETE FROM tasks WHERE status = 'DONE' RETURNING *", ['pgsql'], 'DELETE tasks'];
        yield 'parse_delimited_identifiers #2' => ['UPDATE foo SET "bar" = 5', ['pgsql'], 'UPDATE foo SET bar'];
        yield 'parse_update_has_keyword #1' => ['UPDATE test SET name=$1, value=$2, where=$3, create=$4, is_default=$5, classification=$6, sort=$7 WHERE id=$8', ['pgsql'], 'UPDATE test SET name, value, where, create, is_default, classification, sort'];
        yield 'parse_update_has_keyword #2' => ['UPDATE test SET name = $1, value = $2, where = $3, create = $4, is_default = $5, classification = $6, sort = $7 WHERE id = $8', ['pgsql'], 'UPDATE test SET name, value, where, create, is_default, classification, sort'];
        yield 'parse_truncate #1' => ['TRUNCATE db.table_name', ['pgsql'], 'TRUNCATE db.table_name'];
        yield 'parse_truncate_with_options #1' => ['TRUNCATE TABLE ONLY db.table_name RESTART IDENTITY CASCADE', ['pgsql'], 'TRUNCATE db.table_name'];
        yield 'parse_truncate_with_table_list #1' => ['TRUNCATE TABLE db.table_name, db.other_table_name RESTART IDENTITY CASCADE', ['pgsql'], 'TRUNCATE db.table_name, db.other_table_name'];
        yield 'parse_truncate_with_descendant #1' => ['TRUNCATE TABLE t *', ['pgsql'], 'TRUNCATE t'];
        yield 'parse_truncate_with_descendant #2' => ['TRUNCATE TABLE ONLY parent, child *, grandchild RESTART IDENTITY', ['pgsql'], 'TRUNCATE parent, child, grandchild'];
        yield 'test_simple_postgres_insert_with_alias #1' => ['INSERT INTO test_tables AS test_table (id, a) VALUES (DEFAULT, 123)', ['pgsql'], 'INSERT test_tables'];
        yield 'test_simple_insert_with_quoted_alias #1' => ['INSERT INTO test_tables AS "Test_Table" (id, a) VALUES (DEFAULT, \'0123\')', ['pgsql'], 'INSERT test_tables'];
        yield 'parse_merge_do_nothing #1' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED THEN DO NOTHING WHEN NOT MATCHED THEN DO NOTHING', ['pgsql'], 'MERGE target'];
        yield 'parse_insert_overriding #1' => ["INSERT INTO t (id, a) OVERRIDING SYSTEM VALUE VALUES (1, 'x')", ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #2' => ["INSERT INTO t (id, a) OVERRIDING USER VALUE VALUES (1, 'x')", ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #3' => ['INSERT INTO t (id, a) OVERRIDING SYSTEM VALUE SELECT id, a FROM u', ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #4' => ['INSERT INTO t (id) OVERRIDING USER VALUE VALUES (1) ON CONFLICT DO NOTHING RETURNING id', ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #5' => ["INSERT INTO t OVERRIDING SYSTEM VALUE VALUES (1, 'x')", ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #6' => ['INSERT INTO t OVERRIDING USER VALUE SELECT * FROM u', ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #7' => ['INSERT INTO t AS x (id) OVERRIDING SYSTEM VALUE VALUES (1)', ['pgsql'], 'INSERT t'];
        yield 'parse_insert_overriding #8' => ['INSERT INTO t (id) VALUES (1)', ['pgsql'], 'INSERT t'];
        yield 'parse_insert_by_name_keywords_as_table_and_alias #1' => ['INSERT INTO BY NAME SELECT 1 AS a', ['pgsql'], 'INSERT BY'];
        yield 'parse_table_inheritance_markers #1' => ['UPDATE ONLY t SET id = 1', ['pgsql'], 'UPDATE t SET id'];
        yield 'parse_table_inheritance_markers #2' => ['UPDATE ONLY (t) SET id = 1', ['pgsql'], 'UPDATE t SET id'];
        yield 'parse_table_inheritance_markers #3' => ['UPDATE t * SET id = 1', ['pgsql'], 'UPDATE t SET id'];
        yield 'parse_table_inheritance_markers #4' => ['DELETE FROM ONLY t', ['pgsql'], 'DELETE t'];
        yield 'parse_table_inheritance_markers #5' => ['DELETE FROM ONLY (t)', ['pgsql'], 'DELETE t'];
        yield 'parse_table_inheritance_markers #6' => ['DELETE FROM t *', ['pgsql'], 'DELETE t'];
        yield 'parse_table_inheritance_markers #7' => ['UPDATE t* SET id = 1', ['pgsql'], 'UPDATE t SET id'];
        yield 'parse_table_inheritance_markers #8' => ['DELETE FROM t*', ['pgsql'], 'DELETE t'];
        yield 'parse_update_has_keyword #3' => ["UPDATE test SET name=\$1,\n                value=\$2,\n                where=\$3,\n                create=\$4,\n                is_default=\$5,\n                classification=\$6,\n                sort=\$7\n                WHERE id=\$8", ['pgsql'], 'UPDATE test SET name, value, where, create, is_default, classification, sort'];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function postgresUnsupportedWrites(): iterable
    {
        yield 'parse_update_in_with_subquery #1' => ['WITH "result" AS (UPDATE "Hero" SET "name" = \'Captain America\', "number_of_movies" = "number_of_movies" + 1 WHERE "secret_identity" = \'Sam Wilson\' RETURNING "id", "name", "secret_identity", "number_of_movies") SELECT * FROM "result"', ['pgsql']];
        yield 'test_complex_postgres_insert_with_alias #1' => ['WITH existing AS (SELECT test_table.id FROM test_tables AS test_table WHERE (a = 12) AND (b = 34)), inserted AS (INSERT INTO test_tables AS test_table (id, a, b, c) VALUES (DEFAULT, 56, 78, 90) ON CONFLICT(a, b) DO UPDATE SET c = EXCLUDED.c WHERE (test_table.c <> EXCLUDED.c)) SELECT c FROM existing', ['pgsql']];
        yield 'parse_table_inheritance_markers #25' => ['UPDATE t * SET id = 1', ['mysql', 'sqlite', 'sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function postgresReads(): iterable
    {
        yield 'parse_json_table_is_not_reserved #1' => ['SELECT * FROM JSON_TABLE', ['pgsql'], ['JSON_TABLE']];
        yield 'parse_join_constraint_unnest_alias #1' => ['SELECT * FROM t1 JOIN UNNEST(t1.a) AS f ON c1 = c2', ['pgsql'], ['t1']];
        yield 'test_table_function_with_ordinality #1' => ['SELECT * FROM generate_series(1, 10) WITH ORDINALITY AS t', ['pgsql'], []];
        yield 'test_lateral_function_with_ordinality_and_column_aliases #1' => ['SELECT * FROM tbl, LATERAL json_array_elements(c1::JSON) WITH ORDINALITY AS t (c1, index)', ['pgsql'], ['tbl']];
        yield 'test_table_unnest_with_ordinality #1' => ['SELECT * FROM UNNEST([10, 20, 30]) WITH ORDINALITY AS t', ['pgsql'], []];
        yield 'parse_right_deep_join_chain #1' => ['SELECT * FROM t0 INNER JOIN t1 INNER JOIN t2 ON true ON true', ['pgsql'], ['t0', 't1', 't2']];
        yield 'parse_right_deep_join_chain #2' => ['SELECT * FROM t0 INNER JOIN (t1 INNER JOIN t2 ON true) ON true', ['pgsql'], ['t0', 't1', 't2']];
        yield 'parse_right_deep_join_chain #3' => ['SELECT * FROM t0 INNER JOIN t1 INNER JOIN t2 INNER JOIN t3 ON true ON true ON true', ['pgsql'], ['t0', 't1', 't2', 't3']];
        yield 'parse_right_deep_join_chain #4' => ['SELECT * FROM t0 INNER JOIN (t1 INNER JOIN (t2 INNER JOIN t3 ON true) ON true) ON true', ['pgsql'], ['t0', 't1', 't2', 't3']];
        yield 'parse_right_deep_join_chain #5' => ['SELECT * FROM t0 NATURAL JOIN t1 INNER JOIN t2 ON true', ['pgsql'], ['t0', 't1', 't2']];
        yield 'parse_non_reserved_keywords_as_table_alias #1' => ['SELECT * FROM tbl_name AS cluster JOIN tbl_name_2 ON cluster.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #2' => ['SELECT * FROM tbl_name AS distribute JOIN tbl_name_2 ON distribute.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #3' => ['SELECT * FROM tbl_name AS explain JOIN tbl_name_2 ON explain.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #4' => ['SELECT * FROM tbl_name AS minus JOIN tbl_name_2 ON minus.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #5' => ['SELECT * FROM tbl_name AS sample JOIN tbl_name_2 ON sample.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #6' => ['SELECT * FROM tbl_name AS sort JOIN tbl_name_2 ON sort.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #7' => ['SELECT * FROM tbl_name AS start JOIN tbl_name_2 ON start.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #8' => ['SELECT * FROM tbl_name AS top JOIN tbl_name_2 ON top.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #9' => ['SELECT * FROM tbl_name AS view JOIN tbl_name_2 ON view.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #10' => ['SELECT * FROM tbl_name cluster JOIN tbl_name_2 ON cluster.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #11' => ['SELECT * FROM tbl_name distribute JOIN tbl_name_2 ON distribute.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #12' => ['SELECT * FROM tbl_name explain JOIN tbl_name_2 ON explain.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #13' => ['SELECT * FROM tbl_name minus JOIN tbl_name_2 ON minus.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #14' => ['SELECT * FROM tbl_name sample JOIN tbl_name_2 ON sample.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #15' => ['SELECT * FROM tbl_name sort JOIN tbl_name_2 ON sort.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #16' => ['SELECT * FROM tbl_name start JOIN tbl_name_2 ON start.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #17' => ['SELECT * FROM tbl_name top JOIN tbl_name_2 ON top.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_non_reserved_keywords_as_table_alias #18' => ['SELECT * FROM tbl_name view JOIN tbl_name_2 ON view.id = tbl_name_2.id', ['pgsql'], ['tbl_name', 'tbl_name_2']];
        yield 'parse_join_using_alias #1' => ['SELECT * FROM t1 JOIN t2 USING (id) AS joined_cols', ['pgsql'], ['t1', 't2']];
        yield 'parse_join_using_alias #2' => ['SELECT * FROM t1 JOIN t2 USING(id) AS joined_cols', ['pgsql'], ['t1', 't2']];
        yield 'parse_string_literal_alias_rejected #3' => ['SELECT a AS "x" FROM t AS "y"', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #9' => ['SELECT * FROM ONLY t', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #10' => ['SELECT * FROM ONLY t AS x', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #11' => ['SELECT * FROM ONLY (t)', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #12' => ['SELECT * FROM ONLY (app.t) x', ['pgsql'], ['app.t']];
        yield 'parse_table_inheritance_markers #13' => ['SELECT * FROM t *', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #14' => ['SELECT * FROM app.t * AS x', ['pgsql'], ['app.t']];
        yield 'parse_table_inheritance_markers #15' => ['SELECT * FROM "only"', ['pgsql'], ['only']];
        yield 'parse_table_inheritance_markers #16' => ['SELECT * FROM ONLY a JOIN b * ON a.id = b.id', ['pgsql'], ['a', 'b']];
        yield 'parse_table_inheritance_markers #17' => ['SELECT * FROM t*', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #18' => ['SELECT * FROM ONLY(t)', ['pgsql'], ['t']];
        yield 'display_table_inheritance_propagates_write_errors #1' => ['SELECT * FROM ONLY (t)', ['pgsql'], ['t']];
        yield 'display_table_inheritance_propagates_write_errors #2' => ['SELECT * FROM ONLY t', ['pgsql'], ['t']];
        yield 'display_table_inheritance_propagates_write_errors #3' => ['SELECT * FROM t *', ['pgsql'], ['t']];
        yield 'display_table_inheritance_propagates_write_errors #4' => ['SELECT * FROM t', ['pgsql'], ['t']];
        yield 'parse_limit_after_locking_clause #1' => ['SELECT * FROM t ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 5', ['pgsql'], ['t']];
        yield 'parse_limit_after_locking_clause #2' => ['SELECT * FROM t FOR UPDATE LIMIT 5', ['pgsql'], ['t']];
        yield 'parse_limit_after_locking_clause #3' => ['SELECT * FROM t ORDER BY id LIMIT 5 FOR UPDATE SKIP LOCKED', ['pgsql'], ['t']];
        yield 'parse_truncate_table #3' => ['TRUNCATE TABLE "users", "orders" RESTART IDENTITY RESTRICT', ['generic', 'pgsql'], []];
        yield 'parse_truncate_table #4' => ['TRUNCATE users, orders RESTART IDENTITY', ['generic', 'pgsql'], []];
        yield 'parse_prepare #1' => ['SELECT * FROM customers WHERE customers.id = a1', ['generic', 'pgsql'], ['customers']];
        yield 'parse_pg_on_conflict #6' => ['INSERT INTO distributors (did, dname) VALUES (5, \'Gizmo Transglobal\'), (6, \'Associated Computing, Inc\') ON CONFLICT(did) DO UPDATE SET dname = EXCLUDED.dname', ['generic', 'pgsql'], []];
        yield 'parse_pg_on_conflict #7' => ['INSERT INTO distributors (did, dname, area) VALUES (5, \'Gizmo Transglobal\', \'Mars\'), (6, \'Associated Computing, Inc\', \'Venus\') ON CONFLICT(did, area) DO UPDATE SET dname = EXCLUDED.dname, area = EXCLUDED.area', ['generic', 'pgsql'], []];
        yield 'parse_pg_on_conflict #8' => ['INSERT INTO distributors (did, dname) VALUES (5, \'Gizmo Transglobal\'), (6, \'Associated Computing, Inc\') ON CONFLICT DO NOTHING', ['generic', 'pgsql'], []];
        yield 'parse_pg_on_conflict #9' => ['INSERT INTO distributors (did, dname, dsize) VALUES (5, \'Gizmo Transglobal\', 1000), (6, \'Associated Computing, Inc\', 1010) ON CONFLICT(did) DO UPDATE SET dname = $1 WHERE dsize > $2', ['generic', 'pgsql'], []];
        yield 'parse_pg_on_conflict #10' => ['INSERT INTO distributors (did, dname, dsize) VALUES (5, \'Gizmo Transglobal\', 1000), (6, \'Associated Computing, Inc\', 1010) ON CONFLICT ON CONSTRAINT distributors_did_pkey DO UPDATE SET dname = $1 WHERE dsize > $2', ['generic', 'pgsql'], []];
        yield 'parse_pg_returning #4' => ['INSERT INTO distributors (did, dname) VALUES (DEFAULT, \'XYZ Widgets\') RETURNING did', ['generic', 'pgsql'], []];
        yield 'parse_pg_returning #5' => ['UPDATE weather SET temp_lo = temp_lo + 1, temp_hi = temp_lo + 15, prcp = DEFAULT WHERE city = \'San Francisco\' AND date = \'2003-07-03\' RETURNING temp_lo AS lo, temp_hi AS hi, prcp', ['generic', 'pgsql'], []];
        yield 'parse_pg_returning #6' => ['DELETE FROM tasks WHERE status = \'DONE\' RETURNING *', ['generic', 'pgsql'], ['tasks']];
        yield 'parse_array_index_expr #1' => ['SELECT foo[0] FROM foos', ['generic', 'pgsql'], ['foos']];
        yield 'parse_array_index_expr #2' => ['SELECT foo[0][0] FROM foos', ['generic', 'pgsql'], ['foos']];
        yield 'parse_array_index_expr #3' => ['SELECT bar[0]["baz"]["fooz"] FROM foos', ['generic', 'pgsql'], ['foos']];
        yield 'test_json #1' => ['SELECT params ->> \'name\' FROM events', ['pgsql'], ['events']];
        yield 'test_json #2' => ['SELECT params -> \'name\' FROM events', ['pgsql'], ['events']];
        yield 'test_json #3' => ['SELECT info -> \'items\' ->> \'product\' FROM orders', ['pgsql'], ['orders']];
        yield 'test_json #4' => ['SELECT info #> \'{a,b,c}\' FROM orders', ['pgsql'], ['orders']];
        yield 'test_json #5' => ['SELECT info #>> \'{a,b,c}\' FROM orders', ['pgsql'], ['orders']];
        yield 'test_json #6' => ['SELECT info FROM orders WHERE info @> \'{"a": 1}\'', ['pgsql'], ['orders']];
        yield 'test_json #7' => ['SELECT info FROM orders WHERE \'{"a": 1}\' <@ info', ['pgsql'], ['orders']];
        yield 'test_json #8' => ['SELECT info #- ARRAY[\'a\', \'b\'] FROM orders', ['pgsql'], ['orders']];
        yield 'test_json #9' => ['SELECT info FROM orders WHERE info @? \'$.a\'', ['pgsql'], ['orders']];
        yield 'test_json #10' => ['SELECT info FROM orders WHERE info @@ \'$.a\'', ['pgsql'], ['orders']];
        yield 'test_json #11' => ['SELECT info FROM orders WHERE info ? \'b\'', ['pgsql'], ['orders']];
        yield 'test_json #12' => ['SELECT info FROM orders WHERE info ?& ARRAY[\'b\', \'c\']', ['pgsql'], ['orders']];
        yield 'test_json #13' => ['SELECT info FROM orders WHERE info ?| ARRAY[\'b\', \'c\']', ['pgsql'], ['orders']];
        yield 'test_composite_value #1' => ['SELECT (on_hand.item).name FROM on_hand WHERE (on_hand.item).price > 9', ['pgsql'], ['on_hand']];
        yield 'parse_custom_operator #1' => ['SELECT * FROM events WHERE relname OPERATOR(database.pg_catalog.~) \'^(table)$\'', ['pgsql'], ['events']];
        yield 'parse_custom_operator #2' => ['SELECT * FROM events WHERE relname OPERATOR(pg_catalog.~) \'^(table)$\'', ['pgsql'], ['events']];
        yield 'parse_custom_operator #3' => ['SELECT * FROM events WHERE relname OPERATOR(~) \'^(table)$\'', ['pgsql'], ['events']];
        yield 'parse_delimited_identifiers #3' => ['UPDATE foo SET "bar" = 5', ['pgsql'], []];
        yield 'parse_update_has_keyword #4' => ["UPDATE test SET name=\$1,\n                value=\$2,\n                where=\$3,\n                create=\$4,\n                is_default=\$5,\n                classification=\$6,\n                sort=\$7\n                WHERE id=\$8", ['pgsql'], []];
        yield 'parse_update_in_with_subquery #2' => ['WITH "result" AS (UPDATE "Hero" SET "name" = \'Captain America\', "number_of_movies" = "number_of_movies" + 1 WHERE "secret_identity" = \'Sam Wilson\' RETURNING "id", "name", "secret_identity", "number_of_movies") SELECT * FROM "result"', ['generic', 'pgsql'], ['result']];
        yield 'parse_select_group_by_grouping_sets #1' => ['SELECT brand, size, sum(sales) FROM items_sold GROUP BY size, GROUPING SETS ((brand), (size), ())', ['generic', 'pgsql'], ['items_sold']];
        yield 'parse_select_group_by_rollup #1' => ['SELECT brand, size, sum(sales) FROM items_sold GROUP BY size, ROLLUP (brand, size)', ['generic', 'pgsql'], ['items_sold']];
        yield 'parse_select_group_by_cube #1' => ['SELECT brand, size, sum(sales) FROM items_sold GROUP BY size, CUBE (brand, size)', ['generic', 'pgsql'], ['items_sold']];
        yield 'parse_truncate #2' => ['TRUNCATE db.table_name', ['generic', 'pgsql'], []];
        yield 'parse_truncate_with_options #2' => ['TRUNCATE TABLE ONLY db.table_name RESTART IDENTITY CASCADE', ['generic', 'pgsql'], []];
        yield 'parse_truncate_with_table_list #2' => ['TRUNCATE TABLE db.table_name, db.other_table_name RESTART IDENTITY CASCADE', ['pgsql'], []];
        yield 'parse_truncate_with_descendant #3' => ['TRUNCATE TABLE t *', ['generic', 'pgsql'], []];
        yield 'parse_truncate_with_descendant #4' => ['TRUNCATE TABLE ONLY parent, child *, grandchild RESTART IDENTITY', ['generic', 'pgsql'], []];
        yield 'parse_select_regexp_as_column_name #1' => ['SELECT REGEXP.REGEXP AS REGEXP FROM REGEXP AS REGEXP WHERE REGEXP.REGEXP', ['generic', 'pgsql'], ['REGEXP']];
        yield 'test_complex_postgres_insert_with_alias #2' => ['WITH existing AS (SELECT test_table.id FROM test_tables AS test_table WHERE (a = 12) AND (b = 34)), inserted AS (INSERT INTO test_tables AS test_table (id, a, b, c) VALUES (DEFAULT, 56, 78, 90) ON CONFLICT(a, b) DO UPDATE SET c = EXCLUDED.c WHERE (test_table.c <> EXCLUDED.c)) SELECT c FROM existing', ['pgsql'], ['test_tables', 'existing']];
        yield 'test_simple_postgres_insert_with_alias #2' => ['INSERT INTO test_tables AS test_table (id, a) VALUES (DEFAULT, 123)', ['pgsql'], []];
        yield 'test_simple_insert_with_quoted_alias #2' => ['INSERT INTO test_tables AS "Test_Table" (id, a) VALUES (DEFAULT, \'0123\')', ['pgsql'], []];
        yield 'parse_array_agg #1' => ['SELECT GREATEST(sections_tbl.*) AS sections FROM sections_tbl', ['pgsql'], ['sections_tbl']];
        yield 'parse_array_agg #2' => ['SELECT ARRAY_AGG(sections_tbl.*) AS sections FROM sections_tbl', ['pgsql'], ['sections_tbl']];
        yield 'parse_array_agg #3' => ['SELECT GREATEST(my_schema.sections_tbl.*) AS sections FROM sections_tbl', ['pgsql'], ['sections_tbl']];
        yield 'parse_array_agg #4' => ['SELECT ARRAY_AGG(my_schema.sections_tbl.*) AS sections FROM sections_tbl', ['pgsql'], ['sections_tbl']];
        yield 'parse_mat_cte #1' => ['WITH cte AS MATERIALIZED (SELECT id FROM accounts) SELECT id FROM cte', ['pgsql'], ['accounts', 'cte']];
        yield 'parse_mat_cte #2' => ['WITH cte AS NOT MATERIALIZED (SELECT id FROM accounts) SELECT id FROM cte', ['pgsql'], ['accounts', 'cte']];
        yield 'parse_interval_keyword_as_unquoted_identifier #1' => ['SELECT MAX(interval) FROM tbl', ['pgsql'], ['tbl']];
        yield 'parse_merge_do_nothing #2' => ['MERGE INTO target USING source ON target.id = source.id WHEN MATCHED THEN DO NOTHING WHEN NOT MATCHED THEN DO NOTHING', ['generic', 'pgsql'], []];
        yield 'parse_compound_field_access_numeric_display #1' => ['SELECT * FROM t WHERE CASE WHEN a = 1 THEN b ELSE c END . 2', ['pgsql'], ['t']];
        yield 'parse_trim_from_without_characters #1' => ['SELECT TRIM(FROM \' x \')', ['pgsql'], []];
        yield 'parse_insert_overriding #9' => ['INSERT INTO t AS x (id) OVERRIDING SYSTEM VALUE VALUES (1)', ['pgsql'], []];
        yield 'parse_insert_overriding #10' => ['INSERT INTO t (id) VALUES (1)', ['generic', 'pgsql'], []];
        yield 'parse_insert_by_name_keywords_as_table_and_alias #2' => ['INSERT INTO BY NAME SELECT 1 AS a', ['pgsql'], []];
        yield 'parse_custom_type_typed_string #1' => ['SELECT count(*) FROM t WHERE m = mood \'sad\'', ['pgsql'], ['t']];
        yield 'parse_geometric_keyword_as_column_name #1' => ['SELECT circle FROM t', ['pgsql'], ['t']];
        yield 'parse_geometric_keyword_as_column_name #2' => ['SELECT point FROM t', ['pgsql'], ['t']];
        yield 'parse_table_inheritance_markers #26' => ['UPDATE t* SET id = 1', ['generic', 'pgsql'], []];
        yield 'parse_table_inheritance_markers #27' => ['DELETE FROM t*', ['generic', 'pgsql'], ['t']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function postgresUnsupportedReads(): iterable
    {
        yield 'parse_stage_table_factor_rejected #1' => ['SELECT * FROM @stage', ['pgsql']];
        yield 'parse_string_literal_alias_rejected #1' => ["SELECT a FROM t 'x'", ['pgsql']];
        yield 'parse_string_literal_alias_rejected #2' => ["SELECT a FROM t AS 'x'", ['pgsql']];
        yield 'parse_table_inheritance_markers #19' => ['SELECT * FROM ONLY', ['pgsql']];
        yield 'parse_table_inheritance_markers #20' => ['SELECT * FROM ONLY t *', ['pgsql']];
        yield 'parse_table_inheritance_markers #21' => ['SELECT * FROM ONLY (t *)', ['pgsql']];
        yield 'parse_table_inheritance_markers #22' => ['SELECT * FROM ONLY (t', ['pgsql']];
        yield 'parse_table_inheritance_markers #23' => ['SELECT * FROM ONLY ()', ['pgsql']];
        yield 'parse_table_inheritance_markers #24' => ['SELECT * FROM t *', ['mysql', 'sqlite', 'sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function sqliteWrites(): iterable
    {
        yield 'parse_update_tuple_row_values #1' => ['UPDATE x SET (a, b) = (1, 2)', ['sqlite'], 'UPDATE x SET a, b'];
        yield 'test_update_delete_limit #1' => ['UPDATE foo SET bar = 1 LIMIT 99', ['sqlite'], 'UPDATE foo SET bar'];
        yield 'test_update_delete_limit #2' => ['DELETE FROM foo LIMIT 99', ['sqlite'], 'DELETE foo'];
        yield 'parse_update_set_double_eq #1' => ['UPDATE t SET a == 1', ['sqlite'], 'UPDATE t SET a'];
        yield 'parse_update_set_double_eq #2' => ['UPDATE t SET a = 1', ['sqlite'], 'UPDATE t SET a'];
        yield 'parse_update_set_double_eq #3' => ['UPDATE t SET a == 1, b == 2', ['sqlite'], 'UPDATE t SET a, b'];
        yield 'parse_update_set_double_eq #4' => ['UPDATE t SET a = 1, b = 2', ['sqlite'], 'UPDATE t SET a, b'];
        yield 'parse_comma_join_constraint #1' => ['UPDATE t SET a = 1 FROM u, v ON u.a = v.a', ['sqlite'], 'UPDATE t SET a'];
        yield 'parse_comma_join_constraint #2' => ['UPDATE t SET a = 1 FROM u JOIN v ON u.a = v.a', ['sqlite'], 'UPDATE t SET a'];
        yield 'parse_indexed_by #1' => ['DELETE FROM t INDEXED BY i WHERE a = 1', ['sqlite'], 'DELETE t'];
        yield 'parse_indexed_by #2' => ['UPDATE t INDEXED BY i SET a = 2 WHERE a = 1', ['sqlite'], 'UPDATE t SET a'];
        yield 'parse_on_conflict_target_predicate #1' => ['INSERT INTO t VALUES (1, 2, 3) ON CONFLICT(a) WHERE b > 0 DO NOTHING', ['sqlite'], 'INSERT t'];
        yield 'test_dollar_sign_in_identifier #1' => ['INSERT INTO t$ VALUES (1)', ['sqlite'], 'INSERT t$'];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function sqliteUnsupportedWrites(): iterable
    {
        yield 'parse_update_set_double_eq #6' => ['UPDATE t SET a == 1', ['generic', 'mysql', 'pgsql', 'sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function sqliteReads(): iterable
    {
        yield 'parse_single_quoted_identified #1' => ["SELECT 't'.*, t.'x' FROM 't'", ['sqlite'], ['t']];
        yield 'parse_in_table_keyword_names #1' => ['SELECT 1 IN view', ['sqlite'], ['view']];
        yield 'parse_in_table_keyword_names #2' => ['SELECT 1 IN offset', ['sqlite'], ['offset']];
        yield 'parse_in_table_keyword_names #3' => ['SELECT 1 IN key', ['sqlite'], ['key']];
        yield 'parse_in_table_keyword_names #4' => ['SELECT 1 IN abort', ['sqlite'], ['abort']];
        yield 'parse_in_table_keyword_names #5' => ['SELECT 1 IN "from"', ['sqlite'], ['from']];
        yield 'parse_comma_join_constraint #3' => ['SELECT * FROM t JOIN u ON t.a = u.a', ['sqlite'], ['t', 'u']];
        yield 'parse_comma_join_constraint #4' => ['SELECT * FROM t, u USING (a)', ['sqlite'], ['t', 'u']];
        yield 'parse_comma_join_constraint #5' => ['SELECT * FROM t JOIN u USING(a)', ['sqlite'], ['t', 'u']];
        yield 'parse_comma_join_constraint #6' => ['SELECT * FROM t, u ON t.a = u.a, v ON v.a = u.a', ['sqlite'], ['t', 'u', 'v']];
        yield 'parse_comma_join_constraint #7' => ['SELECT * FROM t JOIN u ON t.a = u.a JOIN v ON v.a = u.a', ['sqlite'], ['t', 'u', 'v']];
        yield 'parse_comma_join_constraint #8' => ['SELECT * FROM t, u ON t.a = u.a JOIN v ON v.a = u.a', ['sqlite'], ['t', 'u', 'v']];
        yield 'parse_comma_join_constraint #9' => ['SELECT * FROM t, u', ['sqlite'], ['t', 'u']];
        yield 'parse_comma_join_constraint #13' => ['SELECT * FROM t, u USING (a)', ['mysql', 'pgsql', 'sqlsrv'], ['t', 'u']];
        yield 'parse_parenthesized_table_factor #1' => ['SELECT * FROM (t)', ['generic', 'sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #2' => ['SELECT * FROM t', ['generic', 'sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #3' => ['SELECT * FROM ((t))', ['generic', 'sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #4' => ['SELECT * FROM (t) AS a', ['generic', 'sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #5' => ['SELECT * FROM t AS a', ['generic', 'sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #6' => ['SELECT * FROM (t AS a)', ['generic', 'sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #7' => ['SELECT * FROM ((SELECT 1) AS s)', ['generic', 'sqlite'], []];
        yield 'parse_parenthesized_table_factor #8' => ['SELECT * FROM (SELECT 1) AS s', ['generic', 'sqlite'], []];
        yield 'parse_parenthesized_table_factor #9' => ['SELECT * FROM f(), (t)', ['generic', 'sqlite'], ['t']];
        yield 'parse_parenthesized_table_factor #10' => ['SELECT * FROM f(), t', ['generic', 'sqlite'], ['t']];
        yield 'parse_indexed_by #3' => ['SELECT * FROM t INDEXED BY i WHERE a = 1', ['sqlite'], ['t']];
        yield 'parse_indexed_by #4' => ['SELECT * FROM t NOT INDEXED', ['sqlite'], ['t']];
        yield 'parse_indexed_by #5' => ['SELECT * FROM t AS x NOT INDEXED', ['sqlite'], ['t']];
        yield 'parse_indexed_by #6' => ['SELECT * FROM t INDEXED BY i, t AS u NOT INDEXED', ['sqlite'], ['t', 't']];
        yield 'parse_indexed_by #7' => ['SELECT * FROM t AS x INDEXED BY i WHERE a = 1', ['sqlite'], ['t']];
        yield 'parse_indexed_by #8' => ['SELECT * FROM t INDEXED BY i', ['sqlite'], ['t']];
        yield 'display_index_directives_propagates_formatter_errors #1' => ['SELECT * FROM t INDEXED BY idx_b', ['sqlite'], ['t']];
        yield 'display_index_directives_propagates_formatter_errors #2' => ['SELECT * FROM t NOT INDEXED', ['sqlite'], ['t']];
        yield 'test_dollar_sign_in_identifier #2' => ['SELECT a$ FROM t', ['sqlite'], ['t']];
        yield 'double_equality_operator #1' => ['SELECT a==b FROM t', ['generic', 'sqlite'], ['t']];
        yield 'parse_update_tuple_row_values #2' => ['UPDATE x SET (a, b) = (1, 2)', ['sqlite'], []];
        yield 'parse_where_in_empty_list #1' => ['SELECT * FROM t1 WHERE a IN ()', ['sqlite'], ['t1']];
        yield 'parse_where_in_empty_list #2' => ['SELECT * FROM t1 WHERE a IN (,)', ['sqlite'], ['t1']];
        yield 'test_match_operator #1' => ['SELECT * FROM email WHERE email MATCH \'fts5\'', ['sqlite'], ['email']];
        yield 'test_regexp_operator #1' => ['SELECT count(*) FROM messages WHERE msg_text REGEXP \'\\d+\'', ['sqlite'], ['messages']];
        yield 'test_glob_operator #1' => ['SELECT count(*) FROM files WHERE name GLOB \'*.txt\'', ['sqlite'], ['files']];
        yield 'sqlite_is_operator_keyword_operands #1' => ['SELECT a IS json(\'{}\'), a IS NOT json(\'{}\') FROM t', ['sqlite'], ['t']];
        yield 'test_update_delete_limit #3' => ['UPDATE foo SET bar = 1 LIMIT 99', ['sqlite'], []];
        yield 'test_update_delete_limit #4' => ['DELETE FROM foo LIMIT 99', ['sqlite'], ['foo']];
        yield 'parse_update_set_double_eq #5' => ['UPDATE t SET a == 1', ['sqlite'], []];
        yield 'parse_update_set_double_eq #7' => ['UPDATE t SET a == 1, b == 2', ['sqlite'], []];
        yield 'parse_update_set_double_eq #8' => ['UPDATE t SET a = 1', ['sqlite'], []];
        yield 'parse_n_prefix_not_national_string #1' => ['SELECT t.n\'\' FROM t', ['sqlite'], ['t']];
        yield 'parse_n_prefix_not_national_string #2' => ['SELECT n\'\' FROM t', ['sqlite'], ['t']];
        yield 'parse_indexed_by #10' => ['DELETE FROM t INDEXED BY i WHERE a = 1', ['sqlite'], ['t']];
        yield 'parse_indexed_by #11' => ['UPDATE t INDEXED BY i SET a = 2 WHERE a = 1', ['sqlite'], []];
        yield 'parse_on_conflict_target_predicate #2' => ['INSERT INTO t VALUES (1, 2, 3) ON CONFLICT(a) WHERE b > 0 DO NOTHING', ['sqlite'], []];
        yield 'test_dollar_sign_in_identifier #3' => ['INSERT INTO t$ VALUES (1)', ['sqlite'], []];
        yield 'parse_bitwise_shift_operators #1' => ['SELECT a << 1 FROM t', ['sqlite'], ['t']];
        yield 'parse_bitwise_shift_operators #2' => ['SELECT a >> 1 FROM t', ['sqlite'], ['t']];
        yield 'parse_window_function_with_filter #1' => ['SELECT row_number(x) FILTER (WHERE y) OVER () FROM t', ['sqlite'], ['t']];
        yield 'parse_window_function_with_filter #2' => ['SELECT rank(x) FILTER (WHERE y) OVER () FROM t', ['sqlite'], ['t']];
        yield 'parse_window_function_with_filter #3' => ['SELECT max(x) FILTER (WHERE y) OVER () FROM t', ['sqlite'], ['t']];
        yield 'parse_window_function_with_filter #4' => ['SELECT count(x) FILTER (WHERE y) OVER () FROM t', ['sqlite'], ['t']];
        yield 'parse_window_function_with_filter #5' => ['SELECT user_defined_function(x) FILTER (WHERE y) OVER () FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #1' => ['SELECT a analyze FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #2' => ['SELECT a cluster FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #3' => ['SELECT a distribute FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #4' => ['SELECT a end FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #5' => ['SELECT a exclude FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #6' => ['SELECT a explain FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #7' => ['SELECT a fetch FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #8' => ['SELECT a lateral FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #9' => ['SELECT a minus FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #10' => ['SELECT a offset FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #11' => ['SELECT a sort FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #12' => ['SELECT a top FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #13' => ['SELECT a view FROM t', ['sqlite'], ['t']];
        yield 'parse_keyword_column_aliases #14' => ['SELECT a with FROM t', ['sqlite'], ['t']];
        yield 'parse_pattern_operators_bind_at_like_precedence #1' => ['SELECT 1 FROM t WHERE a REGEXP \'p\' AND b = 1', ['sqlite'], ['t']];
        yield 'parse_pattern_operators_bind_at_like_precedence #2' => ['SELECT 1 FROM t WHERE a MATCH \'p\' AND b = 1', ['sqlite'], ['t']];
        yield 'parse_pattern_operators_bind_at_like_precedence #3' => ['SELECT 1 FROM t WHERE a GLOB \'p\' AND b = 1', ['sqlite'], ['t']];
        yield 'parse_pattern_operators_bind_at_like_precedence #4' => ['SELECT 1 FROM t WHERE a LIKE \'p\' AND b = 1', ['sqlite'], ['t']];
        yield 'parse_pattern_operators_bind_at_like_precedence #5' => ['SELECT 1 FROM t WHERE a REGEXP \'p\' || \'q\'', ['sqlite'], ['t']];
        yield 'parse_pattern_operators_bind_at_like_precedence #6' => ['SELECT 1 FROM t WHERE a MATCH \'p\' || \'q\'', ['sqlite'], ['t']];
        yield 'parse_pattern_operators_bind_at_like_precedence #7' => ['SELECT 1 FROM t WHERE a GLOB \'p\' || \'q\'', ['sqlite'], ['t']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function sqliteUnsupportedReads(): iterable
    {
        yield 'parse_comma_join_constraint #10' => ['SELECT * FROM t, u USING a', ['sqlite']];
        yield 'parse_comma_join_constraint #11' => ['SELECT * FROM t, u ON t.a = u.a CROSS', ['sqlite']];
        yield 'parse_comma_join_constraint #12' => ['SELECT * FROM t, u ON t.a = u.a', ['mysql', 'pgsql', 'sqlsrv']];
        yield 'parse_comma_join_constraint #14' => ['SELECT * FROM t, u ON t.a = u.a, v ON v.a = u.a', ['mysql', 'pgsql', 'sqlsrv']];
        yield 'parse_comma_join_constraint #15' => ['SELECT * FROM t, u ON t.a = u.a JOIN v ON v.a = u.a', ['mysql', 'pgsql', 'sqlsrv']];
        yield 'parse_parenthesized_table_factor #11' => ['SELECT * FROM (t)', ['mysql', 'pgsql', 'sqlsrv']];
        yield 'parse_indexed_by #9' => ['SELECT * FROM t INDEXED BY i', ['mysql', 'pgsql', 'sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function mssqlWrites(): iterable
    {
        yield 'parse_mssql_insert_with_output #1' => ["INSERT INTO customers (name, email) OUTPUT INSERTED.id, INSERTED.name VALUES ('John', 'john@example.com')", ['sqlsrv'], 'INSERT customers'];
        yield 'parse_mssql_insert_with_output_into #1' => ["INSERT INTO customers (name, email) OUTPUT INSERTED.id, INSERTED.name INTO @new_ids VALUES ('John', 'john@example.com')", ['sqlsrv'], 'INSERT customers'];
        yield 'parse_mssql_delete_with_output #1' => ['DELETE FROM customers OUTPUT DELETED.* WHERE id = 1', ['sqlsrv'], 'DELETE customers'];
        yield 'parse_mssql_delete_with_output_into #1' => ['DELETE FROM customers OUTPUT DELETED.id, DELETED.name INTO @deleted_rows WHERE active = 0', ['sqlsrv'], 'DELETE customers'];
        yield 'parse_mssql_update_with_output #1' => ["UPDATE employees SET salary = salary * 1.1 OUTPUT INSERTED.id, DELETED.salary, INSERTED.salary WHERE department = 'Engineering'", ['sqlsrv'], 'UPDATE employees SET salary'];
        yield 'parse_mssql_update_with_output_into #1' => ["UPDATE employees SET salary = salary * 1.1 OUTPUT INSERTED.id, DELETED.salary, INSERTED.salary INTO @changes WHERE department = 'Engineering'", ['sqlsrv'], 'UPDATE employees SET salary'];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function mssqlUnsupportedWrites(): iterable
    {
        yield 'parse_mssql_merge_with_output #1' => ['MERGE dso.products AS t USING dsi.products AS s ON s.ProductID = t.ProductID WHEN MATCHED AND NOT (t.ProductName = s.ProductName OR (ISNULL(t.ProductName, s.ProductName) IS NULL)) THEN UPDATE SET t.ProductName = s.ProductName WHEN NOT MATCHED BY TARGET THEN INSERT (ProductID, ProductName) VALUES (s.ProductID, s.ProductName) WHEN NOT MATCHED BY SOURCE THEN DELETE OUTPUT $action, deleted.ProductID INTO dsi.temp_products', ['sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function mssqlReads(): iterable
    {
        yield 'parse_mssql_identifiers #1' => ['SELECT @@version, _foo$123 FROM ##temp', ['sqlsrv'], ['##temp']];
        yield 'parse_table_time_travel #1' => ["SELECT 1 FROM t1 FOR SYSTEM_TIME AS OF '2023-08-18 23:08:18'", ['sqlsrv'], ['t1']];
        yield 'parse_mssql_delimited_identifiers #1' => ['SELECT [a.b!] [FROM] FROM foo [WHERE]', ['sqlsrv'], ['foo']];
        yield 'parse_mssql_delimited_identifiers #2' => ['SELECT [a.b!] AS [FROM] FROM foo [WHERE]', ['sqlsrv'], ['foo']];
        yield 'parse_mssql_apply_join #1' => ['SELECT * FROM sys.dm_exec_query_stats AS deqs CROSS APPLY sys.dm_exec_query_plan(deqs.plan_handle)', ['sqlsrv'], ['sys.dm_exec_query_stats']];
        yield 'parse_mssql_apply_join #2' => ['SELECT * FROM sys.dm_exec_query_stats AS deqs OUTER APPLY sys.dm_exec_query_plan(deqs.plan_handle)', ['sqlsrv'], ['sys.dm_exec_query_stats']];
        yield 'parse_mssql_apply_join #3' => ['SELECT * FROM foo OUTER APPLY (SELECT foo.x + 1) AS bar', ['sqlsrv'], ['foo']];
        yield 'parse_mssql_openjson #1' => ['SELECT B.kind, B.id_list FROM t_test_table AS A CROSS APPLY OPENJSON(A.param, \'$.config\') WITH (kind VARCHAR(20) \'$.kind\', [id_list] NVARCHAR(MAX) \'$.id_list\' AS JSON) AS B', ['sqlsrv'], ['t_test_table']];
        yield 'parse_mssql_openjson #2' => ['SELECT B.kind, B.id_list FROM t_test_table AS A CROSS APPLY OPENJSON(A.param) WITH (kind VARCHAR(20) \'$.kind\', [id_list] NVARCHAR(MAX) \'$.id_list\' AS JSON) AS B', ['sqlsrv'], ['t_test_table']];
        yield 'parse_mssql_openjson #3' => ['SELECT B.kind, B.id_list FROM t_test_table AS A CROSS APPLY OPENJSON(A.param) WITH (kind VARCHAR(20), [id_list] NVARCHAR(MAX)) AS B', ['sqlsrv'], ['t_test_table']];
        yield 'parse_mssql_openjson #4' => ['SELECT B.kind, B.id_list FROM t_test_table AS A CROSS APPLY OPENJSON(A.param, \'$.config\') AS B', ['sqlsrv'], ['t_test_table']];
        yield 'parse_mssql_openjson #5' => ['SELECT B.kind, B.id_list FROM t_test_table AS A CROSS APPLY OPENJSON(A.param) AS B', ['sqlsrv'], ['t_test_table']];
        yield 'parse_table_name_in_square_brackets #1' => ['SELECT [a column] FROM [a schema].[a table]', ['sqlsrv'], ['a schema.a table']];
        yield 'parse_mssql_table_identifier_with_default_schema #1' => ['SELECT * FROM mydatabase..MyTable', ['sqlsrv'], ['mydatabase..MyTable']];
        yield 'parse_non_reserved_keyword_table_names #1' => ['SELECT * FROM limit', ['sqlsrv'], ['limit']];
        yield 'parse_non_reserved_keyword_table_names #2' => ['SELECT * FROM t JOIN limit ON true', ['sqlsrv'], ['t', 'limit']];
        yield 'parse_quoted_statement_keyword_aliases #1' => ['SELECT * FROM t [PRINT]', ['sqlsrv'], ['t']];
        yield 'parse_quoted_statement_keyword_aliases #2' => ['SELECT * FROM t AS [PRINT]', ['sqlsrv'], ['t']];
        yield 'parse_for_clause #1' => ['SELECT a FROM t FOR JSON PATH', ['sqlsrv'], ['t']];
        yield 'parse_for_clause #2' => ['SELECT c FROM t FOR JSON AUTO, WITHOUT_ARRAY_WRAPPER', ['sqlsrv'], ['t']];
        yield 'parse_for_clause #3' => ['SELECT 2 FROM t FOR XML AUTO', ['sqlsrv'], ['t']];
        yield 'parse_for_clause #4' => ['SELECT * FROM t WHERE x FOR XML AUTO, ELEMENTS', ['sqlsrv'], ['t']];
        yield 'parse_for_clause #5' => ["SELECT y FROM t FOR XML PATH('x'), ROOT('y'), ELEMENTS", ['sqlsrv'], ['t']];
        yield 'parse_for_clause #6' => ['SELECT * FROM t FOR BROWSE', ['sqlsrv'], ['t']];
        yield 'parse_mssql_top_paren #1' => ['SELECT TOP (5) * FROM foo', ['generic', 'sqlsrv'], ['foo']];
        yield 'parse_mssql_top_percent #1' => ['SELECT TOP (5) PERCENT * FROM foo', ['generic', 'sqlsrv'], ['foo']];
        yield 'parse_mssql_top_with_ties #1' => ['SELECT TOP (5) WITH TIES * FROM foo', ['generic', 'sqlsrv'], ['foo']];
        yield 'parse_mssql_top_percent_with_ties #1' => ['SELECT TOP (10) PERCENT WITH TIES * FROM foo', ['generic', 'sqlsrv'], ['foo']];
        yield 'parse_mssql_top #1' => ['SELECT TOP 5 bar, baz FROM foo', ['generic', 'sqlsrv'], ['foo']];
        yield 'parse_for_clause #7' => ['SELECT b FROM t FOR JSON AUTO', ['generic', 'sqlsrv'], ['t']];
        yield 'parse_for_clause #8' => ['SELECT 1 FROM t FOR JSON PATH, ROOT(\'x\'), INCLUDE_NULL_VALUES', ['generic', 'sqlsrv'], ['t']];
        yield 'parse_for_clause #9' => ['SELECT 3 FROM t FOR XML AUTO, TYPE, ELEMENTS', ['generic', 'sqlsrv'], ['t']];
        yield 'parse_for_clause #10' => ['SELECT x FROM t ORDER BY y FOR XML AUTO, ELEMENTS', ['generic', 'sqlsrv'], ['t']];
        yield 'parse_for_clause #11' => ['SELECT z FROM t FOR XML EXPLICIT, BINARY BASE64', ['generic', 'sqlsrv'], ['t']];
        yield 'parse_for_clause #12' => ['SELECT * FROM t FOR XML RAW(\'x\')', ['generic', 'sqlsrv'], ['t']];
        yield 'parse_for_json_expect_ast #1' => ['SELECT * FROM t FOR JSON PATH, ROOT(\'root\')', ['sqlsrv'], ['t']];
        yield 'parse_mssql_json_object #1' => ['SELECT s.session_id, JSON_OBJECT(\'security_id\' : s.security_id, \'login\' : s.login_name, \'status\' : s.status) AS info FROM sys.dm_exec_sessions AS s WHERE s.is_user_process = 1', ['sqlsrv'], ['sys.dm_exec_sessions']];
        yield 'parse_mssql_json_array #1' => ['SELECT s.session_id, JSON_ARRAY(s.host_name, s.program_name, s.client_interface_name NULL ON NULL) AS info FROM sys.dm_exec_sessions AS s WHERE s.is_user_process = 1', ['sqlsrv'], ['sys.dm_exec_sessions']];
        yield 'parse_mssql_merge_with_output #2' => ['MERGE dso.products AS t USING dsi.products AS s ON s.ProductID = t.ProductID WHEN MATCHED AND NOT (t.ProductName = s.ProductName OR (ISNULL(t.ProductName, s.ProductName) IS NULL)) THEN UPDATE SET t.ProductName = s.ProductName WHEN NOT MATCHED BY TARGET THEN INSERT (ProductID, ProductName) VALUES (s.ProductID, s.ProductName) WHEN NOT MATCHED BY SOURCE THEN DELETE OUTPUT $action, deleted.ProductID INTO dsi.temp_products', ['generic', 'sqlsrv'], []];
        yield 'parse_mssql_insert_with_output #2' => ['INSERT INTO customers (name, email) OUTPUT INSERTED.id, INSERTED.name VALUES (\'John\', \'john@example.com\')', ['generic', 'sqlsrv'], []];
        yield 'parse_mssql_insert_with_output_into #2' => ['INSERT INTO customers (name, email) OUTPUT INSERTED.id, INSERTED.name INTO @new_ids VALUES (\'John\', \'john@example.com\')', ['generic', 'sqlsrv'], []];
        yield 'parse_mssql_delete_with_output #2' => ['DELETE FROM customers OUTPUT DELETED.* WHERE id = 1', ['generic', 'sqlsrv'], ['customers']];
        yield 'parse_mssql_delete_with_output_into #2' => ['DELETE FROM customers OUTPUT DELETED.id, DELETED.name INTO @deleted_rows WHERE active = 0', ['generic', 'sqlsrv'], ['customers']];
        yield 'parse_mssql_update_with_output #2' => ['UPDATE employees SET salary = salary * 1.1 OUTPUT INSERTED.id, DELETED.salary, INSERTED.salary WHERE department = \'Engineering\'', ['generic', 'sqlsrv'], []];
        yield 'parse_mssql_update_with_output_into #2' => ['UPDATE employees SET salary = salary * 1.1 OUTPUT INSERTED.id, DELETED.salary, INSERTED.salary INTO @changes WHERE department = \'Engineering\'', ['generic', 'sqlsrv'], []];
        yield 'test_collate_on_compound_identifier #1' => ['SELECT t1.a COLLATE Latin1_General_CI_AS FROM t1', ['generic', 'sqlsrv'], ['t1']];
        yield 'parse_bracket_quoted_eq_alias_assignment #1' => ['SELECT a = [from] FROM t', ['sqlsrv'], ['t']];
        yield 'parse_create_proc #1' => ['SELECT proc FROM jobs', ['generic', 'sqlsrv'], ['jobs']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function mssqlUnsupportedReads(): iterable
    {
        yield 'parse_table_time_travel #2' => ["SELECT 1 FROM t1 FOR SYSTEM TIME AS OF 'some_timestamp'", ['sqlsrv']];
        yield 'test_sql_keywords_as_table_aliases #1' => ['SELECT * FROM tbl IF', ['sqlsrv']];
        yield 'test_sql_keywords_as_table_aliases #2' => ['SELECT * FROM tbl AS IF', ['sqlsrv']];
        yield 'test_sql_keywords_as_table_aliases #3' => ['SELECT * FROM tbl ELSE', ['sqlsrv']];
        yield 'test_sql_keywords_as_table_aliases #4' => ['SELECT * FROM tbl AS ELSE', ['sqlsrv']];
        yield 'test_tsql_statement_keywords_not_implicit_aliases #1' => ['select * from t declare @x as int', ['sqlsrv']];
        yield 'test_tsql_statement_keywords_not_implicit_aliases #2' => ['select * from t drop table t', ['sqlsrv']];
        yield 'test_tsql_statement_keywords_not_implicit_aliases #3' => ['select * from t create table u (id int)', ['sqlsrv']];
        yield 'test_tsql_statement_keywords_not_implicit_aliases #4' => ['select * from t exec sp_who', ['sqlsrv']];
        yield 'dont_parse_trailing_for #1' => ['SELECT * FROM foo FOR', ['sqlsrv']];
        yield 'test_tsql_no_semicolon_delimiter #1' => ["\nSELECT col FROM tbl\nIF x=1\n  SELECT 1\nELSE\n  SELECT 2\n    ", ['sqlsrv']];
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function portWrites(): iterable
    {
        yield 'a column named output or returning in an assigned value' => ['UPDATE jobs SET a = output, b = returning, c = 2', ['mysql'], 'UPDATE jobs SET a, b, c'];
        yield 'OUTPUT INTO a table variable with columns' => ['UPDATE t SET a = 1 OUTPUT inserted.a INTO @v (a) WHERE b = 2', ['sqlsrv'], 'UPDATE t SET a'];
        yield 'a qualified wildcard delete target' => ['DELETE t.* FROM t JOIN u ON t.id = u.id', ['mysql'], 'DELETE t'];
        yield 'TRUNCATE IF EXISTS' => ['TRUNCATE TABLE IF EXISTS t', self::ALL_AND_GENERIC, 'TRUNCATE t'];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function portUnsupportedWrites(): iterable
    {
        yield 'OUTPUT INTO a table from an INSERT' => ['INSERT INTO t (a) OUTPUT inserted.a INTO audit (a) VALUES (1)', ['sqlsrv']];
        yield 'OUTPUT INTO a table from an UPDATE' => ['UPDATE t SET a = 1 OUTPUT inserted.a INTO audit WHERE b = 2', ['sqlsrv']];
        yield 'OUTPUT INTO a table from a DELETE' => ['DELETE FROM t OUTPUT deleted.a INTO dbo.audit WHERE b = 2', ['sqlsrv']];
        yield 'SQLite UPDATE OR REPLACE' => ['UPDATE OR REPLACE t SET a = 1', ['sqlite']];
        yield 'a MERGE into a derived table' => ['MERGE INTO (SELECT * FROM t) AS x USING s ON x.id = s.id WHEN MATCHED THEN DELETE', ['sqlsrv']];
        yield 'a DELETE USING from a derived table' => ['DELETE FROM (SELECT 1) AS x USING t', ['pgsql']];
        yield 'a DELETE from a derived table' => ['DELETE FROM (SELECT 1) AS x', ['pgsql']];
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function portReads(): iterable
    {
        yield 'a parenthesized set operation joined to a table' => ['SELECT * FROM ((SELECT x FROM foo) UNION (SELECT y FROM bar)) AS t1 NATURAL JOIN t2', self::ALL, ['t2', 'foo', 'bar']];
        yield 'TABLE query bodies in a subquery and a set operation' => ['SELECT * FROM t WHERE x IN (TABLE u) UNION TABLE v', ['mysql', 'pgsql'], ['t', 'u', 'v']];
        yield 'a lateral subquery with a join' => ['SELECT * FROM a LEFT JOIN LATERAL (SELECT * FROM b JOIN c ON 1 = 1) x ON true', ['mysql', 'pgsql'], ['a', 'b', 'c']];
        yield 'a temporal table followed by a join' => ["SELECT * FROM t FOR SYSTEM_TIME AS OF '2020-01-01' x JOIN u ON u.id = x.id", ['sqlsrv'], ['t', 'u']];
        yield 'a table sample after a derived table alias' => ['SELECT * FROM (SELECT * FROM t) AS x TABLESAMPLE SYSTEM (10), u', ['pgsql'], ['u', 't']];
        yield 'IS DISTINCT FROM in a condition' => ['SELECT * FROM t WHERE a IS DISTINCT FROM b', self::ALL_AND_GENERIC, ['t']];
        yield 'a table sample quantity without parentheses' => ['SELECT * FROM t TABLESAMPLE 10', self::ALL_AND_GENERIC, ['t']];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function portUnsupportedReads(): iterable
    {
        yield 'a temporal clause other than AS OF' => ['SELECT * FROM t FOR SYSTEM_TIME ALL, u', ['sqlsrv']];
        yield 'an unterminated subquery' => ['SELECT * FROM ((SELECT 1', self::ALL_AND_GENERIC];
        yield 'an unbalanced closing parenthesis before a FROM-first query' => ['SELECT 1) FROM t', ['generic']];
        yield 'LEFT without a join' => ['SELECT * FROM a LEFT b', self::ALL_AND_GENERIC];
        yield 'GLOBAL without a join' => ['SELECT * FROM a GLOBAL b', self::ALL_AND_GENERIC];
        yield 'a table sample without a quantity' => ['SELECT * FROM t TABLESAMPLE , u', self::ALL_AND_GENERIC];
        yield 'an index hint without INDEX or KEY' => ['SELECT * FROM t USE x', ['mysql']];
        yield 'an index hint for an unknown clause' => ['SELECT * FROM t USE INDEX FOR x (i)', ['mysql']];
    }

    /**
     * @param  list<string>  $drivers
     */
    #[DataProvider('commonWrites')]
    #[DataProvider('mysqlWrites')]
    #[DataProvider('postgresWrites')]
    #[DataProvider('sqliteWrites')]
    #[DataProvider('mssqlWrites')]
    #[DataProvider('portWrites')]
    public function testWritesNameTheirTargetsAndAssignedColumns(string $sql, array $drivers, string $expected): void
    {
        foreach ($drivers as $driver) {
            $this->assertSame($expected, self::describe(Parser::forSql(self::dialect($driver), $sql)->parseWriteStatement()), $driver);
        }
    }

    /**
     * @param  list<string>  $drivers
     */
    #[DataProvider('commonUnsupportedWrites')]
    #[DataProvider('postgresUnsupportedWrites')]
    #[DataProvider('sqliteUnsupportedWrites')]
    #[DataProvider('mssqlUnsupportedWrites')]
    #[DataProvider('portUnsupportedWrites')]
    public function testWritesThatCannotBeFullyUnderstoodThrow(string $sql, array $drivers): void
    {
        foreach ($drivers as $driver) {
            $this->assertThrowsParserException(static fn () => WriteTargets::of(Parser::forSql(self::dialect($driver), $sql)->parseWriteStatement()), $driver);
        }
    }

    /**
     * @param  list<string>  $drivers
     * @param  list<string>  $expected
     */
    #[DataProvider('commonReads')]
    #[DataProvider('mysqlReads')]
    #[DataProvider('postgresReads')]
    #[DataProvider('sqliteReads')]
    #[DataProvider('mssqlReads')]
    #[DataProvider('portReads')]
    public function testReadsNameEveryRelation(string $sql, array $drivers, array $expected): void
    {
        foreach ($drivers as $driver) {
            $tables = [];
            foreach (Parser::forSql(self::dialect($driver), $sql)->readRelations() as $relation) {
                foreach ($relation->tables() as $table) {
                    $this->assertNotNull($table->name);
                    $tables[] = implode('.', $table->name);
                }
            }

            $this->assertSame($expected, $tables, $driver);
        }
    }

    /**
     * @param  list<string>  $drivers
     */
    #[DataProvider('commonUnsupportedReads')]
    #[DataProvider('mysqlUnsupportedReads')]
    #[DataProvider('postgresUnsupportedReads')]
    #[DataProvider('sqliteUnsupportedReads')]
    #[DataProvider('mssqlUnsupportedReads')]
    #[DataProvider('portUnsupportedReads')]
    public function testReadsOfUnknownShapeThrow(string $sql, array $drivers): void
    {
        foreach ($drivers as $driver) {
            $this->assertThrowsParserException(static fn () => Parser::forSql(self::dialect($driver), $sql)->readRelations(), $driver);
        }
    }

    private function assertThrowsParserException(callable $parse, string $driver): void
    {
        try {
            $parse();
        } catch (ParserException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("Expected a ParserException on {$driver}");
    }

    public function testAnInsertWithoutAnUpsertAssignsNoColumns(): void
    {
        $statement = Parser::forSql(self::dialect('pgsql'), 'INSERT INTO t VALUES (1)')->parseWriteStatement();

        $this->assertInstanceOf(InsertStatement::class, $statement);
        $this->assertNull(WriteTargets::assignedColumns($statement));
    }

    private static function dialect(string $name): Dialect
    {
        return $name === 'generic' ? new GenericDialect : Dialects::forDriver($name);
    }

    private static function describe(InsertStatement|UpdateStatement|DeleteStatement|TruncateStatement|MergeStatement $statement): string
    {
        $targets = implode(', ', array_map(static fn (array $name): string => implode('.', $name), WriteTargets::of($statement)));

        return match (true) {
            $statement instanceof InsertStatement => 'INSERT '.$targets
                .($statement->replacesRows ? ' REPLACING ROWS' : '')
                .($statement->conflictAssignments === null ? '' : ' SET '.implode(', ', WriteTargets::assignedColumns($statement) ?? [])),
            $statement instanceof UpdateStatement => "UPDATE {$targets} SET ".implode(', ', WriteTargets::assignedColumns($statement) ?? []),
            $statement instanceof DeleteStatement => "DELETE {$targets}",
            $statement instanceof TruncateStatement => "TRUNCATE {$targets}",
            $statement instanceof MergeStatement => "MERGE {$targets}",
        };
    }
}
