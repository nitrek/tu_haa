<?php
/**
 * Contains functions related to Authentication and Authorization.
 */

if (! defined('TU_HAA')) {
    exit;
}

/**
 * Securely starts a session.
 */
function HAA_secureSession()
{
    // Custom session name.
    $session_name = 'TU_HAA';
    // Cookie will only be sent on Secure connections.
    $secure = false;
    // This stops JS to access session id.
    $http_only = true;
    // Force session to use only cookies.
    $use_cookies = ini_set('session.use_only_cookies', 1);
    if ($use_cookies == false) {
        echo 'Could not initiate a safe session.';
        exit;
    }
    // Get current cookies parameters.
    $cookie_params = session_get_cookie_params();
    // Set these parameters.
    session_set_cookie_params($cookie_params['lifetime']
        , $cookie_params['path']
        , $cookie_params['domain']
        , $secure
        , $http_only
    );
    // Set session name.
    session_name($session_name);
    // Start php session.
    session_start();
    // Regenerate session id.
    session_regenerate_id();
}

/**
 * Securely logs in a user with credentials.
 *
 * @param string $login_id Login ID
 * @param string $password Password
 * @param bool   $is_admin If user is Admin
 *
 * @return bool true or false
 */
function HAA_secureLogin($login_id, $password, $is_admin = false)
{
    // Initialize.
    $password = hash('sha512', $password);
    if ($is_admin) {
        $sql_query = 'SELECT `password` FROM ' . tblAdmin . ' '
            . 'WHERE `admin_id` = :login_id AND `password` = :password '
            . 'LIMIT 1';
    } else {
        $sql_query = 'SELECT * FROM ' . tblGroupId . ' '
            . 'WHERE `group_id` = :login_id AND `password` = :password '
            . 'LIMIT 1';

        // SQL query to update allotment_status.
        $update_last_login_query = 'UPDATE ' . tblGroupId . ' '
            . 'SET `last_login` = :last_login '
            . 'WHERE `group_id` = :group_id';
    }

    $query_params = array(
        ':login_id' => $login_id,
        ':password' => $password
    );

    $result = $GLOBALS['dbi']->executeQuery($sql_query, $query_params);

    if ($result->rowCount() == 1) {
        // login_id and password are correct.
        // Get the fetched row.
        $group_details = $result->fetch();
        // Check for login limit.
        if (time() - (int) $group_details['last_login'] < 900) {
            $_SESSION['login_limit'] = true;
            $_SESSION['login_error'] = 'Last login at: '
                . date('F j, Y, g:i:s a', (int) $group_details['last_login'])
                . '. '
                . 'Try in 15 mins.';
            return false;
        }
        $_SESSION['last_login'] = time();
        // Get the user-agent string of the user.
        $user_browser = $_SERVER['HTTP_USER_AGENT'];
        // Get allotment process status.
        $allotment_status = HAA_getAllotmentProcessStatus();
        // Set session variables.
        $_SESSION['login_id'] = $login_id;
        $_SESSION['login_limit'] = false;
        $_SESSION['login_string'] = hash('sha512', $password . $user_browser);
        if (! $is_admin) {
            $_SESSION['group_size'] = $group_details['group_size'];
            $params = array(':last_login' => (string) time(), ':group_id' => $login_id);
            $result = $GLOBALS['dbi']->executeQuery(
                $update_last_login_query,
                array(':last_login' => (string) time(), ':group_id' => $login_id)
            );
            HAA_updateGroupAllotmentStatus();
        } else {
            $_SESSION['is_admin'] = true;
        }
        HAA_updateAllotmentProcessStatus();
        // Login successful.
        return true;

    } else {
        // Invalid credentials; check for number of invalid login attempts.
        if (isset($_SESSION['login_attempts'])) {
            if ($_SESSION['login_attempts'] < 2) {
                $_SESSION['login_attempts']++;
            } else {
                // Display captcha if number of invalid login attempts > 2.
                $_SESSION['show_captcha'] = true;
            }
        } else {
            $_SESSION['login_attempts'] = 1;
        }
        $_SESSION['login_error'] = 'Invalid Username/Password.';
        return false;
    }
}

/**
 * Checks if user is logged in or not.
 *
 * @return bool true or false
 */
function HAA_checkLoginStatus($is_admin = false)
{
    unset($_SESSION['login_error']);
    // Check if all session variables are set.
    if (isset($_SESSION['login_id'], $_SESSION['login_string'])) {
        if ((time() - (int) $_SESSION['last_login'] > 900)) {
            HAA_logout();
            $_SESSION['login_error'] = 'Your session expired. Login again.';
            return false;
        }
        // Fetch session variables for verification.
        $login_id = $_SESSION['login_id'];
        $login_string = $_SESSION['login_string'];
        // Get the user-agent string of the user.
        $user_browser = $_SERVER['HTTP_USER_AGENT'];
        // SQL query to fetch password from DB.
        if ($is_admin) {
            $sql_query = 'SELECT `password` FROM ' . tblAdmin . ' '
                . 'WHERE `admin_id` = :login_id '
                . 'LIMIT 1';
        } else {
            $sql_query = 'SELECT * FROM ' . tblGroupId . ' '
                . 'WHERE `group_id` = :login_id '
                . 'LIMIT 1';
        }

        // Execute query.
        $query_params = array(':login_id' => $login_id);
        $result = $GLOBALS['dbi']->executeQuery($sql_query, $query_params);
        if ($result->rowCount() == 1) {
            // Get password saved in DB.
            $row = $result->fetch();
            $password = $row['password'];
            // Create check login_string.
            $login_check = hash('sha512', $password . $user_browser);
            if ($login_string == $login_check) {
                // Update allotment process status.
                HAA_updateAllotmentProcessStatus();
                if (! $is_admin) {
                    // Update group allotment status.
                    HAA_updateGroupAllotmentStatus();
                }
                // Logged in.
                return true;
            } else {
                // Not logged in.
                $_SESSION['login_error'] = 'Invalid Username/Password.';
                return false;
            }
        } else {
            // Not logged in.
            $_SESSION['login_error'] = 'Invalid Username/Password.';
            return false;
        }
    } else {
        // Not logged in.
        return false;
    }
}

