<?php
namespace DALMP;

/**
 * Database - Abstraction Layer for MySQL
 *
 * @author Nicolas Embriz <nbari@dalmp.com>
 * @package DALMP
 * @license BSD License
 * @version 3.0
 */
class Database {

  /**
   * Contains database object
   *
   * @access protected
   * @var object
   */
  protected $DB;

  /**
   * Contains the database parameters DSN.
   *
   * @access protected
   * @var array
   */
  protected $dsn = array();

  /**
   * For successful SELECT, SHOW, DESCRIBE or EXPLAIN queries mysqli_query()
   * will return a result object.
   *
   * For other successful queries mysqli_query() will return True.
   *
   * @access protected
   * @var mixed
   */
  protected $_rs = NULL;

  /**
   * prepared statement object or False if an error occurred.
   *
   * @access protected
   * @var mixed
   */
  protected $_stmt = NULL;

  /**
   * cache instances
   *
   * @access private
   * @var mixed
   */
  private static $cache = NULL;

  /**
   * If enabled, logs all queries and executions.
   *
   * @access private
   * @var boolean
   */
  private $debug = False;

  /**
   * Holds the fetchMode.
   *
   * @access private
   * @var mixed
   */
  private $fetchMode = MYSQLI_BOTH;

  /**
   * Holds the num of rows returned.
   *
   * @access private
   * @var int
   */
  private $numOfRows;

  /**
   * Holds the num of rows affected by INSERT, UPDATE, or DELETE query.
   *
   * @access private
   * @var int
   */
  private $numOfRowsAffected;

  /**
   * Holds the num of fields returned.
   *
   * @access private
   * @var int
   */
  private $numOfFields;

  /**
   * Contains the prepared statments parameters
   *
   * @access private
   * @var array
   */
  private $stmtParams = array();

  /**
   * transaction status
   *
   * @access private
   * @var array
   */
  private $trans = array();

  /**
   * Constructor
   *
   * @param DSN $dsn
   * @param array $ssl
   */
  public function __construct($dsn = NULL, $ssl = NULL) {
    if ($dsn) {
      $dsn = parse_url($dsn);
      $this->dsn['charset'] = isset($dsn['scheme']) ? (($dsn['scheme'] == 'mysql') ? NULL : $dsn['scheme']) : NULL;
      if (isset($dsn['host'])) {
        $host = explode('=', $dsn['host']);
        if ($host[0] == 'unix_socket') {
          $this->dsn['host'] = NULL;
          $this->dsn['socket'] = str_replace('\\', '/', $host[1]);
        } else {
          $this->dsn['host'] = rawurldecode($dsn['host']);
          $this->dsn['socket'] = NULL;
        }
      } else {
        $this->dsn['host'] = '127.0.0.1';
      }
      $this->dsn['port'] = isset($dsn['port']) ? $dsn['port'] : 3306;
      $this->dsn['user'] = isset($dsn['user']) ? rawurldecode($dsn['user']) : NULL;
      $this->dsn['pass'] = isset($dsn['pass']) ? rawurldecode($dsn['pass']) : NULL;
      $this->dsn['dbName'] = isset($dsn['path']) ? rawurldecode(substr($dsn['path'], 1)) : NULL;
      $this->dsn['cache'] = isset($dsn['query']) ? $dsn['query'] : NULL;
      $this->dsn['ssl'] = $ssl;
    } else {
      die('DSN missing: charset://username:password@host:port/database');
    }
  }

  /**
   * Opens a connection to a mysql server
   * @access protected
   */
  protected function connect() {
    if ($this->DB instanceof mysqli) {
      if ($this->debug) $this->debug->log(__METHOD__, 'still connected');
      return;
    }

    if (!extension_loaded('mysqli')) {
      die('The Mysqli extension is required');
    }

    $this->DB = mysqli_init();
    mysqli_options($this->DB, MYSQLI_OPT_CONNECT_TIMEOUT, defined('DALMP_CONNECT_TIMEOUT') ? DALMP_CONNECT_TIMEOUT : 5);

    if (defined('DALMP_MYSQLI_INIT_COMMAND')) {
      mysqli_options($this->DB, MYSQLI_INIT_COMMAND, DALMP_MYSQLI_INIT_COMMAND);
    }

    if (is_array($this->dsn['ssl'])) {
      if ($this->debug) $this->debug->log('DSN - SSL', $this->dsn['ssl']);
      mysqli_ssl_set($this->DB, $this->dsn['ssl']['key'], $this->dsn['ssl']['cert'], $this->dsn['ssl']['ca'], $this->dsn['ssl']['capath'], $this->dsn['ssl']['cipher']);
    }

    $rs = @mysqli_real_connect($this->DB, $this->dsn['host'], $this->dsn['user'], $this->dsn['pass'], $this->dsn['dbName'], $this->dsn['port'], $this->dsn['socket']);
    if ($rs === False || mysqli_connect_errno()) {
      if ($this->debug) $this->debug->log(__METHOD__, 'ERROR', 'mysqli connection error');
      throw new \Exception(mysqli_connect_error(), mysqli_connect_errno());
    }

    if ($this->dsn['charset']) {
      mysqli_set_charset($this->DB, $this->dsn['charset']);
    }
  }

