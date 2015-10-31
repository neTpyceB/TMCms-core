<?php

namespace neTpyceB\TMCms\Orm;

use neTpyceB\TMCms\Cache\Cacher;
use neTpyceB\TMCms\DB\SQL;
use neTpyceB\TMCms\Files\FileSystem;
use neTpyceB\TMCms\Strings\Converter;

class EntityRepository {
    protected $db_table = ''; // Should be overwritten in extended class
    protected $translation_fields = []; // Should be overwritten in extended class

    private static $_cache_key_prefix = 'orm_entity_repository_';

    private $sql_where_fields = [];
    private $sql_select_fields = [];
    private $sql_offset = 0;
    private $sql_limit = 0;
    private $order_fields = [];
    private $order_random = false;
    private $sql_group_by = [];
    private $sql_having = [];
    private $translation_joins = [];

    private $use_iterator = true;
    private $collected_objects = [];
    private $collected_objects_data = [];

    private $total_count_rows;
    private $require_to_count_total_rows = false;

    protected $debug = false;
    private $use_cache = false;
    private $cache_ttl = 60;

    private $join_tables = [];
    private $last_used_sql;

    public function __construct($ids = []) {
        if ($ids) {
            $this->setIds($ids);
        }

        return $this;
    }

    public function deleteObjectCollection() {
        $this->collectObjects();

        // Call delete on every object
        foreach ($this->getCollectedObjects() as $v) {
            /** @var Entity $v */
            $v->deleteObject();
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getDbTableFields()
    {
        return SQL::getFields($this->getDbTableName());
    }

    /**
     * @param bool $skip_objects_creation - true if no need to create objects
     * @param bool $skip_changed_fields - skip update of changed fields
     * @return $this
     */
    protected function collectObjects($skip_objects_creation = false, $skip_changed_fields = false)
    {
        $sql = $this->getSelectSql();
        if ($sql === $this->last_used_sql) {
            // Skip queries - nothing changed
            return $this;
        }
        $this->last_used_sql = $sql;

        // Check cache for this exact collection
        if ($this->use_cache) {
            //Check cached values, set local properties
            $data = Cacher::getInstance()->getDefaultCacher()->get($this->getCacheKey($sql));
            if ($data && is_array($data) && isset($data['collection_arrays'], $data['collection_objects'])) {
                // Set local data
                $this->collected_objects_data = $data['collected_objects_data'];
                $this->collected_objects = $data['collected_objects'];

                // No further actions
                return $this;
            }
        }

            // Use Iterator in DB query
        if ($this->use_iterator) {
            $this->collected_objects_data = SQL::q_assoc_iterator($sql, false);
        } else {
            $this->collected_objects_data = SQL::q_assoc($sql, false);
        }

        if ($this->require_to_count_total_rows) {
            $this->total_count_rows = q_value('SELECT FOUND_ROWS();');
        }

        $this->collected_objects = []; // Reset objects

        if (!$skip_objects_creation) {
            // Need to create objects from array data
            foreach ($this->collected_objects_data as $v) {
                $class = $this->getObjectClass();
                /** @var Entity $obj */
                $obj = new $class();

                if (!isset($v['id'])) {
                    trigger_error('No ID field found for ' . get_class($this));
                }

                // Prevent auto-query db, skip tables with no id field
                $id = $v['id'];
                unset($v['id']);

                // Set object data
                $obj->loadDataFromArray($v, $skip_changed_fields);

                // Set current ID
                $obj->setId($id, false);

                // Save in returning array ob objects
                $this->collected_objects[] = $obj;
            }
        }

        if ($this->use_cache) {
            // Save all collected data to Cache
            $data = [
                'collected_objects_data' => $this->collected_objects_data,
                'collected_objects' => $this->collected_objects
            ];
            Cacher::getInstance()->getDefaultCacher()->set($this->getCacheKey($sql), $data, $this->cache_ttl);
        }

        return $this;
    }

    protected function getCollectedObjects()
    {
        return $this->collected_objects;
    }

    /**
     * Set collected objects in Repository - may be useful in mass-updates
     * @param array $objects
     * @return $this
     */
    public function setCollectedObjects(array $objects)
    {
        $this->collected_objects = $objects;

        return $this;
    }

    protected function getCollectedData()
    {
        return $this->collected_objects_data;
    }

    /**
     * @param array $ids
     * @return $this
     */
    public function setIds(array $ids)
    {
        $this->setFilterValueWhereIn('id', $ids);

        return $this;
    }

    public function getIds() {
        $ids = [];

        foreach ($this->getAsArrayOfObjectData() as $v) {
            $ids[] = $v['id'];
        }

        return $ids;
    }

    public function getSumOfOneField($field) {
        $sum = 0;

        foreach ($this->getAsArrayOfObjectData() as $v) {
            $sum += $v[$field];
        }

        return $sum;
    }

    public function addGroupBy($field, $table = '') {
        // No table provided
        if (!$table) {
            $table = $this->getDbTableName();
        }

        $this->sql_group_by[] = [
            'table' => $table,
            'field' => $field
        ];

        return $this;
    }

    public function addHaving($field, $value) {
        $this->sql_having[] = [
            'field' => $field,
            'value' => $value
        ];
    }

    public function flipBoolValue($field) {
        if (!$this->getCollectedObjects()) {
            $this->collectObjects(false, true);
        }

        foreach ($this->getCollectedObjects() as $object) {
            /** @var Entity $object */
            $object->flipBoolValue($field);
        }

        return $this;
    }

    public function save()
    {
        if (!$this->getCollectedObjects()) {
            $this->collectObjects(false, true);
        }

        foreach ($this->getCollectedObjects() as $object) {
            /** @var Entity $object */
            $object->save();
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function hasAnyObjectInCollection()
    {
        $this->collectObjects(true);

        $obj = $this->getFirstObjectFromCollection();

        return (bool)$obj;
    }

    /**
     * @param int $count
     * @return bool
     */
    public function hasExactCountOfObjects($count)
    {
        $this->collectObjects(true);

        return (bool)((int)$this->getCountOfObjectsInCollection() === (int)$count);
    }

    /**
     * @return bool
     */
    public function getCountOfObjectsInCollection()
    {
        $this->collectObjects(true);

        return count($this->collected_objects_data);
    }

    /**
     * @return bool
     */
    public function getCountOfMaxPossibleFoundObjectsWithoutFilters()
    {
        return (int)q_value($this->getSelectSql(true));
    }

    /**
     * @return Entity
     */
    public function getFirstObjectFromCollection()
    {
        $limit_tmp = $this->sql_limit;
        $this->setLimit(1);

        foreach ($this->getAsArrayOfObjectData() as $obj_data) {
            $this->setLimit($limit_tmp);

            $class = $this->getObjectClass();
            /** @var Entity $obj */
            $obj = new $class();
            $obj->loadDataFromArray($obj_data, true);

            return $obj;
        }

        $this->setLimit($limit_tmp);
        return NULL;
    }

    /**
     * @return Entity
     */
    public function getLastObjectFromCollection()
    {
        $objects = $this->getAsArrayOfObjects();
        if ($objects) {
            return array_pop($objects);
        }

        return NULL;
    }

    public function getAsArrayOfObjects()
    {
        $this->collectObjects();

        return $this->getCollectedObjects();
    }

    /**
     * @param bool $non_iterator - do not use Iterator, may be usefull for dumping output
     * @return array
     */
    public function getAsArrayOfObjectData($non_iterator = false)
    {
        if ($non_iterator) {
            $this->setGenerateOutputWithIterator(false);
        }

        $this->collectObjects(true);

        return $this->getCollectedData();
    }

    /**
     * @param string $value_field
     * @param string $key_field
     * @return array
     */
    public function getPairs($value_field, $key_field = '')
    {
        if (!$key_field) {
            $key_field = 'id';
        }

        $this->collectObjects();

        $pairs = [];
        foreach ($this->getAsArrayOfObjects() as $v) {
            /** @var Entity $v */
            $v->loadDataFromDB();

            $key_method = 'get' . ucfirst($key_field);
            $value_method = 'get' . ucfirst($value_field);
            $pairs[$v->{$key_method}()] = $v->{$value_method}();
        }

        return $pairs;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setLimit($limit) {
        $this->sql_limit = (int)$limit;

        return $this;
    }

    /**
     * @param int $offset
     * @return $this
     */
    public function setOffset($offset) {
        $this->sql_offset = (int)$offset;

        return $this;
    }

    /**
     * @param string $field
     * @param string $direction
     * @param string $table
     * @param bool $do_not_use_table_in_sql required in some conditions with temp fields
     * @return $this
     */
    public function addOrderByField($field, $direction = 'ASC', $table = '', $do_not_use_table_in_sql = false) {
        // No table provided
        if (!$table) {
            $table = $this->getDbTableName();
        }

        $this->order_fields[] = [
            'table' => $table,
            'field' => $field,
            'direction' => $direction,
            'do_not_use_table_in_sql' => $do_not_use_table_in_sql
        ];

        return $this;
    }

    /**
     * @param bool
     * @return $this
     */
    public function setOrderByRandom($flag) {
        $this->order_random = $flag;

        return $this;
    }

    public function clearCollectionCache() {
        $this->last_used_sql = '';
        $this->collectObjects();

        return $this;
    }

    public function addSimpleSelectFields(array $fields, $table = false) {
        if (!$table) {
            $table = $this->getDbTableName();
        }

        foreach ($fields as $k => $field) {
            $this->sql_select_fields[] = [
                'table' => $table,
                'field' => $field,
                'as' => false,
                'type' => 'simple'
            ];
        }

        return $this;
    }

    public function addSelectFieldAsAlias($field, $alias, $table = false) {
        if (!$table) {
            $table = $this->getDbTableName();
        }

        $this->sql_select_fields[] = [
            'table' => $table,
            'field' => $field,
            'as' => $alias,
            'type' => 'simple'
        ];

        return $this;
    }

    public function addSelectFieldAsString($sql) {
        $this->sql_select_fields[] = [
            'table' => false,
            'field' => $sql,
            'as' => false,
            'type' => 'string'
        ];

        return $this;
    }

    public function getSelectFields() {
        return $this->sql_select_fields;
    }

    public function getTotalSelectedRowsWithoutLimit() {
        return $this->total_count_rows;
    }

    public function setRequireCountRowsWithoutLimits($flag) {
        return $this->require_to_count_total_rows = (bool)$flag;
    }

    public function getSelectSql($for_max_object_count = false)
    {
        // Select
        if ($this->sql_select_fields) {
            $select_sql = dump($this->sql_select_fields);
        } else {
            $select_sql = '`'. $this->getDbTableName() .'`.*';
        }

        // Where
        $where_sql = $this->getWhereSql();
        $where_sql = $where_sql ? 'WHERE ' . $where_sql : '';

        // Having
        $having_sql = $this->getHavingSql();
        $having_sql = $having_sql ? 'HAVING ' . $having_sql : '';

        // Order by
        $order_by_sql = $this->getOrderBySQL();

        // Limit
        $limit_sql = $this->sql_limit ? 'LIMIT ' . $this->sql_offset . ', ' . $this->sql_limit : '';

        // Group by
        $group_sql = ($this->sql_group_by ? 'GROUP BY `'. implode('``, `', $this->sql_group_by) . '`' : '');

        // Joins
        $join_sql = $this->getJoinTablesSql();

        // Translations
        $translation_sql = implode(', ', $this->translation_joins);

        // Counting total
        if ($for_max_object_count) {
            $select_sql = 'COUNT(*)';
            $translation_sql = '';
            $where_sql = '';
            $having_sql = '';
            $order_by_sql = '';
            $limit_sql = '';
        }

        $sql_calc_found_rows = $this->require_to_count_total_rows ? ' SQL_CALC_FOUND_ROWS ' : '';

        return '
SELECT '. $sql_calc_found_rows . $select_sql .'
FROM `'. $this->getDbTableName() .'`
'. $translation_sql .'
'. $join_sql .'
'. $where_sql .'
'. $group_sql .'
'. $having_sql .'
'. $order_by_sql .'
'. $limit_sql .'
    ';
    }

    /**
     * @return string SQL string
     */
    private function getOrderBySQL() {
        if ($this->order_random) {
            return ' ORDER BY RAND()';
        }

        $order_by = [];
        foreach ($this->getOrderFields() as $field_data) {
            if (!$field_data['do_not_use_table_in_sql']) {
                $order_by[] = '`'. $field_data['table'] .'`.`'. $field_data['field'] .'` '. $field_data['direction'];
            } else {
                $order_by[] = '`'. $field_data['field'] .'` '. $field_data['direction'];
            }
        }

        if ($order_by) {
            return ' ORDER BY ' . implode(', ', $order_by);
        }

        return '';
    }

    /**
     * @param mixed $data
     * @param int $serialize
     * @param int $clean
     */
    protected function debug($data, $serialize = 0, $clean = 1)
    {
        if (!$this->debug) return;

        dump($data, $serialize, $clean);
    }

    public function enableDebug()
    {
        $this->debug = true;
    }

    private function getObjectClass() {
        // Create object for entity
        $obj_class = substr(get_class($this), 0, -10); // Remove string "Collection" from name

        return $obj_class;
    }

    /**
     * @param $name
     * @param $args
     * @return string
     */
    public function __call($name, $args) {
        $param = substr($name, 8);  // Cut "setWhere"
        $param = Converter::from_camel_case($param);

        // Emulate setWhereSmth($k, $v);
        return $this->setFilterValue($param, $args[0]);
    }

    public function mergeCollectionSqlSelectWithAnotherCollection(EntityRepository $collection) {
        dump('TODO all fields');

        return $collection;
    }

    public function addJoinTable($table, $on_left, $on_right, $type = '') {
        if (!is_string($table)) {
            /** @var EntityRepository $table */
            $table = $table->getDbTableName();
        }
        $this->join_tables[] = [
            'table' => $table,
            'left' => $on_left,
            'right' => $on_right,
            'type' => $type
        ];

        return $this;
    }

    public function getJoinTablesSql() {
        $sql = [];
        foreach ($this->join_tables as $table) {
            $sql[] = $table['type'] .' JOIN `'. $table['table'] .'` ON (`'. $table['table'] .'`.`'. $table['left'] .'` = '. $this->getDbTableName() .'.`' . $table['right'] . '`)';
        }

        return implode(' ', $sql);
    }

    /**
     * Return name in class or try to get from class name
     * @return string
     */
    public function getDbTableName() {
        // Name set in class
        if ($this->db_table) {
            return $this->db_table;
        }

        // Check tables in DB
        $this->db_table = 'cms_' . mb_strtolower(str_replace('Collection', '', Converter::classWithNamespaceToUnqualifiedShort($this))) . 's';
        if (!SQL::tableExists($this->db_table)) {
            $this->db_table = 'm_' . mb_strtolower(str_replace('Collection', '', Converter::classWithNamespaceToUnqualifiedShort($this))) . 's';
        }

        return $this->db_table;
    }

    /**
     * @param string $db_table
     * @return $this
     */
    public function setDbTableName($db_table) {
        $this->db_table = $db_table;

        return $this;
    }

    /**
     * @param bool $flag
     * @return $this
     */
    public function setGenerateOutputWithIterator($flag) {
        $this->use_iterator = $flag;

        return $this;
    }

    /**
     * @param string $hash_string
     * @return string
     */
    private function getCacheKey($hash_string = '') {
        // Cache key = prefix + class name + unique session id (not obligate) + current created sql query
        return self::$_cache_key_prefix . md5(str_replace('\\', '_', get_class($this)) . '_' . $hash_string);
    }

    /**
     * @param int $ttl
     * @return $this
     */
    public function enableUsingCache($ttl = 600)
    {
        // Disable iterator because we need to save full array data
        $this->setGenerateOutputWithIterator(false);
        $this->cache_ttl = $ttl;
        $this->use_cache = true;

        return $this;
    }


    /* STATIC ALIASES */

    /**
     * Return one Entity by array of criteria
     * @param array $criteria
     * @return Entity
     */
    public static function findOneEntityByCriteria(array $criteria) {
        $class = static::class;

        /** @var EntityRepository $obj_collection */
        $obj_collection = new $class();
        foreach ($criteria as $k => $v) {
            $method = 'setWhere' . Converter::to_camel_case($k);
            $obj_collection->{$method}($v);
        }
        return $obj_collection->getFirstObjectFromCollection();
    }

    /**
     * Create one Entity by id
     * @param int $id
     * @return Entity
     */
    public static function findOneEntityById($id) {
        return self::findOneEntityByCriteria(['id' => $id]);
    }

    /**
     * @return array
     */
    private function getWhereFields()
    {
        return $this->sql_where_fields;
    }

    /**
     * @return string
     */
    private function getWhereSql()
    {
        $res = [];
        foreach ($this->getWhereFields() as $field_data) {
            $res[] = '`'. $field_data['table'] .'`.`'. $field_data['field'] .'`';
        }
    }

    /**
     * @return array
     */
    private function getOrderFields()
    {
        return $this->order_fields;
    }

    /**
     * @param bool $download_as_file
     * @return string
     */
    public function exportAsSerializedData($download_as_file = false)
    {
        if (!$this->getCollectedObjects()) {
            $this->collectObjects(false, true);
        }

        $objects = [];
        $object = NULL;
        foreach ($this->getCollectedObjects() as $object) {
            /** @var Entity $object */
            $objects[] = $object;
        }

        if (!$objects) {
            error('No Objects selected');
        }

        $data = [];
        $data['objects'] = serialize($objects);
        $data['class'] = Converter::getPathToClassFile($object);

        $data = serialize($data);

        if (!$download_as_file) {
            return $data;
        }

        FileSystem::streamOutput(Converter::classWithNamespaceToUnqualifiedShort($object) . '.cms_obj', $data);

        return $data;
    }

    /**
     * @param EntityRepository $collection
     * @param string $join_on_key in current collection to join another collection on ID
     * @param string $join_index - main index foreign key
     * @param string $join_type INNER|LEFT
     */
    public function mergeWithCollection(EntityRepository $collection, $join_on_key, $join_index = 'id', $join_type = 'INNER')
    {
        $this->mergeCollectionSqlSelectWithAnotherCollection($collection);
        $this->addJoinTable($collection->getDbTableName(), $join_index, $join_on_key, $join_type);
    }

    private function getHavingSql()
    {
        $res = [];
        foreach ($this->sql_having as $having) {
            $res[] = '`' . $having['field'] . '` ' . $having['value'];
        }
        return implode(' AND ', $res);
    }

    private function getHavingFields()
    {
        return $this->sql_having;
    }

    /**
     * Filter collection by value inclusive
     * @param $field
     * @param $value
     * @return $this
     */
    public function setFilterValue($field, $value)
    {
        $this->addSimpleWhereField($field, SQL::sql_prepare($value));

        return $this;
    }

    /**
     * @param string $field
     * @param string $value
     * @param string $table
     * @return $this
     */
    protected function addSimpleWhereField($field, $value = '', $table = '') {// No table provided
        if (!$table) {
            $table = $this->getDbTableName();
        }

        $this->sql_where_fields[] = [
            'table' => $table,
            'field' => $field,
            'value' => $value,
            'type' => 'simple'
        ];

        return $this;
    }

    /**
     * Filter collection by value inclusive
     * @param $field
     * @param array $values
     * @param string $table
     * @return $this
     */
    public function setFilterValueWhereIn($field, array $values, $table = '')
    {
        if (!$table) {
            $table = $this->getDbTableName();
        }

        if (!$values) {
            $values = [NULL];
        }
        foreach ($values as $k => & $v) {
            $v = sql_prepare($v);
        }

        $this->addWhereFieldAsString('`'. $table .'`.`'. $field .'` IN ("'. implode('", "', $values) .'")');

        return $this;
    }

    public function addWhereFieldAsString($sql) {
        $this->sql_where_fields[] = [
            'table' => false,
            'field' => false,
            'value' => $sql,
            'type' => 'string'
        ];

        return $this;
    }
}