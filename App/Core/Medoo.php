<?php

/* !
 * Medoo database framework
 * http://medoo.in
 * Version 1.1.3
 *
 * Copyright 2016, Angel Lai
 * Released under the MIT license
 * 
 */

/*
 * edit
 * 
 * author:Sgenmi
 * date :2016-11-29
 * content:单例模式，pdo静态化，运用swoole，形式连接池
 */



namespace App\Core;

class Medoo {

    // Optional
    protected $prefix;
    protected $option = array();
    // Variable
    protected $logs = array();
    protected $debug_mode = false;
    public static $pdo = null;
    private static $_instance = null;

    public static function getInstance() {
        if (self::$_instance === null) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {

        if (self::$pdo == null) {
            $_config = \HttpServer::$db_config;
            $port = 3306;
            if (isset($_config['port']) && is_int($_config['port'] * 1)) {
                $port = $_config['port'];
            }
            $type = strtolower($_config['database_type']);
            $dsn = $type . ':host=' . $_config['server'] . ';port=' . $port . ';dbname=' . $_config['database_name'];
            try {
                self::$pdo = new \PDO($dsn, $_config['username'], $_config['password'], array(\PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",\PDO::ATTR_CASE => \PDO::CASE_NATURAL));
            } catch (\PDOException $e) {
                throw new Exception($e->getMessage());
            }
            $this->prefix = $_config['prefix'];
            $this->debug_mode = $_config['debug_mode'];
        }
        
    }

    public function query($query) {
        if ($this->debug_mode) {
            echo $query;
            return false;
        }
        $this->logs[] = $query;
        return self::$pdo->query($query);
    }

    public function exec($query) {
        if ($this->debug_mode) {
            echo $query;
            $this->debug_mode = false;
            return false;
        }
        $this->logs[] = $query;
        return self::$pdo->exec($query);
    }

    public function quote($string) {
        return self::$pdo->quote($string);
    }

    protected function table_quote($table) {
        return '`' . $this->prefix . $table . '`';
    }

    protected function column_quote($string) {
        preg_match('/(\(JSON\)\s*|^#)?([a-zA-Z0-9_]*)\.([a-zA-Z0-9_]*)/', $string, $column_match);
        if (isset($column_match[2], $column_match[3])) {
            return '"' . $this->prefix . $column_match[2] . '"."' . $column_match[3] . '"';
        }
        return '`' . $string . '`';
    }

    protected function column_push(&$columns) {
        if ($columns == '*') {
            return $columns;
        }
        if (is_string($columns)) {
            $columns = array($columns);
        }
        $stack = array();
        foreach ($columns as $key => $value) {
            if (is_array($value)) {
                $stack[] = $this->column_push($value);
            } else {
                preg_match('/([a-zA-Z0-9_\-\.]*)\s*\(([a-zA-Z0-9_\-]*)\)/i', $value, $match);
                if (isset($match[1], $match[2])) {
                    $stack[] = $this->column_quote($match[1]) . ' AS ' . $this->column_quote($match[2]);
                    $columns[$key] = $match[2];
                } else {
                    $stack[] = $this->column_quote($value);
                }
            }
        }
        return implode($stack, ',');
    }

    protected function array_quote($array) {
        $temp = array();
        foreach ($array as $value) {
            $temp[] = is_int($value) ? $value : self::$pdo->quote($value);
        }
        return implode($temp, ',');
    }

    protected function inner_conjunct($data, $conjunctor, $outer_conjunctor) {
        $haystack = array();
        foreach ($data as $value) {
            $haystack[] = '(' . $this->data_implode($value, $conjunctor) . ')';
        }
        return implode($outer_conjunctor . ' ', $haystack);
    }

    protected function fn_quote($column, $string) {
        return (strpos($column, '#') === 0 && preg_match('/^[A-Z0-9\_]*\([^)]*\)$/', $string)) ?
                $string :
                $this->quote($string);
    }

    protected function data_implode($data, $conjunctor, $outer_conjunctor = null) {
        $wheres = array();
        foreach ($data as $key => $value) {
            $type = gettype($value);
            if (
                    preg_match("/^(AND|OR)(\s+#.*)?$/i", $key, $relation_match) &&
                    $type == 'array'
            ) {
                $wheres[] = 0 !== count(array_diff_key($value, array_keys(array_keys($value)))) ?
                        '(' . $this->data_implode($value, ' ' . $relation_match[1]) . ')' :
                        '(' . $this->inner_conjunct($value, ' ' . $relation_match[1], $conjunctor) . ')';
            } else {
                preg_match('/(#?)([\w\.\-]+)(\[(\>|\>\=|\<|\<\=|\!|\<\>|\>\<|\!?~)\])?/i', $key, $match);
                $column = $this->column_quote($match[2]);
                if (isset($match[4])) {
                    $operator = $match[4];
                    if ($operator == '!') {
                        switch ($type) {
                            case 'NULL':
                                $wheres[] = $column . ' IS NOT NULL';
                                break;
                            case 'array':
                                $wheres[] = $column . ' NOT IN (' . $this->array_quote($value) . ')';
                                break;
                            case 'integer':
                            case 'double':
                                $wheres[] = $column . ' != ' . $value;
                                break;
                            case 'boolean':
                                $wheres[] = $column . ' != ' . ($value ? '1' : '0');
                                break;
                            case 'string':
                                $wheres[] = $column . ' != ' . $this->fn_quote($key, $value);
                                break;
                        }
                    }
                    if ($operator == '<>' || $operator == '><') {
                        if ($type == 'array') {
                            if ($operator == '><') {
                                $column .= ' NOT';
                            }
                            if (is_numeric($value[0]) && is_numeric($value[1])) {
                                $wheres[] = '(' . $column . ' BETWEEN ' . $value[0] . ' AND ' . $value[1] . ')';
                            } else {
                                $wheres[] = '(' . $column . ' BETWEEN ' . $this->quote($value[0]) . ' AND ' . $this->quote($value[1]) . ')';
                            }
                        }
                    }
                    if ($operator == '~' || $operator == '!~') {
                        if ($type != 'array') {
                            $value = array($value);
                        }
                        $like_clauses = array();
                        foreach ($value as $item) {
                            $item = strval($item);
                            if (preg_match('/^(?!(%|\[|_])).+(?<!(%|\]|_))$/', $item)) {
                                $item = '%' . $item . '%';
                            }
                            $like_clauses[] = $column . ($operator === '!~' ? ' NOT' : '') . ' LIKE ' . $this->fn_quote($key, $item);
                        }
                        $wheres[] = implode(' OR ', $like_clauses);
                    }
                    if (in_array($operator, array('>', '>=', '<', '<='))) {
                        $condition = $column . ' ' . $operator . ' ';
                        if (is_numeric($value)) {
                            $condition .= $value;
                        } elseif (strpos($key, '#') === 0) {
                            $condition .= $this->fn_quote($key, $value);
                        } else {
                            $condition .= $this->quote($value);
                        }
                        $wheres[] = $condition;
                    }
                } else {
                    switch ($type) {
                        case 'NULL':
                            $wheres[] = $column . ' IS NULL';
                            break;
                        case 'array':
                            $wheres[] = $column . ' IN (' . $this->array_quote($value) . ')';
                            break;
                        case 'integer':
                        case 'double':
                            $wheres[] = $column . ' = ' . $value;
                            break;
                        case 'boolean':
                            $wheres[] = $column . ' = ' . ($value ? '1' : '0');
                            break;
                        case 'string':
                            $wheres[] = $column . ' = ' . $this->fn_quote($key, $value);
                            break;
                    }
                }
            }
        }
        return implode($conjunctor . ' ', $wheres);
    }

    protected function where_clause($where) {
        $where_clause = '';
        if (is_array($where)) {
            $where_keys = array_keys($where);
            $where_AND = preg_grep("/^AND\s*#?$/i", $where_keys);
            $where_OR = preg_grep("/^OR\s*#?$/i", $where_keys);
            $single_condition = array_diff_key($where, array_flip(
                            array('AND', 'OR', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'LIKE', 'MATCH')
            ));
            if ($single_condition != array()) {
                $condition = $this->data_implode($single_condition, '');
                if ($condition != '') {
                    $where_clause = ' WHERE ' . $condition;
                }
            }
            if (!empty($where_AND)) {
                $value = array_values($where_AND);
                $where_clause = ' WHERE ' . $this->data_implode($where[$value[0]], ' AND');
            }
            if (!empty($where_OR)) {
                $value = array_values($where_OR);
                $where_clause = ' WHERE ' . $this->data_implode($where[$value[0]], ' OR');
            }
            if (isset($where['MATCH'])) {
                $MATCH = $where['MATCH'];
                if (is_array($MATCH) && isset($MATCH['columns'], $MATCH['keyword'])) {
                    $where_clause .= ($where_clause != '' ? ' AND ' : ' WHERE ') . ' MATCH ("' . str_replace('.', '"."', implode($MATCH['columns'], '", "')) . '") AGAINST (' . $this->quote($MATCH['keyword']) . ')';
                }
            }
            if (isset($where['GROUP'])) {
                $where_clause .= ' GROUP BY ' . $this->column_quote($where['GROUP']);
                if (isset($where['HAVING'])) {
                    $where_clause .= ' HAVING ' . $this->data_implode($where['HAVING'], ' AND');
                }
            }
            if (isset($where['ORDER'])) {
                $ORDER = $where['ORDER'];
                if (is_array($ORDER)) {
                    $stack = array();
                    foreach ($ORDER as $column => $value) {
                        if (is_array($value)) {
                            $stack[] = 'FIELD(' . $this->column_quote($column) . ', ' . $this->array_quote($value) . ')';
                        } else if ($value === 'ASC' || $value === 'DESC') {
                            $stack[] = $this->column_quote($column) . ' ' . $value;
                        } else if (is_int($column)) {
                            $stack[] = $this->column_quote($value);
                        }
                    }
                    $where_clause .= ' ORDER BY ' . implode($stack, ',');
                } else {
                    $where_clause .= ' ORDER BY ' . $this->column_quote($ORDER);
                }
            }
            if (isset($where['LIMIT'])) {
                $LIMIT = $where['LIMIT'];
                if (is_numeric($LIMIT)) {
                    $where_clause .= ' LIMIT ' . $LIMIT;
                }
                if (
                        is_array($LIMIT) &&
                        is_numeric($LIMIT[0]) &&
                        is_numeric($LIMIT[1])
                ) {
                        $where_clause .= ' LIMIT ' . $LIMIT[0] . ',' . $LIMIT[1];
                }
            }
        } else {
            if ($where != null) {
                $where_clause .= ' ' . $where;
            }
        }
        return $where_clause;
    }

    protected function select_context($table, $join, &$columns = null, $where = null, $column_fn = null) {
        preg_match('/([a-zA-Z0-9_\-]*)\s*\(([a-zA-Z0-9_\-]*)\)/i', $table, $table_match);
        if (isset($table_match[1], $table_match[2])) {
            $table = $this->table_quote($table_match[1]);
            $table_query = $this->table_quote($table_match[1]) . ' AS ' . $this->table_quote($table_match[2]);
        } else {
            $table = $this->table_quote($table);
            $table_query = $table;
        }
        $join_key = is_array($join) ? array_keys($join) : null;
        if (
                isset($join_key[0]) &&
                strpos($join_key[0], '[') === 0
        ) {
            $table_join = array();
            $join_array = array(
                '>' => 'LEFT',
                '<' => 'RIGHT',
                '<>' => 'FULL',
                '><' => 'INNER'
            );
            foreach ($join as $sub_table => $relation) {
                preg_match('/(\[(\<|\>|\>\<|\<\>)\])?([a-zA-Z0-9_\-]*)\s?(\(([a-zA-Z0-9_\-]*)\))?/', $sub_table, $match);
                if ($match[2] != '' && $match[3] != '') {
                    if (is_string($relation)) {
                        $relation = 'USING ("' . $relation . '")';
                    }
                    if (is_array($relation)) {
                        // For ['column1', 'column2']
                        if (isset($relation[0])) {
                            $relation = 'USING ("' . implode($relation, '", "') . '")';
                        } else {
                            $joins = array();
                            foreach ($relation as $key => $value) {
                                $joins[] = (
                                        strpos($key, '.') > 0 ?
                                                // For ['tableB.column' => 'column']
                                                $this->column_quote($key) :
                                                // For ['column1' => 'column2']
                                                $table . '."' . $key . '"'
                                        ) .
                                        ' = ' .
                                        $this->table_quote(isset($match[5]) ? $match[5] : $match[3]) . '."' . $value . '"';
                            }
                            $relation = 'ON ' . implode($joins, ' AND ');
                        }
                    }
                    $table_name = $this->table_quote($match[3]) . ' ';
                    if (isset($match[5])) {
                        $table_name .= 'AS ' . $this->table_quote($match[5]) . ' ';
                    }
                    $table_join[] = $join_array[$match[2]] . ' JOIN ' . $table_name . $relation;
                }
            }
            $table_query .= ' ' . implode($table_join, ' ');
        } else {
            if (is_null($columns)) {
                if (is_null($where)) {
                    if (
                            is_array($join) &&
                            isset($column_fn)
                    ) {
                        $where = $join;
                        $columns = null;
                    } else {
                        $where = null;
                        $columns = $join;
                    }
                } else {
                    $where = $join;
                    $columns = null;
                }
            } else {
                $where = $columns;
                $columns = $join;
            }
        }
        if (isset($column_fn)) {
            if ($column_fn == 1) {
                $column = '1';
                if (is_null($where)) {
                    $where = $columns;
                }
            } else {
                if (empty($columns)) {
                    $columns = '*';
                    $where = $join;
                }
                $column = $column_fn . '(' . $this->column_push($columns) . ')';
            }
        } else {
            $column = $this->column_push($columns);
        }
        return 'SELECT ' . $column . ' FROM ' . $table_query . $this->where_clause($where);
    }

    protected function data_map($index, $key, $value, $data, &$stack) {
        if (is_array($value)) {
            $sub_stack = array();
            foreach ($value as $sub_key => $sub_value) {
                if (is_array($sub_value)) {
                    $current_stack = $stack[$index][$key];
                    $this->data_map(false, $sub_key, $sub_value, $data, $current_stack);
                    $stack[$index][$key][$sub_key] = $current_stack[0][$sub_key];
                } else {
                    $this->data_map(false, preg_replace('/^[\w]*\./i', "", $sub_value), $sub_key, $data, $sub_stack);
                    $stack[$index][$key] = $sub_stack;
                }
            }
        } else {
            if ($index !== false) {
                $stack[$index][$value] = $data[$value];
            } else {
                if (preg_match('/[a-zA-Z0-9_\-\.]*\s*\(([a-zA-Z0-9_\-]*)\)/i', $key, $key_match)) {
                    $key = $key_match[1];
                }
                $stack[$key] = $data[$key];
            }
        }
    }

    public function select($table, $join, $columns = null, $where = null) {
        $column = $where == null ? $join : $columns;
        $is_single_column = (is_string($column) && $column !== '*');

        $query = $this->query($this->select_context($table, $join, $columns, $where));
        $stack = array();
        $index = 0;
        if (!$query) {
            return false;
        }
        if ($columns === '*') {
            return $query->fetchAll(\PDO::FETCH_ASSOC);
        }
        if ($is_single_column) {
            return $query->fetchAll(\PDO::FETCH_COLUMN);
        }
        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            foreach ($columns as $key => $value) {
                if (is_array($value)) {
                    $this->data_map($index, $key, $value, $row, $stack);
                } else {
                    $this->data_map($index, $key, preg_replace('/^[\w]*\./i', "", $value), $row, $stack);
                }
            }
            $index++;
        }
        return $stack;
    }

    public function insert($table, $datas) {
        $lastId = array();
        // Check indexed or associative array
        if (!isset($datas[0])) {
            $datas = array($datas);
        }
        foreach ($datas as $data) {
            $values = array();
            $columns = array();
            foreach ($data as $key => $value) {
                $columns[] = $this->column_quote(preg_replace("/^(\(JSON\)\s*|#)/i", "", $key));
                switch (gettype($value)) {
                    case 'NULL':
                        $values[] = 'NULL';
                        break;
                    case 'array':
                        preg_match("/\(JSON\)\s*([\w]+)/i", $key, $column_match);
                        $values[] = isset($column_match[0]) ?
                                $this->quote(json_encode($value)) :
                                $this->quote(serialize($value));
                        break;
                    case 'boolean':
                        $values[] = ($value ? '1' : '0');
                        break;
                    case 'integer':
                    case 'double':
                    case 'string':
                        $values[] = $this->fn_quote($key, $value);
                        break;
                }
            }
            $this->exec('INSERT INTO ' . $this->table_quote($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode($values, ', ') . ')');
            $lastId[] = self::$pdo->lastInsertId();
        }
        return count($lastId) > 1 ? $lastId : $lastId[0];
    }

    public function update($table, $data, $where = null) {
        $fields = array();
        foreach ($data as $key => $value) {
            preg_match('/([\w]+)(\[(\+|\-|\*|\/)\])?/i', $key, $match);
            if (isset($match[3])) {
                if (is_numeric($value)) {
                    $fields[] = $this->column_quote($match[1]) . ' = ' . $this->column_quote($match[1]) . ' ' . $match[3] . ' ' . $value;
                }
            } else {
                $column = $this->column_quote(preg_replace("/^(\(JSON\)\s*|#)/i", "", $key));
                switch (gettype($value)) {
                    case 'NULL':
                        $fields[] = $column . ' = NULL';
                        break;
                    case 'array':
                        preg_match("/\(JSON\)\s*([\w]+)/i", $key, $column_match);
                        $fields[] = $column . ' = ' . $this->quote(
                                        isset($column_match[0]) ? json_encode($value) : serialize($value)
                        );
                        break;
                    case 'boolean':
                        $fields[] = $column . ' = ' . ($value ? '1' : '0');
                        break;
                    case 'integer':
                    case 'double':
                    case 'string':
                        $fields[] = $column . ' = ' . $this->fn_quote($key, $value);
                        break;
                }
            }
        }
        return $this->exec('UPDATE ' . $this->table_quote($table) . ' SET ' . implode(', ', $fields) . $this->where_clause($where));
    }

    public function delete($table, $where) {
        return $this->exec('DELETE FROM ' . $this->table_quote($table) . $this->where_clause($where));
    }

    public function replace($table, $columns, $search = null, $replace = null, $where = null) {
        if (is_array($columns)) {
            $replace_query = array();
            foreach ($columns as $column => $replacements) {
                foreach ($replacements as $replace_search => $replace_replacement) {
                    $replace_query[] = $column . ' = REPLACE(' . $this->column_quote($column) . ', ' . $this->quote($replace_search) . ', ' . $this->quote($replace_replacement) . ')';
                }
            }
            $replace_query = implode(', ', $replace_query);
            $where = $search;
        } else {
            if (is_array($search)) {
                $replace_query = array();
                foreach ($search as $replace_search => $replace_replacement) {
                    $replace_query[] = $columns . ' = REPLACE(' . $this->column_quote($columns) . ', ' . $this->quote($replace_search) . ', ' . $this->quote($replace_replacement) . ')';
                }
                $replace_query = implode(', ', $replace_query);
                $where = $replace;
            } else {
                $replace_query = $columns . ' = REPLACE(' . $this->column_quote($columns) . ', ' . $this->quote($search) . ', ' . $this->quote($replace) . ')';
            }
        }
        return $this->exec('UPDATE ' . $this->table_quote($table) . ' SET ' . $replace_query . $this->where_clause($where));
    }

    public function get($table, $join = null, $columns = null, $where = null) {
        $column = $where == null ? $join : $columns;
        $is_single_column = (is_string($column) && $column !== '*');
        $query = $this->query($this->select_context($table, $join, $columns, $where) . ' LIMIT 1');
        if ($query) {
            $data = $query->fetchAll(\PDO::FETCH_ASSOC);
            if (isset($data[0])) {
                if ($is_single_column) {
                    return $data[0][preg_replace('/^[\w]*\./i', "", $column)];
                }

                if ($column === '*') {
                    return $data[0];
                }
                $stack = array();
                foreach ($columns as $key => $value) {
                    if (is_array($value)) {
                        $this->data_map(0, $key, $value, $data[0], $stack);
                    } else {
                        $this->data_map(0, $key, preg_replace('/^[\w]*\./i', "", $value), $data[0], $stack);
                    }
                }
                return $stack[0];
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function has($table, $join, $where = null) {
        $column = null;
        $query = $this->query('SELECT EXISTS(' . $this->select_context($table, $join, $column, $where, 1) . ')');
        if ($query) {
            return $query->fetchColumn() === '1';
        } else {
            return false;
        }
    }

    public function count($table, $join = null, $column = null, $where = null) {
        $query = $this->query($this->select_context($table, $join, $column, $where, 'COUNT'));
        return $query ? 0 + $query->fetchColumn() : false;
    }

    public function max($table, $join, $column = null, $where = null) {
        $query = $this->query($this->select_context($table, $join, $column, $where, 'MAX'));
        if ($query) {
            $max = $query->fetchColumn();
            return is_numeric($max) ? $max + 0 : $max;
        } else {
            return false;
        }
    }

    public function min($table, $join, $column = null, $where = null) {
        $query = $this->query($this->select_context($table, $join, $column, $where, 'MIN'));
        if ($query) {
            $min = $query->fetchColumn();
            return is_numeric($min) ? $min + 0 : $min;
        } else {
            return false;
        }
    }

    public function avg($table, $join, $column = null, $where = null) {
        $query = $this->query($this->select_context($table, $join, $column, $where, 'AVG'));
        return $query ? 0 + $query->fetchColumn() : false;
    }

    public function sum($table, $join, $column = null, $where = null) {
        $query = $this->query($this->select_context($table, $join, $column, $where, 'SUM'));
        return $query ? 0 + $query->fetchColumn() : false;
    }

    public function action($actions) {
        if (is_callable($actions)) {
            self::$pdo->beginTransaction();
            $result = $actions($this);
            if ($result === false) {
                self::$pdo->rollBack();
            } else {
                self::$pdo->commit();
            }
        } else {
            return false;
        }
    }

    public function debug() {
        $this->debug_mode = true;
        return $this;
    }

    public function error() {
        return self::$pdo->errorInfo();
    }

    public function last_query() {
        return end($this->logs);
    }

    public function log() {
        return $this->logs;
    }

    public function info() {
        $output = array(
            'server' => 'SERVER_INFO',
            'driver' => 'DRIVER_NAME',
            'client' => 'CLIENT_VERSION',
            'version' => 'SERVER_VERSION',
            'connection' => 'CONNECTION_STATUS'
        );
        foreach ($output as $key => $value) {
            $output[$key] = self::$pdo->getAttribute(constant('\PDO::ATTR_' . $value));
        }
        return $output;
    }

}
