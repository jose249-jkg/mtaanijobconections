<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function api_response($status, $payload) {
    if (function_exists('http_response_code')) {
        http_response_code($status);
    } else {
        $messages = array(200 => 'OK', 201 => 'Created', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 422 => 'Unprocessable Entity', 500 => 'Internal Server Error', 503 => 'Service Unavailable');
        header('HTTP/1.1 ' . $status . ' ' . (isset($messages[$status]) ? $messages[$status] : 'Error'));
    }
    echo json_encode($payload);
    exit;
}

function request_data() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_response(400, array('ok' => false, 'error' => 'Expected a JSON request body.'));
    }
    return $data;
}

function required_text($data, $key, $label, $max_length) {
    $value = isset($data[$key]) ? trim((string)$data[$key]) : '';
    if ($value === '' || strlen($value) > $max_length) {
        api_response(422, array('ok' => false, 'error' => $label . ' is required and must be under ' . $max_length . ' characters.'));
    }
    return $value;
}

function optional_text($data, $key, $max_length) {
    $value = isset($data[$key]) ? trim((string)$data[$key]) : '';
    if (strlen($value) > $max_length) {
        api_response(422, array('ok' => false, 'error' => 'A submitted field is too long.'));
    }
    return $value;
}

function normalize_email($value) {
    $email = strtolower(trim((string)$value));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        api_response(422, array('ok' => false, 'error' => 'Enter a valid email address.'));
    }
    return $email;
}

function validate_phone($value) {
    $phone = trim((string)$value);
    if (!preg_match('/^(?:\+?254|0)[0-9]{9}$/', preg_replace('/[\s()\-]/', '', $phone))) {
        api_response(422, array('ok' => false, 'error' => 'Enter a valid Kenyan phone number.'));
    }
    return $phone;
}

function current_user() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_user() {
    $user = current_user();
    if (!$user) {
        api_response(401, array('ok' => false, 'error' => 'Sign in to continue.'));
    }
    return $user;
}

function require_admin() {
    $user = require_user();
    if ($user['role'] !== 'admin') {
        api_response(403, array('ok' => false, 'error' => 'Admin access is required.'));
    }
    return $user;
}

function require_csrf() {
    $sent = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    $saved = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    if ($sent === '' || $saved === '' || !hash_equals($saved, $sent)) {
        api_response(403, array('ok' => false, 'error' => 'The security token is missing or expired. Refresh and try again.'));
    }
}

function new_csrf_token() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function lookup_id($db, $table, $name) {
    if ($table !== 'services' && $table !== 'locations') {
        api_response(400, array('ok' => false, 'error' => 'Invalid lookup type.'));
    }
    $statement = $db->prepare('SELECT id FROM ' . $table . ' WHERE name = :name LIMIT 1');
    $statement->execute(array(':name' => $name));
    $id = $statement->fetchColumn();
    if (!$id) {
        api_response(422, array('ok' => false, 'error' => 'Choose a listed service and location.'));
    }
    return (int)$id;
}

function log_login($db, $user) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? @inet_pton($_SERVER['REMOTE_ADDR']) : false;
    $statement = $db->prepare('INSERT INTO login_logs (user_id, email, role, ip_address, signed_in_at) VALUES (:user_id, :email, :role, :ip, NOW())');
    $statement->bindValue(':user_id', (int)$user['id'], PDO::PARAM_INT);
    $statement->bindValue(':email', $user['email']);
    $statement->bindValue(':role', $user['role']);
    $statement->bindValue(':ip', $ip === false ? null : $ip, $ip === false ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $statement->execute();
}

