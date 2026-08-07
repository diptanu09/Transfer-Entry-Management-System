<?php
/**
 * Oracle 11g Database connection using native OCI8 driver wrapper.
 * Configured for SID: DB11G @ 192.168.100.247
 */

define('DB_HOST', '192.168.100.247');    // Oracle Server IP
define('DB_PORT', '1521');               // Oracle Port
define('DB_SID',  'DB11G');              // Oracle SID
define('DB_USER', 'TransferEntry');      // Schema Username
define('DB_PASS', 'TransferEntry');      // Schema Password

class Oci8PdoWrapper {
    /** @var resource|null */
    private $conn;
    /** @var bool */
    private $in_transaction = false;

    /**
     * @param string $username
     * @param string $password
     * @param string $connection_string
     */
    public function __construct(string $username, string $password, string $connection_string) {
        $this->conn = @oci_connect($username, $password, $connection_string, 'AL32UTF8');
        if (!$this->conn) {
            $e = oci_error();
            throw new Exception("Oracle Connection Failed: " . ($e['message'] ?? 'Unable to connect'));
        }
    }

    /**
     * @param string $sql
     * @return Oci8StatementWrapper
     */
    public function prepare(string $sql): Oci8StatementWrapper {
        return new Oci8StatementWrapper($this->conn, $sql, $this->in_transaction);
    }

    /**
     * @param string $sql
     * @return Oci8StatementWrapper
     */
    public function query(string $sql): Oci8StatementWrapper {
        $stmt = $this->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    /**
     * @param string $sql
     * @return bool
     */
    public function exec(string $sql): bool {
        $stmt = $this->prepare($sql);
        return $stmt->execute();
    }

    /**
     * @return bool
     */
    public function beginTransaction(): bool {
        $this->in_transaction = true;
        return true;
    }

    /**
     * @return bool
     */
    public function commit(): bool {
        if ($this->conn) {
            oci_commit($this->conn);
        }
        $this->in_transaction = false;
        return true;
    }

    /**
     * @return bool
     */
    public function rollBack(): bool {
        if ($this->conn) {
            oci_rollback($this->conn);
        }
        $this->in_transaction = false;
        return true;
    }

    /**
     * @param string|null $name
     * @return int
     */
    public function lastInsertId(?string $name = null): int {
        return 0;
    }
}

class Oci8StatementWrapper {
    /** @var resource */
    private $conn;
    /** @var string */
    private $sql;
    /** @var resource */
    private $stmt;
    /** @var bool */
    private $in_transaction;
    /** @var array */
    private $bound_params = [];

    /**
     * @param resource $conn
     * @param string $sql
     * @param bool $in_transaction
     */
    public function __construct($conn, string $sql, bool $in_transaction = false) {
        $this->conn = $conn;
        $this->in_transaction = $in_transaction;

        // Convert positional '?' parameters to Oracle named parameters (:p1, :p2, ...)
        $count = 0;
        $this->sql = preg_replace_callback('/\?/', function($matches) use (&$count) {
            $count++;
            return ":p" . $count;
        }, $sql);

        $this->stmt = oci_parse($this->conn, $this->sql);
        if (!$this->stmt) {
            $e = oci_error($this->conn);
            throw new Exception("Oracle SQL Parse Error: " . ($e['message'] ?? 'Syntax error'));
        }
    }

    /**
     * @param array $params
     * @return bool
     */
    public function execute(array $params = []): bool {
        if (!empty($params)) {
            $this->bound_params = array_values($params);

            foreach ($this->bound_params as $index => &$value) {
                $param_name = ":p" . ($index + 1);
                oci_bind_by_name($this->stmt, $param_name, $this->bound_params[$index]);
            }
        }

        $mode = $this->in_transaction ? OCI_NO_AUTO_COMMIT : OCI_COMMIT_ON_SUCCESS;
        $result = @oci_execute($this->stmt, $mode);

        if (!$result) {
            $e = oci_error($this->stmt);
            throw new Exception("Oracle Execution Error: " . ($e['message'] ?? 'Execution failed'));
        }
        return true;
    }

    /**
     * @return array
     */
    public function fetchAll(): array {
        $rows = [];
        oci_fetch_all($this->stmt, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW + OCI_ASSOC);
        
        $result = [];
        foreach ($rows as $row) {
            $lower_row = [];
            foreach ($row as $k => $v) {
                if (is_object($v) && get_class($v) === 'OCI-Lob') {
                    $v = $v->read($v->size());
                }
                $lower_row[strtolower($k)] = $v;
            }
            $result[] = $lower_row;
        }
        return $result;
    }

    /**
     * @return array|bool
     */
    public function fetch() {
        $row = oci_fetch_array($this->stmt, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS);
        if (!$row) return false;

        $lower_row = [];
        foreach ($row as $k => $v) {
            if (is_object($v) && get_class($v) === 'OCI-Lob') {
                $v = $v->read($v->size());
            }
            $lower_row[strtolower($k)] = $v;
        }
        return $lower_row;
    }

    /**
     * @return mixed
     */
    public function fetchColumn() {
        $row = oci_fetch_array($this->stmt, OCI_NUM);
        return $row ? $row[0] : false;
    }
}

/**
 * @return Oci8PdoWrapper
 */
function get_db_connection(): Oci8PdoWrapper {
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

/**
 * @return Oci8PdoWrapper
 */
function initialize_database(): Oci8PdoWrapper {
    return get_db_connection();
}