<?php

if (! defined('ABSPATH')) {
    exit;
}

class Ileben_Api_CF7_Integration
{
    private $repository;
    private $api_client;

    public function __construct($repository, $api_client)
    {
        $this->repository = $repository;
        $this->api_client = $api_client;
    }

    public function register()
    {
        add_action('wpcf7_init', array($this, 'register_channel_form_tag'));
        add_action('wpcf7_mail_sent', array($this, 'handle_cf7_mail_sent'));
    }

    public function register_channel_form_tag()
    {
        if (! function_exists('wpcf7_add_form_tag')) {
            return;
        }

        wpcf7_add_form_tag('ileben_channel', array($this, 'render_channel_form_tag'), array('display-hidden' => true));
    }

    public function render_channel_form_tag($tag)
    {
        $name = 'channel';
        $value = '';

        if (is_object($tag)) {
            if (! empty($tag->name)) {
                $name = sanitize_text_field((string) $tag->name);
            }

            if (! empty($tag->values) && is_array($tag->values)) {
                $value = sanitize_text_field((string) $tag->values[0]);
            }
        }

        if ($name === '') {
            $name = 'channel';
        }

        return sprintf(
            '<input type="hidden" name="%s" value="%s" />',
            esc_attr($name),
            esc_attr($value)
        );
    }

    public function handle_cf7_mail_sent($contact_form)
    {
        if (! class_exists('WPCF7_Submission')) {
            return;
        }

        $submission = WPCF7_Submission::get_instance();
        if (! $submission) {
            return;
        }

        $posted_data = $submission->get_posted_data();
        if (! is_array($posted_data)) {
            $posted_data = array();
        }

        $channel = $this->resolve_channel($posted_data);
        $fields = $this->build_fields($posted_data);
        $turnstile_token = $this->resolve_turnstile_token($posted_data);

        $validation_error = $this->validate_payload($channel, $fields);

        $form_id = is_object($contact_form) && method_exists($contact_form, 'id') ? (int) $contact_form->id() : 0;
        $form_title = is_object($contact_form) && method_exists($contact_form, 'title') ? sanitize_text_field((string) $contact_form->title()) : '';

        if ($validation_error !== '') {
            error_log('[ileben-api] CF7 Form #' . $form_id . ' Validation error: ' . $validation_error);
            $this->repository->save_contact_sync_log(array(
                'form_id' => $form_id,
                'form_title' => $form_title,
                'channel' => $channel,
                'status' => 'validation_error',
                'contact_name' => $this->pick_first_field_value($fields, array('name', 'nombre')),
                'contact_email' => $this->pick_first_field_value($fields, array('email', 'correo')),
                'payload_json' => wp_json_encode(array('channel' => $channel, 'fields' => $fields)),
                'response_code' => 422,
                'response_body' => '',
                'error_message' => $validation_error,
                'remote_submission_id' => 0,
            ));
            return;
        }

        $result = $this->api_client->submit_contact_submission($channel, $fields, $turnstile_token);

        $status = 'failed';
        if (! empty($result['success'])) {
            $status = 'sent';
        } elseif ((int) ($result['status_code'] ?? 0) === 422) {
            $status = 'validation_error';
        } elseif ((int) ($result['status_code'] ?? 0) === 429) {
            $status = 'rate_limited';
        }

        $error_message = $this->build_result_error_message($result);

        if ($status !== 'sent') {
            error_log('[ileben-api] CF7 Form #' . $form_id . ' (' . $form_title . ') Status=' . $status . ', Code=' . (int) ($result['status_code'] ?? 0) . ', Error: ' . $error_message);
        }

        $this->repository->save_contact_sync_log(array(
            'form_id' => $form_id,
            'form_title' => $form_title,
            'channel' => $channel,
            'status' => $status,
            'contact_name' => $this->pick_first_field_value($fields, array('name', 'nombre')),
            'contact_email' => $this->pick_first_field_value($fields, array('email', 'correo')),
            'payload_json' => wp_json_encode($result['request_payload'] ?? array('channel' => $channel, 'fields' => $fields)),
            'response_code' => (int) ($result['status_code'] ?? 0),
            'response_body' => (string) ($result['raw_body'] ?? ''),
            'error_message' => $error_message,
            'remote_submission_id' => (int) (($result['data']['id'] ?? 0)),
        ));
    }

    private function resolve_channel($posted_data)
    {
        $channel = sanitize_text_field((string) ($posted_data['channel'] ?? ''));
        if ($channel === '') {
            $channel = sanitize_text_field((string) ($posted_data['_channel'] ?? ''));
        }
        return $channel;
    }

    private function resolve_turnstile_token($posted_data)
    {
        $token = sanitize_text_field((string) ($posted_data['turnstile_token'] ?? ''));
        if ($token === '') {
            $token = sanitize_text_field((string) ($posted_data['cf-turnstile-response'] ?? ''));
        }
        return $token;
    }

    private function build_fields($posted_data)
    {
        $fields = array();

        foreach ($posted_data as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '' || strpos($key, '_wpcf7') === 0 || $key === 'channel' || $key === '_channel' || $key === 'turnstile_token' || $key === 'cf-turnstile-response') {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', array_map('sanitize_text_field', $value));
            }

            $clean = sanitize_text_field((string) $value);
            if ($clean === '') {
                continue;
            }

            $fields[$key] = $clean;
        }