  /**
   * debuger
   *
   * @param boolean $log2file
   * @param mixed $debugFile
   */
  public function debug($log2file = False, $debugFile = False) {
    $debugFile = $debugFile ?: (defined('DALMP_DEBUG_FILE') ? DALMP_DEBUG_FILE : '/tmp/dalmp.log');
    $this->debug = new Logger($log2file, $debugFile);
    $this->debug->log('DSN', $this->dsn);
    if ($this->isConnected()) {
      $this->debug->log('DALMP', mysqli_get_host_info($this->DB), 'protocol version: ' . mysqli_get_proto_info($this->DB), 'character set: ' . mysqli_character_set_name($this->DB));
    }
    return;
  }

  /**
   * isConnected
   *
   * @return boolean
   */
  public function isConnected() {
    return (bool) ($this->DB instanceof mysqli);
  }

  /**
   * Closes a previously opened database connection
   */
  public function closeConnection() {
    if ($this->debug) $this->debug->log(__METHOD__);
    return ($this->isConnected()) && $this->DB->close();
  }

  /**
   * Closes a previously opened database connection
   */
  public function Close() {
    if ($this->debug) $this->debug->log(__METHOD__);
    return $this->_rs->close();
  }

  /**
   * Closes a prepared statement
   */
  public function PClose() {
    if ($this->debug) $this->debug->log('PreparedStatements', __METHOD__);
    $this->_stmt->free_result();
    return $this->_stmt->close();
  }

  /**
   * getNumOfRows
   *
   * @return int num of rows
   */
  public function getNumOfRows() {
    return $this->numOfRows;
  }

  /**
   * getNumOfRowsAffected
   *
   * @return int num of rows affected
   */
  public function getNumOfRowsAffected() {
    return $this->numOfRowsAffected;
  }

  /**
   * getNumOfFields
   *
   * @return int num of fields
   */
  public function getNumOfFields() {
    return $this->numOfFields;
  }

  /**
   * Get the column names
   *
   * @param $table;
   * @return array or False if no table set
   */
  public function getColumnNames($table = NULL) {
    return ($table) ? $this->getCol("DESCRIBE $table") : False;
  }

  /**
   * Sets the Fetch Mode
   *
   * @chainable
   * @param ASSOC = MYSQLI_ASSOC, NUM = MYSQLI_NUM, NULL = MYSQLI_BOTH.
   */
  public function FetchMode($mode = NULL) {
    switch (strtoupper($mode)) {
    case 'NUM':
      $this->fetchMode = MYSQLI_NUM;
      break;

    case 'ASSOC':
      $this->fetchMode = MYSQLI_ASSOC;
      break;

    default :
      $this->fetchMode = MYSQLI_BOTH;
    }

    if ($this->debug) $this->debug->log(__METHOD__, $mode, $this->fetchMode);

    return $this;
  }

  /**
   * Prepare arguments
   *
   * @param string $args
   * @return array with arguments;
   */
  public function Prepare() {
    if ($this->debug) $this->debug->log('PreparedStatements', __METHOD__, func_get_args());

    switch (func_num_args()) {
    case 1:
      $param = func_get_arg(0);
      $clean = True;
      break;

    case 2:
      $key = func_get_arg(0);
      $param = func_get_arg(1);
      if (in_array($key, array('i', 'd', 's', 'b'), True)) {
        $this->stmtParams[] = array($key => $param);
      } else {
        $clean = True;
      }
      break;

    default :
      return $this->stmtParams;
    }

    if (isset($clean)) {
      if (is_numeric($param)) {
        $param = !strcmp(intval($param), $param) ? (int) $param : (!strcmp(floatval($param), $param) ? (float) $param : $param);
      }
      $key = is_int($param) ? 'i' : (is_float($param) ? 'd' : (is_string($param) ? 's' : 'b'));
      return $this->stmtParams[] = array($key => $param);
    }
  }

