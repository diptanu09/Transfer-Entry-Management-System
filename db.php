<?php
/**
 * Oracle 11g Database connection using native OCI8 driver wrapper.
 * Configured for SID: DB11G @ 192.168.100.247
 */

define('DB_HOST', '192.168.100.247');    // Oracle Server IP
define('DB_PORT', '1521');               // Oracle Port
define('DB_SID',  'DB11G');              // Oracle SID
define('DB_USER', 'TransferEntry');      // Main Application Schema Username
define('DB_PASS', 'TransferEntry');      // Main Application Schema Password

define('VLCS_DB_USER', 'vlcs');          // TE Batch Posting Service Schema Username
define('VLCS_DB_PASS', 'vlcs');          // TE Batch Posting Service Schema Password

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
            } catch (Throwable $e) {
                // Fall back to SQLite if Oracle connection fails
            }
        }

        if ($db === null) {
            $sqlite_file = __DIR__ . '/data/daily_reports.db';
            $pdo = new PDO('sqlite:' . $sqlite_file);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db = new SafePdoWrapper($pdo);
        }
    }
    ensure_database_schema($db);
    return $db;
}

/**
 * Get dedicated database connection specifically for TE Batch Posting service (schema vlcs).
 * Connects to Oracle as user 'vlcs' / 'vlcs', fallback to main DB connection or SQLite.
 *
 * @return Oci8PdoWrapper|SafePdoWrapper
 */
function get_vlcs_db_connection() {
    static $vlcs_db = null;
    if ($vlcs_db === null) {
        $tns = "(DESCRIPTION =
            (ADDRESS = (PROTOCOL = TCP)(HOST = " . DB_HOST . ")(PORT = " . DB_PORT . "))
            (CONNECT_DATA = (SID = " . DB_SID . "))
        )";

        if (extension_loaded('oci8') && function_exists('oci_connect')) {
            try {
                $vlcs_db = new Oci8PdoWrapper(VLCS_DB_USER, VLCS_DB_PASS, $tns);
            } catch (Throwable $e) {
                // Fallback if dedicated vlcs Oracle connection is unavailable
            }
        }

        if ($vlcs_db === null) {
            $vlcs_db = get_db_connection();
        }
    }
    return $vlcs_db;
}

/**
 * Ensures composite unique constraint on (accounting_month, sectional_number) in generated_transfer_reports
 * and drops legacy single-column UNIQUE constraints on sectional_number (e.g. Oracle SYS_C... constraints).
 * @param Oci8PdoWrapper|SafePdoWrapper $db
 */
