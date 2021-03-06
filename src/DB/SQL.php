<?php
declare(strict_types=1);

namespace TMCms\DB;

use Exception;
use InvalidArgumentException;
use Iterator;
use TMCms\Cache\Cacher;
use TMCms\Config\Configuration;
use TMCms\Config\Constants;
use TMCms\Config\Settings;
use TMCms\Log\Errors;
use TMCms\Log\Stats;
use TMCms\Orm\TableStructure;
use TMCms\Strings\Converter;
use PDO;
use PDOStatement;

\defined('INC') or exit;

/**
 * Class SQL
 */
class SQL extends SqlDao
{
    /**
     * @param string $tbl
     * @param bool $use_cache
     * @return bool
     */
    public function tableExists($tbl, $use_cache = true): bool
    {
        return \in_array($tbl, $this->getTables(NULL, $use_cache), true);
    }

    /**
     * Show tables in database
     *
     * @param string $db - database name
     * @param bool $use_cache
     * @return array - list of non-temporary tables, by pairs key/value
     */
    public function getTables($db = NULL, $use_cache = true): array
    {
        if (!$db) {
            $db = Configuration::getInstance()->get('db')['name'];
            if (!$db) {
                return [];
            }
        }

        if (Settings::isCacheEnabled()) {
            $cache_key = 'db_table_list_all';
            $cacher = Cacher::getInstance()->getDefaultCacher();

            if (!isset(self::$_table_list[$db])) {
                self::$_table_list[$db] = $cacher->get($cache_key);
            }
        }

        if (!isset(self::$_table_list[$db]) || !$use_cache) {
            self::$_table_list[$db] = $this->q_pairs('SHOW TABLES FROM `' . $db . '`');
        }

        if (Settings::isCacheEnabled()) {
            $cacher->set($cache_key, self::$_table_list[$db], 86400);
        }

        return self::$_table_list[$db];
    }

    /**
     * Return array with paired data, e.g. [1 => 'a', 2 => 'b', 14 => 'n']
     *
     * @param string $q
     *
     * @return array
     */
    public function q_pairs($q): array
    {
        $res = [];
        $qh = $this->sql_query($q);

        while ($q = $qh->fetch(PDO::FETCH_NUM)) {
            if (isset($q[1])) {
                $res[$q[0]] = $q[1];
            } else {
                $res[] = $q[0];
            }
        }

        return $res;
    }

    /**
     * Common DB query
     * @param string $q
     * @param bool $return_inserted_id
     *
     * @return PDOStatement | int
     */
    public function sql_query($q, $return_inserted_id = false)
    {
        if (!$this->pdo_db) {
            $this->connect();
        }

        $q = trim($q);

        // Set query start time if debug is enabled or if we analyze queries
        if (Settings::get('debug_panel') || Settings::get('analyze_db_queries')) {
            $ts = microtime(true);
        }

        /** @var PDOStatement $pdo_query */
        $pdo_query = $this->pdo_db->query($q);

        if (!$pdo_query) {
            $err = $this->pdo_db->errorInfo()[2];

            trigger_error($err . '<br><br>Query:<br><br>' . $q);
        }

        if (isset($ts)) {
            // Start time exists - so we save query to analyze
            $tt = microtime(true) - $ts;

            if (Settings::get('debug_panel')) {
                Stats::addQuery([
                    'query' => $q,
                    'backtrace' => debug_backtrace(),
                    'time' => $tt
                ]);
            }

            if (Settings::get('analyze_db_queries')) {
                QueryAnalyzer::getInstance()
                    ->addQuery($q, $tt);
            }
        }

        return $return_inserted_id ? $this->pdo_db->lastInsertId() : $pdo_query;
    }

