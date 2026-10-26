import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createHash} from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

// Exercise the real theme compiler in an isolated copy; never edit the source theme.
if (!process.argv[2]) {
    throw new Error('Usage: node tests/tailwind-build-acceptance.mjs /path/to/tailwind-theme');
}
const source = path.resolve(process.argv[2]);
const fixture = await fs.mkdtemp(path.join(os.tmpdir(), 'st-tailwind-build-'));
let watcher;
let assertions = 0;

function check(condition, message) {
    assert.ok(condition, message);
    assertions += 1;
}

async function write(relative, content) {
    const file = path.join(fixture, relative);
    await fs.mkdir(path.dirname(file), {recursive: true});
    await fs.writeFile(file, content);
}

async function read(relative) {
    return fs.readFile(path.join(fixture, relative), 'utf8');
}

function runBuild() {
    return new Promise((resolve, reject) => {
        const child = spawn(process.platform === 'win32' ? 'npm.cmd' : 'npm', ['run', 'build'], {
            cwd: fixture,
            env: process.env,
            shell: process.platform === 'win32'
        });
        let output = '';
        child.stdout.on('data', data => { output += data; });
        child.stderr.on('data', data => { output += data; });
        child.on('error', reject);
        child.on('close', (code, signal) => resolve({code, signal, output}));
    });
}

async function hashes(directory) {
    const result = {};
    async function visit(current) {
        for (const entry of await fs.readdir(current, {withFileTypes: true})) {
            const file = path.join(current, entry.name);
            if (entry.isDirectory()) {
                await visit(file);
            } else {
                result[path.relative(directory, file)] = createHash('sha256').update(await fs.readFile(file)).digest('hex');
            }
        }
    }
    await visit(directory);
    return result;
}

async function waitUntil(condition, description) {
    const deadline = Date.now() + 20000;
    while (Date.now() < deadline) {
        if (await condition()) return;
        await new Promise(resolve => setTimeout(resolve, 150));
    }
    throw new Error('Timed out waiting for ' + description);
}

try {
    await fs.cp(source, fixture, {
        recursive: true,
        filter: file => !path.relative(source, file).split(path.sep).some(part =>
            ['.git', '.st-toolkit', 'node_modules', 'vendor', 'languages'].includes(part))
    });
    await fs.symlink(path.join(source, 'node_modules'), path.join(fixture, 'node_modules'), 'dir');

    await fs.appendFile(path.join(fixture, 'assets/styles/abstracts/_root.css'), '\n@theme { --color-acceptance: #123456; }\n:root { --acceptance-shared: 17px; }\n');
    await write('assets/styles/base/_acceptance.css', '.acceptance { & .acceptance__title { padding: var(--acceptance-shared); @apply bg-acceptance; } }\n');
    for (const entry of ['main.css', 'editor.css']) {
        await fs.appendFile(path.join(fixture, 'assets/styles', entry), '\n@import "./base/_acceptance.css";\n');
    }
    await write('assets/styles/styles-register/acceptance.css', '@reference "../main.css";\n.acceptance-independent { @apply bg-acceptance; }\n');

    const initial = await runBuild();
    check(initial.code === 0, initial.output);
    for (const entry of ['main', 'editor']) {
        const css = await read('assets/css/' + entry + '.min.css');
        check(css.includes('--acceptance-shared:17px'), entry + ' lost shared root variables');
        check(css.includes('.acceptance .acceptance__title'), entry + ' lost nested imported rules');
        check(css.includes('var(--color-acceptance)'), entry + ' lost @apply theme context');
    }
    const independent = await read('assets/css/styles-register/acceptance.min.css');
    check(independent.includes('.acceptance-independent'), 'Independent native entry did not compile');
    check(!independent.includes('--acceptance-shared:17px'), '@reference duplicated root CSS');
    const outputs = await hashes(path.join(fixture, 'assets/css'));
    check(!Object.keys(outputs).some(file => file.includes('_root') || file.includes('_acceptance')), 'An imported partial produced standalone output');
    check(Object.hasOwn(outputs, 'styles-register/plugins/animatecss/animate.min.css'), 'Parallel AnimateCSS Sass build is missing');
    const repeat = await runBuild();
    check(repeat.code === 0, repeat.output);
    assert.deepEqual(await hashes(path.join(fixture, 'assets/css')), outputs, 'Production build is not deterministic');
    assertions += 1;

    await write('assets/scss/styles-register/acceptance.scss', '.collision { color: red; }\n');
    const collision = await runBuild();
    check(collision.code !== null && collision.code !== 0, 'Collision process terminated unexpectedly: ' + JSON.stringify(collision));
    check(collision.output.includes('assets/scss/styles-register/acceptance.scss') && collision.output.includes('assets/styles/styles-register/acceptance.css'), 'Collision did not report both sources: ' + JSON.stringify(collision));
    assert.deepEqual(await hashes(path.join(fixture, 'assets/css')), outputs, 'Collision modified existing generated files');
    assertions += 1;
    await fs.unlink(path.join(fixture, 'assets/scss/styles-register/acceptance.scss'));

    const state = JSON.parse(await read('st-toolkit.json'));
    await write('st-toolkit.json', JSON.stringify({...state, vite_plugins: [], css_entries: []}));
    const inactive = await runBuild();
    check(inactive.code !== 0 && inactive.output.includes('require the Tailwind UI profile'), 'Native CSS was accepted with Tailwind disabled');
    assert.deepEqual(await hashes(path.join(fixture, 'assets/css')), outputs, 'Invalid profile deleted generated files');
    assertions += 1;
    await write('st-toolkit.json', JSON.stringify(state));

    watcher = spawn(process.execPath, ['node_modules/vite/bin/vite.js', 'build', '--watch', '--mode', 'development'], {cwd: fixture, env: process.env});
    let watchOutput = '';
    let builds = 0;
    watcher.stdout.on('data', data => {
        watchOutput += data;
        builds = (watchOutput.match(/built in/g) || []).length;
    });
    watcher.stderr.on('data', data => { watchOutput += data; });
    await waitUntil(() => builds >= 1, 'initial watch build');
    const previous = builds;
    await fs.appendFile(path.join(fixture, 'assets/styles/abstracts/_root.css'), '\n:root { --acceptance-watch: 29px; }\n');
    await waitUntil(async () => builds > previous && (await read('assets/css/main.min.css')).includes('--acceptance-watch: 29px'), 'shared partial rebuild');
    check((await read('assets/css/editor.min.css')).includes('--acceptance-watch: 29px'), 'Watcher did not refresh editor shared variables');
    await write('assets/styles/styles-enqueue/acceptance-dynamic.css', '.acceptance-dynamic { color: #135790; }\n');
    await waitUntil(async () => {
        try { return (await read('assets/css/styles-enqueue/acceptance-dynamic.min.css')).includes('.acceptance-dynamic'); }
        catch { return false; }
    }, 'new entry discovery without watcher restart');
    check(true, 'Watcher discovered a newly added entry');
    console.log('Tailwind build acceptance passed (' + assertions + ' assertions).');
} finally {
    if (watcher && watcher.exitCode === null) {
        await new Promise(resolve => {
            watcher.once('exit', resolve);
            watcher.kill('SIGTERM');
        });
    }
    if (process.env.ST_KEEP_TAILWIND_FIXTURE) {
        console.log('Retained fixture: ' + fixture);
    } else {
        await fs.rm(fixture, {recursive: true, force: true});
    }
}