function ensure_database_schema($db) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        if ($db instanceof Oci8PdoWrapper) {
            // 1. Drop legacy single-column UNIQUE constraints on SECTIONAL_NUMBER in Oracle
            $sqlCons = "
                SELECT uc.constraint_name 
                FROM user_constraints uc
                JOIN user_cons_columns ucc ON uc.constraint_name = ucc.constraint_name
                WHERE UPPER(uc.table_name) = 'GENERATED_TRANSFER_REPORTS'
                  AND uc.constraint_type = 'U'
                  AND UPPER(ucc.column_name) = 'SECTIONAL_NUMBER'
                  AND uc.constraint_name NOT IN (
                      SELECT constraint_name 
                      FROM user_cons_columns 
                      WHERE UPPER(table_name) = 'GENERATED_TRANSFER_REPORTS' 
                        AND UPPER(column_name) <> 'SECTIONAL_NUMBER'
                  )
            ";
            try {
                $stmt = $db->prepare($sqlCons);
                $stmt->execute();
                $consRows = $stmt->fetchAll();
                foreach ($consRows as $cRow) {
                    $cName = $cRow['constraint_name'] ?? '';
                    if ($cName) {
                        try {
                            $db->exec("ALTER TABLE GENERATED_TRANSFER_REPORTS DROP CONSTRAINT {$cName}");
                        } catch (Throwable $eDropCons) {}
                    }
                }
            } catch (Throwable $exCons) {}

            // 2. Drop legacy single-column UNIQUE indexes on SECTIONAL_NUMBER in Oracle
            $sqlIdx = "
                SELECT ui.index_name 
                FROM user_indexes ui
                JOIN user_ind_columns uic ON ui.index_name = uic.index_name
                WHERE UPPER(ui.table_name) = 'GENERATED_TRANSFER_REPORTS'
                  AND ui.uniqueness = 'UNIQUE'
                  AND UPPER(uic.column_name) = 'SECTIONAL_NUMBER'
                  AND ui.index_name NOT IN (
                      SELECT index_name 
                      FROM user_ind_columns 
                      WHERE UPPER(table_name) = 'GENERATED_TRANSFER_REPORTS' 
                        AND UPPER(column_name) <> 'SECTIONAL_NUMBER'
                  )
            ";
            try {
                $stmtIdx = $db->prepare($sqlIdx);
                $stmtIdx->execute();
                $idxRows = $stmtIdx->fetchAll();
                foreach ($idxRows as $iRow) {
                    $iName = $iRow['index_name'] ?? '';
                    if ($iName && strtoupper($iName) !== 'IDX_ACCT_SEC_NUM') {
                        try {
                            $db->exec("DROP INDEX {$iName}");
                        } catch (Throwable $eDropIdx) {}
                    }
                }
            } catch (Throwable $exIdx) {}

            // 3. Create composite unique index on (accounting_month, sectional_number)
            try {
                $db->exec("CREATE UNIQUE INDEX idx_acct_sec_num ON GENERATED_TRANSFER_REPORTS (accounting_month, sectional_number)");
            } catch (Throwable $exCreate) {}

            // 4. Ensure vlcs schema tables in Oracle if not present
            try {
                $db->exec("
                    CREATE TABLE B2_TE_HDRS (
                        TE_NO NUMBER(8,0) PRIMARY KEY,
                        GRANT_CODE NUMBER(2,0) DEFAULT 2,
                        PARAMETER_CODE NUMBER(3,0) DEFAULT 27,
                        SOURCE_CODE NUMBER(3,0) DEFAULT 55,
                        TE_DATE DATE,
                        SST_TAG VARCHAR2(1 BYTE) DEFAULT 'N',
                        BELATED_TAG VARCHAR2(1 BYTE) DEFAULT 'N',
                        DR_CR_DD_DC_TAG VARCHAR2(2 BYTE),
                        DAA_TAG VARCHAR2(1 BYTE),
                        CONTINGENCY_TAG VARCHAR2(1 BYTE) DEFAULT 'N',
                        FIN_YEAR_CODE NUMBER(2,0),
                        ACCOUNTING_MONTH NUMBER(2,0),
                        MONTH_OF_ACCOUNT DATE,
                        CREATE_USER VARCHAR2(10 BYTE) DEFAULT 'DIR',
                        CREATE_DATE DATE,
                        MODIFY_USER VARCHAR2(10 BYTE) DEFAULT 'DIR',
                        MODIFY_DATE DATE,
                        TE_APPROVE_TAG VARCHAR2(1 BYTE) DEFAULT 'Y',
                        TE_DESCR VARCHAR2(60 BYTE),
                        SL_NO NUMBER(3,0) DEFAULT 28,
                        SOURCE_MINISTRY_TAG VARCHAR2(1 BYTE) DEFAULT 'S',
                        TE_SANCTION_NO VARCHAR2(18 BYTE),
                        TAG VARCHAR2(1 BYTE) DEFAULT 'A',
                        MAJOR_HEAD_CODE NUMBER(4,0),
                        SUB_MAJOR_HEAD_CODE NUMBER(4,0),
                        MINOR_HEAD_CODE NUMBER(4,0),
                        SUB_HEAD_CODE NUMBER(4,0),
                        SUB_SUB_HEAD_CODE NUMBER(4,0),
                        DETAIL_HEAD_CODE NUMBER(4,0),
                        SUB_DETAIL_HEAD_CODE NUMBER(4,0),
                        INITIAL_AMOUNT NUMBER(16,2),
                        CAT_SCHEME_CODE NUMBER(4,0) DEFAULT 8
                    )
                ");
            } catch (Throwable $exHdrS) {}

            try {
                $db->exec("
                    CREATE TABLE B2_TE_HDR (
                        TE_NO NUMBER(8,0),
                        MAJOR_HEAD_CODE NUMBER(4,0) DEFAULT 8675,
                        SUB_MAJOR_HEAD_CODE NUMBER(2,0) DEFAULT 0,
                        MINOR_HEAD_CODE NUMBER(3,0) DEFAULT 106,
                        SUB_HEAD_CODE NUMBER(4,0) DEFAULT 3,
                        SUB_SUB_HEAD_CODE NUMBER(4,0) DEFAULT 0,
                        DETAIL_HEAD_CODE NUMBER(3,0) DEFAULT 0,
                        SUB_DETAIL_HEAD_CODE NUMBER(3,0) DEFAULT 0,
                        INITIAL_AMOUNT NUMBER(14,2),
                        CREATE_USER VARCHAR2(10 BYTE) DEFAULT 'DIR',
                        CREATE_DATE DATE,
                        MODIFY_USER VARCHAR2(10 BYTE) DEFAULT 'DIR',
                        MODIFY_DATE DATE,
                        DR_CR_DD_DC_TAG VARCHAR2(2 BYTE) DEFAULT 'DR',
                        DAA_TAG VARCHAR2(1 BYTE) DEFAULT 'N',
                        CAT_SCHEME_CODE NUMBER(4,0) DEFAULT 8,
                        GRANT_CODE NUMBER(2,0) DEFAULT 43,
                        PARAMETER_CODE NUMBER(2,0) DEFAULT 27
                    )
                ");
            } catch (Throwable $exHdr) {}

            try {
                $db->exec("
                    CREATE TABLE B2_TE_DTLS (
                        TE_NO NUMBER(8,0),
                        SRL_NO NUMBER(8,0),
                        GRANT_CODE NUMBER(2,0) DEFAULT 43,
                        PARAMETER_CODE NUMBER(3,0) DEFAULT 27,
                        MAJOR_HEAD_CODE NUMBER(4,0) DEFAULT 1601,
                        SUB_MAJOR_HEAD_CODE NUMBER(2,0) DEFAULT 6,
                        MINOR_HEAD_CODE NUMBER(3,0) DEFAULT 101,
                        SUB_HEAD_CODE NUMBER(4,0),
                        SUB_SUB_HEAD_CODE NUMBER(4,0) DEFAULT 0,
                        DETAIL_HEAD_CODE NUMBER(3,0),
                        SUB_DETAIL_HEAD_CODE NUMBER(3,0) DEFAULT 0,
                        TO_AMOUNT NUMBER(14,2),
                        DAA_TAG VARCHAR2(1 BYTE) DEFAULT 'Y',
                        CONTINGENCY_TAG VARCHAR2(1 BYTE) DEFAULT 'N',
                        DR_CR_DD_DC_TAG VARCHAR2(2 BYTE) DEFAULT 'CR',
                        REMARKS VARCHAR2(40 BYTE),
                        REASONS VARCHAR2(40 BYTE),
                        CREATE_USER VARCHAR2(10 BYTE) DEFAULT 'DIR',
                        CREATE_DATE DATE,
                        MODIFY_USER VARCHAR2(10 BYTE) DEFAULT 'DIR',
                        MODIFY_DATE DATE,
                        SL_NO NUMBER(3,0) DEFAULT 28,
                        CAT_SCHEME_CODE NUMBER(4,0) DEFAULT 8
                    )
                ");
            } catch (Throwable $exDtls) {}

            try {
                $db->exec("
                    CREATE TABLE VLCS_B2_TE_BATCH_LOG (
                        ID NUMBER(10,0) PRIMARY KEY,
                        BATCH_CODE VARCHAR2(30 BYTE),
                        FILTER_TYPE VARCHAR2(30 BYTE),
                        ACCOUNTING_MONTH VARCHAR2(30 BYTE),
                        FIN_YEAR VARCHAR2(20 BYTE),
                        RECORDS_POSTED NUMBER(8,0),
                        TOTAL_AMOUNT NUMBER(16,2),
                        STATUS VARCHAR2(20 BYTE),
                        MESSAGE VARCHAR2(500 BYTE),
                        RUN_USER VARCHAR2(50 BYTE),
                        RUN_DATE DATE
                    )
                ");
            } catch (Throwable $exLog) {}

            try {
                $db->exec("ALTER TABLE GENERATED_TRANSFER_REPORTS ADD (IS_POSTED NUMBER(1,0) DEFAULT 0)");
            } catch (Throwable $exCol) {}

            try {
                $db->exec("ALTER TABLE GENERATED_TRANSFER_REPORTS ADD (VLC_TE_NUMBER VARCHAR2(30 BYTE))");
            } catch (Throwable $exVlcTe) {}

            return;
        }

        @$db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_acct_sec_num ON generated_transfer_reports (accounting_month, sectional_number)");

        // Ensure columns and tables for SQLite fallback
        try {
            $db->exec("ALTER TABLE generated_transfer_reports ADD COLUMN is_posted INTEGER DEFAULT 0");
        } catch (Throwable $exColSq) {}

        try {
            $db->exec("ALTER TABLE generated_transfer_reports ADD COLUMN vlc_te_number TEXT");
        } catch (Throwable $exVlcTeSq) {}

        $db->exec("
            CREATE TABLE IF NOT EXISTS b2_te_hdrs (
                te_no INTEGER PRIMARY KEY,
                grant_code INTEGER DEFAULT 2,
                parameter_code INTEGER DEFAULT 27,
                source_code INTEGER DEFAULT 55,
                te_date TEXT,
                sst_tag TEXT DEFAULT 'N',
                belated_tag TEXT DEFAULT 'N',
                dr_cr_dd_dc_tag TEXT,
                daa_tag TEXT,
                contingency_tag TEXT DEFAULT 'N',
                fin_year_code INTEGER,
                accounting_month INTEGER,
                month_of_account TEXT,
                create_user TEXT DEFAULT 'DIR',
                create_date TEXT,
                modify_user TEXT DEFAULT 'DIR',
                modify_date TEXT,
                te_approve_tag TEXT DEFAULT 'Y',
                te_descr TEXT,
                sl_no INTEGER DEFAULT 28,
                source_ministry_tag TEXT DEFAULT 'S',
                te_sanction_no TEXT,
                tag TEXT DEFAULT 'A',
                major_head_code INTEGER,
                sub_major_head_code INTEGER,
                minor_head_code INTEGER,
                sub_head_code INTEGER,
                sub_sub_head_code INTEGER,
                detail_head_code INTEGER,
                sub_detail_head_code INTEGER,
                initial_amount REAL,
                cat_scheme_code INTEGER DEFAULT 8
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS b2_te_hdr (
                te_no INTEGER,
                major_head_code INTEGER DEFAULT 8675,
                sub_major_head_code INTEGER DEFAULT 0,
                minor_head_code INTEGER DEFAULT 106,
                sub_head_code INTEGER DEFAULT 3,
                sub_sub_head_code INTEGER DEFAULT 0,
                detail_head_code INTEGER DEFAULT 0,
                sub_detail_head_code INTEGER DEFAULT 0,
                initial_amount REAL,
                create_user TEXT DEFAULT 'DIR',
                create_date TEXT,
                modify_user TEXT DEFAULT 'DIR',
                modify_date TEXT,
                dr_cr_dd_dc_tag TEXT DEFAULT 'DR',
                daa_tag TEXT DEFAULT 'N',
                cat_scheme_code INTEGER DEFAULT 8,
                grant_code INTEGER DEFAULT 43,
                parameter_code INTEGER DEFAULT 27
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS b2_te_dtls (
                te_no INTEGER,
                srl_no INTEGER,
                grant_code INTEGER DEFAULT 43,
                parameter_code INTEGER DEFAULT 27,
                major_head_code INTEGER DEFAULT 1601,
                sub_major_head_code INTEGER DEFAULT 6,
                minor_head_code INTEGER DEFAULT 101,
                sub_head_code INTEGER,
                sub_sub_head_code INTEGER DEFAULT 0,
                detail_head_code INTEGER,
                sub_detail_head_code INTEGER DEFAULT 0,
                to_amount REAL,
                daa_tag TEXT DEFAULT 'Y',
                contingency_tag TEXT DEFAULT 'N',
                dr_cr_dd_dc_tag TEXT DEFAULT 'CR',
                remarks TEXT,
                reasons TEXT,
                create_user TEXT DEFAULT 'DIR',
                create_date TEXT,
                modify_user TEXT DEFAULT 'DIR',
                modify_date TEXT,
                sl_no INTEGER DEFAULT 28,
                cat_scheme_code INTEGER DEFAULT 8
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS vlcs_b2_te_batch_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_code TEXT,
                filter_type TEXT,
                accounting_month TEXT,
                fin_year TEXT,
                records_posted INTEGER,
                total_amount REAL,
                status TEXT,
                message TEXT,
                run_user TEXT,
                run_date TEXT
            )
        ");
    } catch (Throwable $ex) {
        // Ignore if schema checks completed
    }
}

/**
 * @return Oci8PdoWrapper|SafePdoWrapper
 */
function initialize_database() {
    return get_db_connection();
}