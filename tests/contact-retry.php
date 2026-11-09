<?php

define('ABSPATH', __DIR__);

function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function wp_json_encode($value) { return json_encode($value); }

require_once __DIR__ . '/../includes/class-ileben-api-client.php';
require_once __DIR__ . '/../public/class-ileben-cf7-integration.php';
require_once __DIR__ . '/../admin/class-ileben-api-admin.php';

function check($condition, $message)
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

class TestClient extends Ileben_Api_Client
{
    public function get_settings() { return array('api_endpoint' => ''); }
}

class TestRepository
{
    public $log = array();
    public $retries = 0;
    public function save_contact_sync_log($data) { $this->log = array_merge($this->log, $data); }
    public function find_contact_sync_log($id) { return $this->log; }
    public function increment_contact_sync_retry($id) { $this->retries++; }
}

class WPCF7_Submission
{
    public static function get_instance() { return new self(); }
    public function get_posted_data()
    {
        return array('channel' => 'sale', 'nombre' => 'Ana', 'email' => 'ana@example.com',
            'comuna' => 'Santiago', 'proyecto' => 'Centro', 'custom' => 'dato', 'turnstile_token' => 'token');
    }
}

$repository = new TestRepository();
$client = new TestClient();
$integration = new Ileben_Api_CF7_Integration($repository, $client);
$integration->handle_cf7_mail_sent(null);
$payload = json_decode($repository->log['payload_json'], true);
check($payload['fields']['custom'] === 'dato', 'El fallo de configuracion debe conservar todos los campos.');
check($payload['channel'] === 'sale' && $payload['turnstile_token'] === 'token', 'El payload debe conservar canal y token.');

class RetryClient
{
    public $request;
    public function submit_contact_submission($channel, $fields, $token)
    {
        $this->request = array($channel, $fields, $token);
        return array('success' => true, 'status_code' => 200, 'message' => 'OK');
    }
}

$retry_client = new RetryClient();
$admin = new Ileben_Api_Admin($repository, $retry_client);
$result = $admin->process_contact_retry(1, 'rent', array('custom' => 'corregido'));
check($result['success'] && $retry_client->request[1]['custom'] === 'corregido', 'El reintento debe enviar los campos editados.');
check($retry_client->request[1]['nombre'] === 'Ana' && $retry_client->request[2] === 'token', 'El reintento debe conservar los demas datos.');
check(json_decode($repository->log['payload_json'], true)['fields']['custom'] === 'corregido', 'Los cambios deben persistir.');

$repository->log = array('payload_json' => '[]', 'channel' => 'sale');
$result = $admin->process_contact_retry(1, null, array('name' => '', 'email' => ''));
check(! $result['success'], 'No se debe reintentar un payload sin valores.');
$result = $admin->process_contact_retry(1, null, array('name' => 'Ana', 'comuna' => 'Santiago', 'proyecto' => 'Centro'));
check($result['success'] && $retry_client->request[1]['name'] === 'Ana', 'Un registro antiguo debe poder reconstruirse y reenviarse.');

echo "contact-retry.php: OK\n";