function public_snapshot($db) {
    $providers = $db->query("SELECT p.id, u.name, p.business_name AS business, s.name AS service, l.name AS location, p.phone, p.description, '' AS rating, 0 AS reviews, 0 AS verified FROM service_providers p JOIN users u ON u.id = p.user_id JOIN services s ON s.id = p.service_id JOIN locations l ON l.id = p.location_id WHERE p.status = 'approved' ORDER BY p.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $jobs = $db->query("SELECT j.id, j.employer_name AS employer, j.title, s.name AS category, j.job_type AS type, l.name AS location, j.pay, j.phone, j.email, j.description, j.posted_at AS postedAt FROM jobs j JOIN services s ON s.id = j.service_id JOIN locations l ON l.id = j.location_id WHERE j.status = 'published' AND (j.expires_at IS NULL OR j.expires_at >= NOW()) ORDER BY j.posted_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $promotions = $db->query("SELECT pr.id, pr.provider_id AS providerId, pr.skill, pr.headline AS title, pr.details, pr.expires_at AS expiresAt, pr.active, pr.created_at AS createdAt FROM promotions pr JOIN service_providers p ON p.id = pr.provider_id WHERE pr.active = 1 AND p.status = 'approved' AND (pr.expires_at IS NULL OR pr.expires_at >= CURDATE()) ORDER BY pr.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $updates = $db->query("SELECT id, update_type AS kind, title, location, event_date AS date, details, published_at AS at FROM community_updates ORDER BY published_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    api_response(200, array('ok' => true, 'providers' => $providers, 'jobs' => $jobs, 'promotions' => $promotions, 'updates' => $updates));
}

function admin_dashboard($db) {
    $logins = $db->query('SELECT ll.id, ll.user_id AS userId, u.name, ll.email, ll.role, ll.signed_in_at AS at FROM login_logs ll JOIN users u ON u.id = ll.user_id ORDER BY ll.signed_in_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    $messages = $db->query("SELECT id, name, email, subject, message, status, received_at AS at FROM messages ORDER BY received_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $skills = $db->query("SELECT ss.id, ss.user_id AS userId, u.name, u.email, ss.business_name AS business, s.name AS service, l.name AS location, ss.phone, ss.skill, ss.headline AS title, ss.details, ss.available_until AS expiresAt, ss.status, ss.submitted_at AS submittedAt FROM skill_submissions ss JOIN users u ON u.id = ss.user_id JOIN services s ON s.id = ss.service_id JOIN locations l ON l.id = ss.location_id ORDER BY ss.submitted_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $providers = $db->query("SELECT p.id, p.user_id AS userId, u.name, u.email, p.business_name AS business, s.name AS service, l.name AS location, p.phone, p.description, p.status FROM service_providers p JOIN users u ON u.id = p.user_id JOIN services s ON s.id = p.service_id JOIN locations l ON l.id = p.location_id ORDER BY p.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $jobs = $db->query("SELECT j.id, j.employer_name AS employer, j.title, s.name AS category, j.job_type AS type, l.name AS location, j.pay, j.phone, j.email, j.description, j.status, j.posted_at AS postedAt FROM jobs j JOIN services s ON s.id = j.service_id JOIN locations l ON l.id = j.location_id ORDER BY j.posted_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $promotions = $db->query("SELECT pr.id, pr.provider_id AS providerId, p.business_name AS business, u.name, pr.skill, pr.headline AS title, pr.details, pr.active, pr.expires_at AS expiresAt, pr.created_at AS createdAt FROM promotions pr JOIN service_providers p ON p.id = pr.provider_id JOIN users u ON u.id = p.user_id ORDER BY pr.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $updates = $db->query('SELECT id, update_type AS kind, title, location, event_date AS date, details, published_at AS at FROM community_updates ORDER BY published_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    api_response(200, array('ok' => true, 'logins' => $logins, 'messages' => $messages, 'skills' => $skills, 'providers' => $providers, 'jobs' => $jobs, 'promotions' => $promotions, 'updates' => $updates));
}

if (PHP_VERSION_ID < 80100) {
    api_response(503, array('ok' => false, 'error' => 'This API requires PHP 8.1 or later. Upgrade the WAMP PHP runtime before enabling database-backed accounts.'));
}

require_once __DIR__ . '/db_config.php';
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(0, '/', '', $https, true);
session_start();

try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ));
} catch (Exception $exception) {
    error_log('Mtaani database connection failed: ' . $exception->getMessage());
    api_response(503, array('ok' => false, 'error' => 'Database unavailable. Check that MySQL is running and db_config.php is correct.'));
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

try {
    if ($action === 'health' && $method === 'GET') {
        $db->query('SELECT 1');
        api_response(200, array('ok' => true, 'database' => 'connected'));
    }

    if ($action === 'public_snapshot' && $method === 'GET') {
        public_snapshot($db);
    }

    if ($action === 'session' && $method === 'GET') {
        $user = current_user();
        api_response(200, array('ok' => true, 'user' => $user, 'csrfToken' => $user ? new_csrf_token() : null));
    }

    if ($action === 'register' && $method === 'POST') {
        $data = request_data();
        $name = required_text($data, 'name', 'Name', 100);
        $email = normalize_email(isset($data['email']) ? $data['email'] : '');
        $password = isset($data['password']) ? (string)$data['password'] : '';
        if (strlen($password) < 12 || strlen($password) > 200) {
            api_response(422, array('ok' => false, 'error' => 'Use a password between 12 and 200 characters.'));
        }
        $statement = $db->prepare("INSERT INTO users (name, email, password_hash, role, created_at) VALUES (:name, :email, :password_hash, 'customer', NOW())");
        $statement->execute(array(':name' => $name, ':email' => $email, ':password_hash' => password_hash($password, PASSWORD_DEFAULT)));
        $user = array('id' => (int)$db->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'customer');
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        log_login($db, $user);
        api_response(201, array('ok' => true, 'user' => $user, 'csrfToken' => new_csrf_token()));
    }

    if ($action === 'login' && $method === 'POST') {
        $data = request_data();
        $email = normalize_email(isset($data['email']) ? $data['email'] : '');
        $password = isset($data['password']) ? (string)$data['password'] : '';
        $statement = $db->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
        $statement->execute(array(':email' => $email));
        $row = $statement->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            api_response(401, array('ok' => false, 'error' => 'Email or password is incorrect.'));
        }
        $user = array('id' => (int)$row['id'], 'name' => $row['name'], 'email' => $row['email'], 'role' => $row['role']);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        log_login($db, $user);
        api_response(200, array('ok' => true, 'user' => $user, 'csrfToken' => new_csrf_token()));
    }

    if ($action === 'logout' && $method === 'POST') {
        require_user();
        require_csrf();
        $_SESSION = array();
        session_destroy();
        api_response(200, array('ok' => true));
    }

    if ($action === 'job_create' && $method === 'POST') {
        $data = request_data();
        if (empty($data['contactPublic'])) {
            api_response(422, array('ok' => false, 'error' => 'Consent is required to publish contact details.'));
        }
        $employer = required_text($data, 'employer', 'Employer name', 100);
        $title = required_text($data, 'title', 'Job title', 140);
        $service_id = lookup_id($db, 'services', required_text($data, 'category', 'Category', 80));
        $location_id = lookup_id($db, 'locations', required_text($data, 'location', 'Location', 100));
        $type = required_text($data, 'type', 'Job type', 40);
        $phone = validate_phone(required_text($data, 'phone', 'Phone', 24));
        $email = optional_text($data, 'email', 254);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_response(422, array('ok' => false, 'error' => 'Enter a valid contact email.'));
        }
        $description = required_text($data, 'description', 'Job details', 5000);
        $pay = optional_text($data, 'pay', 80);
        $statement = $db->prepare("INSERT INTO jobs (employer_name, title, service_id, location_id, job_type, pay, phone, email, description, status, posted_at) VALUES (:employer, :title, :service, :location, :type, :pay, :phone, :email, :description, 'published', NOW())");
        $statement->execute(array(':employer' => $employer, ':title' => $title, ':service' => $service_id, ':location' => $location_id, ':type' => $type, ':pay' => $pay, ':phone' => $phone, ':email' => $email, ':description' => $description));
        api_response(201, array('ok' => true, 'id' => (int)$db->lastInsertId()));
    }

    if ($action === 'contact_create' && $method === 'POST') {
        $data = request_data();
        $name = required_text($data, 'name', 'Name', 100);
        $email = normalize_email(isset($data['email']) ? $data['email'] : '');
        $subject = required_text($data, 'subject', 'Subject', 100);
        $message = required_text($data, 'message', 'Message', 5000);
        $user = current_user();
        $statement = $db->prepare('INSERT INTO messages (user_id, name, email, subject, message, status, received_at) VALUES (:user_id, :name, :email, :subject, :message, \'new\', NOW())');
        $statement->execute(array(':user_id' => $user ? $user['id'] : null, ':name' => $name, ':email' => $email, ':subject' => $subject, ':message' => $message));
        api_response(201, array('ok' => true, 'id' => (int)$db->lastInsertId()));
    }

    if ($action === 'skill_submit' && $method === 'POST') {
        $user = require_user();
        require_csrf();
        $data = request_data();
        $business = optional_text($data, 'business', 120);
        $service_id = lookup_id($db, 'services', required_text($data, 'service', 'Service', 80));
        $location_id = lookup_id($db, 'locations', required_text($data, 'location', 'Location', 100));
        $phone = validate_phone(required_text($data, 'phone', 'Phone', 24));
        $skill = required_text($data, 'skill', 'Skill', 100);
        $headline = required_text($data, 'title', 'Headline', 140);
        $details = required_text($data, 'details', 'Work details', 5000);
        $expires = !empty($data['expiresAt']) ? $data['expiresAt'] : null;
        $statement = $db->prepare("INSERT INTO skill_submissions (user_id, business_name, service_id, location_id, phone, skill, headline, details, available_until, status, submitted_at) VALUES (:user_id, :business, :service, :location, :phone, :skill, :headline, :details, :expires, 'pending', NOW())");
        $statement->execute(array(':user_id' => (int)$user['id'], ':business' => $business, ':service' => $service_id, ':location' => $location_id, ':phone' => $phone, ':skill' => $skill, ':headline' => $headline, ':details' => $details, ':expires' => $expires));
        api_response(201, array('ok' => true, 'id' => (int)$db->lastInsertId(), 'status' => 'pending'));
    }

    if ($action === 'admin_dashboard' && $method === 'GET') {
        require_admin();
        admin_dashboard($db);
    }

    if (strpos($action, 'admin_') === 0 && $method === 'POST') {
        $admin = require_admin();
        require_csrf();
        $data = request_data();

        if ($action === 'admin_skill_review') {
            $submission_id = isset($data['id']) ? (int)$data['id'] : 0;
            $status = isset($data['status']) ? $data['status'] : '';
            if (!$submission_id || !in_array($status, array('approved', 'rejected'), true)) {
                api_response(422, array('ok' => false, 'error' => 'Choose a valid skill submission and review status.'));
            }
            $db->beginTransaction();
            $statement = $db->prepare("SELECT ss.*, u.name, u.email FROM skill_submissions ss JOIN users u ON u.id = ss.user_id WHERE ss.id = :id AND ss.status = 'pending' FOR UPDATE");
            $statement->execute(array(':id' => $submission_id));
            $submission = $statement->fetch();
            if (!$submission) {
                $db->rollBack();
                api_response(404, array('ok' => false, 'error' => 'Pending submission not found.'));
            }
            if ($status === 'approved') {
                $provider = $db->prepare("INSERT INTO service_providers (user_id, business_name, service_id, location_id, phone, description, status, created_at) VALUES (:user_id, :business, :service, :location, :phone, :description, 'approved', NOW()) ON DUPLICATE KEY UPDATE business_name = VALUES(business_name), service_id = VALUES(service_id), location_id = VALUES(location_id), phone = VALUES(phone), description = VALUES(description), status = 'approved'");
                $provider->execute(array(':user_id' => $submission['user_id'], ':business' => $submission['business_name'], ':service' => $submission['service_id'], ':location' => $submission['location_id'], ':phone' => $submission['phone'], ':description' => $submission['details']));
                $provider_query = $db->prepare('SELECT id FROM service_providers WHERE user_id = :user_id');
                $provider_query->execute(array(':user_id' => $submission['user_id']));
                $provider_id = (int)$provider_query->fetchColumn();
                $promotion = $db->prepare('INSERT INTO promotions (provider_id, skill, headline, details, active, expires_at, created_by, created_at) VALUES (:provider, :skill, :headline, :details, 1, :expires, :admin, NOW())');
                $promotion->execute(array(':provider' => $provider_id, ':skill' => $submission['skill'], ':headline' => $submission['headline'], ':details' => $submission['details'], ':expires' => $submission['available_until'], ':admin' => (int)$admin['id']));
                $db->prepare("UPDATE users SET role = 'provider' WHERE id = :id")->execute(array(':id' => $submission['user_id']));
            }
            $review = $db->prepare('UPDATE skill_submissions SET status = :status, reviewed_at = NOW(), reviewed_by = :admin WHERE id = :id');
            $review->execute(array(':status' => $status, ':admin' => (int)$admin['id'], ':id' => $submission_id));
            $db->commit();
            api_response(200, array('ok' => true, 'status' => $status));
        }

        if ($action === 'admin_message_review') {
            $statement = $db->prepare("UPDATE messages SET status = 'reviewed' WHERE id = :id");
            $statement->execute(array(':id' => isset($data['id']) ? (int)$data['id'] : 0));
            api_response(200, array('ok' => true));
        }

        if ($action === 'admin_update_create') {
            $kind = isset($data['kind']) ? $data['kind'] : '';
            if (!in_array($kind, array('Upcoming work', 'Community update'), true)) {
                api_response(422, array('ok' => false, 'error' => 'Choose a valid update type.'));
            }
            $statement = $db->prepare('INSERT INTO community_updates (admin_id, update_type, title, location, event_date, details, published_at) VALUES (:admin, :kind, :title, :location, :date, :details, NOW())');
            $statement->execute(array(':admin' => (int)$admin['id'], ':kind' => $kind, ':title' => required_text($data, 'title', 'Title', 140), ':location' => optional_text($data, 'location', 100), ':date' => !empty($data['date']) ? $data['date'] : null, ':details' => required_text($data, 'details', 'Details', 5000)));
            api_response(201, array('ok' => true, 'id' => (int)$db->lastInsertId()));
        }

        if ($action === 'admin_update_delete') {
            $db->prepare('DELETE FROM community_updates WHERE id = :id')->execute(array(':id' => isset($data['id']) ? (int)$data['id'] : 0));
            api_response(200, array('ok' => true));
        }

        if ($action === 'admin_promotion_create') {
            $statement = $db->prepare("SELECT id FROM service_providers WHERE id = :id AND status = 'approved'");
            $statement->execute(array(':id' => isset($data['providerId']) ? (int)$data['providerId'] : 0));
            $provider_id = (int)$statement->fetchColumn();
            if (!$provider_id) {
                api_response(404, array('ok' => false, 'error' => 'Approved provider not found.'));
            }
            $insert = $db->prepare('INSERT INTO promotions (provider_id, skill, headline, details, active, expires_at, created_by, created_at) VALUES (:provider, :skill, :headline, :details, 1, :expires, :admin, NOW())');
            $insert->execute(array(':provider' => $provider_id, ':skill' => required_text($data, 'skill', 'Skill', 100), ':headline' => required_text($data, 'title', 'Headline', 140), ':details' => required_text($data, 'details', 'Details', 5000), ':expires' => !empty($data['expiresAt']) ? $data['expiresAt'] : null, ':admin' => (int)$admin['id']));
            api_response(201, array('ok' => true, 'id' => (int)$db->lastInsertId()));
        }

        if ($action === 'admin_promotion_toggle') {
            $db->prepare('UPDATE promotions SET active = IF(active = 1, 0, 1) WHERE id = :id')->execute(array(':id' => isset($data['id']) ? (int)$data['id'] : 0));
            api_response(200, array('ok' => true));
        }

        if ($action === 'admin_promotion_delete') {
            $db->prepare('DELETE FROM promotions WHERE id = :id')->execute(array(':id' => isset($data['id']) ? (int)$data['id'] : 0));
            api_response(200, array('ok' => true));
        }

        if ($action === 'admin_job_delete') {
            $db->prepare("UPDATE jobs SET status = 'removed' WHERE id = :id")->execute(array(':id' => isset($data['id']) ? (int)$data['id'] : 0));
            api_response(200, array('ok' => true));
        }
    }

    api_response(404, array('ok' => false, 'error' => 'Unknown API action.'));
} catch (PDOException $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Mtaani API database error: ' . $exception->getMessage());
    if ($exception->getCode() === '23000') {
        api_response(409, array('ok' => false, 'error' => 'An account with this email already exists, or a referenced record is unavailable.'));
    }
    api_response(500, array('ok' => false, 'error' => 'The request could not be saved. Check the server log.'));
} catch (Exception $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Mtaani API error: ' . $exception->getMessage());
    api_response(500, array('ok' => false, 'error' => 'The request could not be completed.'));
}