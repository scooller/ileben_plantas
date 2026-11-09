const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const elements = {
    editContactChannel: {},
    editContactId: {},
    editContactFields: {},
    editContactExtraFields: {},
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

let added = '';
const handlers = {};
const input = { focus() { handlers.focused = true; }, name: 'extra_name[]', value: 'extra', setCustomValidity(value) { this.error = value; } };
elements.addContactExtraField = { addEventListener(type, cb) { handlers.add = cb; }, focus() { handlers.returnedFocus = true; } };
elements.editContactExtraFields = {
    insertAdjacentHTML(where, html) { added = html; },
    addEventListener(type, cb) { handlers[type] = cb; },
    querySelectorAll() { return [input]; }
};
elements.ilebenContactEditForm = { addEventListener(type, cb) { handlers.submit = cb; }, reportValidity() { return !input.error; } };
context.document.getElementById = id => id === 'extraName0' ? input : elements[id];
context.document.querySelectorAll = selector => selector === '.ileben-edit-contact-btn' ? [] : [{ name: 'field_custom' }];
context.attachContactListeners();
handlers.add();
assert.match(added, /name="extra_name\[\]"/);
assert.match(added, /name="extra_value\[\]"/);
assert.ok(handlers.focused);
let prevented = false;
input.value = 'custom';
handlers.submit.call(elements.ilebenContactEditForm, { preventDefault() { prevented = true; } });
assert.ok(prevented && input.error);
input.value = 'nuevo';
handlers.input({ target: input });
prevented = false;
handlers.submit.call(elements.ilebenContactEditForm, { preventDefault() { prevented = true; } });
assert.equal(prevented, false);
let removed = false;
handlers.click({ target: { matches() { return true; }, closest() { return { remove() { removed = true; } }; } } });
assert.ok(removed && handlers.returnedFocus);
console.log('contact-retry.js: OK');