/**
 * Redirects a user to a page based on allotment_status
 */
function HAA_pageCheck()
{
    if (isset($_SESSION['allotment_status'])) {
        $allotment_status = $_SESSION['allotment_status'];
    } else {
        $allotment_status = '';
    }
    $curr_filename = basename($_SERVER['REQUEST_URI'], ".php");

    switch ($allotment_status) {
        case 'SELECT':
            if ($curr_filename != 'map') {
                HAA_redirectTo('map.php');
            }
            break;
        case 'ALLOT':
            if ($curr_filename != 'allot') {
                HAA_redirectTo('allot.php');
            }
            break;
        case 'COMPLETE':
            if ($curr_filename != 'allot') {
                HAA_redirectTo('allot.php');
            }
            break;
        default:
            //HAA_redirectTo('login.php');
            break;
    }
}

/**
 * Fetches details about allotment process from `tblAllotmentStatus`
 *
 * @return PDO object
 */
function HAA_getAllotmentProcessStatus()
{
    // SQL query.
    $sql_query = 'SELECT * FROM ' . tblAllotmentStatus . ' '
        . 'LIMIT 1';

    // Execute query.
    $result = $GLOBALS['dbi']->executeQuery($sql_query, array());

    return $result->fetch();
}

/**
 * Fetches allotment status of group from `tblGroupId`
 *
 * @return string
 */
function HAA_getGroupAllotmentStatus($group_id)
{
    // SQL query.
    $sql_query = 'SELECT `allotment_status` FROM ' . tblGroupId . ' '
        . 'WHERE `group_id` = :group_id';

    // Execute query.
    $result = $GLOBALS['dbi']->executeQuery($sql_query, array(':group_id' => $group_id));
    $row = $result->fetch();

    return $row['allotment_status'];
}

/**
 * Updates allotment process status.
 */
function HAA_updateAllotmentProcessStatus()
{
    // Get allotment process status.
    $status = $GLOBALS['allotment_process_status'];
    $_SESSION['process_status'] = $status['process_status'];
}

/**
 * Updates group allotment status.
 */
function HAA_updateGroupAllotmentStatus()
{
    // Get allotment status.
    $group_id = $_SESSION['login_id'];
    $status = HAA_getGroupAllotmentStatus($group_id);
    $_SESSION['allotment_status'] = $status;
}

/**
 * Checks whether to display any message in global message box or not.
 *
 * @return bool
 */
function HAA_checkToDisplayGlobalMessage()
{
    // Get allotment process status.
    $status = $GLOBALS['allotment_process_status'];

    return ($status['show_message'] == 'SHOW' ? true : false);
}

/**
 * Returns `login_status` ('ENABLED', 'DISABLED')
 *
 * @return string $login_status ('ENABLED', 'DISABLED')
 */
function HAA_isLoginEnabled()
{
    // SQL query.
    $sql_query = 'SELECT `login_status` FROM ' . tblAllotmentStatus . ' '
        . 'LIMIT 1';
    // Execute query.
    $result = $GLOBALS['dbi']->executeQuery($sql_query, array());
    $login_status = $result->fetch();

    return $login_status['login_status'];
}

/**
 * Returns `login_message`
 *
 * @return string $login_status
 */
function HAA_getLoginStatusMessage()
{
    // SQL query.
    $sql_query = 'SELECT `login_message` FROM ' . tblAllotmentStatus . ' '
        . 'LIMIT 1';
    // Execute query.
    $result = $GLOBALS['dbi']->executeQuery($sql_query, array());
    $login_status = $result->fetch();

    return $login_status['login_message'];
}

/**
 * Updates allotment status inside database.
 *
 * @param type $allotment_status Array containing column values
 *
 * @return bool
 */
function HAA_updateAllotmentStatus($allotment_status)
{
    // SQL query.
    $update_query = 'UPDATE `' . tblAllotmentStatus . '` '
            . 'SET `process_status` = :process_status, '
            . '`message` = :message, '
            . '`show_message` = :show_message, '
            . '`login_status` = :login_status, '
            . '`registrations` = :registrations';
    $result = $GLOBALS['dbi']->executeQuery(
            $update_query,
            array(
                ':process_status' => $allotment_status['process_status'],
                ':message' => $allotment_status['message'],
                ':show_message' => $allotment_status['show_message'],
                ':registrations' => $allotment_status['registrations'],
                ':login_status' => $allotment_status['login_status']
            )
    );

    return $result;
}

function HAA_logout()
{
    global $is_admin;
    
    if (!$is_admin) {
        // SQL query to update allotment_status.
        $update_last_login_query = 'UPDATE ' . tblGroupId . ' '
            . 'SET `last_login` = :last_login '
            . 'WHERE `group_id` = :group_id';
        $params = array(
            ':last_login' => (string) (time() - 3600),
            ':group_id' => $_SESSION['login_id']
        );
        $result = $GLOBALS['dbi']->executeQuery(
            $update_last_login_query,
            $params
        );
    }

    // Unset all session variables.
    $_SESSION = array();

    // Get session parameters.
    $params = session_get_cookie_params();

    // Delete the actual cookie.
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );

    // Destroy session
    session_destroy();
}
?>