  /**
   * Prepared Statements
   *
   * example: PGetAll('SELECT * FROM users WHERE name=? AND id=?', 'name', 1, 'db1')
   * user also can define  the corresponding type of the bind variables (i, d, s, b): http://pt.php.net/manual/en/mysqli-stmt.bind-param.php
   * example: PGetAll('SELECT * FROM table WHERE name=? AND id=?', array('s'=>'99.3', 7)); or use the Prepare() method
   *
   * @param SQL $sql
   * @param string $params
   */
  public function PExecute() {
    $args = func_get_args();
    if ($this->debug) $this->debug->log('PreparedStatements', __METHOD__, $args);

    (!$this->isConnected()) && $this->connect();

    $sql = array_shift($args);
    $this->_stmt = $this->DB->prepare($sql);

    if (!$this->_stmt) {
      $this->closeConnection();
      trigger_error('ERROR -> ' . __METHOD__ . ": Please check your sql statement, unable to prepare: $sql with args: " . json_encode($args), E_USER_ERROR);
    }

    $params = array();
    $types = NULL;

    $args = is_array(current($args)) ? current($args) : $args;

    if ($this->debug) $this->debug->log('PreparedStatements', __METHOD__, 'args:',$args);

    if (!empty($args)) {

      foreach ($args as $key => $param) {
        $params[] = &$args[$key];

        if (!in_array($key, array('i', 'd', 's', 'b'), True)) {

          if (is_numeric($param)) {
            $param = !strcmp(intval($param), $param) ? (int) $param : (!strcmp(floatval($param), $param) ? (float) $param : $param);
          }

          $key = is_int($param) ? 'i' : (is_float($param) ? 'd' : (is_string($param) ? 's' : 'b'));
        }

        if ($this->debug) $this->debug->log('PreparedStatements', __METHOD__, "key: $key param: $param");
        $types .= $key;
      }

      array_unshift($params, $types);

      if ($this->debug) $this->debug->log('PreparedStatements', __METHOD__, "sql: $sql params:", $params);

      call_user_func_array(array($this->_stmt, 'bind_param'), $params);
    }

    /**
     * if you get erros like 'Illegal mix of collations
     * (latin1_swedish_ci,IMPLICIT) and (utf8_general_ci,COERCIBLE)'
     * try to set your table fiels to: "character set: UTF8"
     * and "collation: utf8_unicode_ci"
     */
    if ($this->_stmt->execute()) {
      $this->_stmt->store_result();
      $this->numOfRows = $this->_stmt->num_rows;
      $this->numOfFields = $this->_stmt->field_count;
      $this->numOfRowsAffected = $this->_stmt->affected_rows;
      // for SELECT
      if ($this->_stmt->num_rows > 0) {
        return True;
      }

      /**
       * Get the number of rows affected by INSERT, UPDATE, or DELETE query.
       * -1 indicates that the query has returned an error.
       */
      if ($this->_stmt->affected_rows == -1) {
        return False;
      }

      /**
       * Zero indicates that no records where updated for an UPDATE/DELETE
       * statement, no rows matched the WHERE clause in the query or that no
       * query has yet been executed.
       * if ($rs !== False) { query ok and records updated }
       */
      if ($this->_stmt->affected_rows > 0) {
        return True;
      }

      return $this->_stmt->affected_rows;
    } else {
      if (array_key_exists('error', $this->trans)) {
        $this->trans['error']++;
      }

      if ($this->debug) $this->debug->log('PreparedStatements', __METHOD__, 'ERROR', "sql: $sql  params: ", $params, " Errorcode:" . $this->DB->errno);

      throw new \ErrorException(__METHOD__ . 'ERROR -> ' . $this->DB->error . " - sql: $sql with params: " . json_encode($params));

      return False;
    }
  }

  /**
   * Prepared Statements query
   *
   * @param array $row
   */
  public function Pquery(&$row) {
    if ($this->debug) $this->debug->log('PreparedStatements', __METHOD__);
    $meta = $this->_stmt->result_metadata();
    $columns = array();

    while ($column = $meta->fetch_field()) {
      $columns[] = &$row[$column->name];
    }
    call_user_func_array(array($this->_stmt, 'bind_result'), $columns);

    return $this->_stmt->fetch();
  }

  /**
   * _pFetch
   *
   * @access protected
   * @return array
   */
  protected function _pFetch($get = NULL) {
    if ($this->debug) $this->debug->log('PreparedStatements', __METHOD__, $get);

    if (!$this->_stmt->num_rows) {
      $this->PClose();
      return False;
    }

    $meta = $this->_stmt->result_metadata();
    $columns = array();
    $results = array();

    while (($column = $meta->fetch_field()) !== False) {
      $columns[$column->name] = &$results[$column->name];
    }

    call_user_func_array(array($this->_stmt, 'bind_result'), $columns);

    $rs = array();

    switch ($get) {
    case 'one':
      while ($this->_stmt->fetch()) {
        $rs = array_shift($columns);
        break;
      }
      $rs = is_array($rs) ? reset($rs) : $rs;
      break;

    case 'col':
      while ($this->_stmt->fetch()) {
        $rs[] = reset($columns);
      }
      break;

    case 'assoc':
      if ($this->numOfFields < 2) {
        if ($this->debug) { $this->debug->log('PreparedStatements', __METHOD__, 'ERROR', $get, 'num of columns < 2'); }
          return False;
      }
      if ($this->numOfFields == 2) {
        while ($this->_stmt->fetch()) {
          $rs[reset($columns)] = next($columns);
        }
      } else {
        while ($this->_stmt->fetch()) {
          $rs[reset($columns)] = array_slice($columns, 1);
        }
      }
      break;

    default :
      while ($this->_stmt->fetch()) {
        $tmp = array();
        foreach ($columns as $key => $val) {
          switch ($this->fetchMode) {
          case MYSQLI_NUM:
            $tmp[] = $val;
            break;

          case MYSQLI_ASSOC:
            $tmp[$key] = $val;
            break;

          default :
            $tmp[] = $val;
            $tmp[$key] = $val;
            break;
          }
        }
        array_push($rs, $tmp);
        if ($get == 'row') {
          $rs = array_shift($rs);
          break;
        }
      }
    }

    $this->PClose();
    return $rs;
  }