    /**
     * @param string $user
     * @param string $pass
     * @param string $host
     * @param string $db
     * @param bool $local
     * @return PDO
     */
    public function connect($user = NULL, $pass = NULL, $host = NULL, $db = NULL, $local = true): PDO
    {

        $conn_data = Configuration::getInstance()->get('db');

        if (!$user) {
            $user = $conn_data['login'];
        }
        if (!$pass) {
            $pass = $conn_data['password'];
        }

        if (!$db) {
            $db = $conn_data['name'];
        }


        if (!$host && isset($conn_data['host'])) {
            $host = $conn_data['host'];
            if (!$host) {
                $host = CFG_DB_SERVER;
            }
        }

        // Connect as usual
        $delay = Constants::DB_CONNECT_DELAY;
        $i = 0;
        $connected = false;

        while ($i < Constants::DB_CONNECT_MAX_ATTEMPTS && !$connected) {
            try {
                $this->pdo_db = new PDO('mysql:dbname=' . $db . ';charset=utf8mb4;host=' . $host, $user, $pass, [
                    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES "utf8"',
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_PERSISTENT         => false,
                ]);
                $connected = true;
            } catch (Exception $e) {
                usleep($delay);
                $delay *= 2;
            }
            $i++;
        }

        // Open socket connection to verify that the server is down
        if (!$connected && $local) {
            $dbh = explode(':', CFG_DB_SERVER);
            if (isset($dbh[1]) && ctype_digit($dbh[1])) {
                $host = $dbh[0];
                $port = (int)$dbh[1];
            } else {
                $host = CFG_DB_SERVER;
                $port = 3306;
            }
            $fs = @fsockopen($host, $port, $number, $message, 1);

            // If server down
            if ($fs === false) {
                Errors::sendErrorToDevelopers(CFG_DOMAIN . ' DB Server is down or overloaded', 'It seems that DB Server is down or overloaded and does not respond to attempts to establish the connection.');
                exit('Could not connect to database server.<br><br>Administrator is notified.');
            }

            // If server is ok, but connection refused
            fclose($fs);
            Errors::sendErrorToDevelopers(CFG_DOMAIN . ' DB Server is not accessible', 'It seems that login or password is supplied incorrectly in configuration file "config.php"');
            exit('Could not login to database server.<br><br>Administrator is notified.');
        }

        return $this->pdo_db;
    }

    /**
     * Check for abuse parameters in query
     * @param string $q - query
     */
    public function sqlQueryCheck($q)
    {
        if (\stripos($q, 'union') !== false) {
            dump('UNION usage is limited.');
        }

        if (\stripos($q, ' like \'%\'') !== false) {
            dump('LIKE \'%\' usage is limited.');
        }

        if (\strpos($q, '/*') !== false || \strpos($q, '--') !== false) {
            dump('Comments like \* or -- usage is limited. Query: ' . $q);
        }
    }

    /**
     * Show functions in database
     *
     * @param string $db - database name
     * @return array - list of non-temporary tables, by pairs key/value
     */
    public function getFunctions($db = NULL): array
    {
        if (!$db) {
            $db = Configuration::getInstance()->get('db')['name'];
        }

        $res = [];
        foreach (q_assoc_iterator('SHOW FUNCTION STATUS WHERE `Db` = "' . $db . '"') as $v) {
            $res[] = $v['Name'];
        }

        return $res;
    }

    /**
     * Check entry exists in DB
     *
     * @param string $table
     * @param string $where
     *
     * @return bool
     */
    public static function q_check(string $table, string $where = ''): bool
    {
        return (bool)self::getInstance()->sql_query('SELECT NULL FROM `' . $table . '`' . ($where ? ' WHERE ' . $where : '') . ' LIMIT 1')->rowCount();
    }

    /**
     * Return one dimensional array with one column, for example IDs list
     *
     * @param $q
     * @param int $column
     *
     * @return array
     */
    public static function q_column($q, $column = 0): array
    {
        $qh = self::getInstance()->sql_query($q);

        return $qh->fetchAll(PDO::FETCH_COLUMN, $column);
    }

    /**
     * Get create sql for function
     *
     * @param string $tbl - table name
     * @return string with full query
     */
    public static function getCreateFunction($tbl): string
    {
        $sql = self::getInstance()->sql_query("SHOW CREATE FUNCTION `$tbl`");

        $q = $sql->fetch(PDO::FETCH_ASSOC);

        return strtr($q['Create Function'], ["\r" => '', "\n" => '', "\t" => '']);
    }

