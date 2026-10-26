/** ACF v3 preview lifecycle checks, without a browser or npm dependencies. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../resources/blocks/slider/files/assets/js/editorScripts.js'), 'utf8');
let preview;
let mounts = 0;
let parentMounts = 0;
let errors = 0;
let options;
const window = {
    acf: { addAction(name, callback) { assert.equal(name, 'render_block_preview'); preview = callback; } },
    Splide: function () { this.mount = () => { parentMounts++; }; },
};
vm.runInNewContext(source, { window, console: { error() { errors++; } } });
assert.equal(typeof preview, 'function');

function slider(data = '{"direction":"ttb"}') {
    const classes = new Set();
    return {
        dataset: { splide: data },
        classList: { contains: value => classes.has(value), add: value => classes.add(value) },
        querySelector: () => ({}),
        classes,
    };
}
const first = slider();
const root = {
    ownerDocument: { defaultView: { Splide: function (element, config) {
        assert.equal(element, first);
        options = config;
        this.mount = () => { mounts++; element.classes.add('is-initialized'); };
    } } },
    querySelectorAll(selector) { assert.equal(selector, '.slider-section__slider'); return [first]; },
};
preview([root]);
assert.equal(mounts, 1);
assert.equal(options.direction, 'ttb');
assert.equal(first.classes.has('splide-initialized'), true);
preview([root]);
assert.equal(mounts, 1, 'Cached/mutation previews must not mount an existing instance twice.');

const replacement = slider();
root.ownerDocument.defaultView = {};
root.querySelectorAll = () => [replacement];
preview([root]);
assert.equal(parentMounts, 1, 'Iframe markup may use the parent library constructor.');
replacement.querySelector = () => null;
preview([root]);
assert.equal(parentMounts, 1, 'An empty preview must not mount.');

root.querySelectorAll = () => [slider('{broken')];
preview([root]);
assert.equal(errors, 1, 'Initialization failure must remain visible to developers.');
preview([]);
preview(null);
console.log('Slider editor lifecycle checks passed.');