  /**
   * _fetch
   *
   * @access protected
   * @return an array of strings that corresponds to the fetched row or NULL
   * if there are no more rows in resultset.
   */
  protected function _fetch() {
    if ($this->debug) $this->debug->log(__METHOD__);
    return $this->_rs->fetch_array($this->fetchMode);
  }

  /**
   * Auto Execute
   *
   * @param string $table
   * @param array $fields
   * @param string $mode
   * @param string $where
   * @return True or False on error
   */
  public function AutoExecute($table = NULL, $fields = NULL, $mode = 'INSERT', $where = NULL) {
    if (!$table || !is_array($fields)) {
      if ($this->debug) $this->debug->log(__METHOD__, 'ERROR', 'either table or fields missing');
      return False;
    }

    if ($this->debug) $this->debug->log(__METHOD__, 'args:', $table, $fields, $mode, $where);

    $mode = strtoupper($mode);

    if ($mode == 'UPDATE' && !$where) {
      if ($this->debug) $this->debug->log( __METHOD__, 'ERROR', 'WHERE clause missing');
      return False;
    }

    if ($columnNames = $this->getColumnNames($table)) {
      $data = array();
      $placeholder = '';
      foreach ($columnNames as $col) {
        if (isset($fields[$col])) {
          $data["`$col`"] = $fields[$col];
          $placeholder.= '?,';
        }
      }
      if (empty($data)) {
        if ($this->debug) $this->debug->log(__METHOD__, 'ERROR', "no matching fields on table: $table with fields:", $fields);
        return False;
      }
    } else {
      return False;
    }
    switch ($mode) {
    case 'INSERT':
      $fields = implode(', ', array_keys($data));
      $placeholder = rtrim($placeholder, ',');
      $query = array_values($data);
      $sql = "INSERT INTO $table ($fields) VALUES($placeholder)";
      array_unshift($query, $sql);
      return call_user_func_array(array($this,'PExecute'), $query);
      break;

    case 'UPDATE':
      $fields = implode('=?, ', array_keys($data));
      $fields.= '=?';
      $query = array_values($data);
      $sql = "UPDATE $table SET $fields WHERE $where";
      array_unshift($query, $sql);
      return call_user_func_array(array($this,'PExecute'), $query);
      break;

    default :
      if ($this->debug) $this->debug->log(__METHOD__, 'ERROR', 'mode must be INSERT or UPDATE');
      return False;
    }
  }

  /**
   * Execute SQL statement
   *
   * @param strign $sql
   * @return True or False if there was an error in executing the sql.
   */
  public function Execute($sql) {
    if ($this->debug) $this->debug->log(__METHOD__, "sql: $sql");

    (!$this->isConnected()) && $this->connect();

    if ($rs = $this->DB->query($sql)) {
      if (is_object($rs)) {
        $this->_rs = $rs;
        $this->numOfRows = $this->_rs->num_rows;
        $this->numOfFields = $this->_rs->field_count;
        if ($this->debug) $this->debug->log(__METHOD__, 'returned object', "#rows: $this->numOfRows #fields: $this->numOfFields");
        if (!$this->numOfRows) {
          $this->Close();
          return False;
        }
      }
      $this->numOfRowsAffected = @$this->DB->affected_rows;
      return (@$this->DB->affected_rows >= 0 || $rs) ? True : False;
    } else {
      if (array_key_exists('error', $this->trans)) {
        $this->trans['error']++;
      }
      if ($this->debug) $this->debug->log(__METHOD__, 'ERROR', "sql: $sql Errorcode: " . $this->DB->errno);
      throw new \ErrorException(__METHOD__ . ' ERROR -> ' . $this->DB->error . " - sql: $sql");
      return False;
    }
  }

  /**
   * Query
   *
   * @see _fetch
   * @return array or NULL
   */
  public function query() {
    if ($this->debug) $this->debug->log(__METHOD__);
    return $this->_fetch();
  }

