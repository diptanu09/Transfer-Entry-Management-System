<?php
/**
 * Oracle 11g Database connection using native OCI8 driver.
 * Compatible with PHP 7.3.2 and existing PDO call patterns.
 */

define('DB_HOST', '192.168.100.247');           // Oracle Host / IP
define('DB_PORT', '1521');                // Oracle Port
define('DB_SID', 'DB11G');          // Service Name or SID
define('DB_USER', 'TransferEntry');       // Schema Username
define('DB_PASS', 'TransferEntry'); // Schema Password

class Oci8PdoWrapper {
    private $conn;
    private $in_transaction = false;

    public function __construct($username, $password, $connection_string) {
        $this->conn = @oci_connect($username, $password, $connection_string, 'AL32UTF8');
        if (!$this->conn) {
            $e = oci_error();
            throw new Exception("Oracle OCI8 Connection Failed: " . $e['message']);
        }
    }

    public function prepare($sql) {
        return new Oci8StatementWrapper($this->conn, $sql, $this->in_transaction);
    }

    public function query($sql) {
        $stmt = $this->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    public function beginTransaction() {
        $this->in_transaction = true;
        return true;
    }

    public function commit() {
        if ($this->conn) {
            oci_commit($this->conn);
        }
        $this->in_transaction = false;
        return true;
    }

    public function rollBack() {
        if ($this->conn) {
            oci_rollback($this->conn);
        }
        $this->in_transaction = false;
        return true;
    }

    public function lastInsertId($name = null) {
        // Oracle uses sequences/triggers for primary keys
        return 0;
    }
}

class Oci8StatementWrapper {
    private $conn;
    private $sql;
    private $stmt;
    private $in_transaction;

    public function __construct($conn, $sql, $in_transaction = false) {
        $this->conn = $conn;
        $this->in_transaction = $in_transaction;

        // Convert positional '?' parameters to Oracle ':p1', ':p2' named parameters
        $count = 0;
        $this->sql = preg_replace_callback('/\?/', function($matches) use (&$count) {
            $count++;
            return ":p" . $count;
        }, $sql);

        $this->stmt = oci_parse($this->conn, $this->sql);
        if (!$this->stmt) {
            $e = oci_error($this->conn);
            throw new Exception("Oracle Prepare Error: " . $e['message']);
        }
    }

    public function execute($params = []) {
        if (!empty($params)) {
            $i = 1;
            foreach ($params as $param) {
                $param_name = ":p" . $i;
                oci_bind_by_name($this->stmt, $param_name, $params[$i - 1]);
                $i++;
            }
        }

        $mode = $this->in_transaction ? OCI_NO_AUTO_COMMIT : OCI_COMMIT_ON_SUCCESS;
        $result = @oci_execute($this->stmt, $mode);

        if (!$result) {
            $e = oci_error($this->stmt);
            throw new Exception("Oracle Execute Error: " . $e['message']);
        }
        return true;
    }

    public function fetchAll() {
        $rows = [];
        oci_fetch_all($this->stmt, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC);
        
        // Convert column keys to lowercase to match existing PHP code
        $result = [];
        foreach ($rows as $row) {
            $lower_row = [];
            foreach ($row as $k => $v) {
                // Handle BLOB descriptors if fetching BLOB content
                if (is_object($v) && get_class($v) === 'OCI-Lob') {
                    $v = $v->read($v->size());
                }
                $lower_row[strtolower($k)] = $v;
            }
            $result[] = $lower_row;
        }
        return $result;
    }

    public function fetch() {
        $row = oci_fetch_array($this->stmt, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS);
        if (!$row) return false;

        $lower_row = [];
        foreach ($row as $k => $v) {
            $lower_row[strtolower($k)] = $v;
        }
        return $lower_row;
    }

    public function fetchColumn() {
        $row = oci_fetch_array($this->stmt, OCI_NUM);
        return $row ? $row[0] : false;
    }
}

function get_db_connection() {
    static $db = null;
    if ($db === null) {
        $tns = "(DESCRIPTION =
            (ADDRESS = (PROTOCOL = TCP)(HOST = " . DB_HOST . ")(PORT = " . DB_PORT . "))
            (CONNECT_DATA = (SID = " . DB_SID . "))
        )";
        $db = new Oci8PdoWrapper(DB_USER, DB_PASS, $tns);
    }
    return $db;
}

function initialize_database() {
    return get_db_connection();
}