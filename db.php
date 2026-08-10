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

// Fallback constant definitions for VS Code static analysis
if (!defined('OCI_COMMIT_ON_SUCCESS')) define('OCI_COMMIT_ON_SUCCESS', 32);
if (!defined('OCI_NO_AUTO_COMMIT')) define('OCI_NO_AUTO_COMMIT', 0);
if (!defined('OCI_ASSOC')) define('OCI_ASSOC', 1);
if (!defined('OCI_NUM')) define('OCI_NUM', 2);
if (!defined('OCI_FETCHSTATEMENT_BY_ROW')) define('OCI_FETCHSTATEMENT_BY_ROW', 32);
if (!defined('OCI_RETURN_NULLS')) define('OCI_RETURN_NULLS', 4);
if (!defined('OCI_RETURN_LOBS')) define('OCI_RETURN_LOBS', 8);

// Conditional stubs to eliminate VS Code / PHP Linter unknown function warnings
if (!function_exists('oci_connect')) {
    function oci_connect($username, $password, $connection_string, $encoding = '') { return false; }
    function oci_error($resource = null) { return ['message' => 'OCI8 extension not loaded']; }
    function oci_commit($connection) { return false; }
    function oci_rollback($connection) { return false; }
    function oci_parse($connection, $sql) { return false; }
    function oci_bind_by_name($statement, $bv_name, &$variable, $maxlength = -1, $type = 0) { return false; }
    function oci_execute($statement, $mode = 0) { return false; }
    function oci_fetch_all($statement, &$output, $skip = 0, $maxrows = -1, $flags = 0) { return 0; }
    /** @return array|false */
    function oci_fetch_array($statement, $mode = 0) { return false; }
}

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
        $descriptors_to_free = [];
        if (!empty($params)) {
            $this->bound_params = array_values($params);

            foreach ($this->bound_params as $index => &$value) {
                $param_name = ":p" . ($index + 1);
                if (is_string($value) && strncmp($value, '%PDF-', 5) === 0) {
                    $lob = oci_new_descriptor($this->conn, OCI_DTYPE_LOB);
                    if ($lob) {
                        $lob->writeTemporary($value, OCI_TEMP_BLOB);
                        oci_bind_by_name($this->stmt, $param_name, $lob, -1, OCI_B_BLOB);
                        $descriptors_to_free[] = $lob;
                    } else {
                        oci_bind_by_name($this->stmt, $param_name, $this->bound_params[$index]);
                    }
                } elseif (is_string($value) && strlen($value) > 500) {
                    $lob = oci_new_descriptor($this->conn, OCI_DTYPE_LOB);
                    if ($lob) {
                        $lob->writeTemporary($value, OCI_TEMP_CLOB);
                        oci_bind_by_name($this->stmt, $param_name, $lob, -1, OCI_B_CLOB);
                        $descriptors_to_free[] = $lob;
                    } else {
                        oci_bind_by_name($this->stmt, $param_name, $this->bound_params[$index]);
                    }
                } else {
                    oci_bind_by_name($this->stmt, $param_name, $this->bound_params[$index]);
                }
            }
        }

        $mode = $this->in_transaction ? OCI_NO_AUTO_COMMIT : OCI_COMMIT_ON_SUCCESS;
        $result = @oci_execute($this->stmt, $mode);

        foreach ($descriptors_to_free as $lob) {
            @$lob->close();
            @$lob->free();
        }

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
        if (!is_array($row)) return false;

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
        return (is_array($row) && isset($row[0])) ? $row[0] : false;
    }
}

/**
 * @return Oci8PdoWrapper
 */
class SafePdoWrapper {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function prepare(string $sql) {
        return $this->pdo->prepare($sql);
    }

    public function query(string $sql) {
        return $this->pdo->query($sql);
    }

    public function exec(string $sql) {
        return $this->pdo->exec($sql);
    }

    public function beginTransaction(): bool {
        if (!$this->pdo->inTransaction()) {
            return $this->pdo->beginTransaction();
        }
        return true;
    }

    public function commit(): bool {
        if ($this->pdo->inTransaction()) {
            return $this->pdo->commit();
        }
        return true;
    }

    public function rollBack(): bool {
        if ($this->pdo->inTransaction()) {
            return $this->pdo->rollBack();
        }
        return true;
    }

    public function lastInsertId(?string $name = null) {
        return $this->pdo->lastInsertId($name);
    }
}

/**
 * @return Oci8PdoWrapper|SafePdoWrapper
 */
function get_db_connection() {
    static $db = null;
    if ($db === null) {
        $tns = "(DESCRIPTION =
            (ADDRESS = (PROTOCOL = TCP)(HOST = " . DB_HOST . ")(PORT = " . DB_PORT . "))
            (CONNECT_DATA = (SID = " . DB_SID . "))
        )";

        if (extension_loaded('oci8') && function_exists('oci_connect')) {
            try {
                $db = new Oci8PdoWrapper(DB_USER, DB_PASS, $tns);
                return $db;
            } catch (Throwable $e) {
                // Fall back to SQLite if Oracle connection fails
            }
        }

        $sqlite_file = __DIR__ . '/data/daily_reports.db';
        $pdo = new PDO('sqlite:' . $sqlite_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db = new SafePdoWrapper($pdo);
    }
    return $db;
}

/**
 * @return Oci8PdoWrapper|SafePdoWrapper
 */
function initialize_database() {
    return get_db_connection();
}