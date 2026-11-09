const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const elements = {
    editContactChannel: {},
    editContactId: {},
    editContactFields: {},
    ilebenContactEditModal: {}
};
let shown = 0;
const context = {
    console: { log() {}, error() {} },
    document: {
        readyState: 'loading',
        addEventListener() {},
        getElementById(id) { return elements[id]; }
    },
    bootstrap: { Modal: { getOrCreateInstance() { return { show() { shown++; } }; } } },
    jQuery() {},
    alert(message) { throw new Error(message); }
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'admin.js'), 'utf8'), context);

function open(payload) {
    const attributes = {
        'data-contact-id': '1',
        'data-contact-name': 'Ana',
        'data-contact-email': 'ana@example.com',
        'data-contact-channel': 'sale',
        'data-payload': payload
    };
    context.ilebenEditContactModal({ getAttribute(name) { return attributes[name]; } });
    return elements.editContactFields.innerHTML;
}

let html = open('[]');
assert.match(html, /No se guardaron los datos originales completos/);
assert.match(html, /name="field_name" value="Ana"/);
assert.match(html, /name="field_email" value="ana@example\.com"/);
assert.match(html, /name="field_comuna"/);
assert.match(html, /name="field_proyecto"/);

html = open(JSON.stringify({ fields: { name: 'O\'Brien', custom: 'valor', commune: 'Santiago', proyecto: 'Centro', count: 0 } }));
assert.match(html, /O&#039;Brien/);
assert.match(html, /name="field_custom" value="valor"/);
assert.match(html, /name="field_count" value="0"/);
assert.doesNotMatch(html, /name="field_comuna"/);
assert.doesNotMatch(html, /No se guardaron los datos originales completos/);

html = open(JSON.stringify({ fields: { name: 'Ana' } }));
assert.match(html, /name="field_comuna"/);
assert.match(html, /name="field_proyecto"/);
html = open('null');
assert.match(html, /name="field_email" value="ana@example\.com"/);
assert.equal(shown, 4);
console.log('contact-retry.js: OK');