    /**
     * Show all columns in table
     *
     * @param string $tbl - table name
     * @return array - list
     */
    public static function getFields($tbl): array
    {
        if (Settings::isCacheEnabled()) {
            $cache_key = 'db_table_columns_' . $tbl;
            $cacher = Cacher::getInstance()->getDefaultCacher();

            if (!isset(self::$_cached_tbl_columns[$tbl])) {
                self::$_cached_tbl_columns[$tbl] = $cacher->get($cache_key);
            }
        }

        if (isset(self::$_cached_tbl_columns[$tbl])) {
            return self::$_cached_tbl_columns[$tbl];
        }

        $res = [];
        $sql = self::getInstance()->sql_query("SHOW COLUMNS FROM `$tbl`");

        while ($q = $sql->fetch(PDO::FETCH_NUM)) {
            $res[] = $q[0];
        }

        if (Settings::isCacheEnabled()) {
            $cacher->set($cache_key, $res, 86400);
        }

        return self::$_cached_tbl_columns[$tbl] = $res;
    }

    /**
     * Show all columns in table with all data like type
     *
     * @param string $tbl - table name
     * @return array - list
     */
    public static function getFieldsWithAllData($tbl): array
    {
        if (isset(self::$_cached_tbl_fields[$tbl])) {
            return self::$_cached_tbl_fields[$tbl];
        }

        return self::$_cached_tbl_fields = self::q_assoc_id("SHOW FIELDS FROM `$tbl`");
    }

    /**
     * @param string $q query
     *
     * @return array
     */
    public static function q_assoc_id($q): array
    {
        $res = [];
        $qh = is_string($q) ? self::getInstance()->sql_query($q) : $q;

        while ($q = $qh->fetch(PDO::FETCH_ASSOC)) {
            $res[\current($q)] = $q;
        }

        return $res;
    }

    /**
     * Get all rows with data from table
     *
     * @param string $tbl - table name
     * @param string $where - for where clause
     * @return array fetched data
     */
    public static function getRows($tbl, $where = ''): array
    {
        $res = [];
        $sql = self::getInstance()->sql_query('SELECT * FROM `' . self::sql_prepare($tbl) . '`' . ($where ? ' WHERE ' . $where : ''));

        while ($q = $sql->fetch(PDO::FETCH_ASSOC)) {
            $res[] = $q;
        }

        return $res;
    }

    /**
     * retrieve value from associative array
     *
     * @param mixed $str
     * @param bool  $used_in_like
     *
     * @return string
     */
    public static function sql_prepare($str, $used_in_like = false)
    {
        if (!self::getInstance()->pdo_db) {
            self::getInstance()->connect();
        }

        if (is_array($str)) {
            foreach ($str as &$v) {
                $v = self::sql_prepare($v);
            }
        } else {
            $str = substr(self::getInstance()->pdo_db->quote(trim((string)$str)), 1, -1);

            if ($used_in_like) {
                $str = str_replace(['_', '%'], ['\_', '\%'], $str);
            }
        }

        return $str;
    }

    /**
     * Insert a lot of values at once using multiple queries to prevent memory overflow
     *
     * @param string $tbl - table name
     * @param array $rows - rows to be inserted
     * @param array $fields - fields keys and values, name and content
     * @param string $implode
     * @param int $query_max_len
     *
     * @return array
     * @throws InvalidArgumentException
     */
    public static function makeInserts($tbl, $rows, array $fields = [], $implode = ";\n", $query_max_len = 524288): array
    {
        if ($query_max_len > 1048575) {
            throw new InvalidArgumentException('Each query should be shorter than 1MB.');
        }

        if ($fields) {
            foreach ($fields as & $v) {
                $v = '`' . $v . '`';
            }
            unset($v);
            $fields = ' (' . implode(', ', $fields) . ')';
        } else {
            $fields = '';
        }

        $tmp = $res = [];
        $tmp_len = 0;

        foreach ($rows as $line) {
            foreach ($line as & $v) {
                $v = "'" . self::sql_prepare($v) . "'";
            }
            unset($v);

            $q = '(' . implode(', ', $line) . ')';
            $tmp[] = $q;
            $tmp_len += \strlen($q);

            // Make all inserts in multiple queries
            if ($tmp_len >= $query_max_len) {
                $res[] = 'INSERT INTO `' . $tbl . '`' . $fields . ' VALUES ' . implode(', ', $tmp);
                $tmp = [];
                $tmp_len = 0;
            }
        }

        if ($tmp) {
            $res[] = 'INSERT INTO `' . $tbl . '`' . $fields . ' VALUES ' . implode(', ', $tmp);
        }

        return $implode ? implode($implode, $res) : $res;
    }

    /**
     * @param string $tbl
     * @return mixed
     */
    public static function getTableInfo($tbl)
    {
        return self::q_assoc_row('SHOW TABLE STATUS LIKE "' . $tbl . '"');
    }

