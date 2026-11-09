<?php

define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function wp_json_encode($value) { return json_encode($value); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function current_user_can($capability) { return true; }
function check_admin_referer($action) {}
function admin_url($path) { return $path; }
function add_query_arg($args, $url) { return $args; }
function wp_safe_redirect($args) { $GLOBALS['test_redirect_args'] = $args; throw new RuntimeException($args['ip_type'] . ': ' . $args['ip_message']); }
function absint($value) { return abs((int) $value); }
function apply_filters($tag, $value) { return $value; }
function get_bloginfo($show) { return 'UTF-8'; }
function is_serialized($value) { return is_string($value) && preg_match('/^(a|O|s|i|d|b):/', $value) === 1; }
define('ILEBEN_API_CAPABILITY', 'manage_ileben_api');

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
    public $fields;
    public function get_settings() { return array('api_endpoint' => ''); }
    public function submit_contact_submission($channel, $fields, $turnstile_token = '')
    {
        $this->fields = $fields;
        return parent::submit_contact_submission($channel, $fields, $turnstile_token);
    }
}

class TestRepository
{
    public $log = array();
    public $retries = 0;
    public $cfdb7_candidates = array();
    public function save_contact_sync_log($data) { $this->log = array_merge($this->log, $data); }
    public function find_contact_sync_log($id) { return $this->log; }
    public function increment_contact_sync_retry($id) { $this->retries++; }
    public function find_cfdb7_submissions_for_contact($id) { return array('error' => '', 'candidates' => $this->cfdb7_candidates); }
    public function restore_contact_sync_payload_from_cfdb7($id, $submission_id)
    {
        foreach ($this->cfdb7_candidates as $candidate) {
            if ((int) $candidate['id'] !== (int) $submission_id) {
                continue;
            }
            $payload = json_decode($this->log['payload_json'], true);
            $fields = $payload['fields'] ?? array();
            $added = 0;
            foreach ($candidate['fields'] as $key => $value) {
                if (! array_key_exists($key, $fields)) {
                    $fields[$key] = $value;
                    $added++;
                }
            }
            $payload['fields'] = $fields;
            $this->log['payload_json'] = json_encode($payload);
            return array('success' => $added > 0, 'added_count' => $added, 'message' => 'No hay campos faltantes.');
        }
        return array('success' => false, 'message' => 'No coincide.');
    }
}

class WPCF7_Submission
{
    public static $data;
    public static function get_instance() { return new self(); }
    public function get_posted_data()
    {
        if (self::$data !== null) {
            return self::$data;
        }
        return array('channel' => 'sale', 'nombre' => 'Ana', 'email' => 'ana@example.com',
            'comuna' => 'Santiago', 'proyecto' => 'Centro', 'custom' => 'dato', 'hidden_empty' => '',
            'hidden_campaign' => 'web', '_wpcf7' => 'internal', 'turnstile_token' => 'token');
    }
}

$repository = new TestRepository();
$client = new TestClient();
$integration = new Ileben_Api_CF7_Integration($repository, $client);
$integration->handle_cf7_mail_sent(null);
$payload = json_decode($repository->log['payload_json'], true);
check($payload['fields']['custom'] === 'dato', 'El fallo de configuracion debe conservar todos los campos.');
check(array_key_exists('hidden_empty', $payload['fields']) && $payload['fields']['hidden_campaign'] === 'web', 'Debe guardar campos ocultos vacios y con valor.');
check(! isset($payload['fields']['_wpcf7']) && ! isset($payload['fields']['turnstile_token']), 'Los campos internos no deben quedar como campos de contacto.');
check(! array_key_exists('hidden_empty', $client->fields), 'Los campos vacios no deben cambiar el envio original.');
check($payload['channel'] === 'sale' && $payload['turnstile_token'] === 'token', 'El payload debe conservar canal y token.');

WPCF7_Submission::$data = array('channel' => '', 'nombre' => 'Ana', 'hidden_empty' => '');
$integration->handle_cf7_mail_sent(null);
$invalid_payload = json_decode($repository->log['payload_json'], true);
check(array_key_exists('hidden_empty', $invalid_payload['fields']), 'Debe conservar campos ocultos tambien en errores de validacion.');
WPCF7_Submission::$data = null;
$integration->handle_cf7_mail_sent(null);

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

$result = $admin->process_contact_retry(1, null, array(), array('origen', 'otro'), array('web', ''));
check($result['success'] && $retry_client->request[1]['origen'] === 'web' && array_key_exists('otro', $retry_client->request[1]), 'Los campos nuevos deben enviarse.');
check(json_decode($repository->log['payload_json'], true)['fields']['origen'] === 'web', 'Los campos nuevos deben persistir.');
foreach (array('custom', 'origen', 'channel', '_wpcf7_test', 'mal nombre', 'Email') as $invalid_name) {
    $result = $admin->process_contact_retry(1, null, array(), array($invalid_name), array('valor'));
    check(! $result['success'], 'Debe rechazar nombres duplicados, reservados o invalidos.');
}
$result = $admin->process_contact_retry(1, null, array(), array('nuevo', 'nuevo'), array('1', '2'));
check(! $result['success'], 'Debe rechazar nombres adicionales repetidos.');

$_POST = array('id' => '1', 'channel' => 'sale', 'field_custom' => "O\\'Brien",
    'extra_name' => array('campana'), 'extra_value' => array("O\\'Brien"));
