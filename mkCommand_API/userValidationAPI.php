<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

class UserValidator
{
    private $connection;
    private $memberConnection;
    private const DB_HOST = 'localhost';
    private const DB_PORT = 33060;
    private const DB_USER = 'prog4';
    private const DB_PASS = 'prog42023';
    private const DB_NAME = 'staff_portal2';
    private const DB_NAME_MEMBERS = 'mkmembers';

    // private const ADMIN_USERS = ['nathan'];
    private const ADMIN_USERS = ['nathan', 'chuasf'];
    // private const ADMIN_USERS = ['chuasf'];

    private const NORMAL_USER_ACCESS_ENABLED = true; // Set to true to enable normal user access

    public function __construct()
    {
        $this->initializeConnection();
    }

    private function initializeConnection()
    {
        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];

        try {
            $this->connection = new PDO(
                "mysql:host=" . self::DB_HOST . ";port=" . self::DB_PORT . ";dbname=" . self::DB_NAME,
                self::DB_USER,
                self::DB_PASS,
                $pdoOptions
            );

            $this->memberConnection = new PDO(
                "mysql:host=" . self::DB_HOST . ";port=" . self::DB_PORT . ";dbname=" . self::DB_NAME_MEMBERS,
                self::DB_USER,
                self::DB_PASS,
                $pdoOptions
            );
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public function validateUser($person, $comid = null, $id = null)
    {
        $person = trim(strtolower($person));

        // if (empty($person)) {
        //     return $this->createResponse(false, false, $person, $comid, $id, 'Person parameter required');
        // }

        // Check admin access first
        if ($this->isAdmin($person)) {
            return $this->createResponse(true, true, $person, $comid, $id, 'Access granted - admin user');
        }

        // Check normal user access
        if ($this->isAuthorizedNormalUser($person, $comid, $id)) {
            return $this->createResponse(true, true, $person, $comid, $id, 'Access granted - authorized user');
        }

        return $this->createResponse(true, false, $person, $comid, $id, 'Access denied - unauthorized user');
    }

    private function isAdmin($person)
    {
        return in_array($person, self::ADMIN_USERS);
    }

    private function isAuthorizedNormalUser($person, $comid, $id = null)
    {
        // Check if normal user access is enabled
        if (!self::NORMAL_USER_ACCESS_ENABLED) {
            return false;
        }

        // Validate comid parameter
        // if (empty($comid) || !is_numeric($comid)) {
        //     return false;
        // }

        try {
            // Primary lookup: users table
            $stmt = $this->connection->prepare("
                SELECT id FROM users 
                WHERE comp_id = ? 
                AND status = 1 
                AND LOWER(TRIM(person)) = ?
                LIMIT 1
            ");
            $stmt->execute([(int)$comid, $person]);

            if ($stmt->rowCount() > 0) {
                return true;
            }

            // Fallback: member_registrations by id (mkmembers database)
            if (!empty($id) && is_numeric($id)) {
                $stmt = $this->memberConnection->prepare("
                    SELECT id FROM member_registrations 
                    WHERE id = ? 
                    AND deleted = 0
                    LIMIT 1
                ");
                $stmt->execute([(int)$id]);

                return $stmt->rowCount() > 0;
            }

            return false;
        } catch (PDOException $e) {
            error_log("Database query failed: " . $e->getMessage());
            return false;
        }
    }

    private function createResponse($success, $authorized, $person, $comid, $id, $message)
    {
        return [
            'success' => $success,
            'authorized' => $authorized,
            'person' => $person,
            'comid' => $comid,
            'id' => $id,
            'message' => $message
        ];
    }

    public function __destruct()
    {
        $this->connection = null;
        $this->memberConnection = null;
    }
}

// Main execution
try {
    // Extract parameters from POST or GET
    $person = $_POST['person'] ?? $_GET['person'] ?? '';
    $comid = $_POST['comid'] ?? $_GET['comid'] ?? '';
    $id = $_POST['id'] ?? $_GET['id'] ?? '';

    $validator = new UserValidator();
    $response = $validator->validateUser($person, $comid, $id);

    echo json_encode($response);
} catch (Exception $e) {
    error_log("UserValidationAPI Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'authorized' => false,
        'person' => $person ?? '',
        'comid' => $comid ?? '',
        'id' => $id ?? '',
        'message' => 'System error - please try again'
    ]);
}