  /**
   * Export to CSV
   *
   * @param string $sql
   * @return csv
   */
  public function csv() {
    $args = func_get_args();
    if ($this->debug) $this->debug->log(__METHOD__, $args);

    switch (func_num_args()) {
    case 1:
      if (call_user_func_array(array($this, 'Execute'), $args)) {
        $row = $this->_rs->fetch_array(MYSQLI_ASSOC);
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array_keys($row));
        $this->_rs->data_seek(0);
        while ($row = $this->_rs->fetch_array(MYSQLI_NUM)) {
          fputcsv($fp, $row);
        }
        $this->Close();
        fclose($fp);
      }
      break;

    default:
      if (call_user_func_array(array($this, 'PExecute'), $args)) {
        $meta = $this->_stmt->result_metadata();
        $columns = array();
        $results = array();
        while (($column = $meta->fetch_field()) !== False) {
          $columns[$column->name] = &$results[$column->name];
        }
        $fp = fopen('php://output', 'w');
        fputcsv($fp, array_keys($columns));
        call_user_func_array(array($this->_stmt, 'bind_result'), $columns);
        while ($this->_stmt->fetch()) {
          fputcsv($fp, $columns);
        }
        $this->PClose();
        fclose($fp);
      }
    }
  }

  /**
   * maps the result to an object
   *
   * @param sting sql the query string
   * @param string class_name of the class to instantiate
   * @param array optional array of parameters to pass to the constructor for class_name objects.
   * @see mysqli_result::fetch_object
   * @return object
   */
  public function map($sql, $class_name=NULL, $params=array()) {
    if ($this->debug) $this->debug->log(__METHOD__, "sql: $sql");
    if ($this->Execute($sql)) {
      return ($class_name) ? $this->_rs->fetch_object($class_name, $params) : $this->_rs->fetch_object();
    } else {
      return False;
    }
  }

  /**
   * Fetch a result row as an associative, a numeric array, or both
   *
   * @param SQL $sql
   * @return array or False
   */
  public function getAll($sql) {
    if ($this->debug) $this->debug->log(__METHOD__, "sql: $sql");
    if ($this->Execute($sql)) {
      $rows = array();
      while ($row = $this->_fetch()) {
        $rows[] = $row;
      }
      $this->Close();
      return $rows;
    } else {
      return False;
    }
  }

  /**
   * Get a Row
   *
   * @param SQL $sql
   * @return the first row as an array or False.
   */
  public function getRow($sql) {
    if ($this->debug) $this->debug->log(__METHOD__, "sql: $sql");
    if ($this->Execute($sql)) {
      $row = $this->_fetch();
      $this->Close();
      return $row;
    } else {
      return False;
    }
  }

  /**
   * Get a Column
   *
   * @param SQL $sql
   * @return the first column as an array, or False.
   */
  public function getCol($sql) {
    if ($this->debug) $this->debug->log(__METHOD__, "sql: $sql");
    if ($this->Execute($sql)) {
      $col = array();
      while ($row = $this->_rs->fetch_row()) {
        $col[] = reset($row);
      }
      $this->Close();
      return $col;
    } else {
      return False;
    }
  }

  /**
   * Get One
   *
   * @param SQL $sql
   * @return the first field of the first row, or False.
   */
  public function getOne($sql) {
    if ($this->debug) $this->debug->log(__METHOD__, "sql: $sql");
    if ($this->Execute($sql)) {
      $row = $this->_rs->fetch_row();
      $this->Close();
      return reset($row);
    } else {
      return False;
    }
  }

  /**
   * Get an associative array using the first column as keys
   *
   * @param SQL $sql
   * @return associative array, False if columns < 2, or no records found.
   */
  public function getASSOC($sql) {
    if ($this->debug) $this->debug->log(__METHOD__, "sql: $sql");
    if ($this->Execute($sql)) {
      $cols = $this->numOfFields;
      if ($cols < 2) {
        return False;
      }
      $this->fetchMode = MYSQLI_ASSOC;
      $assoc = array();
      if ($cols == 2) {
        while ($row = $this->_fetch()) {
          $assoc[reset($row)] = next($row);
        }
      } else {
        while ($row = $this->_fetch()) {
          $assoc[reset($row)] = array_slice($row, 1);
        }
      }
      $this->Close();
      return $assoc;
    } else {
      return False;
    }
  }

  /**
   * Start the transaction
   */
  public function StartTrans() {
    if (empty($this->trans)) {
      if ($this->debug) $this->debug->log('transactions', __METHOD__);
      $this->trans = array('level' => 0, 'error' => 0);
      return $this->Execute('BEGIN');
    } else {
      $this->trans['level']++;
      if ($this->debug) $this->debug->log('transactions', __METHOD__, 'transaction level: ' . $this->trans['level']);
      return $this->Execute('SAVEPOINT level' . $this->trans['level']);
    }
  }

  /**
   * Complete the transaction
   *
   * @return boolean
   */
  public function CompleteTrans() {
    if ($this->debug) $this->debug->log('transactions', __METHOD__);
    if (empty($this->trans)) {
      return False;
    } else {
      if ($this->trans['error'] > 0) {
        if ($this->debug) $this->debug->log('transactions', __METHOD__, 'ERROR', 'error in level: ' . $this->trans['level']);
        if ($this->trans['level'] > 0) {
          $this->Execute('ROLLBACK TO SAVEPOINT level' . $this->trans['level']);
          $this->trans['level']--;
        } else {
          $this->Execute('ROLLBACK');
        }
        return False;
      }
      if ($this->trans['level'] == 0) {
        $this->trans = array();
        return $this->Execute('COMMIT');
      } else {
        $rs = $this->Execute('RELEASE SAVEPOINT level' . $this->trans['level']);
        $this->trans['level']--;
        return $rs;
      }
    }
  }

  /**
   * Rollback the transaction
   *
   * @return False if there was an error executing the ROLLBACK.
   */
  public function RollBackTrans() {
    if ($this->debug) $this->debug->log('transactions', __METHOD__);
    if (isset($this->trans['level']) && $this->trans['level'] > 0) {
      $rs = $this->Execute('ROLLBACK TO SAVEPOINT level' . $this->trans['level']);
      $this->trans['level']--;
      return $rs;
    } else {
      return $this->Execute('ROLLBACK');
    }
  }

  /**
   * Insert_Id
   *
   * @return int the auto generated id used in the last query
   */
  public function Insert_Id() {
    if ($this->debug) $this->debug->log(__METHOD__);
    return $this->DB->insert_id;
  }

  /**
   * ErrorMsg
   *
   * @return string description of the last error
   */
  public function ErrorMsg() {
    return $this->DB->error;
  }

  /**
   * ErrorNum
   *
   * @return int error code
   */
  public function ErrorNum() {
    return $this->DB->errno;
  }

  /**
   * Quotes a string
   *
   * @param string $value
   */
  public function qstr($value) {
    if ($this->debug) $this->debug->log(__METHOD__, func_get_args());
    if (is_int($value) || is_float($value)) {
      $rs = $value;
    } else {
      (!$this->isConnected()) && $this->connect();
      $rs = $this->DB->real_escape_string($value);
    }
    if ($this->debug) $this->debug->log(__METHOD__, "returned: $rs");
    return $rs;
  }

  /**
   * renumber
   *
   * @param string $table
   * @param int $id
   */
  public function renumber($table, $row = 'id') {
    if (isset($table)) {
      return $this->Execute('SET @var_dalmp=0') ? ($this->Execute("UPDATE $table SET $row = (@var_dalmp := @var_dalmp +1)") ? $this->Execute("ALTER TABLE $table AUTO_INCREMENT = 1") : False) : False;
    } else {
      return False;
    }
  }

  /**
   * Cache
   *
   * @return DALMP\Cache instance
   */
  public function Cache() {
    if (is_null(self::$cache)) {
      list($type, $host, $port, $compress) = @explode(':', $this->dsn['cache']) + array(NULL, NULL, NULL, NULL);

      if ($this->debug) $this->debug->log('Cache', $this->dsn['cache']);

      $cache_type = strtolower($type);

      switch ($cache_type) {
      case 'apc':
        self::$cache = new DAMLP\Cache\APC();
        break;

      case 'memcache':
        self::$cache = new DAMLP\Cache\Memcache($host, $port, 1, $compress);
        break;

      case 'redis':
        self::$cache = new DAMLP\Cache\Redis($host, $port, $compress);
        break;

      default:
        self::$cache = new DALMP\Cache\Disk($host);
      }
    } else {
      return self::$cache;
    }
  }

  /**
   * general method for caching
   *
   * args: $method, $expire, $sql, $key, $group
   * @access protected;
   */
  protected function _Cache() {
    $args = func_get_args();

    $fetch = array_shift($args);
    $expire = (int) (reset($args)) ? array_shift($args) : 3600;
    $sql = array_shift($args);
    $key = isset($args[0]) ? $args[0] : $fetch;

    if (strncmp($key, 'group:', 6) == 0) {
      $group = $key;
      $key = $fetch;
    } else {
      $group = (isset($args[1]) and (strncmp($args[1], 'group:', 6) == 0)) ? $args[1] : NULL;
    }

    $skey = defined('DALMP_SITE_KEY') ? DALMP_SITE_KEY : 'DALMP';
    $hkey = sha1($skey . $sql . $key);

    if ($this->debug) $this->debug->log('Cache - GET', "method: $fetch expire: $expire sql: [ $sql ] key: $key group: $group hkey: $hkey");

    if ($cache = $this->Cache()->get($hkey)) {
      return $cache;
    } else {
      switch ($fetch) {
      case 'execute':
      case 'all':
        $cache = $this->getAll($sql);
        break;
      case 'row':
        $cache = $this->getRow($sql);
        break;
      case 'col':
        $cache = $this->getCol($sql);
        break;
      case 'one':
        $cache = $this->getOne($sql);
        break;
      case 'assoc':
        $cache = $this->getASSOC($sql);
        break;
      }

      $this->setCache($hkey, $cache, $expire, $group);

      if ($this->debug) $this->debug->log('Cache - SET', "method: $fetch expire: $expire sql: [ $sql ] key: $key group: $group hkey: $hkey");

      return $cache;
    }
  }

  /**
   * method for caching prepared statements
   *
   * @access protected
   */
  protected function _CacheP() {
    $args = func_get_args();

    $fetch = array_shift($args);
    $expire = (int) (reset($args)) ? array_shift($args) : 3600;
    $sql = array_shift($args);

    // expected params
    $eparams = count(explode('?', $sql, -1));
    $targs = count($args);
    $args = is_array(current($args)) ? current($args) : $args;
    if ($targs > $eparams) {
      if (($targs - $eparams) == 1) {
        $key = array_pop($args);
        $params = $args;
        if (strncmp($key, 'group:', 6) == 0) {
          $group = $key;
          $key = $fetch . implode('|', array_merge(array_keys($args), $args));
        } else {
          $group = NULL; // only key no group
        }
      } else {
        $group = array_pop($args);
        $group = (strncmp($group, 'group:', 6) == 0) ? $group : NULL;
        $key = array_pop($args);
        $params = $args;
      }
    } else {
      $key = $fetch . implode('|', array_merge(array_keys($args), $args));
      $params = $args;
      $group = NULL;
    }

    array_unshift($args, $sql);

    $skey = defined('DALMP_SITE_KEY') ? DALMP_SITE_KEY : 'DALMP';
    $hkey = sha1($skey . $sql . $key);

    if ($this->debug) $this->debug->log('Cache - GET', "PreparedStatements method: $fetch expire: $expire, sql: $sql params: " . implode('|', $params) . " key: $key group: $group");

    if ($cache = $this->Cache()->Get($hkey)) {
      return $cache;
    } else {
      if ($this->debug) $this->debug->log('Cache', __METHOD__, 'PreparedStatements no cache returned, executing query PExecute with args: ', $args);

      $nargs = array();
      foreach (array_keys($args) as $akey) {
        if (!is_int($akey)) {
          $nargs['dalmp'][$akey] = $args[$akey];
        } else {
          $nargs[] = $args[$akey];
        }
      }
      call_user_func_array(array($this, 'PExecute'), $nargs);
      $cache = $this->_pFetch($fetch);

      $this->setCache($hkey, $cache, $expire, $group);

      if ($this->debug) $this->debug->log('Cache - SET', "method: $fetch expire: $expire sql: [ $sql ] key: $key group: $group hkey: $hkey");

      return $cache;
    }
  }

  /**
   * setCache - store data in cache
   *
   * @param string $hkey The key that will be associated with the item.
   * @param data $cache The variable to store
   * @param int $expire Expiration time of the item
   * @param string $group group:name (to group cache keys) usefull when flushing the cache
   * @return boolean
   */
  public function setCache($hkey, $cache, $expire = 3600, $group = NULL) {
    if ($group) {
      $skey = defined('DALMP_SITE_KEY') ? DALMP_SITE_KEY : 'DALMP';
      $gkey = sha1($skey . $group);

      if ($gCache = $this->Cache()->Get($gkey)) {
        foreach ($gCache as $key => $exp) {
          if ($exp < time()) {
            unset($gCache[$key]);
          }
        }
      } else {
        $gCache = array();
      }

      $gCache[$hkey] = time() + $expire;

      if (!$this->Cache()->Set($hkey, $cache, $expire)->Set($gkey, $gCache, $expire)) {
        trigger_error('ERROR -> ' . __METHOD__ . " setCache: Error saving data to cache.", E_USER_NOTICE);
      }
    } else {
      if (!$this->Cache()->Set($hkey, $cache, $expire)) {
        trigger_error('ERROR -> ' . __METHOD__ . " setCache: Error saving data to cache.", E_USER_NOTICE);
      }
    }
    return True;
  }

  /**
   * Cache flush
   *
   * @param SQL $sql, cache group or NULL
   */
  public function CacheFlush($sql = NULL, $key = NULL) {
    if (is_null($sql)) {
      return $this->Cache($type)->Flush();
    }

    if ($this->debug) $this->debug->log(__METHOD__, "flush: $sql, key: $key");

    $skey = defined('DALMP_SITE_KEY') ? DALMP_SITE_KEY : 'DALMP';
    $hkey = sha1($skey . $sql . $key);

    if (strncmp($sql, 'group:', 6) == 0) {
      $gkey = sha1($skey . $sql);
      $group = $this->Cache()->get($gkey);
      $group = is_array($group) ? $group : array();
      foreach ($group as $key => $timeout) {
        $this->Cache()->Delete($key);
      }
    }

    return $this->Cache()->Delete($hkey);
  }

  /**
   * @return string server Version
   */
  public function getServerVersion() {
    $version = $this->DB->server_version;
    $major = (int) ($version / 10000);
    $minor = (int) ($version % 10000 / 100);
    $revision = (int) ($version % 100);
    return $major . '.' . $minor . '.' . $revision;
  }

  /**
   * @return string Client Version
   */
  public function getClientVersion() {
    $version = $this->DB->client_version;
    $major = (int) ($version / 10000);
    $minor = (int) ($version % 10000 / 100);
    $revision = (int) ($version % 100);
    return $major . '.' . $minor . '.' . $revision;
  }

  /**
   * isCli()
   *
   * @param boolean $eol
   * @return boolean or PHP_EOL, <br/>
   */
  public static function isCli($eol = NULL) {
    ($cli = (php_sapi_name() == 'cli' && empty($_SERVER['REMOTE_ADDR']))) && $cli = $eol ? PHP_EOL : True;
    return $cli ?  : ($eol ? '<br/>' : False);
  }

  /**
   * Universally Unique Identifier
   *
   * @param int $b
   * @return UUID, if $b returns binary(16)
   */
  public static function UUID($b=NULL) {
    if (function_exists('uuid_create')) {
      $uuid = uuid_create();
    } else { // creates a UUID v4
      $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
      );
    }
    return $b ? pack('H*', str_replace('-', '', $uuid)) : $uuid;
  }

  /**
   * sqlite3 Queue
   *
   * @param SQL $data
   * @param string $queue
   */
  public static function Queue($data, $queue = 'default') {
    $queue_db = defined('DALMP_QUEUE_DB') ? DALMP_QUEUE_DB : DALMP_DIR.'/dalmp_queue.db';
    $sdb = new SQLite3($queue_db);
    $sdb->busyTimeout(2000);

    if (defined('DALMP_SQLITE_ENC_KEY')) $sdb->exec("PRAGMA key='" . DALMP_SQLITE_ENC_KEY . "'");

    $sdb->exec('PRAGMA synchronous=OFF; PRAGMA temp_store=MEMORY; PRAGMA journal_mode=MEMORY');
    $sdb->exec('CREATE TABLE IF NOT EXISTS queues (id INTEGER PRIMARY KEY, queue VARCHAR (64) NOT NULL, data TEXT, cdate DATE)');
    $sql = "INSERT INTO queues VALUES (NULL, '$queue', '" . base64_encode($data) . "', '" . @date('Y-m-d H:i:s') . "')";

    if (!$sdb->exec($sql)) {
      trigger_error("queue: could not save $data - $queue on $queue_db", E_USER_NOTICE);
    }

    $sdb->busyTimeout(0);
    $sdb->close();
  }

  /**
   * read the sqlite3 queue
   *
   * @param string queue name
   * @param int print or return the queue
   * @return True or array
   */
  public static function readQueue($queue = '*', $print = False) {
    $queue_db = defined('DALMP_QUEUE_DB') ? DALMP_QUEUE_DB : DALMP_DIR.'/dalmp_queue.db';
    $sdb = new SQLite3($queue_db);

    if (defined('DALMP_SQLITE_ENC_KEY')) $sdb->exec("PRAGMA key='" . DALMP_SQLITE_ENC_KEY . "'");

    $rs = ($queue === '*') ? @$sdb->query('SELECT * FROM queues') : @$sdb->query("SELECT * FROM queues WHERE queue='$queue'");

    if ($rs) {
      if ($print) {
        while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
          echo $row['id'] , '|' , $row['queue'] , '|' , base64_decode($row['data']) , '|' , $row['cdate'] , DALMP::isCli(1);
        }
      } else {
        return $rs;
      }
    } else {
      return array();
    }
  }

  /**
   * magic method for Pget, Cacheget, and CachePge(all, row, col, one, assoc)
   */
  public function __call($name, $args) {
    $n = strtolower($name);

    $method = function ($subject) {
      ($m = preg_match('/^(pget|cacheget|cachepget)/i', $subject, $matches)) && $m = $matches[0];
      return $m;
    };

    $get = function ($m) use ($n) {
      $method = explode($m, $n) + array(NULL, NULL);
      return in_array($method[1], array('all', 'row', 'col', 'one', 'assoc')) ? $method[1] : False;
    };

    switch ($method($n)) {
    case 'pget':
      if ($func = $get('pget')) {
        if ($this->debug) $this->debug->log('PreparedStatements', __METHOD__);
        return call_user_func_array(array($this, 'PExecute'), $args) ? $this->_pFetch($func) : False;
      }
      break;

    case 'cacheget':
      if ($func = $get('cacheget')) {
        if ($this->debug) $this->debug->log('Cache', __METHOD__);
        array_unshift($args, $func);
        return call_user_func_array(array($this, '_Cache'), $args);
      }
      break;

    case 'cachepget':
      if ($func = $get('cachepget')) {
        if ($this->debug) $this->debug->log('CacheP', __METHOD__);
        array_unshift($args, $func);
        return call_user_func_array(array($this, '_CacheP'), $args);
      }
      break;
    }
    throw new \Exception("DALMP DB method ({$name}) does not exist", 0);
  }

  /**
   * usage: echo $db;
   *
   * @return DALMP stats
   */
  public function __toString() {
    if ($this->isConnected()) {
      $status = 'DALMP :: ';
      $status .= 'Character set: ' . $this->DB->character_set_name();
      $status .= ', ' . $this->DB->host_info;
      $status .= ', Server version: ' . $this->getServerVersion();
      $status .= ', Client version: ' . $this->getClientVersion();
      $status .= ', System status: ' . $this->DB->stat();
    } else {
      $status = 'no connections available';
    }

    if ($this->debug) $this->debug->log(__METHOD__, $status);

    return $status;
  }

  /**
   * DALMP destructor
   */
  public function __destruct() {
    if ($this->debug) $this->debug->getLog();
    return $this->closeConnection();
  }

}