try {
    $admin->handle_retry_contact_sync();
    check(false, 'El handler debe redirigir.');
} catch (RuntimeException $error) {
    check(strpos($error->getMessage(), 'success:') === 0, 'El handler debe procesar el formulario.');
}
check($retry_client->request[1]['custom'] === "O'Brien" && $retry_client->request[1]['campana'] === "O'Brien", 'El handler debe quitar los slashes en ambos tipos de campos.');

$repository->log = array('payload_json' => '[]', 'channel' => 'sale');
$result = $admin->process_contact_retry(1, null, array('name' => '', 'email' => ''));
check(! $result['success'], 'No se debe reintentar un payload sin valores.');
$result = $admin->process_contact_retry(1, null, array('name' => 'Ana', 'comuna' => 'Santiago', 'proyecto' => 'Centro'));
check($result['success'] && $retry_client->request[1]['name'] === 'Ana', 'Un registro antiguo debe poder reconstruirse y reenviarse.');

$repository->log = array(
    'id' => 1,
    'form_id' => 23,
    'channel' => 'sale',
    'contact_email' => 'ana@example.com',
    'created_at' => '2026-11-09 10:00:00',
    'payload_json' => json_encode(array('channel' => 'sale', 'fields' => array('name' => 'Ana', 'email' => 'ana@example.com'))),
);
$repository->cfdb7_candidates = array(
    array('id' => 77, 'date' => '2026-11-09 10:00:05', 'fields' => array(
        'name' => 'Otro nombre', 'email' => 'ana@example.com', 'hidden_campaign' => 'web', 'hidden_empty' => '',
    )),
);
$_POST = array('id' => '1');
try {
    $admin->handle_recover_contact_payload();
    check(false, 'La recuperación debe redirigir.');
} catch (RuntimeException $error) {
    check(strpos($error->getMessage(), 'success:') === 0, 'Un único candidato debe recuperarse automáticamente.');
}
$recovered_fields = json_decode($repository->log['payload_json'], true)['fields'];
check($recovered_fields['name'] === 'Ana', 'La recuperación no debe sobrescribir campos existentes.');
check($recovered_fields['hidden_campaign'] === 'web' && array_key_exists('hidden_empty', $recovered_fields), 'Debe recuperar campos hidden con y sin valor.');

$repository->cfdb7_candidates[] = array('id' => 78, 'date' => '2026-11-09 10:00:10', 'fields' => array('email' => 'ana@example.com', 'hidden_other' => 'x'));
$_POST = array('id' => '1');
try {
    $admin->handle_recover_contact_payload();
    check(false, 'Con varias coincidencias debe solicitar selección.');
} catch (RuntimeException $error) {
    check(strpos($error->getMessage(), 'info:') === 0, 'Las coincidencias múltiples deben abrir el selector.');
    check(($GLOBALS['test_redirect_args']['recover_id'] ?? 0) === 1, 'El selector debe conservar el ID del contacto.');
}

$_POST = array('id' => '1', 'cfdb7_form_id' => '78');
try {
    $admin->handle_recover_contact_payload();
    check(false, 'La selección explícita debe redirigir.');
} catch (RuntimeException $error) {
    check(strpos($error->getMessage(), 'success:') === 0, 'Debe permitir elegir una coincidencia específica.');
}

class Ileben_Api_Plugin
{
    public static function get_table_name() { return 'wp_ileben_api'; }
    public static function get_contact_sync_table_name() { return 'wp_ileben_api_contact_sync'; }
}

class TestWpdb
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $contact;
    public $cfdb7_rows = array();
    public function prepare($query) { return $query; }
    public function esc_like($value) { return $value; }
    public function get_var($query) { return 'wp_db7_forms'; }
    public function get_row($query, $format) { return $this->contact; }
    public function get_results($query, $format) { return $this->cfdb7_rows; }
}

require_once __DIR__ . '/../includes/class-ileben-api-repository.php';
$wpdb = new TestWpdb();
$wpdb->contact = array(
    'id' => 9,
    'form_id' => 23,
    'contact_email' => 'ana@example.com',
    'created_at' => '2026-11-09 10:00:00',
);
$wpdb->cfdb7_rows = array(
    array('form_id' => 101, 'form_date' => '2026-11-09 10:00:05', 'form_value' => serialize(array(
        'email' => 'ana@example.com', 'nombre' => 'Ana&#039;s', 'hidden_empty' => '',
        'channel' => 'sale', '_wpcf7' => 'internal', 'cfdb7_status' => 'unread',
    ))),
    array('form_id' => 102, 'form_date' => '2026-11-09 10:00:05', 'form_value' => serialize(array('email' => 'other@example.com'))),
);
$actual_repository = new Ileben_Api_Repository();
$actual_matches = $actual_repository->find_cfdb7_submissions_for_contact(9);
check(count($actual_matches['candidates']) === 1, 'La consulta CFDB7 debe filtrar por email.');
check($actual_matches['candidates'][0]['fields']['name'] === "Ana's", 'La recuperación debe normalizar aliases y entidades HTML.');
check(array_key_exists('hidden_empty', $actual_matches['candidates'][0]['fields']), 'La consulta debe preservar hidden vacíos.');
check(! isset($actual_matches['candidates'][0]['fields']['_wpcf7']) && ! isset($actual_matches['candidates'][0]['fields']['cfdb7_status']), 'No debe importar metadatos de CF7/CFDB7.');

echo "contact-retry.php: OK\n";
