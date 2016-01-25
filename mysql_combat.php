<?php
/**
 * MySQL Migration to PDO Compatibility Pack
 * Copyright 2016 Charlotte Dunois, All Rights Reserved
 *
 * Website: http://charuru.moe
 * License: https://github.com/CharlotteDunois/MySQL_API_PDO/blob/master/LICENSE
 * $ 25.01.2016 21:03 Charlotte Dunois $
**/

if(version_compare(PHP_VERSION, '7.0', '<')) {
	define('MYSQL_BOTH', 0);
	define('MYSQL_NUM', 1);
	define('MYSQL_ASSOC', 2);
	
	if(!defined('DB_CONNECT_CHARSET')) { // Define DB_CONNECT_CHARSET before you include/require this file if you want to use a different charset than utf8mb4 (the true utf8 charset)
		define('DB_CONNECT_CHARSET', 'utf8mb4');
	}
	
	//Define NO_PREPARE (for global and always) or use MySQL_API_PDO::app()->noPrepare(true) (for once or sometimes) || MySQL_API_PDO::app()->noPrepare(false) (to start emulate again)
	//if this library should not emulate prepare statements and just run the query
	//Remember, the values don't get escaped if you don't define NO_PREPARE
	
	class MySQL_API_PDO {
		public $query_time = 0;
		public $query_count = 0; 
		private $connect = array('host' => '', 'user' => '', 'password' => '', 'db' => '');
		private $current_link = NULL;
		private $db = NULL;
		private $emulate_prepare = true;
		static protected $instance = NULL;
		
		function __construct() {
			
		}
		
		function __destruct() {
			
		}
		
		private function __clone() {
			
		}
		
		private function __wakeup() {
			
		}
		
		/* Returns if the class emulates prepared statements */
		function getPrepare() {
			return $this->emulate_prepare;
		}
		
		/* Tells the class to stop (or start) emulating prepared statements */
		function noPrepare($val) {
			$this->emulate_prepare = !(bool) $val;
		}
		
		static function app() {
			if(self::$instance === NULL OR is_a(self::$instance, 'MySQL_API_PDO') === false) {
				self::$instance = new MySQL_API_PDO();
			}
			
			return self::$instance;
		}
		
		function connect_db($host, $user, $password, $db, $driver = array()) {
			$this->get_execution_time();
			
			try {
				$this->db = new PDO("mysql:host=".$host.";dbname=".$db.";charset=".DB_CONNECT_CHARSET, $user, $password, $driver);
				$this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
				$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				
				$this->connect = array('host' => $host, 'user' => $user, 'password' => $password, 'db' => $db);
			} catch(PDOException $e) {
				throw new Exception("Unable to connect. Error: ".$e->getMessage());
			}
			
			$time_spent = $this->get_execution_time();
			$this->query_time += $time_spent;
			
			return $this->db;
		}
		
		function connect_new_db($dbname) {
			return $this->db->exec("USE ".$dbname);
		}
		
		function get_databasename() {
			return $this->connect['db'];
		}
		
		function close_link() {
			if(is_a($this->current_link, 'PDOStatement')) {
				@$this->current_link->closeCursor();
			}
			
			$this->current_link = NULL;
			$this->db = NULL;
		}
		
		function check_db_uptime() {
			if(!is_a($this->db, 'PDO')) {
				$this->close_link();
				$this->db = $this->connect_db($connect['host'], $connect['user'], $connect['password'], $connect['db']);
			} else {
				try {
					$sql = $this->db->query("SELECT 1");
					if($sql === false) {
						$this->close_link();
						$this->db = $this->connect_db($connect['host'], $connect['user'], $connect['password'], $connect['db']);
					}
				} catch(PDOException $e) {
					$this->close_link();
					$this->db = $this->connect_db($connect['host'], $connect['user'], $connect['password'], $connect['db']);
				}
			}
		}
		
		function getAttribute($attr) {
			return $this->db->getAttribute($attr);
		}
		
		function query($query) {
			$this->get_execution_time();
			
			if(!defined('NO_PREPARE') AND $this->emulate_prepare === true AND strpos($query, 'WHERE') !== false) {
				$new = $this->prepare_where($query);
				$query = $new['query'];
				$values = $new['values'];
			} else {
				$values = array();
			}
			
			try {
				if(!empty($values)) {
					$this->current_link = $this->db->prepare($query);
					$this->current_link->execute($values);
				} else {
					$this->current_link = $this->db->query($query);
				}
			} catch(PDOException $e) {
				throw new Exception("SQL Error: ".$e->getMessage()." | Query: ".$query);
			}
			
			$time_spent = $this->get_execution_time();
			$this->query_time += $time_spent;
			$this->query_count++;
			
			return $this->current_link;
		}
		
		private function prepare_where($query) {
			$strpos_where = strpos($query, 'WHERE') + 6;
			$strpos_limit = (int) strpos($query, 'LIMIT');
			$strpos_group = (int) strpos($query, 'GROUP BY');
			$strpos_order = (int) strpos($query, 'ORDER BY');
			
			$new_query = substr($query, 0, $strpos_where);
			if($strpos_limit > 0 AND $strpos_limit < $strpos_group AND $strpos_limit < $strpos_order) {
				$where = substr($query, $strpos_where, $strpos_limit);
				$new_subquery = substr($query, $strpos_limit);
			} elseif($strpos_group > 0 AND $strpos_group < $strpos_limit AND $strpos_group < $strpos_order) {
				$where = substr($query, $strpos_where, $strpos_group);
				$new_subquery = substr($query, $strpos_group);
			} elseif($strpos_order > 0 AND $strpos_order < $strpos_limit AND $strpos_order < $strpos_group) {
				$where = substr($query, $strpos_where, $strpos_order);
				$new_subquery = substr($query, $strpos_order);
			} else {
				$where = substr($query, $strpos_where);
				$new_subquery = "";
			}
			
			$where_values = array();
			$where_break = explode(' AND ', $where);
			$where_break_c = count($where_break);
			
			for($i = 0; $i < $where_break_c; $i++) {
				if(!isset($where_break[$i])) {
					continue;
				}
				
				if(isset($where_break[$i + 1]) AND preg_match('/([\`a-z0-9\_\-]+)[\s]{0,1}(\=|LIKE|\<|\>|\<\=|\>\=|!\=)[\s]{0,1}(.*)/i', $where_break[$i + 1]) != 1) {
					$where_break[$i] = $where_break[$i]." AND ".$where_break[$i + 1];
					unset($where_break[$i + 1]);
				}
				
				$where_break_or = explode(' OR ', $where_break[$i]);
				$where_break_or_c = count($where_break_or);
				for($j = 0; $j < $where_break_or_c; $j++) {
					if(!isset($where_break_or[$i])) {
						continue;
					}
					
					if(isset($where_break_or[$j + 1]) AND preg_match('/([\`a-z0-9\_\-]+)[\s]{0,1}(\=|LIKE|\<|\>|\<\=|\>\=|!\=)[\s]{0,1}(.*)/i', $where_break_or[$j + 1]) != 1) {
						$where_break_or[$j] = $where_break_or[$j]." OR ".$where_break_or[$j + 1];
						unset($where_break_or[$j + 1]);
					}
					
					preg_match('/([\`a-z0-9\_\-]+)[\s]{0,1}(\=|LIKE|\<|\>|\<\=|\>\=|!\=)[\s]{0,1}(.*)/i', $where_break_or[$j], $matches);
					if(count($matches) < 4) {
						preg_match('/([\`a-z0-9\_\-]+)[\s]{0,1}(IN)[\s]{0,1}(.*)/i', $where_break_or[$j], $matches);
						if(count($matches) < 4) {
							unset($where_break_or[$j]);
							continue;
						}
						
						$cmatches = explode(',', substr($matches[3], 1, -1));
						$matches_c = count($cmatches);
						for($k = 0; $k < $matches_c; $k++) {
							$cmatches[$k] = trim($cmatches[$k]);
							if(substr($cmatches[$k], 0, 1) == "'" OR substr($cmatches[$k], 0, 1) == '"') {
								$where_values[] = substr($cmatches[$k], 1, -1);
							} else {
								$where_values[] = $cmatches[$k];
							}
							
							$cmatches[$k] = "?";
						}
						
						$where_break_or[$j] = str_replace($matches[3], '('.implode(',', $cmatches).')', $where_break_or[$j]);
					} else {
						if(substr($matches[3], 0, 1) == "'" OR substr($matches[3], 0, 1) == '"') {
							$where_values[] = substr($matches[3], 1, -1);
						} else {
							$where_values[] = $matches[3];
						}
						
						$where_break_or[$j] = str_replace($matches[3], '?', $where_break_or[$j]);
					}
				}
				
				$where_break[$i] = implode(' OR ', $where_break_or);
			}
			
			$where_break = implode(' AND ', $where_break);
			$new_query .= $where_break.$new_subquery;
			return array('query' => $new_query, 'values' => $where_values);
		}
		
		function insert_id($name = "") {
			return $this->db->lastInsertId($name);
		}
		
		function affected_rows() {
			$this->get_execution_time();
			
			$count = $this->current_link->rowCount();
			
			$time_spent = $this->get_execution_time();
			$this->query_time += $time_spent;
			
			return $count;
		}
		
		function num_rows($parameter) {
			$this->get_execution_time();
			
			$count = $parameter->rowCount();
			
			$time_spent = $this->get_execution_time();
			$this->query_time += $time_spent;
			
			return $count;
		}
		
		function fetch($parameter, $type, $cursor = PDO::FETCH_ORI_NEXT, $offset = 0) {
			if(!is_a($parameter, 'PDOStatement')) {
				return false;
			}
			
			$this->get_execution_time();
	
			$this->current_link = $parameter->fetch($type, $cursor, $offset);
			
			$time_spent = $this->get_execution_time();
			$this->query_time += $time_spent;
			
			return $this->current_link;
		}
		
		function fetch_object($parameter) {
			if(!is_a($parameter, 'PDOStatement')) {
				return false;
			}
			
			$this->get_execution_time();
	
			$this->current_link = $parameter->fetch(PDO::FETCH_OBJ);
			
			$time_spent = $this->get_execution_time();
			$this->query_time += $time_spent;
			
			return $this->current_link;
		}
		
		function fetch_array($parameter) {
			if(!is_a($parameter, 'PDOStatement')) {
				return false;
			}
			
			$this->get_execution_time();
	
			$this->current_link = $parameter->fetch(PDO::FETCH_ASSOC);
			
			$time_spent = $this->get_execution_time();
			$this->query_time += $time_spent;
			
			return $this->current_link;
		}
		
		function fetch_array_all($parameter) {
			if(!is_a($parameter, 'PDOStatement')) {
				return false;
			}
			
			$this->get_execution_time();
	
			$this->current_link = $parameter->fetchAll(PDO::FETCH_ASSOC);
			
			$time_spent = $this->get_execution_time();
			$this->query_time += $time_spent;
			
			return $this->current_link;
		}
		
		function get_execution_time() {
			static $time_start;
			$time = microtime(true);
	
			if(!$time_start) {
				$time_start = $time;
				return;
			} else {
				$total = $time - $time_start;
				if($total < 0) {
					$total = 0;
				}
				$time_start = 0;
				return $total;
			}
		}
		
		function error($num = 2) {
			if(is_a($this->current_link, 'PDOStatement')) {
				return $this->current_link->errorInfo($num);
			} else {
				if($num = 1) {
					return 0;
				} else {
					return "";
				}
			}
		}
	}
	
	function mysql_affected_rows($link = NULL) {
		return MySQL_API_PDO::app()->affected_rows();
	}
	
	function mysql_client_encoding($link = NULL) {
		return DB_CONNECT_CHARSET;
	}
	
	function mysql_close($link = NULL) {
		return MySQL_API_PDO::app()->close_link();
	}
	
	function mysql_connect($host, $user, $pw, $new_link = false, $client_flags = 0) {
		return MySQL_API_PDO::app()->connect_db($host, $user, $pw, "");
	}
	
	function mysql_create_db($database, $link = NULL) {
		return MySQL_API_PDO::app()->query("CREATE DATABASE ".$database);
	}
	
	function mysql_data_seek($result, $row_number) {
		return MySQL_API_PDO::app()->fetch($result, PDO::FETCH_BOTH, PDO::FETCH_ORI_ABS, $row_number);
	}
	
	function mysql_db_name($result, $row, $field = NULL) {
		$db = MySQL_API_PDO::app();
		
		$j = 0;
		$colname = "";
		$tables = array();
		while($row = $db->fetch_array($result)) {
			if($colname == "") {
				$colname = array_keys($row);
				$colname = $colname[0];
			}
			
			if($j == $i) {
				return $row[$colname];
			}
			
			$j++;
		}
		
		return "";
	}
	
	function mysql_db_query($database, $query, $link = "") {
		MySQL_API_PDO::app()->connect_new_db($database);
		return MySQL_API_PDO::app()->query($query);
	}
	
	function mysql_drop_db($database, $link = NULL) {
		MySQL_API_PDO::app()->connect_new_db($database);
		return MySQL_API_PDO::app()->query("DROP DATABASE");
	}
	
	function mysql_errno($link = NULL) {
		return MySQL_API_PDO::app()->error(1);
	}
	
	function mysql_error($link = NULL) {
		return MySQL_API_PDO::app()->error(2);
	}
	
	function mysql_escape_string($string) {
		if(!defined('NO_PREPARE')) {
			return $string;
		} else {
			return addslahes($string);
		}
	}
	
	function mysql_fetch_array($result, $result_type = MYSQL_ASSOC) {
		if($result_type == MYSQL_BOTH) {
			$result_type = PDO::FETCH_BOTH;
		} elseif($result_type == MYSQL_NUM) {
			$result_type = PDO::FETCH_NUM;
		} else {
			$result_type = PDO::FETCH_ASSOC;
		}
		
		return MySQL_API_PDO::app()->fetch($result, $result_type);
	}
	
	function mysql_fetch_assoc($result) {
		return MySQL_API_PDO::app()->fetch_array($result);
	}
	
	function mysql_fetch_field($result, $field_offset = 0) {
		$db = MySQL_API_PDO::app();
		$fields = array();
		
		$field = true;
		for($i = 0; $field !== false; $i++) {
			$field = @$result->getColumnMeta($i);
			if($field === false) {
				break;
			}
			
			$fields[] = array('name' => $field['flags']['name'], 'table' => $field['flags']['table'], 'max_length' => $field['flags']['len'], 'not_null' => (int) in_array('not_null', $field['flags']), 'primary_key' => (int) in_array('primary_key', $field['flags']), 'unique_key' => (int) in_array('unique_key', $field['flags']), 'multiple_key' => (int) in_array('multiple_key', $field['flags']), 'numeric' => (int) in_array($field['native_type'], array('TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT', 'DECIMAL', 'FLOAT', 'DOUBLE', 'REAL', 'BIT', 'BOOLEAN', 'SERIAL')), 'blob' => (int) in_array($field['native_type'], array('TINYBLOB', 'MEDIUMBLOB', 'BLOB', 'LONGBLOB')), 'type' => $field['native_type'], 'unsigned' => (int) in_array('unsigned', $field['flags']), 'zerofill' => (int) in_array('zerofill', $field['flags']));
		}
		
		return $fields;
	}
	
	function mysql_fetch_lengths($result) {
		$db = MySQL_API_PDO::app();
		$fields = array();
		
		$field = true;
		for($i = 0; $field !== false; $i++) {
			$field = @$result->getColumnMeta($i);
			if($field === false) {
				break;
			} elseif(isset($field['flags']['len'])) {
				$fields[] = $field['flags']['len'];
			}
		}
		
		return $fields;
	}
	
	function mysql_fetch_object($result, $classname = NULL, $params = array()) {
		return MySQL_API_PDO::app()->fetch_object($result);
	}
	
	function mysql_fetch_row($result) {
		return $result->fetch(PDO::FETCH_NUM);
	}
	
	function mysql_field_flags() {
		$db = MySQL_API_PDO::app();
		$field = $result->getColumnMeta($field_offset);
		if(isset($field['flags'])) {
			return $field['flags'];
		} else {
			return false;
		}
	}
	
	function mysql_field_len() {
		$field = $result->getColumnMeta($field_offset);
		if(isset($field['flags']['len'])) {
			return $field['flags']['len'];
		} else {
			return false;
		}
	}
	
	function mysql_field_name() {
		$field = $result->getColumnMeta($field_offset);
		if(isset($field['flags']['name'])) {
			return $field['flags']['name'];
		} else {
			return false;
		}
	}
	
	function mysql_field_seek($result, $field_offset) {
		return MySQL_API_PDO::app()->fetch($result, PDO::FETCH_BOTH, PDO::FETCH_ORI_NEXT, $field_offset);
	}
	
	function mysql_field_table($result, $field_offset) {
		$field = $result->getColumnMeta($field_offset);
		if(isset($field['flags']['table'])) {
			return $field['flags']['table'];
		} else {
			return false;
		}
	}
	
	function mysql_fieldtype($result, $field_offset) {
		return mysql_field_type($result, $field_offset);
	}
	
	function mysql_field_type($result, $field_offset) {
		$field = $result->getColumnMeta($field_offset);
		if(isset($field['native_type'])) {
			return $field['native_type'];
		} else {
			return false;
		}
	}
	
	function mysql_free_result($result) {
		if(is_a($result, 'PDOStatement')) {
			return $result->closeCursor();
		}
		
		return false;
	}
	
	function mysql_get_client_info() {
		return MySQL_API_PDO::app()->getAttribute(PDO::ATTR_CLIENT_VERSION);
	}
	
	function mysql_get_host_info() {
		return "";
	}
	
	function mysql_get_proto_info() {
		return MySQL_API_PDO::app()->getAttribute(PDO::ATTR_DRIVER_NAME);
	}
	
	function mysql_get_server_info() {
		return MySQL_API_PDO::app()->getAttribute(PDO::ATTR_SERVER_VERSION);
	}
	
	function mysql_info() {
		return "";
	}
	
	function mysql_insert_id($name = "") {
		return MySQL_API_PDO::app()->insert_id($name);
	}
	
	function mysql_list_dbs($link = NULL) {
		return MySQL_API_PDO::app()->query("SHOW DATABASES");
	}
	
	function mysql_list_fields($db, $table, $link = NULL) {
		return MySQL_API_PDO::app()->query("SHOW COLUMNS FROM ".$db.".".$table);
	}
	
	function mysql_list_processes($link = NULL) {
		return MySQL_API_PDO::app()->query("SHOW PROCESSLIST");
	}
	
	function mysql_list_tables($db, $link = NULL) {
		return MySQL_API_PDO::app()->query("SHOW TABLES FROM ".$db);
	}
	
	function mysql_num_fields($result) {
		return MySQL_API_PDO::app()->num_fields($result);
	}
	
	function mysql_num_rows($result) {
		return MySQL_API_PDO::app()->num_rows($result);
	}
	
	function mysql_pconnect($host, $user, $pw, $client_flags = 0) {
		return MySQL_API_PDO::app()->connect_db($host, $user, $pw, "", array(PDO_ATTR_PERSISTENT => true));
	}
	
	function mysql_ping($link = NULL) {
		MySQL_API_PDO::app()->check_db_uptime();
		return true;
	}
	
	function mysql_query($query, $link = NULL) {
		return MySQL_API_PDO::app()->query($query);
	}
	
	function mysql_real_escape_string($string, $link = NULL) {
		if(!defined('NO_PREPARE')) {
			return $string;
		} else {
			return addslahes($string);
		}
	}
	
	function mysql_result($result, $row, $field = 0) {
		return MySQL_API_PDO::app()->result($result, $row, $field);
	}
	
	function mysql_select_db($dbname, $link = NULL) {
		return MySQL_API_PDO::app()->connect_new_db($dbname);
	}
	
	function mysql_set_charset($charset, $link = NULL) {
		return false;
	}
	
	function mysql_stat($link = NULL) {
		return MySQL_API_PDO::app()->getAttribute(PDO::ATTR_SERVER_INFO);
	}
	
	function mysql_tablename($result, $i) {
		$db = MySQL_API_PDO::app();
		
		$j = 0;
		$colname = "";
		$tables = array();
		while($row = $db->fetch_array($result)) {
			if($colname == "") {
				$colname = array_keys($row);
				$colname = $colname[0];
			}
			
			if($j == $i) {
				return $row[$colname];
			}
			
			$j++;
		}
		
		return "";
	}
	
	function mysql_thread_id($link = NULL) {
		return 0;
	}
	
	function mysql_unbuffered_query($query, $link = NULL) {
		return MySQL_API_PDO::app()->query($query);
	}
}