        $aliases = array(
            'name' => array('name', 'nombre', 'your-name'),
            'email' => array('email', 'correo', 'your-email'),
            'message' => array('message', 'mensaje', 'your-message'),
            'phone' => array('phone', 'telefono', 'fono', 'celular', 'whatsapp', 'your-phone'),
            'comuna' => array('comuna', 'commune', 'district', 'project_commune', 'your-comuna', 'your-commune', 'select-comuna'),
            'proyecto' => array('proyecto', 'project', 'project_name', 'nombre_proyecto', 'your-proyecto', 'your-project', 'select-proyecto', 'nombre-proyecto'),
        );

        foreach ($aliases as $target => $alias_list) {
            if (! empty($fields[$target])) {
                continue;
            }

            $alias_value = $this->find_alias_field_value($fields, $alias_list);
            if ($alias_value !== '') {
                $fields[$target] = $alias_value;
            }
        }

        return $fields;
    }

    /**
     * Valida que el payload tenga los campos obligatorios.
     *
     * IMPORTANTE: El formulario CF7 DEBE incluir campos hidden obligatorios:
     *   [hidden channel "valor"]
     *   [hidden comuna "valor"]
     *   [hidden proyecto "valor"]
     *
     * Retorna string vacio si la validacion es exitosa, o un mensaje de error si falla.
     */
    private function validate_payload($channel, $fields)
    {
        if (trim((string) $channel) === '') {
            return 'El campo Canal de contacto es obligatorio.';
        }

        $has_comuna = $this->has_any_field($fields, array('comuna', 'commune', 'district', 'project_commune'));
        if (! $has_comuna) {
            return 'El campo Comuna es obligatorio.';
        }

        $has_proyecto = $this->has_any_field($fields, array('proyecto', 'project', 'project_name', 'nombre_proyecto'));
        if (! $has_proyecto) {
            return 'El campo Proyecto es obligatorio.';
        }

        return '';
    }

    private function has_any_field($fields, $keys)
    {
        foreach ($keys as $key) {
            $key = sanitize_key((string) $key);
            if (! empty($fields[$key])) {
                return true;
            }
        }

        return false;
    }

    private function pick_first_field_value($fields, $keys)
    {
        foreach ($keys as $key) {
            $key = sanitize_key((string) $key);
            if (! empty($fields[$key])) {
                return sanitize_text_field((string) $fields[$key]);
            }
        }

        return '';
    }

    private function find_alias_field_value($fields, $alias_list)
    {
        if (! is_array($fields)) {
            return '';
        }

        foreach ($alias_list as $alias) {
            $alias_key = sanitize_key((string) $alias);
            if ($alias_key !== '' && ! empty($fields[$alias_key])) {
                return sanitize_text_field((string) $fields[$alias_key]);
            }
        }

        foreach ($fields as $key => $value) {
            $normalized_key = sanitize_key((string) $key);
            if ($normalized_key === '') {
                continue;
            }

            foreach ($alias_list as $alias) {
                $alias_key = sanitize_key((string) $alias);
                if ($alias_key === '') {
                    continue;
                }

                if (strpos($normalized_key, $alias_key) !== false && ! empty($value)) {
                    return sanitize_text_field((string) $value);
                }
            }
        }

        return '';
    }

    /**
     * Parsea la respuesta del API y extrae los errores de forma estructurada.
     * Retorna un array con:
     *   - 'message': Mensaje principal del error
     *   - 'field_errors': Array de errores por campo [campo => array de mensajes]
     *   - 'error_json': JSON para guardar en BD
     */
    private function parse_detailed_errors($result)
    {
        $base = sanitize_text_field((string) ($result['message'] ?? ''));
        $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array();

        $field_errors = array();
        if (! empty($errors)) {
            foreach ($errors as $field => $messages) {
                $field = sanitize_text_field((string) $field);
                if ($field === '') {
                    continue;
                }

                $clean_messages = array();
                if (is_array($messages)) {
                    foreach ($messages as $msg) {
                        $msg = sanitize_text_field((string) $msg);
                        if ($msg !== '') {
                            $clean_messages[] = $msg;
                        }
                    }
                } else {
                    $clean_messages[] = sanitize_text_field((string) $messages);
                }

                if (! empty($clean_messages)) {
                    $field_errors[$field] = $clean_messages;
                }
            }
        }

        return array(
            'message' => $base,
            'field_errors' => $field_errors,
            'error_json' => wp_json_encode(array('message' => $base, 'errors' => $field_errors)),
        );
    }

    private function build_result_error_message($result)
    {
        $parsed = $this->parse_detailed_errors($result);
        $base = $parsed['message'];
        $field_errors = $parsed['field_errors'];

        if (empty($field_errors)) {
            return $base;
        }

        $parts = array();
        foreach ($field_errors as $field => $messages) {
            $parts[] = esc_html($field) . ': ' . implode(' | ', array_map('esc_html', $messages));
        }

        $detail = implode(' ; ', $parts);
        if ($base !== '' && $detail !== '') {
            return $base . ' - ' . $detail;
        }

        if ($detail !== '') {
            return $detail;
        }

        return $base;
    }
}