    /**
     * @param string $q
     * @return array
     */
    public static function q_assoc_row($q)
    {
        $qh = \is_string($q) ? self::getInstance()->sql_query($q) : $q;

        return $qh->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create new empty table with same name and save old with content with unique name
     * Useful saving logs and achieving
     *
     * @param string $tbl
     * @return string
     */
    public static function swapTable($tbl): string
    {
        $tmp_tbl = $tbl . '_' . NOW;
        $new_tbl = $tmp_tbl . '_new';

        self::getInstance()->sql_query(str_replace('`' . $tbl . '`', '`' . $new_tbl . '`', self::getCreateTable($tbl)));
        self::getInstance()->sql_query('RENAME TABLE `' . $tbl . '` TO `' . $tmp_tbl . '`, `' . $new_tbl . '` TO `' . $tbl . '`');

        return $tmp_tbl;
    }

    /**
     * Get create table or create view statement
     *
     * @param string $tbl - table name
     * @return string with full query
     */
    public static function getCreateTable($tbl): string
    {
        if (isset(self::$cached_create_table_statements[$tbl])) {
            return self::$cached_create_table_statements[$tbl];
        }

        $sql = self::getInstance()->sql_query("SHOW CREATE TABLE `$tbl`");
        $q = $sql->fetch(PDO::FETCH_ASSOC);

        $str = '';

        if (isset($q['Create Table'])) {
            $str = strtr($q['Create Table'], ["\r" => '', "\n" => '', "\t" => '']);
        } elseif (isset($q['Create View'])) {
            $str = strtr($q['Create View'], ["\r" => '', "\n" => '', "\t" => '']);
        }

        return self::$cached_create_table_statements[$tbl] = $str;
    }

    /**
     * Check if query uses all possible db table indexes
     *
     * @param string $sql
     * @return bool
     */
    public static function usesAllIndexes($sql): bool
    {
        $qh = self::getInstance()->sql_query('EXPLAIN ' . $sql);

        if (!$qh->rowCount()) {
            return false;
        }

        while ($q = $qh->fetch(PDO::FETCH_ASSOC)) {
            if (!$q['key']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $tbl
     * @param string $comment
     */
    public static function setTableComment($tbl, $comment = '')
    {
        self::getInstance()->sql_query('ALTER TABLE `' . $tbl . '` COMMENT = "' . self::sql_prepare($comment) . '"');
    }

    /**
     * This function saves all table data when using table with form inputs
     *
     * @param string $table
     * @param array $data
     * @return array
     */
    public static function storeEditableCmsTable($table, array $data): array
    {
        $res = ['add' => 0, 'update' => 0, 'delete' => 0];

        // Field to be updated
        if (isset($data['update']) && \is_array($data['update'])) {
            foreach ($data['update'] as $id => $v) {
                $res['update'] += self::update($table, $v, $id);
            }
        }

        // Delete fields
        if (isset($data['delete']) && \is_array($data['delete'])) {
            foreach ($data['delete'] as $v) {
                $res['delete'] += self::getInstance()->delete($table, $v);
            }
        }

        // Same as deleted
        if (isset($data['remove']) && \is_array($data['remove'])) {
            foreach ($data['remove'] as $v) {
                $res['delete'] += self::getInstance()->delete($table, $v);
            }
        }

        // Create new fields
        if (isset($data['add']) && \is_array($data['add'])) {
            foreach ($data['add'] as $v) {
                $res['add'] += self::add($table, $v);
            }
        }

        return $res;
    }

    /**
     * @param string $tbl
     * @param array $data
     * @param int $id
     * @param string $id_col
     * @param bool $low_priority
     * @return int updated rows
     */
    public static function update($tbl, array $data, $id, $id_col = 'id', $low_priority = false): int
    {
        if (!$data || !$id) {
            return 0;
        }

        foreach ($data as $k => &$v) {
            $v = '`' . $k . '` = "' . self::sql_prepare((string)$v) . '"';
        }

        $id = (array)$id;

        foreach ($id as &$v) {
            $v = self::sql_prepare($v);
        }

        return self::getInstance()->sql_query('UPDATE' . ($low_priority ? ' LOW_PRIORITY' : '') . ' `' . $tbl . '` SET ' . implode(', ', $data) . ' WHERE `' . $id_col . '` IN ("' . implode('", "', $id) . '")')->rowCount();
    }

    /**
     * @param string $tbl
     * @param int|array $id
     * @param string $id_col
     * @param bool $look_for_translations auto-delete all linked Translations, when column is commented with "translation"
     * @return int deleted count
     */
    public function delete($tbl, $id = [], $id_col = 'id', $look_for_translations = true): int
    {
        $id = (array)$id;
        if (!$id) {
            return 0;
        }

        // Find translations in deleted rows
        if ($look_for_translations) {
            $translation_columns = [];

            foreach (self::getColumnsComments($tbl) as $v) {
                if (strtolower($v['COLUMN_COMMENT']) === strtolower(TableStructure::FIELD_TYPE_TRANSLATION)) {
                    $translation_columns[] = $v['COLUMN_NAME'];
                }
            }

            // Get translation's IDs to be deleted
            $translation_ids = [];
            if ($translation_columns) {
                foreach (self::q_assoc_iterator('SELECT `' . implode('`, `', $translation_columns) . '` FROM `' . $tbl . '` WHERE `' . $id_col . '` IN ("' . implode('", "', $id) . '")') as $v) {
                    foreach ($translation_columns as $c) {
                        $translation_ids[] = $v[$c];
                    }
                }
            }

            // Founds ids - delete them
            if ($translation_ids) {
                $this->delete('cms_translations', $translation_ids);
            }
        }

        // Delete values as usual
        foreach ($id as &$v) {
            $v = self::sql_prepare($v);
        }

        return self::getInstance()->sql_query('DELETE FROM `' . $tbl . '` WHERE `' . $id_col . '` IN ("' . implode('", "', $id) . '")')->rowCount();
    }

    /**
     * @param $table
     * @param string $column
     *
     * @return array
     */
    public static function getColumnsComments($table, $column = ''): array
    {
        return self::q_assoc('SELECT
COLUMN_COMMENT, COLUMN_NAME
FROM
    INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = "' . Configuration::getInstance()->get('db')['name'] . '"
AND TABLE_NAME = "' . self::sql_prepare($table) . '"
' . ($column ? 'AND COLUMN_NAME = "' . self::sql_prepare($column) . '"' : '') . ''
        );
    }

    /**
     * Return pointer to iterate array, same as in q_assoc
     * This function consumes much less memory that q_assoc, because uses yield iterator
     * @param string $q
     *
     * @return Iterator
     */
    public static function q_assoc_iterator($q)
    {
        $qh = self::getInstance()->sql_query($q);

        while ($record = $qh->fetch(PDO::FETCH_ASSOC)) {
            yield $record;
        }
    }

    /**
     * @param string $tbl
     * @param array  $data
     * @param bool   $return_id
     * @param bool   $update_on_duplicate
     * @param bool   $low_priority
     * @param bool   $delayed
     *
     * @return int inserts count
     */
    public static function add($tbl, array $data, $return_id = false, $update_on_duplicate = false, $low_priority = false, $delayed = false): int
    {
        if (!$data) {
            return 0;
        }

        foreach ($data as $k => &$v) {
            $v = '`' . $k . '` = "' . self::sql_prepare((string)$v) . '"';
        }

        $sql = 'INSERT' . ($low_priority ? ' LOW_PRIORITY' : '') . ($delayed ? ' DELAYED' : '') . ' INTO `' . $tbl . '` SET ' . implode(', ', $data);

        if ($update_on_duplicate) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $data);
        }

        if ($return_id) {
            return (int)self::getInstance()->sql_query($sql, true);
        }

        return self::getInstance()->sql_query($sql)->rowCount();
    }

    /**
     * @param string $table
     * @return array
     */
    public static function getTableColumns($table): array
    {
        return self::q_assoc('SHOW COLUMNS FROM `' . self::sql_prepare($table) . '`');
    }

    /**
     * Return assoc array of all entries, e.g. [['id' => 10, 'name' => 'John'], ['id' => 22, 'name' => 'Doe'], ...]
     * @param string $q
     *
     * @return array
     */
    public static function q_assoc($q): array
    {
        $res = [];
        $qh = self::getInstance()->sql_query($q);

        /** @noinspection PhpAssignmentInConditionInspection */
        /** @noinspection PhpStatementHasEmptyBodyInspection */
        while ($res[] = $qh->fetch(PDO::FETCH_ASSOC)) {
            // Do nothing
        }

        \array_pop($res);

        return $res;
    }

    /**
     * @return string
     */
    public static function selectFoundRows(): string
    {
        return self::getInstance()->pdo_db->query('SELECT FOUND_ROWS()')->fetchColumn();
    }

    /**
     * Change active status (enable / disable)
     *
     * @param int $id - row id
     * @param string $tbl - table name
     * @param string $idFld - id field name ('id' by default)
     * @param string $activeFld - activation status field name ('active' by default)
     * @return bool current status
     */
    public static function active($id, $tbl, $idFld = 'id', $activeFld = 'active'): bool
    {
        self::getInstance()->sql_query('UPDATE `' . $tbl . '` SET `' . $activeFld . '` = IF(`' . $activeFld . '`=1, 0, 1) WHERE `' . $idFld . '` = "' . $id . '"');

        return (bool)self::q_value('SELECT `' . $activeFld . '` FROM `' . $tbl . '` WHERE `' . $idFld . '` = "' . $id . '"');
    }

    /**
     * @param string $q
     * @return mixed
     */
    public static function q_value($q)
    {
        $qh = \is_string($q) ? self::getInstance()->sql_query($q) : $q;

        return $qh->fetchColumn();
    }

    /**
     * get next item by order. If $catFld and $catValue are set - get next item by order in selected category
     *
     * @param string $table - table name
     * @param string $field - order field name ('order' by default)
     * @param string $category_field_name - category field name ('' by default)
     * @param int $category_id - category id ('' by default)
     *
     * @return int order value
     */
    public static function getNextOrder($table, $field = 'order', $category_field_name = '', $category_id = 0): int
    {
        return (int)self::q_value('SELECT `' . $field . '` FROM `' . $table . '`' . ($category_field_name ? ' WHERE `' . $category_field_name . '` = "' . self::sql_prepare((string)$category_id) . '"' : '') . ' ORDER BY `' . $field . '` DESC LIMIT 1') + 1;
    }

    /**
     * Change list order by moving selected item up (default $direction value) or down (any other $direction value)
     *
     * @param int $id1 - row id that should be moved
     * @param string $tbl - table name
     * @param string $direction - direction ('up' by default or any other)
     * @param string $idFld - id field name ('id' by default)
     * @param string $orderFld - order field name ('order' by default)
     * @return bool true
     */
    public static function order($id1, $tbl, $direction = 'up', $idFld = 'id', $orderFld = 'order'): bool
    {
        $data = [];
        $ord1 = '';

        $sql = self::getInstance()->sql_query('SELECT `' . $orderFld . '` AS `order`, ' . (\strpos($idFld, '`') === false ? '`' . $idFld . '`' : $idFld) . ' AS `id` FROM `' . $tbl . '` ORDER BY `' . $orderFld . '`');

        while ($q = $sql->fetch(PDO::FETCH_ASSOC)) {
            if ($q['id'] == $id1) { // For "Direct Exchange"
                $ord1 = $q['order'];
            }
            $data[$q['id']] = $q['order']; // For "Exchange"
        }

        $SO = \count($data);
        if ($ord1 === '') {
            return false;
        }

        $res = 0;
        for ($i = 0; $i < $SO; ++$i) {
            $res += $i;
        }

        if ($res === array_sum($data)) { // Direct Exchange
            $sql2 = self::q_assoc_row('SELECT ' . (\strpos($idFld, '`') === false ? '`' . $idFld . '`' : $idFld) . ' AS `id`, `' . $orderFld . '` AS `order` FROM `' . $tbl . '` WHERE `' . $orderFld . '`' . ($direction === 'up' ? '<' : '>') . $ord1 . ' ORDER BY `'. $orderFld .'`' . ($direction === 'up' ? ' DESC' : '') . ' LIMIT 1');
            if (!$sql2) {
                return false;
            }

            list($id2, $ord2) = [$sql2['id'], $sql2['order']];
            self::getInstance()->sql_query('UPDATE `' . $tbl . '` SET `' . $orderFld . '` = ' . $ord2 . ' WHERE ' . (\strpos($idFld, '`') === false ? '`' . $idFld . '`' : $idFld) . '="' . $id1 . '" LIMIT 1');
            self::getInstance()->sql_query('UPDATE `' . $tbl . '` SET `' . $orderFld . '` = ' . $ord1 . ' WHERE ' . (\strpos($idFld, '`') === false ? '`' . $idFld . '`' : $idFld) . '="' . $id2 . '" LIMIT 1');

        } else { // Exchange and then "Direct Exchange"
            $i = 0;

            foreach ($data as $k => $v) {
                self::getInstance()->sql_query('UPDATE `' . $tbl . '` SET `' . $orderFld . '` = "' . $i . '" WHERE `' . $idFld . '` = "' . $k . '" LIMIT 1');
                ++$i;
            }

            self::order($id1, $tbl, $direction, $idFld, $orderFld);
        }

        return true;
    }

    /**
     * @param int $id
     * @param string $table
     * @param string $direction
     * @param int $step
     * @param string $id_field
     * @param string $order_field
     */
    public static function orderMoveByStep($id, $table, $direction = 'up', $step = 1, $id_field = 'id', $order_field = 'order')
    {
        $sql = self::getInstance()->sql_query('SELECT `' . $order_field . '` AS `order`, ' . (\strpos($id_field, '`') === false ? '`' . $id_field . '`' : $id_field) . ' AS `id` FROM `' . $table . '` ORDER BY `' . $order_field . '`');
        $data = [];

        while ($fetch = $sql->fetch(PDO::FETCH_ASSOC)) {
            $data[$fetch['id']] = $fetch['order'];
        }

        $old_order = $data[$id];
        $new_order = $direction === 'down' ? $old_order + $step : $old_order - $step;

        foreach ($data as $id_key => $order_value) {
            if ($direction === 'down' && $order_value <= $new_order && $order_value >= $old_order) {
                self::getInstance()->sql_query('UPDATE `' . $table . '` SET `' . $order_field . '` = "' . ($order_value - 1) . '" WHERE `' . $id_field . '` = "' . $id_key . '" LIMIT 1');
            } elseif ($direction === 'up' && $order_value >= $new_order && $order_value <= $old_order) {
                self::getInstance()->sql_query('UPDATE `' . $table . '` SET `' . $order_field . '` = "' . ($order_value + 1) . '" WHERE `' . $id_field . '` = "' . $id_key . '" LIMIT 1');
            }
        }

        self::getInstance()->sql_query('UPDATE `' . $table . '` SET `' . $order_field . '` = "' . $new_order . '" WHERE `' . $id_field . '` = "' . $id . '" LIMIT 1');
    }

    /**
     * Change list order in selected category $catID by moving selected item up (default $direction value) or down (any other $direction value)
     *
     * @param int $id1 - row id that should be moved
     * @param string $tbl - table name
     * @param int | array $catID - category id
     * @param string | array $catFld
     * @param string $direction - direction ('up' by default or any other)
     * @param string $idFld - id field name ('id' by default)
     * @param string $orderFld - order field name ('order' by default)
     * @return bool true
     */
    public static function orderCat($id1, $tbl, $catID, $catFld, $direction = 'up', $idFld = 'id', $orderFld = 'order', $start_with = 0): bool
    {
        $data = [];
        $ord1 = '';

        $cat_cond = [];
        if(\is_array($catID)){
            foreach($catID as $idx => $c_id){
                $cat_cond[] = '`' . $catFld[$idx] . '` = "' . $c_id . '"';
            }
        }else{
            $cat_cond[] = '`' . $catFld . '` = "' . $catID . '"';
        }
        $cat_cond = implode(' AND ', $cat_cond);

        $sql = self::getInstance()->sql_query('SELECT `' . $orderFld . '` AS `order`, ' . (\strpos($idFld, '`') === false ? '`' . $idFld . '`' : $idFld) . ' AS `id` FROM `' . $tbl . '` WHERE ' . $cat_cond . ' ORDER BY `' . $orderFld . '`');

        while ($q = $sql->fetch(PDO::FETCH_ASSOC)) {
            if ($q['id'] == $id1) {
                $ord1 = $q['order'];
            } // For "Direct Exchange"

            $data[$q['id']] = $q['order']; // For "Exchange"
        }

        $SO = \count($data);
        if ($ord1 === '') {
            return false;
        }

        $res = 0;
        for ($i = 0; $i < $SO; ++$i) {
            $res += $i;
        }

        if ($res === array_sum($data)) { // Direct Exchange
            $sql2 = q('SELECT ' . (\strpos($idFld, '`') === false ? '`' . $idFld . '`' : $idFld) . ', `' . $orderFld . '` FROM `' . $tbl . '` WHERE `' . $orderFld . '`' . ($direction === 'up' ? '<' : '>') . $ord1 . ' AND ' . $cat_cond . ' ORDER BY `'. $orderFld .'`' . ($direction === 'up' ? ' DESC' : null) . ' LIMIT 1');
            if (!$sql2->columnCount()) {
                return false;
            }

            list($id2, $ord2) = $sql2->fetch();

            if (!$id2) {
                $id2 = 0;
            }
            if (!$ord2) {
                $ord2 = $start_with;
            }

            self::getInstance()->sql_query('UPDATE `' . $tbl . '` SET `' . $orderFld . '` = ' . $ord2 . ' WHERE ' . (\strpos($idFld, '`') === false ? '`' . $idFld . '`' : $idFld) . '="' . $id1 . '" AND ' . $cat_cond . ' LIMIT 1');
            self::getInstance()->sql_query('UPDATE `' . $tbl . '` SET `' . $orderFld . '` = ' . $ord1 . ' WHERE ' . (\strpos($idFld, '`') === false ? '`' . $idFld . '`' : $idFld) . '="' . $id2 . '" AND ' . $cat_cond . ' LIMIT 1');
        } else { // Exchange and then "Direct Exchange"
            $i = $start_with;

            foreach (array_keys($data) as $k) {
                self::getInstance()->sql_query('UPDATE `' . $tbl . '` SET `' . $orderFld . '` = ' . $i . ' WHERE ' . (\strpos($idFld, '`') === false ? '`' . $idFld . '`' : $idFld) . '="' . $k . '" AND ' . $cat_cond . ' LIMIT 1');
                ++$i;
            }

            self::orderCat($id1, $tbl, $catID, $catFld, $direction, $idFld, $orderFld);
        }
        return true;
    }

    /**
     * Begin DB Transaction
     */
    public static function startTransaction()
    {
        if (!self::isTransactionActive()) {
            self::getInstance()->pdo_db->beginTransaction();
        }
    }

    /**
     * Check whether now in transaction
     */
    public static function isTransactionActive()
    {
        return self::getInstance()->pdo_db && self::getInstance()->pdo_db->inTransaction();
    }

    /**
     * Accept,confirm and close DB Transaction
     */
    public static function confirmTransaction()
    {
        if (self::isTransactionActive()) {
            self::getInstance()->pdo_db->commit();
        }
    }

    /**
     * Cancel DB Transaction
     */
    public static function cancelTransaction()
    {
        self::getInstance()->pdo_db->rollBack();
    }

    /**
     * Get values available for column in table
     * @param string $table
     * @param string $column
     * @return array
     */
    public static function getEnumPairs($table, $column): array
    {
        $result = self::q_assoc_row('SHOW COLUMNS FROM `' . self::sql_prepare($table) . '` WHERE `field` = "' . self::sql_prepare($column) . '"');
        $result = str_replace(["enum('", "')", "''"], ['', '', "'"], $result['Type']);
        $result = explode("','", $result);

        $res = [];

        foreach ($result as $v) {
            $res[$v] = Converter::charsToNormalTitle($v);
        }

        return $res;
    }

    /**
     * @return array
     */
    public static function getLastError(): array
    {
        return self::getInstance()->pdo_db->errorInfo();
    }

    /**
     * Should be non-static because we can have more than one connection
     * Stop current connection
     */
    public function disconnect()
    {
        $this->pdo_db = NULL;
    }

    /**
     * @return PDO | null
     */
    public function getConnectionHandler()
    {
        return $this->pdo_db;
    }

    /**
     * @return string
     */
    public function getServerInfo(): string
    {
        if (!$this->pdo_db) {
            $this->connect();
        }

        return $this->pdo_db->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    /**
     * Create ID field for table - required for Entity management
     *
     * @param string $table
     * @return bool
     */
    public function addPrimaryAutoIncrementIdFieldToTable($table): bool
    {
        // Drop primary key - suspend error because we can have no primary index
        @$this->sql_query('ALTER IGNORE TABLE `' . $table . '` DROP PRIMARY KEY');
        // Create field and primary index
        $this->sql_query('ALTER IGNORE TABLE  `' . $table . '` ADD  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');

        return true;
    }
}
